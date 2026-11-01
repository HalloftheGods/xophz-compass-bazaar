<?php
/**
 * In-memory Stock_Store for the plain-PHP tests, and the stock domain loader.
 *
 * Behaves like the wpdb store where the tests rely on it:
 * - transaction() nests (inner calls join) and an exception in the outermost
 *   call restores every enlisted participant (this store and the fake
 *   WooCommerce port). Ids are never reused, as with AUTO_INCREMENT.
 * - insert_batch() enforces the unique idem_key with Core_Duplicate_Key_Exception.
 * - Writes outside a transaction, and balance writes to a pair that was not
 *   locked in the current transaction, throw LogicException.
 * - Every lock call is appended to $lock_log as [tx serial, kind, items as passed].
 * - $hidden_keys[key] = n makes find_batch() miss that key n times, which
 *   simulates a concurrent winner that this transaction cannot see yet.
 *
 * Loading this file does not register the plugin autoloader.
 */
$bazaar_root = dirname( dirname( __DIR__ ) );
foreach ( [
  'core/class-xophz-compass-bazaar-core-canonical.php',
  'core/class-xophz-compass-bazaar-core-http-error.php',
  'core/class-xophz-compass-bazaar-core-duplicate-key-exception.php',
  'stock/interface-xophz-compass-bazaar-stock-store.php',
  'stock/interface-xophz-compass-bazaar-stock-wc-port.php',
  'stock/class-xophz-compass-bazaar-stock-line.php',
  'stock/class-xophz-compass-bazaar-stock-meta.php',
  'stock/class-xophz-compass-bazaar-stock-keys.php',
  'stock/class-xophz-compass-bazaar-stock-batch.php',
  'stock/class-xophz-compass-bazaar-stock-result.php',
  'stock/class-xophz-compass-bazaar-stock-planner.php',
  'stock/class-xophz-compass-bazaar-stock-order-math.php',
  'stock/class-xophz-compass-bazaar-stock-insufficient-exception.php',
  'stock/class-xophz-compass-bazaar-stock-conflict-exception.php',
  'stock/class-xophz-compass-bazaar-stock-service.php',
] as $bazaar_file ) {
  require_once $bazaar_root . '/includes/' . $bazaar_file;
}
unset( $bazaar_root, $bazaar_file );

if ( class_exists( 'Bazaar_Test_Stock_Memory_Store' ) ) {
  return;
}

class Bazaar_Test_Stock_Memory_Store implements Xophz_Compass_Bazaar_Stock_Store {

  /** @var array id => batch row */
  public $batches = [];

  /** @var array id => movement row */
  public $movements = [];

  /** @var array "pid:loc" => ['on_hand', 'is_enabled', 'last_movement_id'] */
  public $rows = [];

  /** @var array[] [tx serial, 'products'|'balances', items as passed] */
  public $lock_log = [];

  /** @var array key => remaining misses */
  public $hidden_keys = [];

  /** @var int Outermost transactions started. */
  public $tx_serial = 0;

  /** @var int Outermost transactions rolled back. */
  public $rollbacks = 0;

  /** @var object[] Participants with snapshot(): array and restore(array). */
  private $participants = [];

  private $depth = 0;

  private $next_batch = 1;

  private $next_movement = 1;

  /** @var array "pid:loc" => true, locked in the open transaction */
  private $locked = [];

  public function __construct() {
    $this->participants[] = $this;
  }

  public function enlist( $participant ): void {
    $this->participants[] = $participant;
  }

  public function in_transaction(): bool {
    return $this->depth > 0;
  }

  public function record_lock( string $kind, array $items ): void {
    $this->lock_log[] = [ $this->tx_serial, $kind, $items ];
  }

  public function snapshot(): array {
    return [ $this->batches, $this->movements, $this->rows ];
  }

  public function restore( array $state ): void {
    list( $this->batches, $this->movements, $this->rows ) = $state;
  }

  public function transaction( callable $fn ) {
    if ( $this->depth > 0 ) {
      $this->depth++;
      try {
        return $fn();
      } finally {
        $this->depth--;
      }
    }
    $saved = array_map( function ( $p ) { return $p->snapshot(); }, $this->participants );
    $this->tx_serial++;
    $this->depth  = 1;
    $this->locked = [];
    try {
      $result = $fn();
    } catch ( Throwable $e ) {
      foreach ( $this->participants as $i => $p ) {
        $p->restore( $saved[ $i ] );
      }
      $this->rollbacks++;
      throw $e;
    } finally {
      $this->depth  = 0;
      $this->locked = [];
    }
    return $result;
  }

  public function find_batch( string $idem_key ): ?array {
    if ( ! empty( $this->hidden_keys[ $idem_key ] ) ) {
      $this->hidden_keys[ $idem_key ]--;
      return null;
    }
    foreach ( $this->batches as $row ) {
      if ( $row['idem_key'] === $idem_key ) {
        $lines = array_values( array_filter( $this->movements, function ( $m ) use ( $row ) { return $m['batch_id'] === $row['id']; } ) );
        usort( $lines, function ( $a, $b ) { return $a['line_no'] <=> $b['line_no']; } );
        return [ 'batch' => $row, 'lines' => $lines ];
      }
    }
    return null;
  }

  public function has_balances( int $pid ): bool {
    return (bool) $this->balances( $pid );
  }

