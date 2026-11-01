<?php
/**
 * Read-only ledger audit (spec 7.2 and 8.8), used by `wp bazaar stock verify`
 * and by Stock_Mode::set( 'ledger' ).
 *
 * run() returns ['ok', 'violations', 'notices', 'counts', 'checked_at'].
 * Every entry has a 'type' and a 'message'. Violations:
 * - balance_sum (I1): a balance whose on_hand is not SUM(qty_delta) of its
 *   movements; orphan_movements (I1): movements with no balance row;
 * - running_sum (I1): the first movement of a (product, location) whose
 *   balance_after is not the running sum, or a balance whose on_hand or
 *   last_movement_id is not its last movement's;
 * - projection_drift (I2): Stock_Mode::drift_violations();
 * - duplicate_key (I3): an idem_key on more than one batch;
 * - order_line_net (I3): an order item whose net outflow is below 0 or
 *   above its quantity;
 * - duplicate_stock_rows: a post with more than one `_stock` row;
 * - negative_balance: a balance below 0 for a product that does not allow
 *   backorders (with backorders it is a notice, negative_balance_backorder);
 * - order_target: a ledger-owned order whose net outflow differs from its
 *   target (committed for paid statuses, on-hold and refunded, and for
 *   pending while its checkout row is committed; released for cancelled,
 *   failed and other pending orders); order_not_ledger_owned: an order with
 *   order movements that is missing or not ledger-owned.
 * Notices (reported, never counted as violations): cancelled_with_payments
 * (an order that is cancelled while it has capture rows in a counted
 * status), negative_balance_backorder, order_status_unchecked (a ledger-owned
 * order in a status this audit has no target for).
 *
 * Guarantees: it only reads; it repairs nothing.
 * Not guaranteed: a consistent snapshot. It takes no locks, so a write that
 * commits while it runs can show up as a violation that is gone on the next
 * run.
 *
 * @package Xophz_Compass_Bazaar
 */
final class Xophz_Compass_Bazaar_Stock_Audit {

  const EPSILON = 0.00005;
  const PAGE    = 2000;

  public static function run(): array {
    $found = array_merge(
      self::balance_sums(),
      self::running_sums(),
      self::projection(),
      self::duplicate_keys(),
      self::order_line_bounds(),
      self::duplicate_stock_rows(),
      self::negative_balances(),
      self::order_targets(),
      self::cancelled_with_payments()
    );
    $violations = [];
    $notices    = [];
    $counts     = [];
    foreach ( $found as $entry ) {
      if ( ! empty( $entry['notice'] ) ) {
        unset( $entry['notice'] );
        $notices[] = $entry;
        continue;
      }
      $violations[]             = $entry;
      $counts[ $entry['type'] ] = ( $counts[ $entry['type'] ] ?? 0 ) + 1;
    }
    return [
      'ok'         => ! $violations,
      'violations' => $violations,
      'notices'    => $notices,
      'counts'     => $counts,
      'checked_at' => Xophz_Compass_Bazaar_Core_Db::now(),
    ];
  }

  /** I1: on_hand = SUM(qty_delta), and no movement without its balance row. */
  private static function balance_sums(): array {
    $b   = self::t( 'stock_balances' );
    $m   = self::t( 'stock_movements' );
    $out = [];
    $sum = "(SELECT stock_product_id, location_id, SUM(qty_delta) AS total, COUNT(*) AS n FROM $m GROUP BY stock_product_id, location_id)";
    foreach ( Xophz_Compass_Bazaar_Core_Db::get_results( "SELECT b.stock_product_id, b.location_id, b.on_hand, COALESCE(s.total, 0) AS total FROM $b b LEFT JOIN $sum s ON s.stock_product_id = b.stock_product_id AND s.location_id = b.location_id WHERE ABS(b.on_hand - COALESCE(s.total, 0)) >= %f ORDER BY b.stock_product_id, b.location_id", [ self::EPSILON ] ) as $row ) {
      $out[] = self::entry( 'balance_sum', sprintf( 'Product %d location %d: on_hand %s, movements sum to %s.', $row->stock_product_id, $row->location_id, self::num( $row->on_hand ), self::num( $row->total ) ), [ 'stock_product_id' => (int) $row->stock_product_id, 'location_id' => (int) $row->location_id, 'on_hand' => self::num( $row->on_hand ), 'movements_sum' => self::num( $row->total ) ] );
    }
    foreach ( Xophz_Compass_Bazaar_Core_Db::get_results( "SELECT s.stock_product_id, s.location_id, s.total, s.n FROM $sum s LEFT JOIN $b b ON b.stock_product_id = s.stock_product_id AND b.location_id = s.location_id WHERE b.stock_product_id IS NULL ORDER BY s.stock_product_id, s.location_id" ) as $row ) {
      $out[] = self::entry( 'orphan_movements', sprintf( 'Product %d location %d: %d movements (sum %s) and no balance row.', $row->stock_product_id, $row->location_id, $row->n, self::num( $row->total ) ), [ 'stock_product_id' => (int) $row->stock_product_id, 'location_id' => (int) $row->location_id, 'movements' => (int) $row->n ] );
    }
    return $out;
  }

