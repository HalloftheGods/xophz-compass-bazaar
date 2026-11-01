<?php
/**
 * Fixtures and checks for refunds-v2.sh. Not a plugin file: it is copied into
 * the WordPress container and run with wp-cli.
 *
 * Two roles:
 * 1. Loaded with --require (before WordPress): taxes are switched on for this
 *    process only (pre_option hooks). Nothing is written to the options table.
 * 2. Run with eval-file, BZ_ACTION = setup | run | once | parallel_state |
 *    legacy_state | legacy_rest | teardown.
 *    - setup:    tax class bazaar-rf-test with two rates, three private
 *                products, a bazaar_cashier and a subscriber, and five
 *                completed orders built from fixed line totals and taxes
 *                (multi-rate, single unit, approval, card, legacy with no
 *                payment rows). Prints "STATE <json>".
 *    - run:      the REST checks; prints "CHECK <name> <value>".
 *    - once:     one refund of an order's only unit at BZ_AT (parallel check).
 *    - teardown: deletes the orders (also the ledger-owned one the run adds,
 *                found by its _bazaar_test_run meta), their refund, payment,
 *                checkout, event and ledger rows, then the products, users,
 *                rates and class.
 * The ledger mode is switched on inside the run with an in-process filter
 * only, so other processes keep the store's mode.
 */
if ( ! function_exists( 'wc_get_product' ) ) {
  if ( class_exists( 'WP_CLI' ) && false !== getenv( 'BZ_ACTION' ) ) {
    if ( ! defined( 'WC_TAX_ROUNDING_MODE' ) ) {
      define( 'WC_TAX_ROUNDING_MODE', 1 );
    }
    WP_CLI::add_wp_hook( 'wp_die_handler', function () {
      return function ( $message ) {
        echo 'ERROR wp_die: ' . ( is_scalar( $message ) ? $message : ( is_wp_error( $message ) ? $message->get_error_message() : 'unknown' ) ) . "\n";
        exit( 1 );
      };
    }, 10000 );
    WP_CLI::add_wp_hook( 'pre_option_woocommerce_calc_taxes', function () { return 'yes'; } );
    WP_CLI::add_wp_hook( 'pre_option_woocommerce_prices_include_tax', function () { return 'no'; } );
    // No mail leaves these processes: the refund emails only slow the checks down.
    WP_CLI::add_wp_hook( 'pre_wp_mail', function () { return true; } );
  }
  return;
}
if ( defined( 'BZ_RF_FIXTURES' ) ) {
  return;
}
define( 'BZ_RF_FIXTURES', true );

$bz_state = json_decode( (string) getenv( 'BZ_STATE' ), true ) ?: [];
$bz_admin = (int) ( get_users( [ 'role' => 'administrator', 'number' => 1, 'orderby' => 'ID', 'order' => 'ASC', 'fields' => 'ID' ] )[0] ?? 0 );
wp_set_current_user( $bz_admin );

