<?php
/**
 * Fixtures and checks for checkout-v2.sh. Not a plugin file: it is copied
 * into the WordPress container and run with wp-cli.
 *
 * Two roles:
 * 1. Loaded with --require (before WordPress): taxes are switched on for this
 *    process only (pre_option hooks; prices exclusive of tax, rounded per
 *    line). Nothing is written to the options table.
 * 2. Run with eval-file, BZ_ACTION = setup | crash | run | teardown.
 *    - setup:    tax class bazaar-co-test with one 8.25% base-location rate,
 *                three private products (A 12.50 with 20 in stock, B 7.99 with
 *                2 in stock, C 5.00 unmanaged), a 10% coupon, a bazaar_cashier
 *                and a subscriber. Prints "STATE <json>".
 *    - crash:    submits a cash sale of A whose process exits at the
 *                after_order_created test point, leaving the row in
 *                order_created under a live lease (a simulated crash).
 *                BZ_KEY is the client key. Prints "CRASHED <checkout id>".
 *    - run:      every checkout check; prints "CHECK <name> <value>".
 *                BZ_CRASH_RETRY and BZ_CRASH_SWEEP are two crashed keys.
 *    - teardown: deletes the orders (trash, then delete), checkout, payment,
 *                event and ledger rows of this run's keys and products, then
 *                the products, coupon, rate, class and users.
 * Ledger mode and failure points are switched on with in-process filters
 * only, so other processes keep the store's mode. Keys used are kept in the
 * option bazaar_checkout_v2_test_keys until teardown.
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
    WP_CLI::add_wp_hook( 'pre_option_woocommerce_tax_round_at_subtotal', function () { return 'no'; } );
  }
  return;
}
if ( defined( 'BZ_CHECKOUT_FIXTURES' ) ) {
  return;
}
define( 'BZ_CHECKOUT_FIXTURES', true );

$bz_state = json_decode( (string) getenv( 'BZ_STATE' ), true ) ?: [];
$bz_admin = (int) ( get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] )[0] ?? 0 );
wp_set_current_user( $bz_admin );

if ( ! function_exists( 'bz_co_rest' ) ) {
  function bz_co_rest( string $method, string $route, $body = null, ?int $user = null ): array {
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

  function bz_co_say( string $name, $value ): void {
    echo 'CHECK ' . $name . ' ' . ( is_scalar( $value ) ? (string) $value : wp_json_encode( $value ) ) . "\n";
  }

  /** A fresh UUID v4, remembered for teardown. */
  function bz_co_key(): string {
    $key  = wp_generate_uuid4();
    $keys = (array) get_option( 'bazaar_checkout_v2_test_keys', [] );
    $keys[] = $key;
    update_option( 'bazaar_checkout_v2_test_keys', $keys, false );
    return $key;
  }

  function bz_co_post( array $body, ?int $user = null ): array {
    return bz_co_rest( 'POST', '/bazaar/v1/pos/checkouts', $body, $user );
  }

  function bz_co_body( string $key, array $cart, array $tenders, array $extra = [] ): array {
    return array_merge( [ 'client_key' => $key, 'cart' => $cart, 'tenders' => $tenders ], $extra );
  }

  function bz_co_quote( array $cart, ?int $user = null ): array {
    return bz_co_rest( 'POST', '/bazaar/v1/pos/quote', $cart, $user )['data'];
  }

  function bz_co_cash( int $total, int $extra = 500 ): array {
    return [ [ 'method_id' => 'cash', 'amount_minor' => $total, 'tendered_minor' => $total + $extra ] ];
  }

  /** Orders that this run's checkouts created (by _bazaar_checkout_id), so other scripts' POS orders never count. */
  function bz_co_orders(): int {
    global $wpdb;
    $keys = array_values( (array) get_option( 'bazaar_checkout_v2_test_keys', [] ) );
    $ids  = $keys ? $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . Xophz_Compass_Bazaar_Core_Db::table( 'checkouts' ) . ' WHERE client_key IN (' . implode( ',', array_fill( 0, count( $keys ), '%s' ) ) . ')', $keys ) ) : [];
    if ( ! $ids ) {
      return 0;
    }
    return count( wc_get_orders( [
      'limit'      => -1,
      'return'     => 'ids',
      'status'     => array_keys( wc_get_order_statuses() ),
      'meta_query' => [ [ 'key' => '_bazaar_checkout_id', 'value' => array_map( 'strval', $ids ), 'compare' => 'IN' ] ],
    ] ) );
  }

  function bz_co_row( string $key, int $user = 0 ): ?array {
    return Xophz_Compass_Bazaar_Checkout_Repository::find_by_key( $user ?: get_current_user_id(), $key );
  }

  function bz_co_payments( int $checkout_id ): int {
    return count( Xophz_Compass_Bazaar_Payments_Repository::for_checkout( $checkout_id ) );
  }

  function bz_co_moves( int $order_id ): int {
    global $wpdb;
    return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Xophz_Compass_Bazaar_Core_Db::table( 'stock_movements' ) . ' WHERE order_id = %d', $order_id ) );
  }

  /** _stock straight from the database (no object cache). */
  function bz_co_stock( int $pid ): float {
    global $wpdb;
    return (float) $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_stock'", $pid ) );
  }

  function bz_co_order( int $order_id ): ?WC_Order {
    $order = wc_get_order( $order_id );
    return $order instanceof WC_Order ? $order : null;
  }

  function bz_co_minor( $amount ): int {
    return Xophz_Compass_Bazaar_Core_Money::to_minor( wc_format_decimal( $amount, wc_get_price_decimals() ), wc_get_price_decimals() );
  }

  function bz_co_ledger( bool $on ): void {
    remove_all_filters( 'pre_option_bazaar_ledger_mode' );
    if ( $on ) {
      add_filter( 'pre_option_bazaar_ledger_mode', function () { return 'ledger'; } );
    }
  }

  /** Turn on one test failure point ('' turns them off). */
  function bz_co_fail( string $point ): void {
    remove_all_filters( Xophz_Compass_Bazaar_Checkout_Service::FAIL_FILTER );
    if ( '' !== $point ) {
      add_filter( Xophz_Compass_Bazaar_Checkout_Service::FAIL_FILTER, function ( $hit, $at ) use ( $point ) { return $at === $point ? $at : $hit; }, 10, 2 );
    }
  }

  function bz_co_expire( int $checkout_id ): void {
    global $wpdb;
    $wpdb->query( $wpdb->prepare( 'UPDATE ' . Xophz_Compass_Bazaar_Core_Db::table( 'checkouts' ) . ' SET lease_until = UTC_TIMESTAMP() - INTERVAL 5 SECOND WHERE id = %d', $checkout_id ) );
  }

  function bz_co_setup(): array {
    $state = [ 'rate' => 0, 'products' => [], 'coupon' => [], 'cashier' => 0, 'subscriber' => 0 ];
    if ( ! in_array( 'bazaar-co-test', WC_Tax::get_tax_class_slugs(), true ) ) {
      WC_Tax::create_tax_class( 'Bazaar Checkout Test', 'bazaar-co-test' );
    }
    $state['rate'] = (int) WC_Tax::_insert_tax_rate( [
      'tax_rate_country' => WC()->countries->get_base_country(), 'tax_rate_state' => WC()->countries->get_base_state(),
      'tax_rate' => '8.2500', 'tax_rate_name' => 'BZ CO State', 'tax_rate_priority' => 1, 'tax_rate_compound' => 0,
      'tax_rate_shipping' => 1, 'tax_rate_order' => 0, 'tax_rate_class' => 'bazaar-co-test',
    ] );
    $product = function ( string $name, string $price, ?int $stock ) {
      $p = new WC_Product_Simple();
      $p->set_name( $name );
      $p->set_status( 'private' );
      $p->set_regular_price( $price );
      $p->set_tax_class( 'bazaar-co-test' );
      $p->set_tax_status( 'taxable' );
      $p->set_manage_stock( null !== $stock );
      if ( null !== $stock ) {
        $p->set_stock_quantity( $stock );
        $p->set_backorders( 'no' );
      }
      return (int) $p->save();
    };
    $state['products'] = [
      'a' => $product( 'Bazaar checkout-v2 A', '12.50', 20 ),
      'b' => $product( 'Bazaar checkout-v2 B', '7.99', 2 ),
      'c' => $product( 'Bazaar checkout-v2 C', '5.00', null ),
    ];
    $suffix = strtolower( wp_generate_password( 6, false ) );
    $c      = new WC_Coupon();
    $c->set_code( 'bz-co-pct-' . $suffix );
    $c->set_discount_type( 'percent' );
    $c->set_amount( '10' );
    $state['coupon']     = [ 'id' => (int) $c->save(), 'code' => 'bz-co-pct-' . $suffix ];
    $state['cashier']    = (int) wp_insert_user( [ 'user_login' => 'bz-co-cashier-' . $suffix, 'user_pass' => wp_generate_password( 24 ), 'role' => 'bazaar_cashier' ] );
    $state['subscriber'] = (int) wp_insert_user( [ 'user_login' => 'bz-co-sub-' . $suffix, 'user_pass' => wp_generate_password( 24 ), 'role' => 'subscriber' ] );
    return $state;
  }

  function bz_co_teardown( array $state ): void {
    global $wpdb;
    $t = function ( string $name ) { return Xophz_Compass_Bazaar_Core_Db::table( $name ); };
    foreach ( (array) get_option( 'bazaar_checkout_v2_test_keys', [] ) as $key ) {
      foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT id, order_id FROM ' . $t( 'checkouts' ) . ' WHERE client_key = %s', $key ) ) as $row ) {
        $order = $row->order_id ? wc_get_order( (int) $row->order_id ) : null;
        $buyer = $order ? get_user_by( 'id', $order->get_customer_id() ) : null;
        if ( $buyer && 0 === strpos( $buyer->user_email, 'bz-co-new-' ) && '@example.test' === substr( $buyer->user_email, -13 ) ) {
          require_once ABSPATH . 'wp-admin/includes/user.php';
          wp_delete_user( (int) $buyer->ID );
        }
        if ( $order ) {
          $order->delete( false );
          // delete() zeroes the object's id, so the forced delete needs a fresh copy.
          $trashed = wc_get_order( (int) $row->order_id );
          if ( $trashed ) {
            $trashed->delete( true );
          }
        }
        $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . $t( 'payments' ) . ' WHERE checkout_id = %d', (int) $row->id ) );
        $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . $t( 'events' ) . " WHERE aggregate_type = 'checkout' AND aggregate_id = %d", (int) $row->id ) );
        $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . $t( 'checkouts' ) . ' WHERE id = %d', (int) $row->id ) );
      }
    }
    delete_option( 'bazaar_checkout_v2_test_keys' );
    $pids = array_map( 'intval', array_values( (array) ( $state['products'] ?? [] ) ) );
    if ( $pids ) {
      $in      = implode( ',', $pids );
      $batches = array_map( 'intval', $wpdb->get_col( 'SELECT DISTINCT batch_id FROM ' . $t( 'stock_movements' ) . " WHERE stock_product_id IN ($in)" ) );
      if ( $batches ) {
        $bin = implode( ',', $batches );
        $wpdb->query( 'DELETE FROM ' . $t( 'events' ) . " WHERE aggregate_type = 'stock_batch' AND aggregate_id IN ($bin)" );
        $wpdb->query( 'DELETE FROM ' . $t( 'stock_movements' ) . " WHERE batch_id IN ($bin)" );
        $wpdb->query( 'DELETE FROM ' . $t( 'stock_batches' ) . " WHERE id IN ($bin)" );
      }
      $wpdb->query( 'DELETE FROM ' . $t( 'stock_balances' ) . " WHERE stock_product_id IN ($in)" );
    }
    foreach ( $pids as $id ) {
      $p = wc_get_product( $id );
      if ( $p ) {
        $p->delete( true );
      }
    }
    if ( ! empty( $state['coupon']['id'] ) ) {
      wp_delete_post( (int) $state['coupon']['id'], true );
    }
    if ( ! empty( $state['rate'] ) ) {
      WC_Tax::_delete_tax_rate( (int) $state['rate'] );
    }
    WC_Tax::delete_tax_class_by( 'slug', 'bazaar-co-test' );
    require_once ABSPATH . 'wp-admin/includes/user.php';
    foreach ( [ 'cashier', 'subscriber' ] as $who ) {
      if ( ! empty( $state[ $who ] ) ) {
        wp_delete_user( (int) $state[ $who ] );
      }
    }
  }
}

