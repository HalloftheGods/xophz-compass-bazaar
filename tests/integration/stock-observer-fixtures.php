<?php
/**
 * Fixtures and in-process checks for stock-observer.sh, run with `wp eval-file`
 * inside the container. Prints `name=value` lines that the shell script
 * asserts on.
 *
 * Env: FIX_ACTION, RUN, and the ids the setup printed (A, B, C, D, V, VP, ADMIN).
 * - setup: saves the mode, mode history and backfill cursor options, sets
 *   the mode to off, creates private products that manage stock (A 10, B 8,
 *   C 6, D 9 at 5.00, and a variation V 4 of the variable product VP) and
 *   prints one JSON line with the ids and the saved options.
 * - seq_backfill: backfill from the first fixture id; every `_stock` row in
 *   the database is compared before and after.
 * - seq_shadow: web orders (sale, restore, refund restock), a card-vault-style
 *   wc_update_product_stock, a WooCommerce admin stock edit, a duplicate
 *   hook, a change inside a Bazaar transaction, and the audit.
 * - seq_ledger: a ledger-owned order (a checkout row plus sync_order) through
 *   completed, cancelled, re-opened and an admin refund with restock.
 * - cleanup SAVED: trashes the orders tagged with _bazaar_test_run = RUN,
 *   deletes the checkout row, the fixtures' ledger rows and outbox rows,
 *   trashes the products and restores the saved options.
 *
 * Only touches the ids it is given and the orders and checkout row tagged with
 * RUN. The mode is site-wide, so other scripts running at the same time see it.
 */
global $wpdb;

$env = function ( string $name ): string {
  return (string) getenv( $name );
};
$tbl = function ( string $name ): string {
  return Xophz_Compass_Bazaar_Core_Db::table( $name );
};
$say = function ( string $key, $value ) {
  if ( is_bool( $value ) ) {
    $value = $value ? 'true' : 'false';
  } elseif ( is_int( $value ) || is_float( $value ) ) {
    $value = Xophz_Compass_Bazaar_Stock_Keys::number( (float) $value );
  } elseif ( null === $value ) {
    $value = 'null';
  } elseif ( ! is_string( $value ) ) {
    $value = wp_json_encode( $value );
  }
  echo $key . '=' . $value . "\n";
};
$admin = function (): int {
  $ids = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID', 'orderby' => 'ID' ] );
  return (int) ( $ids[0] ?? 0 );
};
/** Fresh WooCommerce and ledger state of one stock key. */
$state = function ( int $pid ) use ( $wpdb, $tbl ): array {
  wp_cache_delete( $pid, 'post_meta' );
  $last = $wpdb->get_row( $wpdb->prepare( 'SELECT b.id, b.reason, b.source_type, b.source_id, SUM(m.qty_delta) AS qty FROM ' . $tbl( 'stock_batches' ) . ' b JOIN ' . $tbl( 'stock_movements' ) . ' m ON m.batch_id = b.id WHERE m.stock_product_id = %d GROUP BY b.id ORDER BY b.id DESC LIMIT 1', $pid ) );
  return [
    'wc'      => (float) $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_stock' ORDER BY meta_id LIMIT 1", $pid ) ),
    'bal'     => (float) $wpdb->get_var( $wpdb->prepare( 'SELECT on_hand FROM ' . $tbl( 'stock_balances' ) . ' WHERE stock_product_id = %d AND location_id = 0', $pid ) ),
    'init'    => (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $tbl( 'stock_balances' ) . ' WHERE stock_product_id = %d', $pid ) ),
    'batches' => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(DISTINCT batch_id) FROM ' . $tbl( 'stock_movements' ) . ' WHERE stock_product_id = %d', $pid ) ),
    'reason'  => $last ? (string) $last->reason : '',
    'source'  => $last ? $last->source_type . ':' . $last->source_id : '',
    'qty'     => $last ? (float) $last->qty : 0.0,
  ];
};
$report = function ( string $prefix, int $pid ) use ( $state, $say ) {
  foreach ( $state( $pid ) as $key => $value ) {
    $say( "$prefix.$key", $value );
  }
};
$product = function ( int $stock, string $name, string $price = '3.00' ): int {
  $p = new WC_Product_Simple();
  $p->set_name( $name );
  $p->set_status( 'private' );
  $p->set_regular_price( $price );
  $p->set_manage_stock( true );
  $p->set_stock_quantity( $stock );
  $p->save();
  return $p->get_id();
};
$tagged_order = function ( array $lines, string $run ): WC_Order {
  $order = wc_create_order( [ 'status' => 'pending' ] );
  foreach ( $lines as $line ) {
    $order->add_product( wc_get_product( $line[0] ), $line[1] );
  }
  $order->update_meta_data( '_bazaar_test_run', $run );
  $order->calculate_totals();
  $order->save();
  return $order;
};
/** Audit entries that name a fixture product or order. */
$fixture_audit = function ( array $pids, array $orders ): array {
  $report = Xophz_Compass_Bazaar_Stock_Audit::run();
  $mine   = array_values( array_filter( $report['violations'], function ( $v ) use ( $pids, $orders ) {
    return in_array( (int) ( $v['stock_product_id'] ?? 0 ), $pids, true ) || in_array( (int) ( $v['order_id'] ?? 0 ), $orders, true );
  } ) );
  return [ 'mine' => $mine, 'all' => $report ];
};
$reduced = function ( int $order_id ): int {
  $n = 0;
  foreach ( wc_get_order( $order_id )->get_items() as $item ) {
    $n += '' !== (string) $item->get_meta( '_reduced_stock', true ) ? 1 : 0;
  }
  return $n;
};

