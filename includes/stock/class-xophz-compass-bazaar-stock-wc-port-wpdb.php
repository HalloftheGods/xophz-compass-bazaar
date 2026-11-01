<?php
/**
 * Stock_Wc_Port on WooCommerce (spec 8.2 and 8.5): the only Bazaar code that
 * writes WooCommerce `_stock` for the ledger.
 *
 * Guarantees:
 * - lock_products() locks the `_stock` postmeta rows with SELECT ... FOR
 *   UPDATE in ascending post id order. A product with no `_stock` row gets
 *   one first; ensure_stock_rows() does that outside a transaction, and a
 *   row still missing inside the transaction is added after locking the
 *   product's posts row, so two transactions do not both add one.
 * - apply_delta() moves `_stock` relatively through the WooCommerce product
 *   data store (meta_value + or - x) inside the caller's transaction, and
 *   mirrors balances into _compass_multi_inventory[loc]['stock_quantity'] for
 *   locations already in that meta. It refuses a delta WooCommerce would
 *   round (wc_stock_amount), so `_stock` never silently drifts.
 * - after_commit() runs after the outermost Core_Db commit (at once when no
 *   transaction is open): cache clean-up, product validate_props() and
 *   save(), woocommerce_product_set_stock / woocommerce_variation_set_stock
 *   and wc_trigger_stock_change_actions(). A rollback clears the caches of
 *   every product apply_delta() touched.
 * - writing() is true while this port changes WooCommerce data, so the
 *   observer can ignore those changes.
 *
 * Not guaranteed: the save() in after_commit runs outside the transaction,
 * so a stock status written there can lag a change committed by another
 * request in between; the next after_commit for that product corrects it.
 *
 * @package Xophz_Compass_Bazaar
 */
class Xophz_Compass_Bazaar_Stock_Wc_Port_Wpdb implements Xophz_Compass_Bazaar_Stock_Wc_Port {

  /** @var int Depth of this port's own WooCommerce writes. */
  private static $writing = 0;

  /** True while this port is writing WooCommerce stock data. */
  public static function writing(): bool {
    return self::$writing > 0;
  }

  /**
   * Run $fn with writing() raised.
   *
   * @return mixed
   */
  public static function while_writing( callable $fn ) {
    self::$writing++;
    try {
      return $fn();
    } finally {
      self::$writing--;
    }
  }

  /**
   * Add a `_stock` row ('0', unique) for each product that has none. Call it
   * before the transaction; WooCommerce reads a missing row as 0 too.
   *
   * @param int[] $pids
   */
  public function ensure_stock_rows( array $pids ): void {
    foreach ( self::ids( $pids ) as $pid ) {
      if ( get_post( $pid ) && ! metadata_exists( 'post', $pid, '_stock' ) ) {
        self::while_writing(
          function () use ( $pid ) {
            add_post_meta( $pid, '_stock', '0', true );
          }
        );
      }
    }
  }

  public function lock_products( array $pids ): array {
    $pids = self::ids( $pids );
    if ( ! $pids ) {
      return [];
    }
    $values  = self::select_stock( $pids );
    $missing = array_values( array_diff( $pids, array_keys( $values ) ) );
    if ( $missing ) {
      global $wpdb;
      Xophz_Compass_Bazaar_Core_Db::get_results(
        "SELECT ID FROM {$wpdb->posts} WHERE ID IN (" . self::placeholders( $missing ) . ') ORDER BY ID FOR UPDATE',
        $missing
      );
      foreach ( array_diff( $missing, array_keys( self::select_stock( $missing ) ) ) as $pid ) {
        Xophz_Compass_Bazaar_Core_Db::insert( $wpdb->postmeta, [ 'post_id' => $pid, 'meta_key' => '_stock', 'meta_value' => '0' ] );
        $this->forget_on_rollback( $pid );
      }
      $values = self::select_stock( $pids );
    }
    $out = [];
    foreach ( $pids as $pid ) {
      $out[ $pid ] = (float) ( $values[ $pid ] ?? 0.0 );
    }
    return $out;
  }