// Declared only once: --require compiles this file before WordPress loads, and
// PHP hoists unconditional top-level functions even past the early return.
if ( ! function_exists( 'rf_rest' ) ) {
function rf_rest( string $method, string $route, $body = null, ?int $user = null ): array {
  $previous = get_current_user_id();
  if ( null !== $user ) {
    wp_set_current_user( $user );
  }
  $request = new WP_REST_Request( $method, $route );
  if ( null !== $body ) {
    $request->set_header( 'Content-Type', 'application/json' );
    $request->set_body( is_string( $body ) ? $body : wp_json_encode( $body ) );
  }
  $response = rest_do_request( $request );
  $data     = rest_get_server()->response_to_data( $response, false );
  wp_set_current_user( $previous );
  return [ 'status' => $response->get_status(), 'data' => json_decode( wp_json_encode( $data ), true ) ];
}

function rf_say( string $name, $value ): void {
  echo 'CHECK ' . $name . ' ' . ( is_scalar( $value ) ? (string) $value : wp_json_encode( $value ) ) . "\n";
}

function rf_tbl( string $name ): string {
  return Xophz_Compass_Bazaar_Core_Db::table( $name );
}

/** POST a refund; $lines is [[order_item_id, qty], ...]. */
function rf_refund( int $order, array $lines, array $extra = [], ?int $user = null ): array {
  $body = array_merge( [
    'client_key' => wp_generate_uuid4(),
    'lines'      => array_map( function ( $l ) { return [ 'order_item_id' => $l[0], 'qty' => $l[1] ]; }, $lines ),
    'reason'     => 'refunds-v2.sh',
  ], $extra );
  return rf_rest( 'POST', "/bazaar/v1/orders/$order/refunds", $body, $user );
}

function rf_minor( $amount ): int {
  return Xophz_Compass_Bazaar_Core_Money::to_minor( abs( (float) $amount ) );
}

/** An order built from fixed line totals and taxes; $payment adds one capture of the whole total. */
function rf_order( array $lines, ?array $payment, int $admin, array $meta = [] ): WC_Order {
  $order = wc_create_order( [ 'status' => 'pending' ] );
  foreach ( $lines as $l ) {
    $item = new WC_Order_Item_Product();
    $item->set_product( wc_get_product( $l['product'] ) );
    $item->set_quantity( $l['qty'] );
    $item->set_subtotal( $l['total'] );
    $item->set_total( $l['total'] );
    $item->set_taxes( [ 'total' => $l['taxes'], 'subtotal' => $l['taxes'] ] );
    $order->add_item( $item );
  }
  $order->update_meta_data( '_bazaar_test_run', 'refunds-v2' );
  foreach ( $meta as $k => $v ) {
    $order->update_meta_data( $k, $v );
  }
  $order->update_taxes();
  $order->calculate_totals( false );
  $order->set_status( 'completed' );
  $order->save();
  if ( $payment ) {
    Xophz_Compass_Bazaar_Payments_Repository::insert_capture( array_merge( [
      'order_id' => $order->get_id(), 'status' => 'captured', 'verified' => true, 'amount_minor' => rf_minor( $order->get_total() ),
      'currency' => $order->get_currency(), 'operator_user_id' => $admin, 'cashier_user_id' => $admin,
    ], $payment ) );
  }
  return wc_get_order( $order->get_id() );
}

function rf_item( WC_Order $order, int $n = 0 ): int {
  return (int) array_keys( $order->get_items() )[ $n ];
}

function rf_capture( int $order_id ): array {
  $rows = Xophz_Compass_Bazaar_Payments_Repository::for_order( $order_id, 'capture' );
  return $rows ? (array) $rows[0] : [];
}

function rf_wc_refunds( int $order_id ): int {
  return count( wc_get_order( $order_id )->get_refunds() );
}

function rf_rows( int $order_id ): array {
  global $wpdb;
  return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . rf_tbl( 'refunds' ) . ' WHERE order_id = %d ORDER BY id', $order_id ), ARRAY_A );
}

function rf_approval( int $requester, int $approver, string $scope, string $subject, int $amount ): string {
  $token = bin2hex( random_bytes( 16 ) );
  Xophz_Compass_Bazaar_Core_Db::insert( rf_tbl( 'approvals' ), [
    'token_hash' => Xophz_Compass_Bazaar_Staff_Approvals::hash_token( $token ), 'scope' => $scope, 'subject_hash' => $subject,
    'amount_minor' => $amount, 'basis_points' => 0, 'requested_by' => $requester, 'approved_by' => $approver, 'device_id' => '',
    'reason' => 'refunds-v2.sh', 'created_at' => gmdate( 'Y-m-d H:i:s' ), 'expires_at' => gmdate( 'Y-m-d H:i:s', time() + 300 ), 'consumed_ref' => '',
  ] );
  return $token;
}

function rf_consumed( string $token ): string {
  global $wpdb;
  return $wpdb->get_var( $wpdb->prepare( 'SELECT consumed_at FROM ' . rf_tbl( 'approvals' ) . ' WHERE token_hash = %s', Xophz_Compass_Bazaar_Staff_Approvals::hash_token( $token ) ) ) ? 'spent' : 'unspent';
}

function rf_stock( int $pid ): float {
  global $wpdb;
  return (float) $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_stock'", $pid ) );
}

