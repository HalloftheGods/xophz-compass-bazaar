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
 * - seq_locations (shadow mode, findings 14 and 15 of #6983): a product L
 *   tracked at two private warehouses WA 5 and WB 5. A legacy location
 *   count (WA 8) and a disable (WB) are recorded per location; a web order
 *   sold from WA is booked at WA; then, in ledger mode, a ledger sale at WA
 *   projects the counted value minus one into the meta, and a web order
 *   from WA is again booked at WA. Prints L, WA and WB as loc.L, loc.WA,
 *   loc.WB, and leaves the mode at shadow.
 * - seq_ledger: a ledger-owned order (a checkout row plus sync_order) through
 *   completed, cancelled, re-opened and an admin refund with restock; then a
 *   ledger-owned order completed, cancelled and fully refunded (finding 16
 *   of #6983), and a backorders product F oversold to -2 that then stops
 *   managing stock (finding 17). Prints F's id as ledger.F.
 * - cleanup SAVED, EXTRA: trashes the orders tagged with _bazaar_test_run =
 *   RUN, deletes the checkout row, the fixtures' ledger rows and outbox rows
 *   (EXTRA: more product ids, comma separated, created by the sequences),
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

    $web3 = $tagged_order( [ [ $ids['A'], 2 ] ], $run );
    $web3->update_status( 'processing' );
    $web3->set_payment_method( 'bazaar_no_such_gateway' );
    $web3->save();
    $item3   = array_key_first( $web3->get_items() );
    $failed  = wc_create_refund( [ 'order_id' => $web3->get_id(), 'amount' => '3.00', 'reason' => "stock observer $run", 'line_items' => [ $item3 => [ 'qty' => 1, 'refund_total' => '3.00', 'refund_tax' => [] ] ], 'restock_items' => true, 'refund_payment' => true ] );
    $say( 'refund.failed_is_error', is_wp_error( $failed ) );
    $say( 'refund.failed_context_depth', Xophz_Compass_Bazaar_Stock_Context::depth() );

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

  case 'seq_locations':
    $wh = [];
    foreach ( [ 'WA', 'WB' ] as $name ) {
      $wh[ $name ] = (int) wp_insert_post( [ 'post_type' => 'compass_warehouse', 'post_status' => 'private', 'post_title' => "Stock observer $name $run" ] );
      $say( "loc.$name", $wh[ $name ] );
    }
    $entry = function ( int $loc, float $qty, bool $on ): array {
      return [ 'location_id' => $loc, 'stock_quantity' => $qty, 'low_stock_threshold' => 5, 'sku' => '', 'price' => null, 'is_enabled' => $on ];
    };
    // Created with the observer held off, so the opening batch sees the meta.
    $lid = Xophz_Compass_Bazaar_Stock_Wc_Port_Wpdb::while_writing( function () use ( $product, $run, $entry, $wh ) {
      $id = $product( 10, "Stock observer locations $run" );
      update_post_meta( $id, '_compass_multi_inventory', [ $wh['WA'] => $entry( $wh['WA'], 5, true ), $wh['WB'] => $entry( $wh['WB'], 5, true ) ] );
      return $id;
    } );
    $say( 'loc.L', $lid );
    Xophz_Compass_Bazaar_Stock_Factory::service()->ensure_initialized( $lid );
    $loc_state = function ( string $prefix ) use ( $lid, $wh, $wpdb, $tbl, $say ) {
      wp_cache_delete( $lid, 'post_meta' );
      $meta = (array) get_post_meta( $lid, '_compass_multi_inventory', true );
      $say( "$prefix.wc", (float) get_post_meta( $lid, '_stock', true ) );
      foreach ( [ 0 => 'L0', $wh['WA'] => 'WA', $wh['WB'] => 'WB' ] as $loc => $name ) {
        $row = $wpdb->get_row( $wpdb->prepare( 'SELECT on_hand, is_enabled FROM ' . $tbl( 'stock_balances' ) . ' WHERE stock_product_id = %d AND location_id = %d', $lid, $loc ) );
        $say( "$prefix.$name", $row ? Xophz_Compass_Bazaar_Stock_Keys::number( (float) $row->on_hand ) . ( (int) $row->is_enabled ? '' : ' off' ) : 'none' );
        if ( $loc > 0 ) {
          $say( "$prefix.meta.$name", $meta[ $loc ]['stock_quantity'] ?? null );
        }
      }
    };
    $loc_state( 'loc.open' );
    // Finding 14: shadow-mode location writes are recorded per location
    Xophz_Compass_Bazaar_Admin_Product_Stock::set_location_stock( $lid, $wh['WA'], 8, "$run-la" );
    $loc_state( 'loc.count' );
    Xophz_Compass_Bazaar_Admin_Product_Stock::set_location_stock( $lid, $wh['WB'], 5, "$run-lb", [ 'is_enabled' => false ] );
    $loc_state( 'loc.disable' );
    // Finding 15: an observed web order is booked where it was sold
    $sold = $tagged_order( [ [ $lid, 1 ] ], $run );
    $sold->update_meta_data( '_bazaar_location_id', $wh['WA'] );
    $sold->save();
    wc_get_order( $sold->get_id() )->update_status( 'processing' );
    $loc_state( 'loc.web' );
    $mine = function () use ( $lid ): array {
      $report = Xophz_Compass_Bazaar_Stock_Audit::run();
      $pick   = function ( array $entries ) use ( $lid ): array {
        return array_values( array_unique( array_column( array_filter( $entries, function ( $v ) use ( $lid ) {
          return (int) ( $v['stock_product_id'] ?? 0 ) === $lid;
        } ), 'type' ) ) );
      };
      return [ $pick( $report['violations'] ), $pick( $report['notices'] ) ];
    };
    list( $violations, $notices ) = $mine();
    $say( 'loc.shadow.violations', $violations );
    $say( 'loc.shadow.notices', $notices );
    // Ledger mode: the projection keeps the counted value; a web order still leaves WA
    try {
      Xophz_Compass_Bazaar_Stock_Mode::set( 'ledger', [ 'reason' => "stock-observer.sh $run" ] );
    } catch ( Xophz_Compass_Bazaar_Core_Http_Error $e ) {
      Xophz_Compass_Bazaar_Stock_Mode::set( 'ledger', [ 'force_with_drift' => true, 'reason' => "stock-observer.sh $run: " . $e->getMessage() ] );
    }
    Xophz_Compass_Bazaar_Stock_Factory::service()->adjust( Xophz_Compass_Bazaar_Stock_Keys::adjust( (int) $env( 'ADMIN' ), "$run-lsale" ), $lid, $wh['WA'], -1, 'sale', new Xophz_Compass_Bazaar_Stock_Meta( [ 'actor_user_id' => (int) $env( 'ADMIN' ) ] ) );
    $loc_state( 'loc.ledger_sale' );
    $web = $tagged_order( [ [ $lid, 1 ] ], $run );
    $web->update_meta_data( '_bazaar_location_id', $wh['WA'] );
    $web->save();
    wc_get_order( $web->get_id() )->update_status( 'processing' );
    $loc_state( 'loc.ledger_web' );
    $say( 'loc.ledger_web.available_WA', Xophz_Compass_Bazaar_Stock_Factory::service()->available( $lid, $wh['WA'] ) );
    list( $violations, $notices ) = $mine();
    $say( 'loc.ledger.violations', $violations );
    update_option( 'bazaar_ledger_mode', 'shadow' );
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

    // Finding 16: completed, cancelled (stock returned), then fully refunded
    $e = $tagged_order( [ [ $d, 1 ] ], $run );
    $e->update_meta_data( '_bazaar_stock_owner', 'ledger' );
    $e->update_meta_data( '_bazaar_location_id', 0 );
    $e->save();
    $eid = $e->get_id();
    wc_get_order( $eid )->update_status( 'completed' );
    wc_get_order( $eid )->update_status( 'cancelled' );
    $e     = wc_get_order( $eid );
    $eitem = array_key_first( $e->get_items() );
    $total = (string) $e->get_total();
    $er    = wc_create_refund( [ 'order_id' => $eid, 'amount' => $total, 'reason' => "stock observer $run", 'line_items' => [ $eitem => [ 'qty' => 1, 'refund_total' => $total, 'refund_tax' => [] ] ], 'restock_items' => true, 'refund_payment' => false ] );
    $say( 'cancel_refund.created', ! is_wp_error( $er ) );
    $say( 'cancel_refund.status', wc_get_order( $eid )->get_status() );
    $say( 'cancel_refund.net_outflow', -(float) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(qty_delta), 0) FROM ' . $tbl( 'stock_movements' ) . ' WHERE order_id = %d', $eid ) ) );
    $audit = $fixture_audit( [], [ $eid ] );
    $say( 'cancel_refund.audit.violations', count( $audit['mine'] ) );
    $say( 'cancel_refund.audit.detail', $audit['mine'] );

    // Finding 17: a backorder notice stays a notice once the product stops managing stock
    $f = new WC_Product_Simple();
    $f->set_name( "Stock observer backorder $run" );
    $f->set_status( 'private' );
    $f->set_regular_price( '3.00' );
    $f->set_manage_stock( true );
    $f->set_backorders( 'yes' );
    $f->set_stock_quantity( 0 );
    $f->save();
    $fid = $f->get_id();
    $say( 'ledger.F', $fid );
    Xophz_Compass_Bazaar_Stock_Factory::service()->adjust( Xophz_Compass_Bazaar_Stock_Keys::adjust( $admin, "$run-neg" ), $fid, 0, -2, 'adjust', new Xophz_Compass_Bazaar_Stock_Meta( [ 'actor_user_id' => $admin ] ) );
    $types = function ( array $entries ) use ( $fid ): array {
      return array_values( array_unique( array_column( array_filter( $entries, function ( $v ) use ( $fid ) {
        return (int) ( $v['stock_product_id'] ?? 0 ) === $fid;
      } ), 'type' ) ) );
    };
    $all = Xophz_Compass_Bazaar_Stock_Audit::run();
    $say( 'backorder.balance', $state( $fid )['bal'] );
    $say( 'backorder.violations', $types( $all['violations'] ) );
    $say( 'backorder.notices', $types( $all['notices'] ) );
    $f = wc_get_product( $fid );
    $f->set_manage_stock( false );
    $f->save();
    $all = Xophz_Compass_Bazaar_Stock_Audit::run();
    $say( 'unmanaged.violations', $types( $all['violations'] ) );
    $say( 'unmanaged.notices', $types( $all['notices'] ) );
    break;

  case 'cleanup':
    $extra = array_values( array_filter( array_map( 'intval', explode( ',', $env( 'EXTRA' ) ) ) ) );
    $pids  = array_merge( $pids, $extra );
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
    foreach ( array_filter( array_merge( [ $ids['V'], $ids['VP'], $ids['A'], $ids['B'], $ids['C'], $ids['D'] ], $extra ) ) as $id ) {
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
