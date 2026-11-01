<?php
/**
 * Fixtures and in-process checks for stock-ledger.sh, run with `wp eval-file`
 * inside the container. Each WordPress boot is slow in the Docker stack, so
 * the sequential checks run many REST requests (rest_do_request, as the first
 * administrator unless noted) in one process and print `name=value` lines
 * that the shell script asserts on.
 *
 * Env: FIX_ACTION and the action's inputs.
 * - setup RUN: saves the two mode options, creates five private products
 *   that manage stock (P1 7, P2 5, P3 5, P4 4 with two warehouses W1 4
 *   enabled and W2 3 disabled, P5 6). Prints one JSON line with the ids,
 *   the administrator id and the saved options.
 * - seq_ledger P1..P4, W1, W2, RUN: modes, lazy init, adjustments, movements
 *   paging and the multi-inventory projection; leaves the mode at ledger
 *   and initializes P2 and P3 for the parallel checks.
 * - seq_drift P1..P5, W1, ADMIN, RUN: the parallel results, then drift
 *   refusal and shadow mode.
 * - cleanup PIDS, WIDS, SAVED: deletes the ledger and outbox rows of those
 *   products' batches and the products' own outbox rows (drift events),
 *   trashes the posts, puts the mode options back and prints
 *   cleanup.rows_left (0 when none of those rows is left).
 *
 * Only touches the ids it is given. Ledger rows of other products are left as
 * they are. Nonce checks are not exercised (rest_do_request skips them).
 */
global $wpdb;