function rf_setup( int $admin ): array {
  $state = [ 'rates' => [], 'products' => [], 'cashier' => 0, 'subscriber' => 0, 'orders' => [] ];
  if ( ! get_role( 'bazaar_cashier' ) ) {
    Xophz_Compass_Bazaar_Security_Capabilities::install_roles();
  }
  if ( ! in_array( 'bazaar-rf-test', WC_Tax::get_tax_class_slugs(), true ) ) {
    WC_Tax::create_tax_class( 'Bazaar Refunds Test', 'bazaar-rf-test' );
  }
  foreach ( [ [ 'BZ RF State', '8.2500', 1 ], [ 'BZ RF County', '1.5000', 2 ] ] as $r ) {
    $state['rates'][] = (int) WC_Tax::_insert_tax_rate( [
      'tax_rate_country' => WC()->countries->get_base_country(), 'tax_rate_state' => WC()->countries->get_base_state(),
      'tax_rate' => $r[1], 'tax_rate_name' => $r[0], 'tax_rate_priority' => $r[2], 'tax_rate_compound' => 0,
      'tax_rate_shipping' => 0, 'tax_rate_order' => 0, 'tax_rate_class' => 'bazaar-rf-test',
    ] );
  }
  $product = function ( string $name, string $price, ?int $stock ) {
    $p = new WC_Product_Simple();
    $p->set_name( $name );
    $p->set_status( 'private' );
    $p->set_regular_price( $price );
    $p->set_manage_stock( null !== $stock );
    if ( null !== $stock ) {
      $p->set_stock_quantity( $stock );
      $p->set_backorders( 'no' );
    }
    return (int) $p->save();
  };
  $state['products']   = [ 'a' => $product( 'Bazaar refunds-v2 A', '10.00', 20 ), 'b' => $product( 'Bazaar refunds-v2 B', '7.99', null ), 'c' => $product( 'Bazaar refunds-v2 C', '12.00', 10 ) ];
  $suffix              = strtolower( wp_generate_password( 6, false ) );
  $state['cashier']    = (int) wp_insert_user( [ 'user_login' => 'bz-rf-cashier-' . $suffix, 'user_pass' => wp_generate_password( 20 ), 'user_email' => "bz-rf-cashier-$suffix@example.invalid", 'role' => 'bazaar_cashier' ] );
  $state['subscriber'] = (int) wp_insert_user( [ 'user_login' => 'bz-rf-sub-' . $suffix, 'user_pass' => wp_generate_password( 20 ), 'user_email' => "bz-rf-sub-$suffix@example.invalid", 'role' => 'subscriber' ] );
  list( $r1, $r2 )     = $state['rates'];
  list( $pa, $pb )     = [ $state['products']['a'], $state['products']['b'] ];
  $cash = [ 'provider' => 'cash', 'method_id' => 'cash', 'kind' => 'cash' ];
  $card = [ 'provider' => 'external_terminal', 'method_id' => 'bazaar_card_terminal', 'kind' => 'external_terminal', 'reference' => 'SLIP-0001', 'card_brand' => 'visa', 'card_last4' => '1881' ];
  $o    = [];
  $o['multi']  = rf_order( [
    [ 'product' => $pa, 'qty' => 3, 'total' => '30.00', 'taxes' => [ $r1 => '2.48', $r2 => '0.45' ] ],
    [ 'product' => $pb, 'qty' => 2, 'total' => '15.98', 'taxes' => [ $r1 => '1.32', $r2 => '0.24' ] ],
  ], $cash, $admin );
  $o['last']   = rf_order( [ [ 'product' => $pb, 'qty' => 1, 'total' => '10.00', 'taxes' => [ $r1 => '0.83' ] ] ], $cash, $admin );
  $o['app']    = rf_order( [ [ 'product' => $pb, 'qty' => 2, 'total' => '40.00', 'taxes' => [ $r1 => '3.30' ] ] ], $cash, $admin );
  $o['card']   = rf_order( [ [ 'product' => $pb, 'qty' => 1, 'total' => '20.00', 'taxes' => [ $r1 => '1.65' ] ] ], $card, $admin );
  $o['legacy'] = rf_order( [ [ 'product' => $pa, 'qty' => 2, 'total' => '25.00', 'taxes' => [ $r1 => '2.06' ] ] ], null, $admin );
  wc_reduce_stock_levels( $o['legacy']->get_id() );
  foreach ( $o as $name => $order ) {
    $state['orders'][ $name ] = $order->get_id();
  }
  return $state;
}