  public function lock_balances( array $pairs ): array {
    $this->must_be_in_tx();
    $this->record_lock( 'balances', $pairs );
    $sorted = $pairs;
    usort( $sorted, function ( $a, $b ) { return [ $a[0], $a[1] ] <=> [ $b[0], $b[1] ]; } );
    $out = [];
    foreach ( $sorted as $pair ) {
      $key = (int) $pair[0] . ':' . (int) $pair[1];
      if ( ! isset( $this->rows[ $key ] ) ) {
        $this->rows[ $key ] = [ 'on_hand' => 0.0, 'is_enabled' => true, 'last_movement_id' => 0 ];
      }
      $this->locked[ $key ] = true;
      $out[ $key ]          = $this->rows[ $key ];
    }
    return $out;
  }

  public function insert_batch( array $row ): int {
    $this->must_be_in_tx();
    foreach ( $this->batches as $existing ) {
      if ( $existing['idem_key'] === $row['idem_key'] ) {
        throw new Xophz_Compass_Bazaar_Core_Duplicate_Key_Exception( "Duplicate entry '{$row['idem_key']}' for key 'idem_key'", 1062 );
      }
    }
    $id                   = $this->next_batch++;
    $this->batches[ $id ] = [ 'id' => $id ] + $row;
    return $id;
  }

  public function insert_movement( array $row ): int {
    $this->must_be_in_tx();
    if ( ! isset( $this->batches[ $row['batch_id'] ] ) ) {
      throw new LogicException( 'Movement for an unknown batch.' );
    }
    foreach ( $this->movements as $existing ) {
      if ( $existing['batch_id'] === $row['batch_id'] && $existing['line_no'] === $row['line_no'] ) {
        throw new Xophz_Compass_Bazaar_Core_Duplicate_Key_Exception( 'Duplicate entry for key batch_line', 1062 );
      }
    }
    $id                     = $this->next_movement++;
    $this->movements[ $id ] = [ 'id' => $id ] + $row;
    return $id;
  }

  public function update_balance( int $pid, int $loc, float $on_hand, int $movement_id ): void {
    $key = $this->locked_key( $pid, $loc );
    $this->rows[ $key ]['on_hand']          = $on_hand;
    $this->rows[ $key ]['last_movement_id'] = $movement_id;
  }

  public function set_enabled( int $pid, int $loc, bool $enabled ): void {
    $this->rows[ $this->locked_key( $pid, $loc ) ]['is_enabled'] = $enabled;
  }

  public function order_sums( int $order_id ): array {
    $items = [];
    $last  = 0;
    foreach ( $this->movements as $m ) {
      if ( $m['order_id'] !== $order_id || $order_id <= 0 ) {
        continue;
      }
      $last = max( $last, $m['id'] );
      $item = $m['order_item_id'];
      $loc  = $m['location_id'];
      if ( ! isset( $items[ $item ][ $loc ] ) ) {
        $items[ $item ][ $loc ] = [ 'net' => 0.0, 'restocked' => 0.0, 'stock_product_id' => $m['stock_product_id'], 'product_id' => $m['product_id'], 'variation_id' => $m['variation_id'] ];
      }
      $items[ $item ][ $loc ]['net'] = round( $items[ $item ][ $loc ]['net'] + $m['qty_delta'], 4 );
      if ( 'refund_restock' === $this->batches[ $m['batch_id'] ]['reason'] ) {
        $items[ $item ][ $loc ]['restocked'] = round( $items[ $item ][ $loc ]['restocked'] + $m['qty_delta'], 4 );
      }
    }
    return [ 'items' => $items, 'last_movement_id' => $last ];
  }

  public function last_movement_id( int $pid ): int {
    $last = 0;
    foreach ( $this->movements as $m ) {
      if ( $m['stock_product_id'] === $pid ) {
        $last = max( $last, $m['id'] );
      }
    }
    return $last;
  }

  public function balances( int $pid ): array {
    $out = [];
    foreach ( $this->rows as $key => $row ) {
      list( $row_pid, $loc ) = array_map( 'intval', explode( ':', $key ) );
      if ( $row_pid === $pid ) {
        $out[ $loc ] = [ 'on_hand' => (float) $row['on_hand'], 'is_enabled' => (bool) $row['is_enabled'] ];
      }
    }
    ksort( $out );
    return $out;
  }

  /** I1: every balance equals the sum of its movements, and each balance_after is the running sum. @return string[] */
  public function i1_violations(): array {
    $bad     = [];
    $running = [];
    foreach ( $this->movements as $m ) {
      $key             = $m['stock_product_id'] . ':' . $m['location_id'];
      $running[ $key ] = round( ( $running[ $key ] ?? 0.0 ) + $m['qty_delta'], 4 );
      if ( abs( $running[ $key ] - $m['balance_after'] ) > 0.00001 ) {
        $bad[] = "movement {$m['id']} balance_after {$m['balance_after']} != running {$running[ $key ]}";
      }
    }
    foreach ( $this->rows as $key => $row ) {
      if ( abs( ( $running[ $key ] ?? 0.0 ) - $row['on_hand'] ) > 0.00001 ) {
        $bad[] = "balance $key on_hand {$row['on_hand']} != sum " . ( $running[ $key ] ?? 0.0 );
      }
    }
    return $bad;
  }

  private function must_be_in_tx(): void {
    if ( 0 === $this->depth ) {
      throw new LogicException( 'Stock store write outside a transaction.' );
    }
  }

  private function locked_key( int $pid, int $loc ): string {
    $this->must_be_in_tx();
    $key = "$pid:$loc";
    if ( empty( $this->locked[ $key ] ) ) {
      throw new LogicException( "Balance $key written without lock_balances in this transaction." );
    }
    return $key;
  }
}
