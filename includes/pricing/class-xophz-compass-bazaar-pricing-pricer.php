<?php
/**
 * The one server pricing engine, for the quote and for checkout.
 *
 * Spec: docs/plugins/bazaar-core-v2.md section 9.2. price() runs:
 * 1. Catalog lines from wc_get_product(), refused with 422 invalid_line
 *    (data.line = the request index) when the product is missing, a
 *    variable parent, or not purchasable; custom lines need bazaar_sell.
 * 2. Coupons through Pricing_Coupon_Engine (WC_Discounts::set_items).
 * 3. Custom discounts as line-level reductions after the coupons
 *    (Pricing_Discount_Math).
 * 4. Taxes through Pricing_Tax_Calculator at Pricing_Tax_Location.
 * 5. The tip as an untaxed, undiscounted fee.
 * 6. approvals_needed from Staff_Limits, when that class is installed.
 * 7. Advisory stock per line.
 *
 * It writes nothing, with one exception: in ledger mode, reading stock
 * through Stock_Service records the opening balance of a product that has
 * none yet (the ledger's lazy initialization). It does not reserve stock or
 * check that a sale is allowed; checkout does.
 *
 * @package Xophz_Compass_Bazaar
 */
final class Xophz_Compass_Bazaar_Pricing_Pricer {

  /** Seconds a quote is advertised as fresh (advisory). */
  const QUOTE_TTL = 600;

  /** @var Xophz_Compass_Bazaar_Pricing_Tax_Calculator|null */
  private $calculator;
  /** @var Xophz_Compass_Bazaar_Pricing_Coupon_Engine */
  private $coupons;
  /** @var callable(): int */
  private $clock;

  public function __construct( ?Xophz_Compass_Bazaar_Pricing_Tax_Calculator $calculator = null, ?Xophz_Compass_Bazaar_Pricing_Coupon_Engine $coupons = null, ?callable $clock = null ) {
    $this->calculator = $calculator;
    $this->coupons    = $coupons ?? new Xophz_Compass_Bazaar_Pricing_Coupon_Engine();
    $this->clock      = $clock ?? 'time';
  }

  /** @throws Xophz_Compass_Bazaar_Core_Http_Error 422 invalid_line, 403 forbidden. */
  public function price( Xophz_Compass_Bazaar_Pricing_Cart_Request $request, Xophz_Compass_Bazaar_Security_Context $context ): Xophz_Compass_Bazaar_Pricing_Priced_Cart {
    $calc     = $this->calculator ?? Xophz_Compass_Bazaar_Pricing_Tax_Calculator::from_woocommerce();
    $taxes_on = function_exists( 'wc_tax_enabled' ) && wc_tax_enabled();
    $location = Xophz_Compass_Bazaar_Pricing_Tax_Location::for_location( $request->location_id );
    $lines    = $this->build_lines( $request, $context, $calc, $taxes_on, $location );

    $bases = [];
    foreach ( $lines as $key => $line ) {
      $bases[ $key ] = $calc->line_subtotal( $line );
    }
    $coupons = $this->coupons->apply( $request->coupons, $this->coupon_items( $lines, $bases, $calc ), $request->customer, $calc->prices_include_tax() );

    $remaining = [];
    foreach ( $lines as $key => $line ) {
      $remaining[ $key ] = max( 0, (int) round( $bases[ $key ]['discount_price'] ) - $coupons['by_line'][ $key ] );
    }
    $custom = Xophz_Compass_Bazaar_Pricing_Discount_Math::apply_all( $request->discounts, $remaining );

    foreach ( $lines as $key => &$line ) {
      $line['discounts'] = [ $coupons['by_line'][ $key ], $custom['by_line'][ $key ] ];
    }
    unset( $line );
    $priced = $calc->price_lines( $lines );

    $warnings = [];
    $out      = [];
    foreach ( $lines as $key => $line ) {
      $out[] = $this->priced_line( $line, $priced['lines'][ $key ], $calc, $request->location_id, $warnings );
    }
    $tip = $request->tip_minor;
    return new Xophz_Compass_Bazaar_Pricing_Priced_Cart( [
      'request'            => $request,
      'currency'           => function_exists( 'get_woocommerce_currency' ) ? (string) get_woocommerce_currency() : '',
      'decimals'           => $calc->decimals(),
      'tax_enabled'        => $taxes_on,
      'prices_include_tax' => $calc->prices_include_tax(),
      'round_at_subtotal'  => $calc->round_at_subtotal(),
      'tax_location'       => $location,
      'lines'              => $out,
      'coupons'            => $this->coupon_rows( $coupons['coupons'], $lines, $calc ),
      'discounts'          => $custom['discounts'],
      'tax_lines'          => $this->tax_lines( $lines, $priced['rates'] ),
      'totals'             => [
        'subtotal_minor' => $priced['subtotal_minor'],
        'discount_minor' => $priced['discount_minor'],
        'tax_minor'      => $priced['tax_minor'],
        'tip_minor'      => $tip,
        'total_minor'    => $priced['total_minor'] + $tip,
      ],
      'approvals'          => $this->approvals( $context, $lines, $custom, $warnings ),
      'warnings'           => $warnings,
      'fingerprint'        => $this->fingerprint( $calc, $taxes_on, $location, $lines ),
      'expires_at'         => gmdate( 'Y-m-d\TH:i:s\Z', (int) call_user_func( $this->clock ) + self::QUOTE_TTL ),
    ] );
  }