function rf_teardown( array $s ): void {
  global $wpdb;
  $ids = array_map( 'intval', array_merge( array_values( $s['orders'] ?? [] ), (array) wc_get_orders( [
    'limit' => -1, 'return' => 'ids', 'status' => array_keys( wc_get_order_statuses() ),
    'meta_query' => [ [ 'key' => '_bazaar_test_run', 'value' => 'refunds-v2' ] ],
  ] ) ) );
  $ids = array_values( array_unique( $ids ) );
  foreach ( $ids as $id ) {
    $order = wc_get_order( $id );
    if ( $order ) {
      foreach ( $order->get_refunds() as $refund ) {
        $refund->delete( true );
      }
      $order->delete( true );
    }
  }
  if ( $ids ) {
    $in      = implode( ',', $ids );
    $refunds = array_map( 'intval', $wpdb->get_col( 'SELECT id FROM ' . rf_tbl( 'refunds' ) . " WHERE order_id IN ($in)" ) );
    if ( $refunds ) {
      $wpdb->query( 'DELETE FROM ' . rf_tbl( 'events' ) . " WHERE aggregate_type = 'refund' AND aggregate_id IN (" . implode( ',', $refunds ) . ')' );
    }
    $wpdb->query( 'DELETE FROM ' . rf_tbl( 'payments' ) . " WHERE order_id IN ($in)" );
    $wpdb->query( 'DELETE FROM ' . rf_tbl( 'refunds' ) . " WHERE order_id IN ($in)" );
    $batches = array_map( 'intval', $wpdb->get_col( 'SELECT DISTINCT batch_id FROM ' . rf_tbl( 'stock_movements' ) . " WHERE order_id IN ($in)" ) );
    $stocks  = array_map( 'intval', $wpdb->get_col( 'SELECT DISTINCT stock_product_id FROM ' . rf_tbl( 'stock_movements' ) . " WHERE order_id IN ($in)" ) );
    if ( $batches ) {
      $bin = implode( ',', $batches );
      $wpdb->query( 'DELETE FROM ' . rf_tbl( 'events' ) . " WHERE aggregate_type = 'stock_batch' AND aggregate_id IN ($bin)" );
      $wpdb->query( 'DELETE FROM ' . rf_tbl( 'stock_movements' ) . " WHERE batch_id IN ($bin)" );
      $wpdb->query( 'DELETE FROM ' . rf_tbl( 'stock_batches' ) . " WHERE id IN ($bin)" );
    }
    if ( $stocks ) {
      $wpdb->query( 'DELETE FROM ' . rf_tbl( 'stock_balances' ) . ' WHERE stock_product_id IN (' . implode( ',', $stocks ) . ')' );
    }
    $wpdb->query( 'DELETE FROM ' . rf_tbl( 'checkouts' ) . " WHERE order_id IN ($in)" );
  }
  // The ledger opens a product at its stock with a movement that has no order
  // (the sale of the ledger-owned order creates it), so remove by product too.
  $pids = array_map( 'intval', array_values( $s['products'] ?? [] ) );
  if ( $pids ) {
    $pin     = implode( ',', $pids );
    $batches = array_map( 'intval', $wpdb->get_col( 'SELECT DISTINCT batch_id FROM ' . rf_tbl( 'stock_movements' ) . " WHERE stock_product_id IN ($pin)" ) );
    if ( $batches ) {
      $bin = implode( ',', $batches );
      $wpdb->query( 'DELETE FROM ' . rf_tbl( 'events' ) . " WHERE aggregate_type = 'stock_batch' AND aggregate_id IN ($bin)" );
      $wpdb->query( 'DELETE FROM ' . rf_tbl( 'stock_movements' ) . " WHERE batch_id IN ($bin)" );
      $wpdb->query( 'DELETE FROM ' . rf_tbl( 'stock_batches' ) . " WHERE id IN ($bin)" );
    }
    $wpdb->query( 'DELETE FROM ' . rf_tbl( 'stock_balances' ) . " WHERE stock_product_id IN ($pin)" );
    $wpdb->query( 'DELETE FROM ' . rf_tbl( 'events' ) . " WHERE aggregate_type = 'product' AND aggregate_id IN ($pin)" );
  }
  foreach ( [ 'cashier', 'subscriber' ] as $who ) {
    if ( ! empty( $s[ $who ] ) ) {
      if ( 'cashier' === $who ) {
        $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . rf_tbl( 'approvals' ) . ' WHERE requested_by = %d', $s[ $who ] ) );
      }
      require_once ABSPATH . 'wp-admin/includes/user.php';
      wp_delete_user( (int) $s[ $who ] );
    }
  }
  foreach ( array_map( 'intval', $s['products'] ?? [] ) as $pid ) {
    wp_delete_post( $pid, true );
  }
  foreach ( (array) ( $s['rates'] ?? [] ) as $rate ) {
    WC_Tax::_delete_tax_rate( (int) $rate );
  }
  WC_Tax::delete_tax_class_by( 'slug', 'bazaar-rf-test' );
  rf_say( 'teardown', 'done' );
}