  /** I1: balance_after is the running sum, and the balance matches its last movement. */
  private static function running_sums(): array {
    $m     = self::t( 'stock_movements' );
    $out   = [];
    $bad   = [];
    $last  = [];
    $after = [ 0, -1, 0 ];
    do {
      list( $pid, $loc, $id ) = $after;
      $rows = Xophz_Compass_Bazaar_Core_Db::get_results(
        "SELECT id, stock_product_id, location_id, qty_delta, balance_after FROM $m"
          . ' WHERE stock_product_id > %d OR (stock_product_id = %d AND (location_id > %d OR (location_id = %d AND id > %d)))'
          . ' ORDER BY stock_product_id, location_id, id LIMIT %d',
        [ $pid, $pid, $loc, $loc, $id, self::PAGE ]
      );
      foreach ( $rows as $row ) {
        $key = (int) $row->stock_product_id . ':' . (int) $row->location_id;
        $run = Xophz_Compass_Bazaar_Stock_Line::qty( ( $last[ $key ]['sum'] ?? 0.0 ) + (float) $row->qty_delta );
        if ( ! isset( $bad[ $key ] ) && abs( $run - (float) $row->balance_after ) >= self::EPSILON ) {
          $bad[ $key ] = true;
          $out[]       = self::entry( 'running_sum', sprintf( 'Product %d location %d: movement %d has balance_after %s, the running sum is %s.', $row->stock_product_id, $row->location_id, $row->id, self::num( $row->balance_after ), self::num( $run ) ), [ 'stock_product_id' => (int) $row->stock_product_id, 'location_id' => (int) $row->location_id, 'movement_id' => (int) $row->id ] );
        }
        $last[ $key ] = [ 'sum' => $run, 'id' => (int) $row->id, 'after' => (float) $row->balance_after ];
        $after        = [ (int) $row->stock_product_id, (int) $row->location_id, (int) $row->id ];
      }
    } while ( count( $rows ) === self::PAGE );
    foreach ( Xophz_Compass_Bazaar_Core_Db::get_results( 'SELECT stock_product_id, location_id, on_hand, last_movement_id FROM ' . self::t( 'stock_balances' ) ) as $row ) {
      $key  = (int) $row->stock_product_id . ':' . (int) $row->location_id;
      $tail = $last[ $key ] ?? null;
      if ( ! $tail || isset( $bad[ $key ] ) ) {
        continue;
      }
      if ( (int) $row->last_movement_id !== $tail['id'] || abs( (float) $row->on_hand - $tail['after'] ) >= self::EPSILON ) {
        $out[] = self::entry( 'running_sum', sprintf( 'Product %d location %d: the balance says on_hand %s at movement %d, the last movement is %d with balance_after %s.', $row->stock_product_id, $row->location_id, self::num( $row->on_hand ), $row->last_movement_id, $tail['id'], self::num( $tail['after'] ) ), [ 'stock_product_id' => (int) $row->stock_product_id, 'location_id' => (int) $row->location_id, 'movement_id' => $tail['id'] ] );
      }
    }
    return $out;
  }

  /** I2. */
  private static function projection(): array {
    $out = [];
    foreach ( Xophz_Compass_Bazaar_Stock_Mode::drift_violations() as $row ) {
      $out[] = self::entry( 'projection_drift', sprintf( 'Product %d: _stock is %s, the projectable sum is %s.', $row['stock_product_id'], self::num( $row['wc_stock'] ), self::num( $row['projectable'] ) ), [ 'stock_product_id' => (int) $row['stock_product_id'], 'wc_stock' => self::num( $row['wc_stock'] ), 'projectable' => self::num( $row['projectable'] ) ] );
    }
    return $out;
  }

  /** I3: unique batch keys. */
  private static function duplicate_keys(): array {
    $out = [];
    foreach ( Xophz_Compass_Bazaar_Core_Db::get_results( 'SELECT idem_key, COUNT(*) AS n FROM ' . self::t( 'stock_batches' ) . ' GROUP BY idem_key HAVING COUNT(*) > 1' ) as $row ) {
      $out[] = self::entry( 'duplicate_key', sprintf( 'Batch key %s is on %d batches.', $row->idem_key, $row->n ), [ 'idem_key' => (string) $row->idem_key ] );
    }
    return $out;
  }