$env = function ( string $name ): string {
  return (string) getenv( $name );
};
$tbl = function ( string $name ): string {
  return Xophz_Compass_Bazaar_Core_Db::table( $name );
};
$num = function ( $value ): string {
  $text = rtrim( rtrim( sprintf( '%.4F', (float) $value ), '0' ), '.' );
  return '-0' === $text ? '0' : $text;
};
$say = function ( string $key, $value ) use ( $num ) {
  if ( is_bool( $value ) ) {
    $value = $value ? 'true' : 'false';
  } elseif ( is_int( $value ) || is_float( $value ) ) {
    $value = $num( $value );
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
/** One REST request in this process; a query string in $route becomes query params. */
$rest = function ( string $method, string $route, $body = null, ?int $user = null ) use ( $admin ): array {
  wp_set_current_user( null === $user ? $admin() : $user );
  $path    = (string) wp_parse_url( $route, PHP_URL_PATH );
  $request = new WP_REST_Request( $method, $path );
  parse_str( (string) wp_parse_url( $route, PHP_URL_QUERY ), $query );
  $request->set_query_params( $query );
  if ( null !== $body ) {
    $request->set_header( 'Content-Type', 'application/json' );
    $request->set_body( is_string( $body ) ? $body : wp_json_encode( $body ) );
  }
  $response = rest_do_request( $request );
  $data     = rest_get_server()->response_to_data( $response, false );
  return [ 'status' => $response->get_status(), 'data' => json_decode( wp_json_encode( $data ), true ) ];
};
/** Fresh ledger and WooCommerce state of one product. */
$state = function ( int $pid ) use ( $wpdb, $tbl ): array {
  wp_cache_delete( $pid, 'post_meta' );
  $bal = [];
  $en  = [];
  foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT location_id, on_hand, is_enabled FROM ' . $tbl( 'stock_balances' ) . ' WHERE stock_product_id = %d ORDER BY location_id', $pid ) ) as $row ) {
    $bal[ (int) $row->location_id ] = (float) $row->on_hand;
    $en[ (int) $row->location_id ]  = (bool) (int) $row->is_enabled;
  }
  $multi = [];
  foreach ( (array) get_post_meta( $pid, '_compass_multi_inventory', true ) as $loc => $entry ) {
    if ( is_array( $entry ) ) {
      $multi[ (int) $loc ] = $entry['stock_quantity'] ?? null;
    }
  }
  return [
    'wc'      => (float) get_post_meta( $pid, '_stock', true ),
    'bal'     => $bal,
    'en'      => $en,
    'batches' => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(DISTINCT batch_id) FROM ' . $tbl( 'stock_movements' ) . ' WHERE stock_product_id = %d', $pid ) ),
    'multi'   => $multi,
  ];
};
$product = function ( int $stock, string $name, array $multi = [] ): int {
  $p = new WC_Product_Simple();
  $p->set_name( $name );
  $p->set_status( 'private' );
  $p->set_regular_price( '3.00' );
  $p->set_manage_stock( true );
  $p->set_stock_quantity( $stock );
  $p->save();
  if ( $multi ) {
    $meta = [];
    foreach ( $multi as $loc => $entry ) {
      $meta[ $loc ] = [ 'location_id' => $loc, 'stock_quantity' => $entry[0], 'low_stock_threshold' => 5, 'sku' => '', 'price' => null, 'is_enabled' => $entry[1] ];
    }
    update_post_meta( $p->get_id(), '_compass_multi_inventory', $meta );
  }
  return $p->get_id();
};
$adjust = function ( array $body ) use ( $rest ): array {
  return $rest( 'POST', '/bazaar/v1/stock/adjustments', $body );
};

$p   = [];
foreach ( [ 'P1', 'P2', 'P3', 'P4', 'P5', 'W1', 'W2' ] as $name ) {
  $p[ $name ] = (int) $env( $name );
}
$run = $env( 'RUN' );

switch ( $env( 'FIX_ACTION' ) ) {
  case 'setup':
    $saved = [ 'mode' => get_option( 'bazaar_ledger_mode', '__absent__' ), 'history' => get_option( 'bazaar_ledger_mode_history', '__absent__' ) ];
    $w     = [];
    foreach ( [ 'W1', 'W2' ] as $name ) {
      $w[ $name ] = (int) wp_insert_post( [ 'post_type' => 'compass_warehouse', 'post_status' => 'private', 'post_title' => "Ledger $name $run" ] );
    }
    echo wp_json_encode(
      [
        'P1'    => $product( 7, "Stock ledger lazy $run" ),
        'P2'    => $product( 5, "Stock ledger parallel $run" ),
        'P3'    => $product( 5, "Stock ledger same key $run" ),
        'P4'    => $product( 4, "Stock ledger multi $run", [ $w['W1'] => [ 4, true ], $w['W2'] => [ 3, false ] ] ),
        'P5'    => $product( 6, "Stock ledger drift $run" ),
        'W1'    => $w['W1'],
        'W2'    => $w['W2'],
        'ADMIN' => $admin(),
        'saved' => $saved,
      ]
    ) . "\n";
    break;

  case 'seq_ledger':
    $r = $rest( 'POST', '/bazaar/v1/stock/mode', [ 'mode' => 'shadow' ] );
    $say( 'mode.shadow.status', $r['status'] );
    $r = $rest( 'POST', '/bazaar/v1/stock/mode', [ 'mode' => 'ledger' ] );
    $say( 'mode.ledger.status', $r['status'] );
    if ( 200 !== $r['status'] ) {
      // Name what the site-wide audit found, so a refusal is diagnosable from the FAIL line.
      $say( 'mode.ledger.refused_by', array_column( Xophz_Compass_Bazaar_Stock_Audit::run()['violations'], 'message' ) );
    }
    $say( 'mode.ledger.mode', $r['data']['mode'] ?? '' );
    $say( 'mode.ledger.engines_ok', $r['data']['engines_ok'] ?? null );
    $say( 'mode.ledger.violations', $r['data']['violations'] ?? null );
    $r = $rest( 'POST', '/bazaar/v1/stock/mode', [ 'mode' => 'sideways' ] );
    $say( 'mode.unknown.code', $r['data']['code'] ?? '' );
    $r = $rest( 'POST', '/bazaar/v1/stock/adjustments', [], 0 );
    $say( 'auth.logged_out.status', $r['status'] );

    $r = $rest( 'GET', "/bazaar/v1/stock/balances/{$p['P1']}" );
    $s = $state( $p['P1'] );
    $say( 'lazy.status', $r['status'] );
    $say( 'lazy.initialized', $r['data']['initialized'] ?? null );
    $say( 'lazy.balances', $r['data']['balances'] ?? null );
    $say( 'lazy.batches', $s['batches'] );
    $say( 'lazy.wc', $s['wc'] );

    $add = [ 'client_key' => "$run-a", 'product_id' => $p['P1'], 'operation' => 'add', 'quantity' => 3, 'note' => 'integration' ];
    $r   = $adjust( $add );
    $s   = $state( $p['P1'] );
    $say( 'add.status', $r['status'] );
    $say( 'add.replayed', $r['data']['replayed'] ?? null );
    $say( 'add.wc', $s['wc'] );
    $say( 'add.bal0', $s['bal'][0] ?? null );
    $r = $adjust( array_diff_key( $add, [ 'note' => 1 ] ) );
    $s = $state( $p['P1'] );
    $say( 'replay.replayed', $r['data']['replayed'] ?? null );
    $say( 'replay.wc', $s['wc'] );
    $r = $adjust( [ 'quantity' => 4 ] + $add );
    $say( 'reuse.code', $r['data']['code'] ?? '' );
    $r = $adjust( [ 'client_key' => "$run-s", 'product_id' => $p['P1'], 'operation' => 'subtract', 'quantity' => 4 ] );
    $s = $state( $p['P1'] );
    $say( 'subtract.status', $r['status'] );
    $say( 'subtract.wc', $s['wc'] );
    $say( 'subtract.bal0', $s['bal'][0] ?? null );
    $r = $adjust( [ 'client_key' => "$run-c", 'product_id' => $p['P1'], 'operation' => 'count', 'quantity' => 9, 'expected' => 6 ] );
    $s = $state( $p['P1'] );
    $say( 'count.status', $r['status'] );
    $say( 'count.wc', $s['wc'] );
    $say( 'count.bal0', $s['bal'][0] ?? null );
    $r = $adjust( [ 'client_key' => "$run-c2", 'product_id' => $p['P1'], 'operation' => 'count', 'quantity' => 2, 'expected' => 6 ] );
    $say( 'stale.code', $r['data']['code'] ?? '' );
    $say( 'stale.current', $r['data']['data']['current'] ?? null );
    $r = $adjust( [ 'client_key' => "$run-o", 'product_id' => $p['P1'], 'operation' => 'subtract', 'quantity' => 50 ] );
    $say( 'oversell.code', $r['data']['code'] ?? '' );
    $r = $adjust( [ 'client_key' => "$run-f", 'product_id' => $p['P1'], 'operation' => 'add', 'quantity' => 1.5 ] );
    $s = $state( $p['P1'] );
    $say( 'fraction.code', $r['data']['code'] ?? '' );
    $say( 'refused.wc', $s['wc'] );
    $say( 'refused.bal0', $s['bal'][0] ?? null );

    $r = $rest( 'GET', "/bazaar/v1/stock/movements?product={$p['P1']}&limit=2" );
    $say( 'movements.page1.reasons', array_column( $r['data']['items'] ?? [], 'reason' ) );
    $cursor = $r['data']['next_cursor'] ?? null;
    $say( 'movements.page1.has_cursor', null !== $cursor );
    $r = $rest( 'GET', "/bazaar/v1/stock/movements?product={$p['P1']}&cursor=" . (int) $cursor );
    $say( 'movements.page2.reasons', array_column( $r['data']['items'] ?? [], 'reason' ) );
    $say( 'movements.page2.next_cursor', array_key_exists( 'next_cursor', $r['data'] ) ? $r['data']['next_cursor'] : 'missing' );
    $r = $rest( 'GET', '/bazaar/v1/stock/movements?since=not-a-date' );
    $say( 'movements.bad_since.code', $r['data']['code'] ?? '' );

    $r = $rest( 'GET', "/bazaar/v1/stock/balances/{$p['P4']}" );
    $say( 'multi.tracked', $r['data']['location_tracked'] ?? null );
    $say( 'multi.open', $r['data']['balances'] ?? null );
    $r = $adjust( [ 'client_key' => "$run-m1", 'product_id' => $p['P4'], 'location_id' => $p['W2'], 'operation' => 'add', 'quantity' => 2 ] );
    $s = $state( $p['P4'] );
    $say( 'multi.disabled.status', $r['status'] );
    $say( 'multi.disabled.wc', $s['wc'] );
    $say( 'multi.disabled.bal', $s['bal'][ $p['W2'] ] ?? null );
    $say( 'multi.disabled.meta', $s['multi'][ $p['W2'] ] ?? null );
    $r = $adjust( [ 'client_key' => "$run-m2", 'product_id' => $p['P4'], 'location_id' => $p['W1'], 'operation' => 'subtract', 'quantity' => 1 ] );
    $s = $state( $p['P4'] );
    $say( 'multi.enabled.status', $r['status'] );
    $say( 'multi.enabled.wc', $s['wc'] );
    $say( 'multi.enabled.bal', $s['bal'][ $p['W1'] ] ?? null );
    $say( 'multi.enabled.meta', $s['multi'][ $p['W1'] ] ?? null );
    $say( 'multi.disabled.still', $s['en'][ $p['W2'] ] ?? null );

    foreach ( [ 'P2', 'P3' ] as $name ) {
      $r = $rest( 'GET', "/bazaar/v1/stock/balances/{$p[ $name ]}" );
      $say( "init.$name", $r['data']['projectable'] ?? null );
    }
    break;

  case 'seq_drift':
    foreach ( [ 'P2', 'P3' ] as $name ) {
      $s = $state( $p[ $name ] );
      $say( "par.$name.wc", $s['wc'] );
      $say( "par.$name.bal0", $s['bal'][0] ?? null );
      $say( "par.$name.batches", $s['batches'] );
    }
    $key = Xophz_Compass_Bazaar_Stock_Keys::adjust( (int) $env( 'ADMIN' ), "$run-same" );
    $say( 'par.same.key_batches', (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $tbl( 'stock_batches' ) . ' WHERE idem_key = %s', $key ) ) );

    $r = $rest( 'POST', '/bazaar/v1/stock/mode', [ 'mode' => 'shadow' ] );
    $say( 'drift.shadow.previous', $r['data']['previous'] ?? '' );
    $rest( 'GET', "/bazaar/v1/stock/balances/{$p['P5']}" );
    $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->postmeta} SET meta_value = '9' WHERE post_id = %d AND meta_key = '_stock'", $p['P5'] ) );
    wp_cache_delete( $p['P5'], 'post_meta' );
    $r = $rest( 'POST', '/bazaar/v1/stock/mode', [ 'mode' => 'ledger' ] );
    $say( 'drift.refused.status', $r['status'] );
    $say( 'drift.refused.code', $r['data']['code'] ?? '' );
    $say( 'drift.refused.violations', $r['data']['data']['violations'] ?? null );
    $r = $rest( 'POST', '/bazaar/v1/stock/mode', [ 'mode' => 'ledger', 'force_with_drift' => true ] );
    $say( 'drift.no_reason.code', $r['data']['code'] ?? '' );
    $r = $rest( 'POST', '/bazaar/v1/stock/mode', [ 'mode' => 'ledger', 'force_with_drift' => true, 'reason' => 'integration test' ] );
    $say( 'drift.forced.status', $r['status'] );
    $say( 'drift.forced.forced', $r['data']['forced'] ?? null );
    $say( 'drift.forced.current', Xophz_Compass_Bazaar_Stock_Mode::current() );
    $history = Xophz_Compass_Bazaar_Stock_Mode::history();
    $last    = end( $history );
    $say( 'drift.forced.history_reason', $last['reason'] ?? '' );
    $r = $adjust( [ 'client_key' => "$run-ph", 'product_id' => $p['P1'], 'location_id' => 987654321, 'operation' => 'add', 'quantity' => 3 ] );
    $say( 'ledger.phantom.status', $r['status'] );
    $say( 'ledger.phantom.code', $r['data']['code'] ?? '' );
    $say( 'ledger.phantom.rows', (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $tbl( 'stock_balances' ) . ' WHERE stock_product_id = ' . (int) $p['P1'] . ' AND location_id = 987654321' ) );
    $rest( 'POST', '/bazaar/v1/stock/mode', [ 'mode' => 'shadow' ] );

    $r = $adjust( [ 'client_key' => "$run-sh", 'product_id' => $p['P1'], 'operation' => 'add', 'quantity' => 2 ] );
    $s = $state( $p['P1'] );
    $say( 'shadow.status', $r['status'] );
    $say( 'shadow.ledger', $r['data']['ledger'] ?? null );
    $say( 'shadow.wc', $s['wc'] );
    $say( 'shadow.bal0', $s['bal'][0] ?? null );
    $say( 'shadow.batch_reason', (string) $wpdb->get_var( $wpdb->prepare( 'SELECT b.reason FROM ' . $tbl( 'stock_batches' ) . ' b JOIN ' . $tbl( 'stock_movements' ) . ' m ON m.batch_id = b.id WHERE m.stock_product_id = %d ORDER BY b.id DESC LIMIT 1', $p['P1'] ) ) );
    $r = $adjust( [ 'client_key' => "$run-sh2", 'product_id' => $p['P4'], 'location_id' => $p['W1'], 'operation' => 'add', 'quantity' => 1 ] );
    $say( 'shadow.warehouse.code', $r['data']['code'] ?? '' );
    break;

  case 'cleanup':
    $pids      = array_values( array_filter( array_map( 'intval', explode( ',', $env( 'PIDS' ) ) ) ) );
    $batch_ids = [];
    if ( $pids ) {
      $in        = implode( ',', $pids );
      $batch_ids = array_map( 'intval', $wpdb->get_col( 'SELECT DISTINCT batch_id FROM ' . $tbl( 'stock_movements' ) . " WHERE stock_product_id IN ($in)" ) );
      if ( $batch_ids ) {
        $bin = implode( ',', $batch_ids );
        $wpdb->query( 'DELETE FROM ' . $tbl( 'events' ) . " WHERE aggregate_type = 'stock_batch' AND aggregate_id IN ($bin)" );
        $wpdb->query( 'DELETE FROM ' . $tbl( 'stock_movements' ) . " WHERE batch_id IN ($bin)" );
        $wpdb->query( 'DELETE FROM ' . $tbl( 'stock_batches' ) . " WHERE id IN ($bin)" );
      }
      $wpdb->query( 'DELETE FROM ' . $tbl( 'events' ) . " WHERE aggregate_type = 'product' AND aggregate_id IN ($in)" );
      $wpdb->query( 'DELETE FROM ' . $tbl( 'stock_balances' ) . " WHERE stock_product_id IN ($in)" );
    }
    $trashed = 0;
    foreach ( array_merge( $pids, array_filter( array_map( 'intval', explode( ',', $env( 'WIDS' ) ) ) ) ) as $id ) {
      $trashed += wp_trash_post( $id ) ? 1 : 0;
    }
    $saved = json_decode( $env( 'SAVED' ), true );
    if ( is_array( $saved ) && array_key_exists( 'mode', $saved ) ) {
      foreach ( [ 'bazaar_ledger_mode' => $saved['mode'], 'bazaar_ledger_mode_history' => $saved['history'] ?? '__absent__' ] as $name => $value ) {
        if ( '__absent__' === $value ) {
          delete_option( $name );
        } else {
          update_option( $name, $value );
        }
      }
    }
    $say( 'cleanup.batches', count( $batch_ids ) );
    $say( 'cleanup.trashed', $trashed );
    $left = 0;
    if ( $pids ) {
      $left += (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $tbl( 'stock_movements' ) . " WHERE stock_product_id IN ($in)" );
      $left += (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $tbl( 'stock_balances' ) . " WHERE stock_product_id IN ($in)" );
      $left += (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $tbl( 'events' ) . " WHERE aggregate_type = 'product' AND aggregate_id IN ($in)" );
    }
    if ( $batch_ids ) {
      $left += (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $tbl( 'stock_batches' ) . " WHERE id IN ($bin)" );
      $left += (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $tbl( 'events' ) . " WHERE aggregate_type = 'stock_batch' AND aggregate_id IN ($bin)" );
    }
    $say( 'cleanup.rows_left', $left );
    $say( 'cleanup.mode', get_option( 'bazaar_ledger_mode', '__absent__' ) );
    break;

  default:
    $say( 'error', 'unknown FIX_ACTION' );
}