/** The refunded tax of one rate and the refunded total of an item, in minor units. */
function rf_item_refunded( WC_Order $order, int $item, int $rate ): string {
  return rf_minor( $order->get_tax_refunded_for_item( $item, $rate ) ) . '/' . rf_minor( $order->get_total_refunded_for_item( $item ) );
}

/** Dry run, multi-rate tax, replay and conflict. */
function rf_run_tax( array $s, int $admin ): void {
  $O = $s['orders'];
  list( $r1, $r2 ) = $s['rates'];
  $multi = wc_get_order( $O['multi'] );
  $iA    = rf_item( $multi, 0 );
  $iB    = rf_item( $multi, 1 );
  $d     = rf_refund( $O['multi'], [ [ $iA, 1 ] ], [ 'dry_run' => true ] );
  rf_say( 'dry_run', implode( ' ', [ $d['status'], var_export( $d['data']['dry_run'] ?? null, true ), $d['data']['amount_minor'] ?? '-', count( $d['data']['approvals_needed'] ?? [ 1 ] ), count( rf_rows( $O['multi'] ) ), rf_wc_refunds( $O['multi'] ), $d['data']['allocations'][0]['method_id'] ?? '-' ] ) );

  $k1 = wp_generate_uuid4();
  $c1 = rf_refund( $O['multi'], [ [ $iA, 1 ] ], [ 'client_key' => $k1 ] );
  $t1 = $c1['data']['lines'][0]['refund_tax'] ?? [];
  rf_say( 'multi_first', implode( ' ', [ $c1['status'], $c1['data']['state'] ?? '-', $c1['data']['amount_minor'] ?? '-', $c1['data']['tax_minor'] ?? '-', $t1[ $r1 ] ?? '-', $t1[ $r2 ] ?? '-' ] ) );
  $rep = rf_refund( $O['multi'], [ [ $iA, 1 ] ], [ 'client_key' => $k1 ] );
  rf_say( 'replay', implode( ' ', [ $rep['status'], var_export( $rep['data']['replayed'] ?? null, true ), ( $rep['data']['wc_refund_id'] ?? 0 ) === ( $c1['data']['wc_refund_id'] ?? -1 ) ? 'same-refund' : 'other-refund', rf_wc_refunds( $O['multi'] ), count( rf_rows( $O['multi'] ) ) ] ) );
  $con = rf_refund( $O['multi'], [ [ $iA, 2 ] ], [ 'client_key' => $k1 ] );
  rf_say( 'conflict', $con['status'] . ' ' . ( $con['data']['code'] ?? '-' ) );
  $c2 = rf_refund( $O['multi'], [ [ $iA, 1 ], [ $iB, 1 ] ] );
  $c3 = rf_refund( $O['multi'], [ [ $iA, 1 ], [ $iB, 1 ] ] );
  rf_say( 'multi_next', implode( ' ', [ $c2['status'], $c2['data']['amount_minor'] ?? '-', $c3['status'], $c3['data']['amount_minor'] ?? '-' ] ) );
  rf_say( 'multi_last_unit_a', ( $c3['data']['lines'][0]['refund_tax'][ $r1 ] ?? '-' ) . ' ' . ( $c3['data']['lines'][0]['refund_tax'][ $r2 ] ?? '-' ) . ' ' . ( $c3['data']['lines'][0]['refund_total_minor'] ?? '-' ) );
  $multi = wc_get_order( $O['multi'] );
  rf_say( 'multi_item_a', rf_item_refunded( $multi, $iA, $r1 ) . ' ' . rf_item_refunded( $multi, $iA, $r2 ) );
  rf_say( 'multi_item_b', rf_item_refunded( $multi, $iB, $r1 ) . ' ' . rf_item_refunded( $multi, $iB, $r2 ) );
  $cap  = rf_capture( $O['multi'] );
  $rows = Xophz_Compass_Bazaar_Payments_Repository::for_order( $O['multi'], 'refund' );
  rf_say( 'multi_payments', implode( ' ', [ $cap['amount_minor'] . '/' . $cap['refunded_minor'], count( $rows ), $rows[0]->kind ?? '-', $rows[0]->status ?? '-', (int) ( $rows[0]->parent_payment_id ?? 0 ) === (int) $cap['id'] ? 'parent' : 'no-parent' ] ) );
  $over = rf_refund( $O['multi'], [ [ $iA, 1 ] ] );
  rf_say( 'over_refund', $over['status'] . ' ' . ( $over['data']['code'] ?? '-' ) . ' ' . rf_wc_refunds( $O['multi'] ) );
}