  /** I3: 0 <= net outflow <= quantity for every order item with movements. */
  private static function order_line_bounds(): array {
    global $wpdb;
    $rows = Xophz_Compass_Bazaar_Core_Db::get_results( 'SELECT order_id, order_item_id, SUM(qty_delta) AS net FROM ' . self::t( 'stock_movements' ) . ' WHERE order_id > 0 GROUP BY order_id, order_item_id ORDER BY order_id, order_item_id' );
    $qty  = [];
    $ids  = array_values( array_filter( array_map( function ( $row ) {
      return (int) $row->order_item_id;
    }, $rows ) ) );
    foreach ( array_chunk( $ids, 500 ) as $chunk ) {
      $in = implode( ',', array_map( 'intval', $chunk ) );
      foreach ( Xophz_Compass_Bazaar_Core_Db::get_results( "SELECT order_item_id, meta_value FROM {$wpdb->prefix}woocommerce_order_itemmeta WHERE meta_key = '_qty' AND order_item_id IN ($in)" ) as $meta ) {
        $qty[ (int) $meta->order_item_id ] = (float) $meta->meta_value;
      }
    }
    $out = [];
    foreach ( $rows as $row ) {
      $outflow = Xophz_Compass_Bazaar_Stock_Line::qty( -(float) $row->net );
      $item    = (int) $row->order_item_id;
      $max     = $qty[ $item ] ?? null;
      if ( $outflow <= -self::EPSILON || ( null !== $max && $outflow - $max >= self::EPSILON ) ) {
        $out[] = self::entry( 'order_line_net', sprintf( 'Order %d item %d: net outflow %s is outside 0 to %s.', $row->order_id, $item, self::num( $outflow ), null === $max ? '?' : self::num( $max ) ), [ 'order_id' => (int) $row->order_id, 'order_item_id' => $item, 'net_outflow' => self::num( $outflow ) ] );
      }
    }
    return $out;
  }

  private static function duplicate_stock_rows(): array {
    global $wpdb;
    $out = [];
    foreach ( Xophz_Compass_Bazaar_Core_Db::get_results( "SELECT post_id, COUNT(*) AS n FROM {$wpdb->postmeta} WHERE meta_key = '_stock' GROUP BY post_id HAVING COUNT(*) > 1 ORDER BY post_id" ) as $row ) {
      $out[] = self::entry( 'duplicate_stock_rows', sprintf( 'Post %d has %d _stock rows.', $row->post_id, $row->n ), [ 'stock_product_id' => (int) $row->post_id, 'rows' => (int) $row->n ] );
    }
    return $out;
  }

  private static function negative_balances(): array {
    $out = [];
    $sql = 'SELECT stock_product_id, location_id, on_hand FROM ' . self::t( 'stock_balances' ) . ' WHERE on_hand <= %f ORDER BY stock_product_id, location_id';
    foreach ( Xophz_Compass_Bazaar_Core_Db::get_results( $sql, [ -self::EPSILON ] ) as $row ) {
      $info    = Xophz_Compass_Bazaar_Stock_Factory::port()->product_info( (int) $row->stock_product_id );
      $backord = ! empty( $info['backorders_allowed'] );
      $out[]   = self::entry( $backord ? 'negative_balance_backorder' : 'negative_balance', sprintf( 'Product %d location %d: on_hand %s%s.', $row->stock_product_id, $row->location_id, self::num( $row->on_hand ), $backord ? ' (backorders allowed)' : '' ), [ 'stock_product_id' => (int) $row->stock_product_id, 'location_id' => (int) $row->location_id, 'on_hand' => self::num( $row->on_hand ) ], $backord );
    }
    return $out;
  }