  public function initial_balances( int $pid, float $wc_value ): array {
    $out = [];
    foreach ( Xophz_Compass_Bazaar_Stock_Locations::parse_entries( self::locked_inventory( $pid ) ) as $loc => $entry ) {
      $out[] = [ $loc, $entry['qty'], $entry['enabled'] ];
    }
    return $out;
  }

  public function product_info( int $id ): ?array {
    $product = $id > 0 ? wc_get_product( $id ) : null;
    if ( ! $product ) {
      return null;
    }
    $is_variation = $product->is_type( 'variation' );
    $key          = (int) $product->get_stock_managed_by_id();
    return [
      'stock_product_id'   => $key > 0 ? $key : $id,
      'product_id'         => $is_variation ? (int) $product->get_parent_id() : $id,
      'variation_id'       => $is_variation ? $id : 0,
      'manages_stock'      => (bool) $product->managing_stock(),
      'backorders_allowed' => (bool) $product->backorders_allowed(),
      'location_tracked'   => (bool) Xophz_Compass_Bazaar_Stock_Locations::inventory_entries( $key > 0 ? $key : $id ),
      'name'               => (string) $product->get_name(),
    ];
  }

  public function apply_delta( int $pid, float $projectable_delta, array $location_balances ): void {
    $this->forget_on_rollback( $pid );
    self::while_writing(
      function () use ( $pid, $projectable_delta, $location_balances ) {
        $delta = Xophz_Compass_Bazaar_Stock_Line::qty( $projectable_delta );
        if ( Xophz_Compass_Bazaar_Stock_Planner::differs( $delta, 0.0 ) ) {
          $amount = abs( $delta );
          if ( Xophz_Compass_Bazaar_Stock_Planner::differs( (float) wc_stock_amount( $amount ), $amount ) ) {
            throw new RuntimeException( "WooCommerce would round the stock change $amount for product $pid; nothing was written." );
          }
          WC_Data_Store::load( 'product' )->update_product_stock( $pid, $amount, $delta > 0 ? 'increase' : 'decrease' );
          self::throw_db_error( 'update_product_stock' );
        }
        $this->mirror_locations( $pid, $location_balances );
      }
    );
  }

  public function after_commit( array $pids, string $reason ): void {
    $pids = self::ids( $pids );
    Xophz_Compass_Bazaar_Core_Db::after_commit(
      function () use ( $pids ) {
        self::while_writing(
          function () use ( $pids ) {
            // One product's failing listener must not skip the others' sync.
            foreach ( $pids as $pid ) {
              try {
                self::sync_product( $pid );
              } catch ( Throwable $e ) {
                error_log( 'Bazaar after_commit sync_product ' . $pid . ': ' . get_class( $e ) . ': ' . $e->getMessage() );
              }
            }
          }
        );
      }
    );
  }

  /** Clear the WordPress and WooCommerce caches that hold the product's stock. */
  public static function clear_caches( int $pid ): void {
    wp_cache_delete( $pid, 'post_meta' );
    if ( function_exists( 'wc_delete_product_transients' ) ) {
      wc_delete_product_transients( $pid );
    }
    clean_post_cache( $pid );
  }

  /** After commit: fresh product, stock status sync and the stock actions third parties listen to. */
  private static function sync_product( int $pid ): void {
    self::clear_caches( $pid );
    $product = wc_get_product( $pid );
    if ( ! $product ) {
      return;
    }
    $product->validate_props();
    $product->save();
    if ( $product->is_type( 'variation' ) ) {
      do_action( 'woocommerce_variation_set_stock', $product );
    } else {
      do_action( 'woocommerce_product_set_stock', $product );
    }
    if ( function_exists( 'wc_trigger_stock_change_actions' ) ) {
      wc_trigger_stock_change_actions( $product );
    }
  }