/** A cashier's refund needs a manager approval; a token is single use. */
function rf_run_approvals( array $s, int $admin ): void {
  $O       = $s['orders'];
  $cashier = (int) $s['cashier'];
  $ia      = rf_item( wc_get_order( $O['app'] ) );
  $dry     = rf_refund( $O['app'], [ [ $ia, 1 ] ], [ 'dry_run' => true ], $cashier );
  $need    = $dry['data']['approvals_needed'][0] ?? [];
  rf_say( 'cashier_dry', implode( ' ', [ $dry['status'], $need['scope'] ?? '-', $need['amount_minor'] ?? '-', strlen( $need['subject_hash'] ?? '' ) ] ) );
  $key  = wp_generate_uuid4();
  $no   = rf_refund( $O['app'], [ [ $ia, 1 ] ], [ 'client_key' => $key ], $cashier );
  $rows = rf_rows( $O['app'] );
  rf_say( 'approval_required', implode( ' ', [ $no['status'], $no['data']['code'] ?? '-', $no['data']['data']['scope'] ?? '-', ( $no['data']['data']['subject_hash'] ?? '' ) === ( $need['subject_hash'] ?? 'x' ) ? 'same-subject' : 'other-subject', rf_wc_refunds( $O['app'] ), $rows[0]['state'] ?? '-' ] ) );
  $bad = rf_approval( $cashier, $admin, 'refund', str_repeat( 'a', 64 ), 2165 );
  $wr  = rf_refund( $O['app'], [ [ $ia, 1 ] ], [ 'client_key' => $key, 'approval_token' => $bad ], $cashier );
  rf_say( 'approval_wrong_subject', $wr['status'] . ' ' . ( $wr['data']['code'] ?? '-' ) . ' ' . rf_consumed( $bad ) . ' ' . rf_wc_refunds( $O['app'] ) );
  $tok  = rf_approval( $cashier, $admin, 'refund', $need['subject_hash'] ?? '', (int) ( $need['amount_minor'] ?? 0 ) );
  $ok   = rf_refund( $O['app'], [ [ $ia, 1 ] ], [ 'client_key' => $key, 'approval_token' => $tok ], $cashier );
  $pay  = Xophz_Compass_Bazaar_Payments_Repository::for_order( $O['app'], 'refund' );
  $rows = rf_rows( $O['app'] );
  rf_say( 'approved', implode( ' ', [ $ok['status'], $ok['data']['state'] ?? '-', rf_consumed( $tok ), rf_wc_refunds( $O['app'] ), $rows[0]['state'] ?? '-', ! empty( $pay[0]->approval_id ) ? 'approval-recorded' : 'no-approval-id' ] ) );
  $reuse = rf_refund( $O['app'], [ [ $ia, 1 ] ], [ 'approval_token' => $tok ], $cashier );
  rf_say( 'approval_reuse', $reuse['status'] . ' ' . ( $reuse['data']['code'] ?? '-' ) . ' ' . rf_wc_refunds( $O['app'] ) );
  $tok2 = rf_approval( $cashier, $admin, 'refund', $need['subject_hash'] ?? '', (int) ( $need['amount_minor'] ?? 0 ) );
  $last = rf_refund( $O['app'], [ [ $ia, 1 ] ], [ 'approval_token' => $tok2 ], $cashier );
  rf_say( 'approval_second_unit', $last['status'] . ' ' . ( $last['data']['amount_minor'] ?? '-' ) . ' ' . rf_wc_refunds( $O['app'] ) );
}