$run  = $env( 'RUN' );
$ids  = [];
foreach ( [ 'A', 'B', 'C', 'D', 'V', 'VP' ] as $name ) {
  $ids[ $name ] = (int) $env( $name );
}
$pids = array_values( array_filter( [ $ids['A'], $ids['B'], $ids['C'], $ids['D'], $ids['V'] ] ) );

switch ( $env( 'FIX_ACTION' ) ) {
  case 'setup':
    $saved = [];
    foreach ( [ 'bazaar_ledger_mode', 'bazaar_ledger_mode_history', Xophz_Compass_Bazaar_Stock_Backfill::OPTION ] as $name ) {
      $saved[ $name ] = get_option( $name, '__absent__' );
    }
    update_option( 'bazaar_ledger_mode', 'off' );
    $out = [
      'A' => $product( 10, "Stock observer web $run" ),
      'B' => $product( 8, "Stock observer vault $run" ),
      'C' => $product( 6, "Stock observer admin $run" ),
      'D' => $product( 9, "Stock observer ledger $run", '5.00' ),
    ];
    $parent = new WC_Product_Variable();
    $parent->set_name( "Stock observer variable $run" );
    $parent->set_status( 'private' );
    $parent->save();
    $variation = new WC_Product_Variation();
    $variation->set_parent_id( $parent->get_id() );
    $variation->set_regular_price( '4.00' );
    $variation->set_manage_stock( true );
    $variation->set_stock_quantity( 4 );
    $variation->save();
    $out += [ 'V' => $variation->get_id(), 'VP' => $parent->get_id(), 'ADMIN' => $admin(), 'saved' => $saved, 'mode' => Xophz_Compass_Bazaar_Stock_Mode::current() ];
    echo wp_json_encode( $out ) . "\n";
    break;

  case 'seq_backfill':
    $snapshot = function () use ( $wpdb ): array {
      $rows = [];
      foreach ( $wpdb->get_results( "SELECT meta_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_stock'" ) as $row ) {
        $rows[ (int) $row->meta_id ] = (string) $row->meta_value;
      }
      return $rows;
    };
    $before = $snapshot();
    $say( 'backfill.mode_before', Xophz_Compass_Bazaar_Stock_Mode::current() );
    $after  = min( $pids ) - 1;
    $errors = 0;
    $init   = 0;
    for ( $i = 0; $i < 50; $i++ ) {
      $r       = Xophz_Compass_Bazaar_Stock_Backfill::run_batch( $after, 200 );
      $errors += count( $r['errors'] );
      $init   += $r['initialized'];
      if ( $r['done'] ) {
        break;
      }
      $after = $r['last_id'];
    }
    $now     = $snapshot();
    $changed = count( array_diff_assoc( $before, $now ) ) + count( array_diff_key( $now, $before ) );
    $say( 'backfill.done', $r['done'] );
    $say( 'backfill.errors', $errors );
    $say( 'backfill.stock_rows_changed', $changed );
    $matching = 0;
    foreach ( $pids as $pid ) {
      $s         = $state( $pid );
      $matching += $s['init'] && abs( $s['bal'] - $s['wc'] ) < 0.00005 && 'opening' === $s['reason'] ? 1 : 0;
    }
    $say( 'backfill.fixtures_opened_at_wc', $matching );
    $say( 'backfill.parent_skipped', ! $state( $ids['VP'] )['init'] );
    $say( 'backfill.mode_after', Xophz_Compass_Bazaar_Stock_Mode::current() );
    $say( 'backfill.cursor_done', Xophz_Compass_Bazaar_Stock_Backfill::cursor()['done'] );
    $batches = array_sum( array_map( function ( $pid ) use ( $state ) {
      return $state( $pid )['batches'];
    }, $pids ) );
    $r2 = Xophz_Compass_Bazaar_Stock_Backfill::run_batch( min( $pids ) - 1, 200 );
    $say( 'backfill.rerun.initialized', $r2['initialized'] );
    $say( 'backfill.rerun.batches_unchanged', array_sum( array_map( function ( $pid ) use ( $state ) {
      return $state( $pid )['batches'];
    }, $pids ) ) === $batches );
    $say( 'backfill.rerun.stock_rows_changed', count( array_diff_assoc( $now, $snapshot() ) ) );
    break;

  case 'seq_shadow':
    if ( 'shadow' !== Xophz_Compass_Bazaar_Stock_Mode::current() ) {
      update_option( 'bazaar_ledger_mode', 'shadow' );
    }
    $say( 'shadow.mode', Xophz_Compass_Bazaar_Stock_Mode::current() );

    $web = $tagged_order( [ [ $ids['A'], 2 ], [ $ids['V'], 1 ] ], $run );
    $web->update_status( 'processing' );
    $report( 'web.A', $ids['A'] );
    $report( 'web.V', $ids['V'] );
    $say( 'web.order', $web->get_id() );
    $say( 'web.wc_reduced_items', $reduced( $web->get_id() ) );
    $say( 'web.context_depth', Xophz_Compass_Bazaar_Stock_Context::depth() );
    $web = wc_get_order( $web->get_id() );
    $web->update_status( 'cancelled' );
    $report( 'restore.A', $ids['A'] );
    $say( 'restore.context_depth', Xophz_Compass_Bazaar_Stock_Context::depth() );

    $web2 = $tagged_order( [ [ $ids['A'], 2 ] ], $run );
    $web2->update_status( 'processing' );
    $item = array_key_first( $web2->get_items() );
    $refund = wc_create_refund( [ 'order_id' => $web2->get_id(), 'amount' => '3.00', 'reason' => "stock observer $run", 'line_items' => [ $item => [ 'qty' => 1, 'refund_total' => '3.00', 'refund_tax' => [] ] ], 'restock_items' => true, 'refund_payment' => false ] );
    $say( 'refund.created', ! is_wp_error( $refund ) );
    $report( 'refund.A', $ids['A'] );
    $say( 'refund.order', $web2->get_id() );
    $say( 'refund.context_depth', Xophz_Compass_Bazaar_Stock_Context::depth() );

    wc_update_product_stock( wc_get_product( $ids['B'] ), 3, 'decrease' );
    $report( 'vault.B', $ids['B'] );
    $say( 'vault.drift_events', (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $tbl( 'events' ) . " WHERE type = 'stock.drift.detected' AND aggregate_type = 'product' AND aggregate_id = %d", $ids['B'] ) ) );

    $c = wc_get_product( $ids['C'] );
    $c->set_stock_quantity( 4 );
    $c->save();
    $report( 'admin.C', $ids['C'] );
    do_action( 'woocommerce_product_set_stock', wc_get_product( $ids['C'] ) );
    $say( 'duplicate.C.batches', $state( $ids['C'] )['batches'] );
    $say( 'admin.drift_events', (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $tbl( 'events' ) . " WHERE type = 'stock.drift.detected' AND aggregate_id = %d", $ids['C'] ) ) );

    $during = Xophz_Compass_Bazaar_Core_Db::tx( function () use ( $ids, $state ) {
      wc_update_product_stock( wc_get_product( $ids['B'] ), 1, 'increase' );
      return $state( $ids['B'] )['bal'];
    } );
    $say( 'tx.during_bal', $during );
    $report( 'tx.B', $ids['B'] );

    $audit = $fixture_audit( $pids, [ $web->get_id(), $web2->get_id() ] );
    $say( 'shadow.audit.fixture_violations', count( $audit['mine'] ) );
    $say( 'shadow.audit.fixture_detail', $audit['mine'] );
    $say( 'shadow.audit.drift_anywhere', (int) ( $audit['all']['counts']['projection_drift'] ?? 0 ) );
    break;

  case 'seq_ledger':
    try {
      $m = Xophz_Compass_Bazaar_Stock_Mode::set( 'ledger', [ 'reason' => "stock-observer.sh $run" ] );
    } catch ( Xophz_Compass_Bazaar_Core_Http_Error $e ) {
      $m = Xophz_Compass_Bazaar_Stock_Mode::set( 'ledger', [ 'force_with_drift' => true, 'reason' => "stock-observer.sh $run: " . $e->getMessage() ] );
    }
    $say( 'ledger.mode', Xophz_Compass_Bazaar_Stock_Mode::current() );
    $say( 'ledger.mode_forced', $m['forced'] );
    $d     = $ids['D'];
    $now   = Xophz_Compass_Bazaar_Core_Db::now();
    $admin = (int) $env( 'ADMIN' );
    $cid   = Xophz_Compass_Bazaar_Core_Db::insert( $tbl( 'checkouts' ), [ 'client_key' => "$run-co", 'created_by' => $admin, 'request_hash' => str_repeat( '0', 64 ), 'channel' => 'pos', 'state' => 'committed', 'operator_user_id' => $admin, 'cashier_user_id' => $admin, 'location_id' => 0, 'stock_owner' => 'ledger', 'currency' => get_woocommerce_currency(), 'created_at' => $now, 'updated_at' => $now ] );
    $order = $tagged_order( [ [ $d, 3 ] ], $run );
    $order->set_created_via( 'bazaar_pos' );
    $order->update_meta_data( '_bazaar_checkout_id', $cid );
    $order->update_meta_data( '_bazaar_stock_owner', 'ledger' );
    $order->update_meta_data( '_bazaar_location_id', 0 );
    $order->save();
    $oid = $order->get_id();
    Xophz_Compass_Bazaar_Core_Db::exec( 'UPDATE ' . $tbl( 'checkouts' ) . ' SET order_id = %d WHERE id = %d', [ $oid, $cid ] );
    $say( 'ledger.order', $oid );
    $say( 'ledger.owned', Xophz_Compass_Bazaar_Stock_Order_Lifecycle::is_ledger_owned( $oid ) );
    Xophz_Compass_Bazaar_Core_Db::tx( function () use ( $order ) {
      return Xophz_Compass_Bazaar_Stock_Order_Lifecycle::sync_order( $order, true );
    } );
    $report( 'sold.D', $d );

    $order = wc_get_order( $oid );
    $order->update_status( 'completed' );
    wc_reduce_stock_levels( $oid );
    wc_maybe_reduce_stock_levels( $oid );
    $report( 'completed.D', $d );
    $say( 'filter.payment_complete', apply_filters( 'woocommerce_payment_complete_reduce_order_stock', true, $oid ) );
    $say( 'filter.can_restock_refunded', apply_filters( 'woocommerce_can_restock_refunded_items', true, wc_get_order( $oid ), [] ) );

    $order = wc_get_order( $oid );
    $order->update_status( 'cancelled' );
    $report( 'cancelled.D', $d );
    do_action( 'woocommerce_order_status_changed', $oid, 'completed', 'cancelled', wc_get_order( $oid ) );
    wc_increase_stock_levels( $oid );
    $report( 'cancelled_again.D', $d );

    $order = wc_get_order( $oid );
    $order->update_status( 'processing' );
    $report( 'reopened.D', $d );

    $order  = wc_get_order( $oid );
    $item   = array_key_first( $order->get_items() );
    $args   = [ 'order_id' => $oid, 'amount' => '5.00', 'reason' => "stock observer $run", 'line_items' => [ $item => [ 'qty' => 1, 'refund_total' => '5.00', 'refund_tax' => [] ] ], 'restock_items' => true, 'refund_payment' => false ];
    $refund = wc_create_refund( $args );
    $say( 'ledger_refund.created', ! is_wp_error( $refund ) );
    $report( 'refunded.D', $d );
    if ( ! is_wp_error( $refund ) ) {
      do_action( 'woocommerce_refund_created', $refund->get_id(), $args );
    }
    $report( 'refunded_again.D', $d );
    $say( 'ledger.wc_reduced_items', $reduced( $oid ) );
    $reasons = $wpdb->get_col( $wpdb->prepare( 'SELECT b.reason FROM ' . $tbl( 'stock_batches' ) . ' b JOIN ' . $tbl( 'stock_movements' ) . ' m ON m.batch_id = b.id WHERE m.stock_product_id = %d GROUP BY b.id ORDER BY b.id', $d ) );
    $say( 'ledger.D.reasons', $reasons );
    $audit = $fixture_audit( $pids, [ $oid ] );
    $say( 'ledger.audit.fixture_violations', count( $audit['mine'] ) );
    $say( 'ledger.audit.fixture_detail', $audit['mine'] );
    break;

  case 'cleanup':
    $orders = wc_get_orders( [ 'limit' => -1, 'type' => 'shop_order', 'status' => array_keys( wc_get_order_statuses() ), 'meta_query' => [ [ 'key' => '_bazaar_test_run', 'value' => $run ] ] ] );
    $trashed_orders = 0;
    foreach ( $orders as $order ) {
      $trashed_orders += $order->delete( false ) ? 1 : 0;
    }
    $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . $tbl( 'checkouts' ) . ' WHERE client_key = %s', "$run-co" ) );
    $batch_ids = [];
    if ( $pids ) {
      $in        = implode( ',', array_map( 'intval', $pids ) );
      $batch_ids = array_map( 'intval', $wpdb->get_col( 'SELECT DISTINCT batch_id FROM ' . $tbl( 'stock_movements' ) . " WHERE stock_product_id IN ($in)" ) );
      if ( $batch_ids ) {
        $bin = implode( ',', $batch_ids );
        $wpdb->query( 'DELETE FROM ' . $tbl( 'events' ) . " WHERE aggregate_type = 'stock_batch' AND aggregate_id IN ($bin)" );
        $wpdb->query( 'DELETE FROM ' . $tbl( 'stock_movements' ) . " WHERE batch_id IN ($bin)" );
        $wpdb->query( 'DELETE FROM ' . $tbl( 'stock_batches' ) . " WHERE id IN ($bin)" );
      }
      $wpdb->query( 'DELETE FROM ' . $tbl( 'events' ) . " WHERE type = 'stock.drift.detected' AND aggregate_type = 'product' AND aggregate_id IN ($in)" );
      $wpdb->query( 'DELETE FROM ' . $tbl( 'stock_balances' ) . " WHERE stock_product_id IN ($in)" );
    }
    $trashed = 0;
    foreach ( array_filter( [ $ids['V'], $ids['VP'], $ids['A'], $ids['B'], $ids['C'], $ids['D'] ] ) as $id ) {
      $trashed += wp_trash_post( $id ) ? 1 : 0;
    }
    $saved = json_decode( $env( 'SAVED' ), true );
    if ( is_array( $saved ) ) {
      foreach ( $saved as $name => $value ) {
        if ( '__absent__' === $value ) {
          delete_option( $name );
        } else {
          update_option( $name, $value );
        }
      }
    }
    $say( 'cleanup.orders', $trashed_orders );
    $say( 'cleanup.batches', count( $batch_ids ) );
    $say( 'cleanup.trashed', $trashed );
    $say( 'cleanup.mode', get_option( 'bazaar_ledger_mode', '__absent__' ) );
    break;

  default:
    $say( 'error', 'unknown FIX_ACTION' );
}