  private function build_lines( Xophz_Compass_Bazaar_Pricing_Cart_Request $request, Xophz_Compass_Bazaar_Security_Context $context, Xophz_Compass_Bazaar_Pricing_Tax_Calculator $calc, bool $taxes_on, array $location ): array {
    $lines = [];
    $rates = [];
    foreach ( $request->lines as $i => $req ) {
      if ( 'custom' === $req['type'] ) {
        $line = $this->custom_line( $req, $i, $context );
      } else {
        $line = $this->catalog_line( $req, $i, $calc->decimals() );
      }
      $qty = (int) $req['quantity'];
      if ( $line['unit_price_minor'] > intdiv( PHP_INT_MAX, $qty ) ) {
        self::invalid_line( $i, 'amount_too_large', 'The line amount is too large.' );
      }
      $line['key']         = 'l' . $i;
      $line['gross_minor'] = $line['unit_price_minor'] * $qty;
      $line['taxable']     = $taxes_on && $line['taxable'];
      $class               = $line['tax_class'];
      if ( $line['taxable'] ) {
        $rates[ $class ]    = $rates[ $class ] ?? WC_Tax::get_rates_from_location( $class, Xophz_Compass_Bazaar_Pricing_Tax_Location::as_rate_location( $location ) );
        $line['rates']      = (array) $rates[ $class ];
        $line['base_rates'] = $calc->prices_include_tax() ? $this->base_rates( $line ) : $line['rates'];
      } else {
        $line['rates']      = [];
        $line['base_rates'] = [];
      }
      $lines[ 'l' . $i ] = $line;
    }
    return $lines;
  }

  /** wc_get_price_excluding_tax(): inclusive prices lose the base-location tax unless the filter says otherwise. */
  private function base_rates( array $line ): array {
    if ( ! apply_filters( 'woocommerce_adjust_non_base_location_prices', true ) ) {
      return $line['rates'];
    }
    return (array) WC_Tax::get_base_tax_rates( $line['base_tax_class'] );
  }