/** An external terminal refund needs the slip reference; permissions; the ledger-owned restock. */
function rf_run_rest( array $s, int $admin ): void {
  $O  = $s['orders'];
  $ic = rf_item( wc_get_order( $O['card'] ) );
  $a  = rf_refund( $O['card'], [ [ $ic, 1 ] ] );
  $b  = rf_refund( $O['card'], [ [ $ic, 1 ] ], [ 'tenders' => [ [ 'method_id' => 'bazaar_card_terminal', 'amount_minor' => 1000, 'reference' => 'SLIP-0002' ] ] ] );
  $c  = rf_refund( $O['card'], [ [ $ic, 1 ] ], [ 'tenders' => [ [ 'method_id' => 'bazaar_card_terminal', 'amount_minor' => 2165, 'reference' => 'ab' ] ] ] );
  $e  = rf_refund( $O['card'], [ [ $ic, 1 ] ], [ 'tenders' => [ [ 'method_id' => 'cash', 'amount_minor' => 2165 ] ] ] );
  rf_say( 'card_refused', implode( ' ', [ $a['status'] . '/' . ( $a['data']['code'] ?? '-' ), $b['status'] . '/' . ( $b['data']['code'] ?? '-' ), $c['status'] . '/' . ( $c['data']['code'] ?? '-' ), $e['status'] . '/' . ( $e['data']['code'] ?? '-' ), rf_wc_refunds( $O['card'] ), (int) rf_capture( $O['card'] )['refunded_minor'] ] ) );
  $g    = rf_refund( $O['card'], [ [ $ic, 1 ] ], [ 'tenders' => [ [ 'method_id' => 'bazaar_card_terminal', 'amount_minor' => 2165, 'reference' => 'SLIP-0002' ] ] ] );
  $rows = Xophz_Compass_Bazaar_Payments_Repository::for_order( $O['card'], 'refund' );
  rf_say( 'card_refund', implode( ' ', [ $g['status'], $g['data']['allocations'][0]['reference'] ?? '-', $rows[0]->kind ?? '-', $rows[0]->reference ?? '-', $rows[0]->card_last4 ?? '-', rf_capture( $O['card'] )['refunded_minor'], rf_wc_refunds( $O['card'] ) ] ) );

  $il = rf_item( wc_get_order( $O['last'] ) );
  $p1 = rf_refund( $O['last'], [ [ $il, 1 ] ], [ 'dry_run' => true ], (int) $s['subscriber'] );
  $p2 = rf_refund( $O['last'], [ [ $il, 1 ] ], [ 'dry_run' => true ], 0 );
  $p3 = rf_refund( 999999991, [ [ 1, 1 ] ], [ 'dry_run' => true ] );
  $p4 = rf_rest( 'POST', "/bazaar/v1/orders/{$O['last']}/refunds", [ 'lines' => [] ] );
  rf_say( 'permissions', implode( ' ', [ $p1['status'], $p2['status'], $p3['status'] . '/' . ( $p3['data']['code'] ?? '-' ), $p4['status'] . '/' . ( $p4['data']['code'] ?? '-' ) ] ) );
}

function rf_run_ledger( array $s, int $admin ): void {
  list( $r1 ) = $s['rates'];
  add_filter( 'pre_option_bazaar_ledger_mode', function () { return 'ledger'; } );
  $now = Xophz_Compass_Bazaar_Core_Db::now();
  $cid = Xophz_Compass_Bazaar_Core_Db::insert( rf_tbl( 'checkouts' ), [
    'client_key' => wp_generate_uuid4(), 'created_by' => $admin, 'request_hash' => str_repeat( '0', 64 ), 'channel' => 'pos', 'state' => 'committed',
    'operator_user_id' => $admin, 'cashier_user_id' => $admin, 'location_id' => 0, 'stock_owner' => 'ledger', 'currency' => get_woocommerce_currency(), 'created_at' => $now, 'updated_at' => $now,
  ] );
  $led = rf_order( [ [ 'product' => $s['products']['c'], 'qty' => 3, 'total' => '36.00', 'taxes' => [ $r1 => '2.97' ] ] ], [ 'provider' => 'cash', 'method_id' => 'cash', 'kind' => 'cash' ], $admin, [ '_bazaar_checkout_id' => $cid, '_bazaar_stock_owner' => 'ledger', '_bazaar_location_id' => 0 ] );
  Xophz_Compass_Bazaar_Core_Db::exec( 'UPDATE ' . rf_tbl( 'checkouts' ) . ' SET order_id = %d WHERE id = %d', [ $led->get_id(), $cid ] );
  $moves = function () use ( $led ) {
    global $wpdb;
    $r = $wpdb->get_row( $wpdb->prepare( 'SELECT COUNT(*) AS n, COALESCE(SUM(qty_delta),0) AS net FROM ' . rf_tbl( 'stock_movements' ) . ' WHERE order_id = %d', $led->get_id() ), ARRAY_A );
    return $r['n'] . ':' . (float) $r['net'];
  };
  if ( '0:0' === $moves() ) {
    Xophz_Compass_Bazaar_Core_Db::tx( function () use ( $led ) {
      return Xophz_Compass_Bazaar_Stock_Order_Lifecycle::sync_order( wc_get_order( $led->get_id() ), true );
    } );
  }
  $sold  = $moves();
  $item  = rf_item( $led );
  $kl    = wp_generate_uuid4();
  $lr    = rf_refund( $led->get_id(), [ [ $item, 1 ] ], [ 'client_key' => $kl ] );
  $after = $moves();
  $lr2   = rf_refund( $led->get_id(), [ [ $item, 1 ] ], [ 'client_key' => $kl ] );
  if ( ! empty( $lr['data']['wc_refund_id'] ) ) {
    do_action( 'woocommerce_refund_created', $lr['data']['wc_refund_id'], [ 'restock_items' => true, 'line_items' => [ $item => [ 'qty' => 1 ] ] ] );
  }
  rf_say( 'ledger_restock', implode( ' ', [ $sold, $lr['status'], $after, $lr2['status'] . '/' . var_export( $lr2['data']['replayed'] ?? null, true ), $moves() ] ) );
  remove_all_filters( 'pre_option_bazaar_ledger_mode' );
}
}