  /** Write on_hand into the meta entries that already exist; other locations are not added. */
  private function mirror_locations( int $pid, array $location_balances ): void {
    $locations = array_filter(
      array_keys( $location_balances ),
      function ( $loc ) {
        return (int) $loc > 0;
      }
    );
    if ( ! $locations ) {
      return;
    }
    wp_cache_delete( $pid, 'post_meta' );
    $raw = self::locked_inventory( $pid );
    if ( ! is_array( $raw ) || ! $raw ) {
      return;
    }
    $changed = false;
    foreach ( $raw as $key => $entry ) {
      if ( ! is_array( $entry ) ) {
        continue;
      }
      $loc = (int) ( $entry['location_id'] ?? $key );
      if ( $loc <= 0 || ! array_key_exists( $loc, $location_balances ) ) {
        continue;
      }
      $value = self::meta_quantity( (float) $location_balances[ $loc ] );
      if ( ! isset( $entry['stock_quantity'] ) || (float) $entry['stock_quantity'] !== (float) $value ) {
        $raw[ $key ]['stock_quantity'] = $value;
        $changed                        = true;
      }
    }
    if ( $changed ) {
      update_post_meta( $pid, Xophz_Compass_Bazaar_Stock_Locations::INVENTORY_META, $raw );
      self::throw_db_error( 'update_post_meta' );
    }
  }

  /** The _compass_multi_inventory value, read with a locking read inside a transaction (latest committed row). */
  private static function locked_inventory( int $pid ) {
    global $wpdb;
    $value = Xophz_Compass_Bazaar_Core_Db::get_var(
      "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s ORDER BY meta_id LIMIT 1"
        . ( Xophz_Compass_Bazaar_Core_Db::in_tx() ? ' FOR UPDATE' : '' ),
      [ $pid, Xophz_Compass_Bazaar_Stock_Locations::INVENTORY_META ]
    );
    return null === $value ? null : maybe_unserialize( $value );
  }

  /** An integer when the balance is whole (what the legacy screens write), else the 4-decimal value. */
  private static function meta_quantity( float $on_hand ) {
    $qty = Xophz_Compass_Bazaar_Stock_Line::qty( $on_hand );
    return Xophz_Compass_Bazaar_Stock_Planner::differs( $qty, round( $qty ) ) ? $qty : (int) round( $qty );
  }

  /** @return array pid => the locked `_stock` value of the lowest meta_id row, for the products that have one. */
  private static function select_stock( array $pids ): array {
    global $wpdb;
    $rows = Xophz_Compass_Bazaar_Core_Db::get_results(
      "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_stock' AND post_id IN (" . self::placeholders( $pids ) . ') ORDER BY post_id, meta_id FOR UPDATE',
      $pids
    );
    $out = [];
    foreach ( $rows as $row ) {
      $pid = (int) $row->post_id;
      if ( ! array_key_exists( $pid, $out ) ) {
        $out[ $pid ] = is_numeric( $row->meta_value ) ? (float) $row->meta_value : 0.0;
      }
    }
    return $out;
  }

  /** Clear the product's caches if the open transaction rolls back. Each call queues one clean-up; repeats are harmless. */
  private function forget_on_rollback( int $pid ): void {
    Xophz_Compass_Bazaar_Core_Db::after_rollback(
      function () use ( $pid ) {
        self::clear_caches( $pid );
      }
    );
  }

  /** WooCommerce does not surface query errors; turn the last wpdb error into an exception so the transaction rolls back. */
  private static function throw_db_error( string $where ): void {
    global $wpdb;
    $message = (string) $wpdb->last_error;
    if ( '' === $message ) {
      return;
    }
    if ( false !== stripos( $message, 'Deadlock found' ) || false !== stripos( $message, 'Lock wait timeout' ) ) {
      throw new Xophz_Compass_Bazaar_Core_Deadlock_Exception( $message, 1213 );
    }
    throw new RuntimeException( "Bazaar stock projection failed in $where: $message" );
  }

  /** @return int[] Distinct positive ids, ascending. */
  private static function ids( array $pids ): array {
    $ids = array_values( array_unique( array_filter( array_map( 'intval', $pids ), function ( $id ) {
      return $id > 0;
    } ) ) );
    sort( $ids );
    return $ids;
  }

  private static function placeholders( array $ids ): string {
    return implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
  }
}
