<?php
/**
 * Fixtures and the parity check for tax-quote.sh. Not a plugin file: it is
 * copied into the WordPress container and run with wp-cli.
 *
 * Two roles:
 * 1. Loaded with --require (before WordPress): BZ_PRICES_INCLUDE_TAX and
 *    BZ_ROUND_AT_SUBTOTAL (yes/no) become this process's WooCommerce tax
 *    settings through pre_option hooks, and WC_TAX_ROUNDING_MODE is defined
 *    the way WooCommerce defines it for that price entry. Nothing is written
 *    to the options table, so other processes keep the store's settings.
 *    Taxes are switched on for this process only.
 * 2. Run with eval-file, BZ_ACTION = setup | run | checks | teardown.
 *    - setup:    tax class bazaar-test with two base-location rates (8.25%,
 *                and 1.5% compound), one standard-class rate for custom
 *                lines, three private products, two coupons, a subscriber.
 *                Prints "STATE <json>".
 *    - run:      for every combination of coupon, custom discount, custom
 *                line and tip, POST /bazaar/v1/pos/quote and compare it with
 *                a reference order WooCommerce prices itself (add_product,
 *                calculate_totals, apply_coupon, calculate_totals); the
 *                order is trashed, then deleted. Prints "OK <combo>" or
 *                "MISMATCH <combo> <details>".
 *    - checks:   refusals, permissions, no writes, the config route and the
 *                order tax-location filter. Prints "CHECK <name> <value>".
 *    - teardown: deletes everything setup created (BZ_STATE).
 */

if ( ! function_exists( 'wc_get_product' ) ) {
  if ( class_exists( 'WP_CLI' ) && false !== getenv( 'BZ_PRICES_INCLUDE_TAX' ) ) {
    $bz_include = 'yes' === getenv( 'BZ_PRICES_INCLUDE_TAX' ) ? 'yes' : 'no';
    $bz_round   = 'yes' === getenv( 'BZ_ROUND_AT_SUBTOTAL' ) ? 'yes' : 'no';
    if ( ! defined( 'WC_TAX_ROUNDING_MODE' ) ) {
      define( 'WC_TAX_ROUNDING_MODE', 'yes' === $bz_include ? 2 : 1 );
    }
    // Print fatal errors as text: a site error template would hide them.
    WP_CLI::add_wp_hook( 'wp_die_handler', function () {
      return function ( $message ) {
        if ( is_wp_error( $message ) ) {
          $data    = $message->get_error_data();
          $message = $message->get_error_message() . ( isset( $data['error']['message'] ) ? ' ' . $data['error']['message'] . ' at ' . basename( (string) $data['error']['file'] ) . ':' . $data['error']['line'] : '' );
        }
        echo 'ERROR wp_die: ' . ( is_scalar( $message ) ? $message : 'unknown' ) . "\n";
        exit( 1 );
      };
    }, 10000 );
    WP_CLI::add_wp_hook( 'pre_option_woocommerce_calc_taxes', function () { return 'yes'; } );
    WP_CLI::add_wp_hook( 'pre_option_woocommerce_prices_include_tax', function () use ( $bz_include ) { return $bz_include; } );
    WP_CLI::add_wp_hook( 'pre_option_woocommerce_tax_round_at_subtotal', function () use ( $bz_round ) { return $bz_round; } );
  }
  return;
}
if ( defined( 'BZ_TAX_QUOTE_FIXTURES' ) ) {
  return;
}
define( 'BZ_TAX_QUOTE_FIXTURES', true );

$bz_state = json_decode( (string) getenv( 'BZ_STATE' ), true ) ?: [];
$bz_admin = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
wp_set_current_user( (int) ( $bz_admin[0] ?? 0 ) );