if ( ! function_exists( 'bz_co_run' ) ) {
  function bz_co_run( array $s, int $admin ): void {
    $A    = (int) $s['products']['a'];
    $B    = (int) $s['products']['b'];
    $C    = (int) $s['products']['c'];
    $line = function ( int $pid, int $qty = 1 ) { return [ 'type' => 'product', 'product_id' => $pid, 'quantity' => $qty ]; };
    $cart = function ( array $lines, array $extra = [] ) { return array_merge( [ 'lines' => $lines ], $extra ); };
    $one  = $cart( [ $line( $A ) ] );
    $T    = (int) bz_co_quote( $one )['totals']['total_minor'];
    bz_co_ledger( false );
    bz_co_fail( '' );
    // First, while the crashed row's 60-second lease is still live.
    $crash = bz_co_row( (string) getenv( 'BZ_CRASH_RETRY' ) );
    $busy  = $crash ? bz_co_post( bz_co_body( $crash['client_key'], $one, bz_co_cash( $T ) ) ) : [ 'data' => [] ];

    // Cash sale, replay, conflicting key, checkout in progress.
    $key    = bz_co_key();
    $cash   = $key;
    $before = bz_co_orders();
    $r      = bz_co_post( bz_co_body( $key, $one, bz_co_cash( $T ), [ 'expected_total_minor' => $T ] ) );
    $row    = bz_co_row( $key );
    $order  = bz_co_order( (int) ( $r['data']['order']['id'] ?? 0 ) );
    bz_co_say( 'cash', implode( ' ', [ $r['status'], $r['data']['state'] ?? '-', $order ? $order->get_status() : '-', bz_co_payments( (int) $row['id'] ), $r['data']['payments'][0]['change_minor'] ?? '-' ] ) );
    bz_co_say( 'cash_meta', implode( ' ', [
      $order ? $order->get_created_via() : '-',
      $order && (int) $order->get_meta( '_bazaar_checkout_id' ) === (int) $row['id'] ? 'checkout' : 'no-checkout',
      $order && (int) $order->get_meta( '_pos_cashier_id' ) === $admin ? 'cashier' : 'no-cashier',
      $order ? $order->get_payment_method() : '-',
      $order && bz_co_minor( $order->get_total() ) === $T && (int) $r['data']['totals']['total_minor'] === $T ? 'total' : 'no-total',
    ] ) );
    $again = bz_co_post( bz_co_body( $key, $one, bz_co_cash( $T ), [ 'expected_total_minor' => $T ] ) );
    bz_co_say( 'replay', implode( ' ', [ $again['status'], var_export( $again['data']['replayed'] ?? null, true ), ( $again['data']['order']['id'] ?? 0 ) === ( $r['data']['order']['id'] ?? -1 ) ? 'same-order' : 'other-order', bz_co_orders() - $before - 1, bz_co_payments( (int) $row['id'] ) ] ) );
    $conflict = bz_co_post( bz_co_body( $key, $one, bz_co_cash( $T, 100 ) ) );
    bz_co_say( 'conflict', $conflict['status'] . ' ' . ( $conflict['data']['code'] ?? '-' ) );
    $key     = bz_co_key();
    $request = Xophz_Compass_Bazaar_Checkout_Request::from_array( bz_co_body( $key, $one, bz_co_cash( $T ) ) );
    Xophz_Compass_Bazaar_Checkout_Repository::claim( [ 'client_key' => $key, 'created_by' => $admin, 'request_hash' => $request->request_hash(), 'operator_user_id' => $admin, 'cashier_user_id' => $admin, 'currency' => get_woocommerce_currency() ] );
    $before = bz_co_orders();
    $busy   = bz_co_post( bz_co_body( $key, $one, bz_co_cash( $T ) ) );
    bz_co_say( 'in_progress', implode( ' ', [ $busy['status'], $busy['data']['code'] ?? '-', $busy['data']['data']['retry_after_ms'] ?? '-', bz_co_orders() - $before ] ) );

    // Refusals before any order exists.
    $before = bz_co_orders();
    $key    = bz_co_key();
    $r      = bz_co_post( bz_co_body( $key, $one, bz_co_cash( $T + 1 ), [ 'expected_total_minor' => $T + 1 ] ) );
    $row    = bz_co_row( $key );
    bz_co_say( 'total_changed', implode( ' ', [ $r['status'], $r['data']['code'] ?? '-', (int) ( $r['data']['data']['quote']['totals']['total_minor'] ?? 0 ) === $T ? 'quote' : 'no-quote', bz_co_orders() - $before, $row['state'] ?? '-', $row['retryable'] ?? '-' ] ) );
    $r = bz_co_post( bz_co_body( bz_co_key(), $one, [ [ 'method_id' => 'bazaar_card_terminal', 'amount_minor' => $T ] ] ) );
    bz_co_say( 'reference_required', implode( ' ', [ $r['status'], $r['data']['code'] ?? '-', bz_co_orders() - $before ] ) );
    $r = bz_co_post( bz_co_body( bz_co_key(), $one, bz_co_cash( $T - 1 ) ) );
    bz_co_say( 'tender_mismatch', implode( ' ', [ $r['status'], $r['data']['code'] ?? '-', bz_co_orders() - $before ] ) );
    bz_co_ledger( true );
    $r = bz_co_post( bz_co_body( bz_co_key(), $cart( [ $line( $B, 3 ) ] ), bz_co_cash( (int) bz_co_quote( $cart( [ $line( $B, 3 ) ] ) )['totals']['total_minor'] ) ) );
    bz_co_say( 'insufficient_ledger', implode( ' ', [ $r['status'], $r['data']['code'] ?? '-', count( $r['data']['data']['lines'] ?? [] ), bz_co_orders() - $before ] ) );
    bz_co_ledger( false );
    $disc = $cart( [ [ 'type' => 'custom', 'name' => 'Display model', 'unit_price_minor' => 5000, 'quantity' => 1 ] ], [ 'discounts' => [ [ 'name' => 'Half', 'type' => 'percent', 'value_bp' => 5000 ] ] ] );
    $dt   = (int) bz_co_quote( $disc, (int) $s['cashier'] )['totals']['total_minor'];
    $r    = bz_co_post( bz_co_body( bz_co_key(), $disc, bz_co_cash( $dt ) ), (int) $s['cashier'] );
    bz_co_say( 'approval_required', implode( ' ', [ $r['status'], $r['data']['code'] ?? '-', $r['data']['data']['scope'] ?? '-', bz_co_orders() - $before ] ) );

    // Split, bank transfer, cod and parity.
    $key = bz_co_key();
    $r   = bz_co_post( bz_co_body( $key, $one, [ [ 'method_id' => 'cash', 'amount_minor' => 1000, 'tendered_minor' => 1000 ], [ 'method_id' => 'bazaar_card_terminal', 'amount_minor' => $T - 1000, 'reference' => 'A1B2C3', 'card_brand' => 'visa', 'card_last4' => '1881' ] ] ) );
    $o   = bz_co_order( (int) ( $r['data']['order']['id'] ?? 0 ) );
    bz_co_say( 'split', implode( ' ', [ $r['status'], $r['data']['state'] ?? '-', $o ? $o->get_payment_method() : '-', count( $r['data']['payments'] ?? [] ), $o ? count( (array) json_decode( (string) $o->get_meta( '_pos_split_payments' ), true ) ) : 0, $o ? $o->get_status() : '-' ] ) );
    $r  = bz_co_post( bz_co_body( bz_co_key(), $one, [ [ 'method_id' => 'bacs', 'amount_minor' => $T, 'reference' => 'INV-1' ] ] ) );
    $o  = bz_co_order( (int) ( $r['data']['order']['id'] ?? 0 ) );
    $p0 = $r['data']['payments'][0] ?? [];
    bz_co_say( 'bacs', implode( ' ', [ $r['status'], $o ? $o->get_status() : '-', $p0['status'] ?? '-', $p0['kind'] ?? '-', $o && $o->get_date_paid() ? 'paid' : 'unpaid' ] ) );
    if ( $o ) {
      $o->update_status( 'processing', 'Test: bank transfer received.' );
    }
    $rows = $o ? Xophz_Compass_Bazaar_Payments_Repository::for_order( $o->get_id() ) : [];
    bz_co_say( 'bacs_paid', $rows ? (string) $rows[0]->status : '-' );
    $r    = bz_co_post( bz_co_body( bz_co_key(), $one, [ [ 'method_id' => 'cod', 'amount_minor' => $T ] ] ) );
    bz_co_say( 'cod', implode( ' ', [ $r['status'], $r['data']['order']['status'] ?? '-', $r['data']['payments'][0]['status'] ?? '-' ] ) );
    $mix   = $cart( [ $line( $A, 2 ), $line( $C ), [ 'type' => 'custom', 'name' => 'Gift wrap', 'unit_price_minor' => 300, 'quantity' => 1, 'taxable' => true ] ], [ 'coupons' => [ $s['coupon']['code'] ], 'discounts' => [ [ 'name' => 'Loyalty', 'type' => 'percent', 'value_bp' => 500 ] ] ] );
    $quote = bz_co_quote( $mix );
    $qt    = $quote['totals'];
    $r     = bz_co_post( bz_co_body( bz_co_key(), $mix, bz_co_cash( (int) $qt['total_minor'] ), [ 'expected_total_minor' => (int) $qt['total_minor'], 'quote_hash' => $quote['quote_hash'] ] ) );
    $o     = bz_co_order( (int) ( $r['data']['order']['id'] ?? 0 ) );
    $got   = $o ? [ bz_co_minor( $o->get_total() ), bz_co_minor( $o->get_total_tax() ), bz_co_minor( $o->get_discount_total() ), count( $o->get_items( 'coupon' ) ), count( $o->get_items( 'line_item' ) ) ] : [];
    bz_co_say( 'parity', $r['status'] . ' ' . ( $got === [ (int) $qt['total_minor'], (int) $qt['tax_minor'], (int) $qt['discount_minor'], 1, 3 ] && $r['data']['totals']['total_minor'] === $qt['total_minor'] ? 'equal' : wp_json_encode( [ 'quote' => $qt, 'order' => $got ] ) ) );
    bz_co_say( 'parity_discounted', $qt['discount_minor'] > 0 && $qt['tax_minor'] > 0 ? 'yes' : 'no' );

    // A ledger sale moves stock once.
    bz_co_ledger( true );
    $stock = bz_co_stock( $A );
    $r     = bz_co_post( bz_co_body( bz_co_key(), $one, bz_co_cash( $T ) ) );
    $oid   = (int) ( $r['data']['order']['id'] ?? 0 );
    $o     = bz_co_order( $oid );
    bz_co_say( 'ledger_sale', implode( ' ', [ $r['status'], $r['data']['state'] ?? '-', $stock - bz_co_stock( $A ), bz_co_moves( $oid ), $o ? $o->get_meta( '_bazaar_stock_owner' ) : '-' ] ) );

    // The three failure points, each retried with the same key (ledger mode).
    foreach ( [ 'after_order_created', 'in_commit' ] as $point ) {
      $key   = bz_co_key();
      $stock = bz_co_stock( $A );
      $token = Xophz_Compass_Bazaar_Pos_Authorization::issue( $admin, 'ppcp-gateway', $T / 100 );
      $body  = bz_co_body( $key, $one, [ [ 'method_id' => 'ppcp-gateway', 'amount_minor' => $T, 'authorization_token' => $token ] ] );
      bz_co_fail( $point );
      $r   = bz_co_post( $body );
      bz_co_fail( '' );
      $row = bz_co_row( $key );
      $o   = bz_co_order( (int) $row['order_id'] );
      $before = bz_co_orders();
      $retry  = bz_co_post( $body );
      bz_co_say( "fail_$point", implode( ' ', [ $r['status'], $r['data']['code'] ?? '-', $row['state'], $o ? $o->get_status() : 'no-order', $o ? $o->get_meta( '_bazaar_failure_code' ) : '-', bz_co_moves( (int) $row['order_id'] ), bz_co_payments( (int) $row['id'] ), $stock - bz_co_stock( $A ) ] ) );
      bz_co_say( "retry_$point", implode( ' ', [ $retry['status'], var_export( $retry['data']['data']['replayed'] ?? null, true ), bz_co_orders() - $before, bz_co_moves( (int) $row['order_id'] ), bz_co_payments( (int) $row['id'] ), '' === Xophz_Compass_Bazaar_Pos_Authorization::peek( $token, $admin, 'ppcp-gateway', $T / 100 ) ? 'token-unspent' : 'token-spent' ] ) );
    }
    // open() saves the order, then a hook throws: the order is cancelled, and a same-key retry makes the only live order.
    $key  = bz_co_key();
    $body = bz_co_body( $key, $one, bz_co_cash( $T ) );
    $boom = function ( $o ) { if ( $o instanceof WC_Order && $o->get_meta( '_bazaar_checkout_id' ) && 'pending' === $o->get_status() ) { throw new Error( 'boom' ); /* WC_Order::save() swallows Exception, not Error. */ } };
    add_action( 'woocommerce_after_order_object_save', $boom );
    $r = bz_co_post( $body );
    remove_action( 'woocommerce_after_order_object_save', $boom );
    $row    = bz_co_row( $key );
    $cid    = (int) $row['id'];
    $mine   = function () use ( $cid ) { return wc_get_orders( [ 'limit' => -1, 'status' => 'any', 'meta_key' => '_bazaar_checkout_id', 'meta_value' => $cid ] ); };
    $stat   = function () use ( $mine ) { $x = array_map( function ( $o ) { return $o->get_status(); }, $mine() ); sort( $x ); return implode( ',', $x ); };
    $first  = $stat();
    $retry  = bz_co_post( $body );
    bz_co_say( 'orphan_open', implode( ' ', [ $r['status'], $row['state'], $first, $retry['status'], $retry['data']['state'] ?? '-', $stat() ] ) );
    $key   = bz_co_key();
    $stock = bz_co_stock( $A );
    bz_co_fail( 'after_commit' );
    $r = bz_co_post( bz_co_body( $key, $one, bz_co_cash( $T ) ) );
    bz_co_fail( '' );
    $row = bz_co_row( $key );
    bz_co_say( 'fail_after_commit', implode( ' ', [ $r['status'], $r['data']['code'] ?? '-', $row['state'], bz_co_order( (int) $row['order_id'] )->get_status(), bz_co_moves( (int) $row['order_id'] ), bz_co_payments( (int) $row['id'] ), $stock - bz_co_stock( $A ) ] ) );
    $retry = bz_co_post( bz_co_body( $key, $one, bz_co_cash( $T ) ) );
    $row   = bz_co_row( $key );
    bz_co_say( 'retry_after_commit', implode( ' ', [ $retry['status'], $retry['data']['state'] ?? '-', bz_co_order( (int) $row['order_id'] )->get_status(), bz_co_moves( (int) $row['order_id'] ), bz_co_payments( (int) $row['id'] ), $stock - bz_co_stock( $A ) ] ) );

    // Recovery: a crashed order_created row on same-key retry and in the sweeper, a committed row and a stuck received row in the sweeper.
    bz_co_ledger( false );
    if ( $crash ) {
      bz_co_expire( (int) $crash['id'] );
      $r = bz_co_post( bz_co_body( $crash['client_key'], $one, bz_co_cash( $T ) ) );
      $o = bz_co_order( (int) $crash['order_id'] );
      bz_co_say( 'crash_retry', implode( ' ', [ $busy['data']['code'] ?? '-', $r['status'], $r['data']['code'] ?? '-', bz_co_row( $crash['client_key'] )['state'], $o ? $o->get_status() : 'no-order' ] ) );
    }
    $sweep_crash = bz_co_row( (string) getenv( 'BZ_CRASH_SWEEP' ) );
    $key         = bz_co_key();
    bz_co_fail( 'after_commit' );
    bz_co_post( bz_co_body( $key, $one, bz_co_cash( $T ) ) );
    bz_co_fail( '' );
    $stuck_key = bz_co_key();
    $stuck     = Xophz_Compass_Bazaar_Checkout_Repository::claim( [ 'client_key' => $stuck_key, 'created_by' => $admin, 'request_hash' => str_repeat( '0', 64 ), 'operator_user_id' => $admin, 'cashier_user_id' => $admin, 'currency' => get_woocommerce_currency() ] )['row'];
    bz_co_expire( (int) $stuck['id'] );
    if ( $sweep_crash ) {
      bz_co_expire( (int) $sweep_crash['id'] );
    }
    $counts = Xophz_Compass_Bazaar_Checkout_Sweeper::run( 500 );
    $after  = [ bz_co_row( $key )['state'], $sweep_crash ? bz_co_row( $sweep_crash['client_key'] )['state'] : 'no-crash', bz_co_row( $stuck_key )['state'], bz_co_row( $stuck_key )['retryable'] ];
    $so     = $sweep_crash ? bz_co_order( (int) $sweep_crash['order_id'] ) : null;
    bz_co_say( 'sweeper', implode( ' ', array_merge( $after, [ $so ? $so->get_status() : 'no-order', bz_co_order( (int) bz_co_row( $key )['order_id'] )->get_status(), $counts['finalized'] >= 1 && $counts['cancelled'] >= 1 && $counts['failed'] >= 1 ? 'counted' : wp_json_encode( $counts ) ] ) ) );
    bz_co_say( 'sweeper_scheduled', Xophz_Compass_Bazaar_Checkout_Sweeper::ensure_scheduled() );

    // Customers: staff are never attached; create_account attaches a new customer with opt-ins.
    $r = bz_co_post( bz_co_body( bz_co_key(), $cart( [ $line( $A ) ], [ 'customer' => [ 'id' => $admin ] ] ), bz_co_cash( $T ) ) );
    $o = bz_co_order( (int) ( $r['data']['order']['id'] ?? 0 ) );
    bz_co_say( 'customer_staff', $r['status'] . ' ' . ( $o ? $o->get_customer_id() : '-' ) );
    $email = 'bz-co-new-' . strtolower( wp_generate_password( 8, false ) ) . '@example.test';
    $r     = bz_co_post( bz_co_body( bz_co_key(), $cart( [ $line( $A ) ], [ 'customer' => [ 'email' => $email, 'create_account' => true, 'marketing_opt_in' => true ] ] ), bz_co_cash( $T ) ) );
    $o     = bz_co_order( (int) ( $r['data']['order']['id'] ?? 0 ) );
    $u     = $o ? get_user_by( 'id', $o->get_customer_id() ) : null;
    bz_co_say( 'customer_create', implode( ' ', [ $r['status'], $u && $u->user_email === $email ? 'attached' : 'none', $u && in_array( 'customer', (array) $u->roles, true ) ? 'customer' : '-', $o ? $o->get_meta( '_pos_marketing_optin' ) : '-', $o && $o->get_meta( '_bazaar_post_complete_done' ) ? 'done' : 'not-done' ] ) );

    // Reads and permissions.
    $own   = bz_co_rest( 'GET', '/bazaar/v1/pos/checkouts/' . $cash );
    $other = bz_co_rest( 'GET', '/bazaar/v1/pos/checkouts/' . $cash, null, (int) $s['cashier'] );
    $sub   = bz_co_rest( 'GET', '/bazaar/v1/pos/checkouts/' . $cash, null, (int) $s['subscriber'] );
    bz_co_say( 'status_get', implode( ' ', [ $own['status'], $own['data']['state'] ?? '-', $other['status'], $other['data']['code'] ?? '-', $sub['status'] ] ) );
    $flight = bz_co_rest( 'GET', '/bazaar/v1/pos/checkouts/' . $stuck_key );
    bz_co_say( 'status_failed', implode( ' ', [ $flight['status'], $flight['data']['state'] ?? '-', $flight['data']['error']['code'] ?? '-' ] ) );
    $r = bz_co_post( bz_co_body( bz_co_key(), $one, bz_co_cash( $T ) ), 0 );
    $q = bz_co_post( bz_co_body( bz_co_key(), $one, bz_co_cash( $T ) ), (int) $s['subscriber'] );
    bz_co_say( 'permissions', $r['status'] . ' ' . ( $r['data']['code'] ?? '-' ) . ' ' . $q['status'] . ' ' . ( $q['data']['code'] ?? '-' ) );
    add_filter( Xophz_Compass_Bazaar_Checkout_Service::ENABLED_FILTER, '__return_false' );
    $r = bz_co_post( bz_co_body( bz_co_key(), $one, bz_co_cash( $T ) ) );
    remove_filter( Xophz_Compass_Bazaar_Checkout_Service::ENABLED_FILTER, '__return_false' );
    bz_co_say( 'disabled', $r['status'] . ' ' . ( $r['data']['code'] ?? '-' ) );
    $shift = bz_co_rest( 'GET', '/bazaar/v1/pos/shift-summary', null, (int) $s['cashier'] );
    bz_co_say( 'shift', $shift['status'] . ' ' . implode( ',', array_keys( (array) $shift['data'] ) ) . ' cashier=' . ( (int) ( $shift['data']['window']['cashier_user_id'] ?? 0 ) === (int) $s['cashier'] ? 'self' : 'other' ) );
    $admin_shift = bz_co_rest( 'GET', '/bazaar/v1/pos/shift-summary' );
    bz_co_say( 'shift_admin', $admin_shift['status'] . ' ' . ( (int) ( $admin_shift['data']['orders'] ?? 0 ) >= 5 && (int) ( $admin_shift['data']['gross_minor'] ?? 0 ) > 0 ? 'counted' : wp_json_encode( $admin_shift['data'] ) ) );
    bz_co_run_fixes( $one, $T, $A, $admin, (string) ( Xophz_Compass_Bazaar_Payments_Repository::for_checkout( (int) bz_co_row( $cash )['id'] )[0]->payment_uuid ?? '' ) );
  }

  /** Review fixes #6985: after-commit throws, payment_uuid repeats, zero totals, closed orders, leases, stock notes. */
  function bz_co_run_fixes( array $one, int $T, int $A, int $admin, string $used_uuid ): void {
    global $wpdb;
    $ct = Xophz_Compass_Bazaar_Core_Db::table( 'checkouts' );
    bz_co_ledger( true );
    // A listener throwing after COMMIT (outbox event and the stock sync) leaves the paid sale completed.
    $boom = function () { throw new RuntimeException( 'listener down' ); };
    add_action( 'xophz_compass_bazaar_event', $boom );
    add_action( 'woocommerce_product_set_stock', $boom );
    $stock = bz_co_stock( $A );
    $r     = bz_co_post( bz_co_body( bz_co_key(), $one, bz_co_cash( $T ) ) );
    remove_action( 'xophz_compass_bazaar_event', $boom );
    remove_action( 'woocommerce_product_set_stock', $boom );
    $oid = (int) ( $r['data']['order']['id'] ?? 0 );
    bz_co_say( 'after_commit_listener', implode( ' ', [ $r['status'], $r['data']['state'] ?? ( $r['data']['code'] ?? '-' ), $oid ? bz_co_order( $oid )->get_status() : '-', bz_co_moves( $oid ), count( $r['data']['payments'] ?? [] ), $stock - bz_co_stock( $A ) ] ) );

    // payment_uuid repeated in the request, or already stored: 422 before any order.
    $before = bz_co_orders();
    $u      = wp_generate_uuid4();
    $half   = intdiv( $T, 2 );
    $r      = bz_co_post( bz_co_body( bz_co_key(), $one, [ [ 'method_id' => 'cash', 'amount_minor' => $half, 'payment_uuid' => $u ], [ 'method_id' => 'cash', 'amount_minor' => $T - $half, 'payment_uuid' => $u ] ] ) );
    $q      = bz_co_post( bz_co_body( bz_co_key(), $one, [ [ 'method_id' => 'cash', 'amount_minor' => $T, 'payment_uuid' => $used_uuid ] ] ) );
    bz_co_say( 'uuid_repeat', implode( ' ', [ $r['status'], $r['data']['code'] ?? '-', $r['data']['data']['field'] ?? '-', $q['status'], $q['data']['code'] ?? '-', '' !== $used_uuid ? 'stored' : 'no-uuid', bz_co_orders() - $before ] ) );

    // Zero-total sales: no tenders, or one tender of 0; a non-zero tender is a mismatch.
    $free = [ 'lines' => [ [ 'type' => 'custom', 'name' => 'Comped item', 'unit_price_minor' => 0, 'quantity' => 1 ] ] ];
    $zt   = (int) bz_co_quote( $free )['totals']['total_minor'];
    $r    = bz_co_post( bz_co_body( bz_co_key(), $free, [] ) );
    $q    = bz_co_post( bz_co_body( bz_co_key(), $free, [ [ 'method_id' => 'cash', 'amount_minor' => 0 ] ] ) );
    $m    = bz_co_post( bz_co_body( bz_co_key(), $free, bz_co_cash( 1, 0 ) ) );
    $zo   = bz_co_order( (int) ( $r['data']['order']['id'] ?? 0 ) );
    bz_co_say( 'zero_total', implode( ' ', [ $zt, $r['status'], $zo ? $zo->get_status() : '-', count( $r['data']['payments'] ?? [ 'x' ] ), $zo ? $zo->get_payment_method_title() : '-', $q['status'], $q['data']['order']['status'] ?? '-', count( $q['data']['payments'] ?? [ 'x' ] ), $m['status'], $m['data']['code'] ?? '-' ] ) );

    // Staff cancel the order while the row waits in committed: finalize keeps it cancelled and cancels the row.
    $key   = bz_co_key();
    $stock = bz_co_stock( $A );
    bz_co_fail( 'after_commit' );
    bz_co_post( bz_co_body( $key, $one, bz_co_cash( $T ) ) );
    bz_co_fail( '' );
    $row = bz_co_row( $key );
    bz_co_order( (int) $row['order_id'] )->update_status( 'cancelled', 'Test: staff cancelled the pending order.' );
    $r     = bz_co_post( bz_co_body( $key, $one, bz_co_cash( $T ) ) );
    $again = bz_co_post( bz_co_body( $key, $one, bz_co_cash( $T ) ) );
    bz_co_say( 'finalize_closed', implode( ' ', [ $r['status'], $r['data']['code'] ?? '-', bz_co_row( $key )['state'], bz_co_order( (int) $row['order_id'] )->get_status(), $stock - bz_co_stock( $A ), bz_co_payments( (int) $row['id'] ), var_export( $again['data']['data']['replayed'] ?? null, true ) ] ) );

    // Another finalizer holds the row's lock (a second connection): a same-key retry waits, answers 409, takes no lease; then completes.
    $key = bz_co_key();
    bz_co_fail( 'after_commit' );
    bz_co_post( bz_co_body( $key, $one, bz_co_cash( $T ) ) );
    bz_co_fail( '' );
    $row   = bz_co_row( $key );
    $host  = $wpdb->parse_db_host( DB_HOST );
    $other = mysqli_connect( $host[0], DB_USER, DB_PASSWORD, DB_NAME, $host[1] ? (int) $host[1] : 3306 );
    $held  = $other ? mysqli_query( $other, "SELECT GET_LOCK('" . Xophz_Compass_Bazaar_Checkout_Repository::finalize_lock_name( (int) $row['id'] ) . "', 0)" )->fetch_row()[0] : '-';
    $busy  = bz_co_post( bz_co_body( $key, $one, bz_co_cash( $T ) ) );
    $mid   = bz_co_row( $key );
    if ( $other ) {
      mysqli_close( $other );
    }
    $done = bz_co_post( bz_co_body( $key, $one, bz_co_cash( $T ) ) );
    bz_co_say( 'finalize_locked', implode( ' ', [ $held, $busy['status'], $busy['data']['code'] ?? '-', $mid['state'], $mid['lease_token'] === $row['lease_token'] ? 'token-kept' : 'token-taken', $done['status'], $done['data']['state'] ?? '-', bz_co_moves( (int) $row['order_id'] ), bz_co_payments( (int) $row['id'] ) ] ) );

    // Every transition to an in-flight state renews the lease; renew_lease refuses a foreign token.
    $lk = bz_co_key();
    $lr = Xophz_Compass_Bazaar_Checkout_Repository::claim( [ 'client_key' => $lk, 'created_by' => $admin, 'request_hash' => str_repeat( '1', 64 ), 'operator_user_id' => $admin, 'cashier_user_id' => $admin, 'currency' => get_woocommerce_currency() ] )['row'];
    $wpdb->query( $wpdb->prepare( "UPDATE $ct SET lease_until = UTC_TIMESTAMP() + INTERVAL 5 SECOND WHERE id = %d", (int) $lr['id'] ) );
    $pr   = Xophz_Compass_Bazaar_Checkout_Repository::transition( Xophz_Compass_Bazaar_Checkout_Repository::find( (int) $lr['id'] ), Xophz_Compass_Bazaar_Checkout_State::PRICED );
    $left = (int) $wpdb->get_var( $wpdb->prepare( "SELECT TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), lease_until) FROM $ct WHERE id = %d", (int) $lr['id'] ) );
    $bad  = Xophz_Compass_Bazaar_Checkout_Repository::renew_lease( array_merge( $pr, [ 'lease_token' => 'not-mine' ] ) );
    Xophz_Compass_Bazaar_Checkout_Repository::transition( $pr, Xophz_Compass_Bazaar_Checkout_State::FAILED, Xophz_Compass_Bazaar_Checkout_Service::error_changes( Xophz_Compass_Bazaar_Checkout_Service::interrupted(), false ) );
    bz_co_say( 'lease_renewal', ( $left >= 55 ? 'renewed' : "left=$left" ) . ' ' . ( null === $bad ? 'foreign-refused' : 'foreign-renewed' ) );

    // Stock note of a sale finalized after a later sale: from/to as of its own commit.
    $s0 = bz_co_stock( $A );
    $k1 = bz_co_key();
    bz_co_fail( 'after_commit' );
    bz_co_post( bz_co_body( $k1, $one, bz_co_cash( $T ) ) );
    bz_co_fail( '' );
    bz_co_post( bz_co_body( bz_co_key(), $one, bz_co_cash( $T ) ) );
    bz_co_post( bz_co_body( $k1, $one, bz_co_cash( $T ) ) );
    $o1    = bz_co_order( (int) bz_co_row( $k1 )['order_id'] );
    $rec   = json_decode( (string) $o1->get_meta( '_bazaar_stock_changes' ), true )[0] ?? [];
    $notes = implode( ' | ', wp_list_pluck( wc_get_order_notes( [ 'order_id' => $o1->get_id() ] ), 'content' ) );
    $want  = wc_stock_amount( $s0 ) . '&rarr;' . wc_stock_amount( $s0 - 1 );
    bz_co_say( 'stock_note', implode( ' ', [ (float) ( $rec['from'] ?? -1 ) === $s0 && (float) ( $rec['to'] ?? -1 ) === $s0 - 1 ? 'recorded' : wp_json_encode( [ $s0, $rec ] ), false !== strpos( $notes, $want ) || false !== strpos( $notes, html_entity_decode( $want ) ) ? 'note' : 'no-note', $s0 - bz_co_stock( $A ) ] ) );
    bz_co_ledger( false );
  }
}