  private function catalog_line( array $req, int $i, int $decimals ): array {
    $id      = $req['variation_id'] > 0 ? $req['variation_id'] : $req['product_id'];
    $product = function_exists( 'wc_get_product' ) ? wc_get_product( $id ) : null;
    if ( ! $product ) {
      self::invalid_line( $i, 'not_found', 'The product does not exist.' );
    }
    if ( $req['variation_id'] > 0 && ( ! $product->is_type( 'variation' ) || (int) $product->get_parent_id() !== $req['product_id'] ) ) {
      self::invalid_line( $i, 'not_found', 'The variation does not belong to this product.' );
    }
    if ( $product->is_type( 'variable' ) ) {
      self::invalid_line( $i, 'variable_parent', 'Choose a variation of this product.' );
    }
    if ( ! $product->is_purchasable() || '' === (string) $product->get_price() ) {
      self::invalid_line( $i, 'not_purchasable', 'This product cannot be sold.' );
    }
    $is_variation = $product->is_type( 'variation' );
    return [
      'type'             => 'product',
      'product'          => $product,
      'product_id'       => $is_variation ? (int) $product->get_parent_id() : (int) $product->get_id(),
      'variation_id'     => $is_variation ? (int) $product->get_id() : 0,
      'name'             => (string) $product->get_name(),
      'sku'              => (string) $product->get_sku(),
      'quantity'         => (int) $req['quantity'],
      'unit_price_minor' => Xophz_Compass_Bazaar_Core_Money::to_minor( (string) $product->get_price(), $decimals ),
      'taxable'          => 'taxable' === $product->get_tax_status(),
      'tax_class'        => (string) $product->get_tax_class(),
      'base_tax_class'   => (string) $product->get_tax_class( 'unfiltered' ),
    ];
  }

  private function custom_line( array $req, int $i, Xophz_Compass_Bazaar_Security_Context $context ): array {
    if ( ! user_can( $context->acting_user_id, Xophz_Compass_Bazaar_Security_Capabilities::SELL ) ) {
      throw new Xophz_Compass_Bazaar_Core_Http_Error( 'forbidden', 'Custom items need the bazaar_sell capability.', 403, [ 'line' => $i ] );
    }
    return [
      'type'             => 'custom',
      'product'          => null,
      'product_id'       => 0,
      'variation_id'     => 0,
      'name'             => $req['name'],
      'sku'              => '',
      'quantity'         => (int) $req['quantity'],
      'unit_price_minor' => (int) $req['unit_price_minor'],
      'taxable'          => (bool) $req['taxable'],
      'tax_class'        => '',
      'base_tax_class'   => '',
    ];
  }

  private function coupon_items( array $lines, array $bases, Xophz_Compass_Bazaar_Pricing_Tax_Calculator $calc ): array {
    $items = [];
    foreach ( $lines as $key => $line ) {
      $items[ $key ] = [
        'product'      => $line['product'],
        'name'         => $line['name'],
        'quantity'     => $line['quantity'],
        'price'        => $bases[ $key ]['discount_price'],
        'subtotal'     => $calc->to_decimal( $bases[ $key ]['subtotal_ex'] ),
        'subtotal_tax' => $calc->to_decimal( $bases[ $key ]['subtotal_tax'] ),
      ];
    }
    return $items;
  }

  /** set_coupon_discount_amounts(): each coupon's tax-exclusive discount and the tax it removed. */
  private function coupon_rows( array $coupons, array $lines, Xophz_Compass_Bazaar_Pricing_Tax_Calculator $calc ): array {
    $rows = [];
    foreach ( $coupons as $coupon ) {
      $tax = 0.0;
      foreach ( $coupon['by_line'] as $key => $amount ) {
        $tax += $calc->discount_tax( (int) $amount, $lines[ $key ]['rates'], $lines[ $key ]['taxable'] );
      }
      $discount = $calc->prices_include_tax() ? $coupon['amount_minor'] - $tax : (float) $coupon['amount_minor'];
      $rows[]   = [
        'code'               => $coupon['code'],
        'valid'              => $coupon['valid'],
        'message'            => $coupon['message'],
        'amount_minor'       => $coupon['amount_minor'],
        'discount_minor'     => $coupon['valid'] ? $calc::round_amount( $discount ) : 0,
        'discount_tax_minor' => $coupon['valid'] ? $calc->round_tax( $tax ) : 0,
        'discount'           => $calc->to_decimal( $coupon['valid'] ? $discount : 0.0 ),
        'discount_tax'       => $calc->to_decimal( $coupon['valid'] ? $tax : 0.0 ),
        'by_line'            => $coupon['by_line'],
      ];
    }
    return $rows;
  }