// Declared conditionally: a top-level function would already be declared by
// the --require pass (PHP hoists them) and eval-file would redeclare it.
if ( ! function_exists( 'bz_base_location' ) ) {
  /** The four-element base location WC_Tax takes. */
  function bz_base_location(): array {
    return [ WC()->countries->get_base_country(), WC()->countries->get_base_state(), WC()->countries->get_base_postcode(), WC()->countries->get_base_city() ];
  }

  function bz_rest( string $method, string $route, $body = null, ?int $user = null ): array {
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

  function bz_minor( $amount ): int {
    return Xophz_Compass_Bazaar_Core_Money::to_minor( (string) wc_format_decimal( $amount, wc_get_price_decimals() ), wc_get_price_decimals() );
  }

  function bz_tax_minor( $amount ): int {
    return bz_minor( wc_round_tax_total( (float) $amount ) );
  }

  function bz_setup(): array {
    $state = [ 'rates' => [], 'products' => [], 'coupons' => [], 'user' => 0 ];
    if ( ! in_array( 'bazaar-test', WC_Tax::get_tax_class_slugs(), true ) ) {
      $class = WC_Tax::create_tax_class( 'Bazaar Test', 'bazaar-test' );
      if ( is_wp_error( $class ) ) {
        throw new RuntimeException( 'tax class: ' . $class->get_error_message() );
      }
    }
    $loc  = bz_base_location();
    $rate = function ( string $class, string $name, string $percent, int $priority, bool $compound ) use ( $loc ) {
      return (int) WC_Tax::_insert_tax_rate( [
        'tax_rate_country'  => $loc[0],
        'tax_rate_state'    => $loc[1],
        'tax_rate'          => $percent,
        'tax_rate_name'     => $name,
        'tax_rate_priority' => $priority,
        'tax_rate_compound' => $compound ? 1 : 0,
        'tax_rate_shipping' => 1,
        'tax_rate_order'    => 0,
        'tax_rate_class'    => $class,
      ] );
    };
    $state['rates'][] = $rate( 'bazaar-test', 'BZ State', '8.2500', 1, false );
    $state['rates'][] = $rate( 'bazaar-test', 'BZ City', '1.5000', 2, true );
    $state['rates'][] = $rate( '', 'BZ Standard', '7.1250', 1, false );

    $suffix  = strtolower( wp_generate_password( 6, false ) );
    $product = function ( string $name, string $price, string $class, string $status ) {
      $p = new WC_Product_Simple();
      $p->set_name( $name );
      $p->set_status( 'private' );
      $p->set_regular_price( $price );
      $p->set_tax_class( $class );
      $p->set_tax_status( $status );
      $p->set_manage_stock( false );
      return (int) $p->save();
    };
    $state['products'][] = $product( 'Bazaar tax-quote A', '12.34', 'bazaar-test', 'taxable' );
    $state['products'][] = $product( 'Bazaar tax-quote B', '7.99', 'bazaar-test', 'taxable' );
    $state['products'][] = $product( 'Bazaar tax-quote C', '5.00', '', 'none' );

    $coupon = function ( string $code, string $type, string $amount ) {
      $c = new WC_Coupon();
      $c->set_code( $code );
      $c->set_discount_type( $type );
      $c->set_amount( $amount );
      return [ 'id' => (int) $c->save(), 'code' => $code ];
    };
    $state['coupons']['percent']    = $coupon( 'bz-tq-pct-' . $suffix, 'percent', '15' );
    $state['coupons']['fixed_cart'] = $coupon( 'bz-tq-cart-' . $suffix, 'fixed_cart', '5.00' );

    $state['user'] = (int) wp_insert_user( [ 'user_login' => 'bz-tq-sub-' . $suffix, 'user_pass' => wp_generate_password( 24 ), 'role' => 'subscriber' ] );
    return $state;
  }

  function bz_teardown( array $state ): void {
    foreach ( $state['coupons'] ?? [] as $coupon ) {
      wp_delete_post( (int) $coupon['id'], true );
    }
    foreach ( $state['products'] ?? [] as $id ) {
      $p = wc_get_product( (int) $id );
      if ( $p ) {
        $p->delete( true );
      }
    }
    foreach ( $state['rates'] ?? [] as $id ) {
      WC_Tax::_delete_tax_rate( (int) $id );
    }
    WC_Tax::delete_tax_class_by( 'slug', 'bazaar-test' );
    if ( ! empty( $state['user'] ) ) {
      require_once ABSPATH . 'wp-admin/includes/user.php';
      wp_delete_user( (int) $state['user'] );
    }
  }

  /** The quote request and the reference recipe for one combination. */
  function bz_combo( array $state, string $coupon, bool $discount, bool $custom, int $tip ): array {
    $lines = [
      [ 'type' => 'product', 'product_id' => $state['products'][0], 'quantity' => 2 ],
      [ 'type' => 'product', 'product_id' => $state['products'][1], 'quantity' => 3 ],
      [ 'type' => 'product', 'product_id' => $state['products'][2], 'quantity' => 1 ],
    ];
    if ( $custom ) {
      $lines[] = [ 'type' => 'custom', 'name' => 'Gift wrap', 'unit_price_minor' => 499, 'quantity' => 1, 'taxable' => true ];
    }
    return [
      'lines'     => $lines,
      'coupons'   => 'none' === $coupon ? [] : [ $state['coupons'][ $coupon ]['code'] ],
      'discounts' => $discount ? [ [ 'name' => 'Loyalty', 'type' => 'percent', 'value_bp' => 1250 ] ] : [],
      'tip_minor' => $tip,
    ];
  }

  /** Price $cart the WooCommerce way on a real (pending) order; returns the comparable numbers. */
  function bz_reference( array $cart ): array {
    $order = wc_create_order( [ 'status' => 'pending' ] );
    foreach ( $cart['lines'] as $line ) {
      if ( 'product' === $line['type'] ) {
        $order->add_product( wc_get_product( $line['product_id'] ), $line['quantity'] );
        continue;
      }
      $gross = $line['unit_price_minor'] * $line['quantity'] / 100;
      $ex    = wc_prices_include_tax() ? $gross - array_sum( WC_Tax::calc_tax( $gross, WC_Tax::get_base_tax_rates( '' ), true ) ) : $gross;
      $item  = new WC_Order_Item_Product();
      $item->set_name( $line['name'] );
      $item->set_quantity( $line['quantity'] );
      $item->set_tax_class( '' );
      $item->set_subtotal( $ex );
      $item->set_total( $ex );
      $order->add_item( $item );
    }
    $order->calculate_totals( true );
    foreach ( $cart['coupons'] as $code ) {
      $applied = $order->apply_coupon( $code );
      if ( true !== $applied ) {
        throw new RuntimeException( 'reference coupon: ' . ( is_wp_error( $applied ) ? $applied->get_error_message() : 'failed' ) );
      }
    }
    foreach ( $cart['discounts'] as $discount ) {
      bz_reference_discount( $order, $cart['coupons'], (int) $discount['value_bp'] );
    }
    if ( $cart['tip_minor'] > 0 ) {
      $fee = new WC_Order_Item_Fee();
      $fee->set_name( 'Tip' );
      $fee->set_tax_status( 'none' );
      $fee->set_total( $cart['tip_minor'] / 100 );
      $order->add_item( $fee );
    }
    $order->calculate_totals( true );

    $rates = [];
    foreach ( $order->get_taxes() as $tax ) {
      $rates[ (int) $tax->get_rate_id() ] = bz_tax_minor( $tax->get_tax_total() );
    }
    ksort( $rates );
    $coupons = [];
    foreach ( $order->get_items( 'coupon' ) as $item ) {
      $coupons[ $item->get_code() ] = bz_minor( round( (float) $item->get_discount(), wc_get_price_decimals() ) );
    }
    $out = [
      'total'    => bz_minor( $order->get_total() ),
      'tax'      => bz_tax_minor( $order->get_total_tax() ),
      'discount' => bz_minor( $order->get_discount_total() ),
      'rates'    => $rates,
      'coupons'  => $coupons,
    ];
    $order->delete( false );
    $order->delete( true );
    return $out;
  }

  /**
   * A register discount the way Bazaar defines it (largest-remainder split of
   * percent_bp of what is left after coupons, in the store's price basis),
   * written as reduced line totals; WooCommerce then computes tax and totals.
   */
  function bz_reference_discount( WC_Order $order, array $codes, int $bp ): void {
    $discounts = new WC_Discounts( $order );
    foreach ( $codes as $code ) {
      $discounts->apply_coupon( new WC_Coupon( $code ), false );
    }
    $by_item = $discounts->get_discounts_by_item( true );
    $items   = $discounts->get_items();
    $weights = [];
    foreach ( $order->get_items() as $id => $item ) {
      $weights[ $id ] = max( 0, (int) round( $items[ $id ]->price ) - (int) round( (float) ( $by_item[ $id ] ?? 0 ) ) );
    }
    $amount = Xophz_Compass_Bazaar_Core_Money::percent_bp( array_sum( $weights ), $bp );
    $parts  = array_combine( array_keys( $weights ), Xophz_Compass_Bazaar_Core_Money::allocate( $amount, array_values( $weights ) ) );
    foreach ( $order->get_items() as $id => $item ) {
      $part = $parts[ $id ] / 100;
      if ( wc_prices_include_tax() && 'taxable' === $item->get_tax_status() ) {
        $part -= array_sum( WC_Tax::calc_tax( $part, WC_Tax::get_rates_from_location( $item->get_tax_class(), bz_base_location() ), true ) );
      }
      $item->set_total( max( 0, (float) $item->get_total() - $part ) );
    }
  }

  function bz_from_quote( array $quote ): array {
    $rates = [];
    foreach ( $quote['tax_lines'] as $line ) {
      $rates[ (int) $line['rate_id'] ] = (int) $line['amount_minor'];
    }
    ksort( $rates );
    $coupons = [];
    foreach ( $quote['coupons'] as $coupon ) {
      $coupons[ $coupon['code'] ] = (int) $coupon['discount_minor'];
    }
    return [
      'total'    => (int) $quote['totals']['total_minor'],
      'tax'      => (int) $quote['totals']['tax_minor'],
      'discount' => (int) $quote['totals']['discount_minor'],
      'rates'    => $rates,
      'coupons'  => $coupons,
    ];
  }

  function bz_run( array $state ): void {
    $mode = 'P=' . get_option( 'woocommerce_prices_include_tax' ) . ',R=' . get_option( 'woocommerce_tax_round_at_subtotal' );
    foreach ( [ 'none', 'percent', 'fixed_cart' ] as $coupon ) {
      foreach ( [ false, true ] as $discount ) {
        foreach ( [ false, true ] as $custom ) {
          foreach ( [ 0, 250 ] as $tip ) {
            $name  = "$mode,coupon=$coupon,discount=" . (int) $discount . ',custom=' . (int) $custom . ",tip=$tip";
            $cart  = bz_combo( $state, $coupon, $discount, $custom, $tip );
            $quote = bz_rest( 'POST', '/bazaar/v1/pos/quote', $cart );
            if ( 200 !== $quote['status'] ) {
              echo "MISMATCH $name status=" . $quote['status'] . ' ' . wp_json_encode( $quote['data'] ) . "\n";
              continue;
            }
            $mine = bz_from_quote( $quote['data'] );
            $ref  = bz_reference( $cart );
            $sums = $quote['data']['totals'];
            $same = $mine === $ref && ( 'yes' === get_option( 'woocommerce_tax_round_at_subtotal' ) || $sums['subtotal_minor'] - $sums['discount_minor'] + $sums['tax_minor'] + $sums['tip_minor'] === $sums['total_minor'] );
            echo ( $same ? 'OK' : 'MISMATCH' ) . " $name" . ( $same ? '' : ' quote=' . wp_json_encode( $mine ) . ' ref=' . wp_json_encode( $ref ) . ' totals=' . wp_json_encode( $sums ) ) . "\n";
          }
        }
      }
    }
  }

  function bz_checks( array $state ): void {
    $line  = [ 'type' => 'product', 'product_id' => $state['products'][0], 'quantity' => 1 ];
    $check = function ( string $name, $value ) {
      echo "CHECK $name " . ( is_string( $value ) ? $value : wp_json_encode( $value ) ) . "\n";
    };

    $r = bz_rest( 'POST', '/bazaar/v1/pos/quote', [ 'lines' => [ $line ], 'coupons' => [ 'bz-tq-no-such-coupon' ] ] );
    $check( 'invalid_coupon', $r['status'] . ' ' . wp_json_encode( [ $r['data']['coupons'][0]['valid'] ?? null, $r['data']['coupons'][0]['message'] ?? '' ] ) );
    $r = bz_rest( 'POST', '/bazaar/v1/pos/quote', [ 'lines' => [ array_merge( $line, [ 'price_override_minor' => 1 ] ) ] ] );
    $check( 'price_override', $r['status'] . ' ' . ( $r['data']['code'] ?? '' ) );
    $r = bz_rest( 'POST', '/bazaar/v1/pos/quote', [ 'lines' => [] ] );
    $check( 'bad_request', $r['status'] . ' ' . ( $r['data']['code'] ?? '' ) . ' ' . wp_json_encode( array_column( $r['data']['data']['errors'] ?? [], 'path' ) ) );
    $r = bz_rest( 'POST', '/bazaar/v1/pos/quote', '{not json' );
    $check( 'malformed_json', $r['status'] . ' ' . ( $r['data']['code'] ?? '' ) );
    $r = bz_rest( 'POST', '/bazaar/v1/pos/quote', [ 'lines' => [ $line, [ 'type' => 'product', 'product_id' => PHP_INT_MAX, 'quantity' => 1 ] ] ] );
    $check( 'missing_product', $r['status'] . ' ' . ( $r['data']['code'] ?? '' ) . ' line=' . ( $r['data']['data']['line'] ?? '' ) );
    $r = bz_rest( 'POST', '/bazaar/v1/pos/quote', [ 'lines' => [ $line ] ], 0 );
    $check( 'logged_out', $r['status'] . ' ' . ( $r['data']['code'] ?? '' ) );
    $r = bz_rest( 'POST', '/bazaar/v1/pos/quote', [ 'lines' => [ $line ] ], (int) $state['user'] );
    $check( 'subscriber', $r['status'] . ' ' . ( $r['data']['code'] ?? '' ) );

    // A quote writes no orders and leaves coupon usage alone.
    $orders = function () { return count( wc_get_orders( [ 'limit' => -1, 'return' => 'ids', 'status' => array_keys( wc_get_order_statuses() ) ] ) ); };
    $usage  = function () use ( $state ) { return ( new WC_Coupon( $state['coupons']['percent']['id'] ) )->get_usage_count(); };
    $before = [ $orders(), $usage() ];
    $r      = bz_rest( 'POST', '/bazaar/v1/pos/quote', [ 'lines' => [ $line ], 'coupons' => [ $state['coupons']['percent']['code'] ] ] );
    $check( 'no_writes', $r['status'] . ' ' . ( $before === [ $orders(), $usage() ] ? 'unchanged' : 'changed ' . wp_json_encode( [ $before, [ $orders(), $usage() ] ] ) ) );
    $check( 'quote_shape', implode( ',', array_keys( $r['data'] ) ) );
    $check( 'line_shape', implode( ',', array_keys( $r['data']['lines'][0] ?? [] ) ) );
    $check( 'quote_hash', strlen( (string) ( $r['data']['quote_hash'] ?? '' ) ) . ' ' . ( $r['data']['tax_location']['source'] ?? '' ) );

    $r = bz_rest( 'GET', '/bazaar/v1/pos/config' );
    $check( 'config_shape', $r['status'] . ' ' . implode( ',', array_keys( $r['data'] ) ) );
    $check( 'config_features', implode( ',', array_keys( $r['data']['features'] ?? [] ) ) );
    $check( 'config_actor', ( in_array( 'bazaar_sell', $r['data']['actor']['caps'] ?? [], true ) ? 'sell' : 'no-sell' ) . ' ' . ( $r['data']['actor']['via'] ?? '' ) . ' tenders=' . count( $r['data']['tenders'] ?? [] ) );
    $r = bz_rest( 'GET', '/bazaar/v1/pos/config', null, (int) $state['user'] );
    $check( 'config_subscriber', $r['status'] . ' ' . ( $r['data']['code'] ?? '' ) );

    // The order tax-location filter: a POS order keeps the store base, others are untouched.
    $away = [ 'country' => 'CA', 'state' => 'BC', 'postcode' => 'V5K 0A1', 'city' => 'Vancouver' ];
    $pos  = new WC_Order();
    $pos->add_meta_data( '_bazaar_checkout_id', '42' );
    $web  = new WC_Order();
    $base = bz_base_location();
    $check( 'tax_location_pos', Xophz_Compass_Bazaar_Pricing_Tax_Location::filter_order_location( $away, $pos )['country'] === $base[0] ? 'store_base' : 'customer' );
    $check( 'tax_location_web', Xophz_Compass_Bazaar_Pricing_Tax_Location::filter_order_location( $away, $web )['country'] );
    $check( 'tax_location_hooked', has_filter( 'woocommerce_order_get_tax_location', [ 'Xophz_Compass_Bazaar_Pricing_Tax_Location', 'filter_order_location' ] ) ? 'yes' : 'no' );
  }
}

$bz_action = (string) getenv( 'BZ_ACTION' );
try {
  if ( 'setup' === $bz_action ) {
    echo 'STATE ' . wp_json_encode( bz_setup() ) . "\n";
  } elseif ( 'run' === $bz_action ) {
    bz_run( $bz_state );
  } elseif ( 'checks' === $bz_action ) {
    bz_checks( $bz_state );
  } elseif ( 'teardown' === $bz_action ) {
    bz_teardown( $bz_state );
    echo "TEARDOWN done\n";
  } else {
    echo "Unknown BZ_ACTION\n";
  }
} catch ( Throwable $e ) {
  echo 'ERROR ' . get_class( $e ) . ': ' . $e->getMessage() . ' at ' . basename( $e->getFile() ) . ':' . $e->getLine() . "\n";
}