  /** Ledger-owned orders against Stock_Order_Math targets. */
  private static function order_targets(): array {
    $checkouts = [];
    foreach ( Xophz_Compass_Bazaar_Core_Db::get_results( 'SELECT order_id, state FROM ' . self::t( 'checkouts' ) . " WHERE order_id IS NOT NULL AND stock_owner = 'ledger'" ) as $row ) {
      $checkouts[ (int) $row->order_id ] = (string) $row->state;
    }
    $ids   = array_map( 'intval', wp_list_pluck( Xophz_Compass_Bazaar_Core_Db::get_results( 'SELECT DISTINCT order_id FROM ' . self::t( 'stock_movements' ) . ' WHERE order_id > 0' ), 'order_id' ) );
    $moved = array_flip( $ids );
    if ( function_exists( 'wc_get_orders' ) ) {
      $ids = array_merge( $ids, array_keys( $checkouts ), array_map( 'intval', (array) wc_get_orders( [ 'limit' => -1, 'return' => 'ids', 'type' => 'shop_order', 'meta_query' => [ [ 'key' => Xophz_Compass_Bazaar_Stock_Order_Lifecycle::OWNER_META, 'value' => Xophz_Compass_Bazaar_Stock_Order_Lifecycle::OWNER_LEDGER ] ] ] ) ) );
    }
    $ids = array_values( array_unique( array_filter( $ids ) ) );
    sort( $ids );
    $store     = new Xophz_Compass_Bazaar_Stock_Store_Wpdb();
    $committed = Xophz_Compass_Bazaar_Stock_Order_Lifecycle::committed_statuses();
    $out       = [];
    foreach ( $ids as $id ) {
      $order = wc_get_order( $id );
      if ( ! $order instanceof WC_Order || ! Xophz_Compass_Bazaar_Stock_Order_Lifecycle::is_ledger_owned( $order ) ) {
        if ( isset( $moved[ $id ] ) ) {
          $out[] = self::entry( 'order_not_ledger_owned', sprintf( 'Order %d has order movements but is %s.', $id, $order ? 'not ledger-owned' : 'missing' ), [ 'order_id' => $id ] );
        }
        continue;
      }
      $target = self::target( (string) $order->get_status(), $committed, $checkouts[ $id ] ?? '' );
      if ( null === $target ) {
        $out[] = self::entry( 'order_status_unchecked', sprintf( 'Order %d is %s; the audit has no stock target for that status.', $id, $order->get_status() ), [ 'order_id' => $id, 'status' => (string) $order->get_status() ], true );
        continue;
      }
      $diff = self::order_difference( $order, $store->order_sums( $id ), $target );
      if ( $diff ) {
        $out[] = self::entry( 'order_target', sprintf( 'Order %d (%s) is not at its %s target: %s.', $id, $order->get_status(), $target ? 'committed' : 'released', implode( ', ', $diff ) ), [ 'order_id' => $id, 'status' => (string) $order->get_status(), 'committed' => $target ] );
      }
    }
    return $out;
  }

  /** True (committed), false (released) or null (no target for the status). */
  private static function target( string $status, array $committed, string $checkout_state ): ?bool {
    if ( in_array( $status, $committed, true ) || 'refunded' === $status ) {
      return true;
    }
    if ( 'pending' === $status ) {
      return 'committed' === $checkout_state;
    }
    if ( in_array( $status, Xophz_Compass_Bazaar_Stock_Order_Lifecycle::RELEASED_STATUSES, true ) ) {
      return false;
    }
    return null;
  }

  /** @return string[] One "item N: change Q at location L" per needed movement; empty when at target. */
  private static function order_difference( WC_Order $order, array $sums, bool $committed ): array {
    $port  = Xophz_Compass_Bazaar_Stock_Factory::port();
    $lines = Xophz_Compass_Bazaar_Stock_Order_Lifecycle::order_lines( $order );
    $out   = [];
    foreach ( Xophz_Compass_Bazaar_Stock_Order_Math::deltas( $lines, $sums, $committed ) as $delta ) {
      $info = $port->product_info( (int) $delta['stock_product_id'] );
      if ( $info && ! empty( $info['manages_stock'] ) ) {
        $out[] = sprintf( 'item %d needs %s at location %d', $delta['order_item_id'], self::num( $delta['qty_delta'] ), $delta['location_id'] );
      }
    }
    return $out;
  }

  /** Notice: cancelled orders that still have counted capture rows. */
  private static function cancelled_with_payments(): array {
    $statuses = class_exists( 'Xophz_Compass_Bazaar_Payments_Repository' ) ? Xophz_Compass_Bazaar_Payments_Repository::COUNTED_STATUSES : [ 'captured', 'pending', 'authorized' ];
    $in       = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
    $out      = [];
    $rows     = Xophz_Compass_Bazaar_Core_Db::get_results( 'SELECT order_id, SUM(amount_minor) AS amount, COUNT(*) AS n FROM ' . self::t( 'payments' ) . " WHERE direction = 'capture' AND order_id > 0 AND status IN ($in) GROUP BY order_id ORDER BY order_id", $statuses );
    foreach ( $rows as $row ) {
      $order = function_exists( 'wc_get_order' ) ? wc_get_order( (int) $row->order_id ) : null;
      if ( $order instanceof WC_Order && 'cancelled' === $order->get_status() ) {
        $out[] = self::entry( 'cancelled_with_payments', sprintf( 'Order %d is cancelled and has %d capture rows (%d minor units).', $row->order_id, $row->n, $row->amount ), [ 'order_id' => (int) $row->order_id, 'amount_minor' => (int) $row->amount ], true );
      }
    }
    return $out;
  }

  private static function entry( string $type, string $message, array $data, bool $notice = false ): array {
    return [ 'type' => $type, 'message' => $message ] + $data + ( $notice ? [ 'notice' => true ] : [] );
  }

  private static function t( string $name ): string {
    return Xophz_Compass_Bazaar_Core_Db::table( $name );
  }

  private static function num( $value ): string {
    return Xophz_Compass_Bazaar_Stock_Keys::number( (float) $value );
  }
}