  private function priced_line( array $line, array $p, Xophz_Compass_Bazaar_Pricing_Tax_Calculator $calc, int $location_id, array &$warnings ): array {
    $dec   = function ( array $taxes ) use ( $calc ) {
      return array_map( [ $calc, 'to_decimal' ], $taxes );
    };
    $stock = 'product' === $line['type'] ? $this->stock( $line['product'], $location_id ) : null;
    $out   = [
      'key'              => $line['key'],
      'type'             => $line['type'],
      'product_id'       => $line['product_id'],
      'variation_id'     => $line['variation_id'],
      'name'             => $line['name'],
      'sku'              => $line['sku'],
      'quantity'         => $line['quantity'],
      'unit_price_minor' => $line['unit_price_minor'],
      'subtotal_minor'   => $p['subtotal_minor'],
      'discount_minor'   => $p['discount_minor'],
      'total_minor'      => $p['total_minor'],
      'tax_minor'        => $p['tax_minor'],
      'taxes'            => $p['taxes'],
      'stock'            => $stock,
      'taxable'          => $line['taxable'],
      'tax_class'        => $line['tax_class'],
      'coupon_discount_minor' => $line['discounts'][0],
      'custom_discount_minor' => $line['discounts'][1],
      'order'            => [
        'subtotal'     => $calc->to_decimal( $p['subtotal_ex'] ),
        'total'        => $calc->to_decimal( $p['total_ex'] ),
        'subtotal_tax' => $calc->to_decimal( $p['subtotal_tax'] ),
        'total_tax'    => $calc->to_decimal( $p['total_tax'] ),
        'taxes'        => [ 'subtotal' => $dec( $p['subtotal_taxes'] ), 'total' => $dec( $p['total_taxes'] ) ],
      ],
    ];
    if ( $stock && $stock['managed'] && null !== $stock['available'] ) {
      $left = $stock['available'] - $line['quantity'];
      if ( $left < 0 ) {
        $warnings[] = [ 'code' => $stock['backorders'] ? 'backorder' : 'insufficient_stock', 'line' => $line['key'] ];
      } elseif ( null !== $stock['low_stock_amount'] && $left <= $stock['low_stock_amount'] ) {
        $warnings[] = [ 'code' => 'low_stock', 'line' => $line['key'] ];
      }
    }
    if ( $out['stock'] ) {
      $out['stock'] = [ 'managed' => $stock['managed'], 'available' => $stock['available'], 'location_id' => $stock['location_id'] ];
    }
    return $out;
  }

  /**
   * Advisory stock for one product: the ledger in ledger mode (Stock_Factory),
   * otherwise WooCommerce's stock (per-location entries for a location-tracked
   * product at a warehouse).
   */
  private function stock( WC_Product $product, int $location_id ): array {
    $id      = (int) $product->get_id();
    $managed = (bool) $product->managing_stock();
    $loc     = class_exists( 'Xophz_Compass_Bazaar_Stock_Locations' )
      ? Xophz_Compass_Bazaar_Stock_Locations::sale_location( $id, $location_id > 0 ? $location_id : null )
      : 0;
    $available = null;
    if ( $managed ) {
      $available = $this->available( $product, $loc );
    }
    $low = $managed && function_exists( 'wc_get_low_stock_amount' ) ? (float) wc_get_low_stock_amount( $product ) : null;
    return [
      'managed'          => $managed,
      'available'        => null === $available ? null : self::qty( $available ),
      'location_id'      => $loc,
      'backorders'       => (bool) $product->backorders_allowed(),
      'low_stock_amount' => $low,
    ];
  }

  /** @return float|null */
  private function available( WC_Product $product, int $loc ) {
    $id = (int) $product->get_id();
    if ( class_exists( 'Xophz_Compass_Bazaar_Stock_Mode' ) && class_exists( 'Xophz_Compass_Bazaar_Stock_Factory' ) && Xophz_Compass_Bazaar_Stock_Mode::is_ledger() ) {
      return Xophz_Compass_Bazaar_Stock_Factory::service()->available( $id, $loc );
    }
    if ( $loc > 0 && class_exists( 'Xophz_Compass_Bazaar_Stock_Locations' ) ) {
      $entries = Xophz_Compass_Bazaar_Stock_Locations::inventory_entries( Xophz_Compass_Bazaar_Stock_Locations::stock_key( $id ) );
      return isset( $entries[ $loc ] ) ? (float) $entries[ $loc ]['qty'] : 0.0;
    }
    $qty = $product->get_stock_quantity();
    return null === $qty ? null : (float) $qty;
  }

