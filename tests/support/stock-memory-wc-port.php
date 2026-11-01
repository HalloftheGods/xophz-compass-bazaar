<?php
/**
 * In-memory Stock_Wc_Port for the plain-PHP tests: a fake WooCommerce `_stock`
 * and `_compass_multi_inventory` per product.
 *
 * - apply_delta() changes $stock relatively and mirrors location balances into
 *   $multi for locations already present there. It throws outside a transaction.
 * - initial_balances() returns the multi-inventory entries only (no location 0),
 *   so the tests prove the service puts the residual on location 0.
 * - external_write() sets `_stock` absolutely, like a WooCommerce admin edit
 *   made between service calls. $on_lock runs inside lock_products() before
 *   the values are read, to simulate a write that lands just before the lock.
 * - The port enlists in the store, so a rollback restores its state too.
 */
require_once __DIR__ . '/stock-memory-store.php';

if ( class_exists( 'Bazaar_Test_Stock_Memory_Wc_Port' ) ) {
  return;
}

class Bazaar_Test_Stock_Memory_Wc_Port implements Xophz_Compass_Bazaar_Stock_Wc_Port {

  /** @var array pid => float, the fake `_stock` */
  public $stock = [];

  /** @var array id => product info */
  public $products = [];

  /** @var array pid => [loc => ['qty' => float, 'enabled' => bool]] */
  public $multi = [];

  /** @var array[] [pid, delta, location balances] per apply_delta call */
  public $deltas = [];

  /** @var array[] [pids, reason] per after_commit call */
  public $commits = [];

  /** @var callable|null fn(array $pids, Bazaar_Test_Stock_Memory_Wc_Port $port) */
  public $on_lock = null;

  /** @var Bazaar_Test_Stock_Memory_Store */
  private $store;

  public function __construct( Bazaar_Test_Stock_Memory_Store $store ) {
    $this->store = $store;
    $store->enlist( $this );
  }

  /** Register a product that manages its own stock. $multi: loc => [qty, enabled]. */
  public function add_product( int $pid, float $stock, array $info = [], array $multi = [] ): void {
    $this->products[ $pid ] = array_merge(
      [
        'stock_product_id'   => $pid,
        'product_id'         => $pid,
        'variation_id'       => 0,
        'manages_stock'      => true,
        'backorders_allowed' => false,
        'location_tracked'   => (bool) $multi,
        'name'               => '',
      ],
      $info
    );
    $this->stock[ $pid ] = $stock;
    foreach ( $multi as $loc => $entry ) {
      $this->multi[ $pid ][ (int) $loc ] = [ 'qty' => (float) $entry[0], 'enabled' => (bool) $entry[1] ];
    }
  }

  public function external_write( int $pid, float $value ): void {
    $this->stock[ $pid ] = $value;
  }

  public function snapshot(): array {
    return [ $this->stock, $this->multi, $this->deltas ];
  }

  public function restore( array $state ): void {
    list( $this->stock, $this->multi, $this->deltas ) = $state;
  }

  public function lock_products( array $pids ): array {
    if ( ! $this->store->in_transaction() ) {
      throw new LogicException( 'lock_products outside a transaction.' );
    }
    $this->store->record_lock( 'products', $pids );
    if ( $this->on_lock ) {
      call_user_func( $this->on_lock, $pids, $this );
    }
    $out = [];
    foreach ( $pids as $pid ) {
      if ( ! isset( $this->stock[ $pid ] ) ) {
        $this->stock[ $pid ] = 0.0;
      }
      $out[ $pid ] = (float) $this->stock[ $pid ];
    }
    return $out;
  }

  public function initial_balances( int $pid, float $wc_value ): array {
    $out = [];
    foreach ( $this->multi[ $pid ] ?? [] as $loc => $entry ) {
      $out[] = [ $loc, $entry['qty'], $entry['enabled'] ];
    }
    return $out;
  }

  public function product_info( int $id ): ?array {
    return $this->products[ $id ] ?? null;
  }

  public function apply_delta( int $pid, float $projectable_delta, array $location_balances ): void {
    if ( ! $this->store->in_transaction() ) {
      throw new LogicException( 'apply_delta outside a transaction.' );
    }
    $this->stock[ $pid ] = round( ( $this->stock[ $pid ] ?? 0.0 ) + $projectable_delta, 4 );
    foreach ( $location_balances as $loc => $on_hand ) {
      if ( isset( $this->multi[ $pid ][ $loc ] ) ) {
        $this->multi[ $pid ][ $loc ]['qty'] = (float) $on_hand;
      }
    }
    $this->deltas[] = [ $pid, $projectable_delta, $location_balances ];
  }

  public function after_commit( array $pids, string $reason ): void {
    if ( $this->store->in_transaction() ) {
      throw new LogicException( 'after_commit inside a transaction.' );
    }
    $this->commits[] = [ $pids, $reason ];
  }

  /** I2: for every initialized product, `_stock` equals location 0 plus every enabled location. @return string[] */
  public function i2_violations(): array {
    $bad = [];
    foreach ( array_keys( $this->stock ) as $pid ) {
      $balances = $this->store->balances( $pid );
      if ( ! $balances ) {
        continue;
      }
      $sum = 0.0;
      foreach ( $balances as $loc => $row ) {
        $sum += ( 0 === $loc || $row['is_enabled'] ) ? $row['on_hand'] : 0.0;
      }
      if ( abs( round( $sum, 4 ) - $this->stock[ $pid ] ) > 0.00001 ) {
        $bad[] = "product $pid _stock {$this->stock[ $pid ]} != projectable $sum";
      }
    }
    return $bad;
  }
}