switch ( getenv( 'BZ_ACTION' ) ) {
  case 'setup':
    echo 'STATE ' . wp_json_encode( rf_setup( $bz_admin ) ) . "\n";
    break;

  case 'teardown':
    rf_teardown( $bz_state );
    break;

  case 'run':
    rf_run_tax( $bz_state, $bz_admin );
    rf_run_approvals( $bz_state, $bz_admin );
    rf_run_rest( $bz_state, $bz_admin );
    rf_run_ledger( $bz_state, $bz_admin );
    break;

  case 'once':
    // One refund of an order's only unit at a chosen moment, for the parallel check.
    $at = (float) getenv( 'BZ_AT' );
    while ( microtime( true ) < $at ) {
      usleep( 500 );
    }
    $order = wc_get_order( (int) getenv( 'BZ_ORDER' ) );
    $r     = rf_refund( $order->get_id(), [ [ rf_item( $order ), 1 ] ] );
    echo 'RESULT ' . $r['status'] . ' ' . ( $r['data']['code'] ?? '-' ) . ' ' . ( $r['data']['wc_refund_id'] ?? '-' ) . "\n";
    break;

  case 'parallel_state':
    $o = $bz_state['orders']['last'];
    rf_say( 'parallel_state', implode( ' ', [ rf_wc_refunds( $o ), rf_capture( $o )['refunded_minor'], count( Xophz_Compass_Bazaar_Payments_Repository::for_order( $o, 'refund' ) ), count( rf_rows( $o ) ) ] ) );
    break;

  case 'legacy_state':
    $o     = $bz_state['orders']['legacy'];
    $order = wc_get_order( $o );
    rf_say( 'legacy_state', implode( ' ', [ 'item=' . rf_item( $order ), 'stock=' . rf_stock( $bz_state['products']['a'] ), 'refunds=' . rf_wc_refunds( $o ), 'payments=' . count( Xophz_Compass_Bazaar_Payments_Repository::for_order( $o, 'capture' ) ) . '/' . count( Xophz_Compass_Bazaar_Payments_Repository::for_order( $o, 'refund' ) ), 'rows=' . count( rf_rows( $o ) ) ] ) );
    break;

  case 'legacy_rest':
    $o      = $bz_state['orders']['legacy'];
    $order  = wc_get_order( $o );
    $before = rf_stock( $bz_state['products']['a'] );
    $r      = rf_refund( $o, [ [ rf_item( $order ), 1 ] ], [ 'restock' => false ] );
    rf_say( 'no_restock', implode( ' ', [ $r['status'], false === ( $r['data']['restock'] ?? null ) ? 'restock-off' : 'restock-on', $before === rf_stock( $bz_state['products']['a'] ) ? 'stock-unchanged' : 'stock-changed', count( $r['data']['allocations'] ?? [ 1 ] ) ] ) );
    break;
}