  /** @return int|float Whole numbers as int. */
  private static function qty( float $qty ) {
    return floor( $qty ) === $qty && abs( $qty ) < PHP_INT_MAX ? (int) $qty : $qty;
  }

  /** WooCommerce rate rows used by the lines => spec 9.3 tax_lines. */
  private function tax_lines( array $lines, array $amounts ): array {
    $rows = [];
    foreach ( $lines as $line ) {
      foreach ( $line['rates'] as $rate_id => $rate ) {
        if ( isset( $rows[ $rate_id ] ) ) {
          continue;
        }
        $rows[ $rate_id ] = [
          'rate_id'      => (int) $rate_id,
          'label'        => (string) ( $rate['label'] ?? '' ),
          'percent'      => self::percent( (float) ( $rate['rate'] ?? 0 ) ),
          'compound'     => 'yes' === ( $rate['compound'] ?? 'no' ),
          'amount_minor' => (int) ( $amounts[ $rate_id ]['amount_minor'] ?? 0 ),
        ];
      }
    }
    return array_values( $rows );
  }

  private static function percent( float $rate ): string {
    $text = rtrim( rtrim( sprintf( '%.4F', $rate ), '0' ), '.' );
    return '' === $text ? '0' : $text;
  }

  /**
   * Staff_Limits (WP9) decides; without it no approvals are listed and the
   * warning limits_unavailable says so when the cart has custom discounts
   * or custom items.
   */
  private function approvals( Xophz_Compass_Bazaar_Security_Context $context, array $lines, array $custom, array &$warnings ): array {
    $max_custom = 0;
    foreach ( $lines as $line ) {
      if ( 'custom' === $line['type'] ) {
        $max_custom = max( $max_custom, (int) $line['unit_price_minor'] );
      }
    }
    if ( $custom['amount_minor'] <= 0 && $max_custom <= 0 ) {
      return [];
    }
    $limits = 'Xophz_Compass_Bazaar_Staff_Limits';
    if ( ! class_exists( $limits ) || ! method_exists( $limits, 'for_user' ) || ! method_exists( $limits, 'approvals_needed' ) ) {
      $warnings[] = [ 'code' => 'limits_unavailable', 'line' => '' ];
      return [];
    }
    $user = $context->acting_user_id;
    return $limits::approvals_needed( $limits::for_user( $user ), [
      'can_discount'        => method_exists( $limits, 'can_discount' ) ? $limits::can_discount( $user ) : true,
      'discount_minor'      => $custom['amount_minor'],
      'discount_base_minor' => $custom['base_minor'],
      'custom_item_minor'   => $max_custom,
    ] );
  }

  private function fingerprint( Xophz_Compass_Bazaar_Pricing_Tax_Calculator $calc, bool $taxes_on, array $location, array $lines ): array {
    $rates = [];
    foreach ( $lines as $line ) {
      foreach ( $line['rates'] as $id => $rate ) {
        $rates[ (string) $id ] = [ (string) ( $rate['rate'] ?? '' ), (string) ( $rate['compound'] ?? '' ) ];
      }
    }
    ksort( $rates, SORT_STRING );
    return [
      'tax_enabled'        => $taxes_on,
      'prices_include_tax' => $calc->prices_include_tax(),
      'round_at_subtotal'  => $calc->round_at_subtotal(),
      'decimals'           => $calc->decimals(),
      'location'           => $location,
      'rates'              => $rates,
    ];
  }

  /** @throws Xophz_Compass_Bazaar_Core_Http_Error */
  private static function invalid_line( int $index, string $reason, string $message ): void {
    throw new Xophz_Compass_Bazaar_Core_Http_Error( 'invalid_line', $message, 422, [ 'line' => $index, 'reason' => $reason ] );
  }
}