switch ( (string) getenv( 'BZ_ACTION' ) ) {
  case 'setup':
    echo 'STATE ' . wp_json_encode( bz_co_setup() ) . "\n";
    break;
  case 'crash':
    $key  = (string) getenv( 'BZ_KEY' );
    $keys = (array) get_option( 'bazaar_checkout_v2_test_keys', [] );
    update_option( 'bazaar_checkout_v2_test_keys', array_merge( $keys, [ $key ] ), false );
    $one   = [ 'lines' => [ [ 'type' => 'product', 'product_id' => (int) $bz_state['products']['a'], 'quantity' => 1 ] ] ];
    $total = (int) bz_co_quote( $one )['totals']['total_minor'];
    add_filter( Xophz_Compass_Bazaar_Checkout_Service::FAIL_FILTER, function ( $hit, $at ) use ( $key ) {
      if ( 'after_order_created' === $at ) {
        echo 'CRASHED ' . (int) bz_co_row( $key )['id'] . "\n";
        exit( 0 );
      }
      return $hit;
    }, 10, 2 );
    bz_co_post( bz_co_body( $key, $one, bz_co_cash( $total ) ) );
    echo "NOT CRASHED\n";
    break;
  case 'run':
    bz_co_run( $bz_state, $bz_admin );
    break;
  case 'teardown':
    bz_co_teardown( $bz_state );
    echo "TORN DOWN\n";
    break;
  default:
    echo "ERROR unknown BZ_ACTION\n";
}

