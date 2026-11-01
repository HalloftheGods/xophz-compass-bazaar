<?php
/**
 * Line taxes and order totals, the way a WooCommerce order computes them.
 *
 * Spec: docs/plugins/bazaar-core-v2.md section 9.2 step 4.
 *
 * The target is parity with an order WooCommerce prices itself
 * (add_product, apply_coupon, calculate_totals), so this class follows
 * abstract-wc-order.php and wc_get_price_excluding_tax():
 * - Order items store tax-exclusive amounts. With prices entered including
 *   tax, a line's tax-exclusive subtotal is the gross price less its
 *   unrounded tax at the base rates; a discount is taken off the gross
 *   price and converted the same way (at the line's rates).
 * - Each line's tax is WC_Tax::calc_tax() on its tax-exclusive total.
 * - Unless woocommerce_tax_round_at_subtotal is yes, each line's tax (per
 *   rate) and each line total are rounded before they are summed; with it,
 *   the unrounded values are summed and the totals rounded once.
 * - A line that is not taxable (tax status not "taxable", a custom line
 *   with taxable false, or taxes disabled) gets no tax.
 *
 * Amounts in and out are minor units. calc_tax() is given major-unit
 * amounts, because WooCommerce orders do and calc_tax() rounds its results
 * to the store rounding precision (6 decimals) of whatever unit it is
 * given; the results are converted back to minor-unit floats.
 *
 * Pure: without WC_Tax (unit tests) it uses a copy of WooCommerce's
 * inclusive and exclusive formulas; any callable can be injected instead.
 * Filters on WooCommerce's rounding helpers are not applied.
 *
 * @package Xophz_Compass_Bazaar
 */
final class Xophz_Compass_Bazaar_Pricing_Tax_Calculator {

  /** @var bool */
  private $prices_include_tax;
  /** @var bool */
  private $round_at_subtotal;
  /** @var int */
  private $decimals;
  /** @var int */
  private $precision;
  /** @var int */
  private $tax_mode;
  /** @var callable(float $price, array $rates, bool $inclusive): array */
  private $calc;

  /**
   * @param array $settings prices_include_tax (bool), round_at_subtotal (bool), decimals (int, 2),
   *                        rounding_precision (int, max(6, decimals + 2)), tax_rounding_mode
   *                        (PHP_ROUND_HALF_UP or PHP_ROUND_HALF_DOWN), calc (callable|null).
   */
  public function __construct( array $settings = [] ) {
    $this->prices_include_tax = (bool) ( $settings['prices_include_tax'] ?? false );
    $this->round_at_subtotal  = (bool) ( $settings['round_at_subtotal'] ?? false );
    $this->decimals           = max( 0, (int) ( $settings['decimals'] ?? 2 ) );
    $this->precision          = (int) ( $settings['rounding_precision'] ?? max( 6, $this->decimals + 2 ) );
    $this->tax_mode           = PHP_ROUND_HALF_DOWN === ( $settings['tax_rounding_mode'] ?? PHP_ROUND_HALF_UP ) ? PHP_ROUND_HALF_DOWN : PHP_ROUND_HALF_UP;
    $this->calc               = $settings['calc'] ?? ( class_exists( 'WC_Tax' ) ? [ 'WC_Tax', 'calc_tax' ] : [ self::class, 'pure_calc_tax' ] );
  }

  /** Settings read from WooCommerce (prices entry, rounding option, decimals, tax rounding mode). */
  public static function from_woocommerce(): self {
    return new self( [
      'prices_include_tax' => function_exists( 'wc_prices_include_tax' ) && wc_prices_include_tax(),
      'round_at_subtotal'  => 'yes' === get_option( 'woocommerce_tax_round_at_subtotal' ),
      'decimals'           => function_exists( 'wc_get_price_decimals' ) ? (int) wc_get_price_decimals() : 2,
      'rounding_precision' => function_exists( 'wc_get_rounding_precision' ) ? (int) wc_get_rounding_precision() : null,
      'tax_rounding_mode'  => function_exists( 'wc_get_tax_rounding_mode' ) ? (int) wc_get_tax_rounding_mode() : PHP_ROUND_HALF_UP,
    ] );
  }

  public function prices_include_tax(): bool {
    return $this->prices_include_tax;
  }

  public function round_at_subtotal(): bool {
    return $this->round_at_subtotal;
  }

  public function decimals(): int {
    return $this->decimals;
  }

  /**
   * Tax per rate id on $amount (minor units), as minor-unit floats at the
   * store rounding precision.
   */
  public function taxes( float $amount, array $rates, bool $inclusive ): array {
    if ( ! $rates || 0.0 === $amount ) {
      return array_fill_keys( array_keys( $rates ), 0.0 );
    }
    $scale = 10 ** $this->decimals;
    $raw   = call_user_func( $this->calc, $amount / $scale, $rates, $inclusive );
    $out   = [];
    foreach ( (array) $raw as $rate_id => $tax ) {
      $out[ $rate_id ] = $this->normalize( (float) $tax * $scale );
    }
    return $out;
  }

  /**
   * The subtotal stage of one line.
   *
   * @param array $line gross_minor (int, the line price in the store's basis), taxable (bool),
   *                    rates (WooCommerce rate rows by id), base_rates (rates removed from a
   *                    tax-inclusive price; defaults to rates).
   * @return array{subtotal_ex:float,subtotal_taxes:float[],subtotal_tax:float,discount_price:float}
   *   discount_price is the price WC_Discounts sees for the line (minor units).
   */
  public function line_subtotal( array $line ): array {
    $gross   = (int) $line['gross_minor'];
    $taxable = ! empty( $line['taxable'] );
    $rates   = $taxable ? (array) ( $line['rates'] ?? [] ) : [];
    $sub_ex  = (float) $gross;
    if ( $taxable && $this->prices_include_tax ) {
      $sub_ex = $gross - array_sum( $this->taxes( $gross, (array) ( $line['base_rates'] ?? $rates ), true ) );
    }
    $taxes = $this->taxes( $sub_ex, $rates, false );
    $tax   = $this->line_tax_sum( $taxes );
    $price = $this->normalize( $sub_ex ) + ( $this->prices_include_tax ? $this->normalize( $tax ) : 0.0 );
    return [ 'subtotal_ex' => $sub_ex, 'subtotal_taxes' => $taxes, 'subtotal_tax' => $tax, 'discount_price' => $price ];
  }

  /**
   * Price every line and the order totals (no fees).
   *
   * @param array[] $lines key => line_subtotal() input plus discounts (int[], gross discount
   *                       amounts applied in order, for example [coupons, custom discounts]).
   * @return array{lines:array[],rates:array[],subtotal_minor:int,discount_minor:int,tax_minor:int,items_total_minor:int,total_minor:int}
   */
  public function price_lines( array $lines ): array {
    $out   = [];
    $rates = [];
    $sums  = [ 'sub' => 0.0, 'total' => 0.0, 'sub_int' => 0, 'total_int' => 0, 'tax_int' => 0 ];
    foreach ( $lines as $key => $line ) {
      $priced      = $this->price_line( $line );
      $out[ $key ] = $priced;
      $sums['sub']       += $priced['subtotal_ex'];
      $sums['total']     += $priced['total_ex'];
      $sums['sub_int']   += $priced['subtotal_minor'];
      $sums['total_int'] += $priced['total_minor'];
      foreach ( $priced['total_taxes'] as $rate_id => $tax ) {
        $rates[ $rate_id ] = $rates[ $rate_id ] ?? [ 'amount' => 0.0, 'amount_minor' => 0 ];
        $rates[ $rate_id ]['amount']       += $tax;
        $rates[ $rate_id ]['amount_minor'] += $priced['taxes'][ $rate_id ];
      }
    }
    return $this->round_at_subtotal ? $this->totals_at_subtotal( $out, $rates, $sums ) : $this->totals_per_line( $out, $rates, $sums );
  }

  /**
   * The tax WooCommerce attributes to one line's share of a coupon
   * (set_coupon_discount_amounts): rounded per line unless rounding at subtotal.
   */
  public function discount_tax( int $discount, array $rates, bool $taxable ): float {
    if ( ! $taxable || ! $rates || $discount <= 0 ) {
      return 0.0;
    }
    $tax = array_sum( $this->taxes( $discount, $rates, $this->prices_include_tax ) );
    return $this->round_at_subtotal ? $tax : (float) $this->round_tax( $tax );
  }

  /** Round a minor-unit tax amount with the store's tax rounding mode. */
  public function round_tax( float $minor ): int {
    return (int) round( $minor, 0, $this->tax_mode );
  }

  /** Round a minor-unit amount half-up (PHP round()). */
  public static function round_amount( float $minor ): int {
    return (int) round( $minor );
  }

  /** A minor-unit float as a major-unit decimal string at the rounding precision, for order items. */
  public function to_decimal( float $minor ): string {
    $places = max( $this->decimals, $this->precision );
    $text   = sprintf( '%.' . $places . 'F', round( $minor / ( 10 ** $this->decimals ), $places ) );
    $text   = false !== strpos( $text, '.' ) ? rtrim( rtrim( $text, '0' ), '.' ) : $text;
    return '-0' === $text || '' === $text ? '0' : $text;
  }

  /**
   * WooCommerce's inclusive and exclusive tax formulas (class-wc-tax.php),
   * results rounded to 6 decimals. Used when WC_Tax is not loaded.
   */
  public static function pure_calc_tax( float $price, array $rates, bool $inclusive ): array {
    $taxes = $inclusive ? self::pure_inclusive( $price, $rates ) : self::pure_exclusive( $price, $rates );
    return array_map( function ( $tax ) { return round( $tax, 6 ); }, $taxes );
  }

  private static function pure_inclusive( float $price, array $rates ): array {
    $taxes    = [];
    $compound = [];
    $regular  = [];
    foreach ( $rates as $key => $rate ) {
      $taxes[ $key ] = 0.0;
      if ( 'yes' === ( $rate['compound'] ?? 'no' ) ) {
        $compound[ $key ] = (float) $rate['rate'];
      } else {
        $regular[ $key ] = (float) $rate['rate'];
      }
    }
    $non_compound = $price;
    foreach ( array_reverse( $compound, true ) as $key => $rate ) {
      $tax           = $non_compound - ( $non_compound / ( 1 + $rate / 100 ) );
      $taxes[ $key ] += $tax;
      $non_compound -= $tax;
    }
    $regular_total = 1 + array_sum( $regular ) / 100;
    foreach ( $regular as $key => $rate ) {
      $the_rate      = ( $rate / 100 ) / $regular_total;
      $net           = $price - ( $the_rate * $non_compound );
      $taxes[ $key ] += $price - $net;
    }
    return $taxes;
  }

  private static function pure_exclusive( float $price, array $rates ): array {
    $taxes = [];
    foreach ( $rates as $key => $rate ) {
      if ( 'yes' !== ( $rate['compound'] ?? 'no' ) ) {
        $taxes[ $key ] = ( $taxes[ $key ] ?? 0.0 ) + $price * ( (float) $rate['rate'] / 100 );
      }
    }
    $pre_compound = array_sum( $taxes );
    foreach ( $rates as $key => $rate ) {
      if ( 'yes' === ( $rate['compound'] ?? 'no' ) ) {
        $taxes[ $key ] = ( $taxes[ $key ] ?? 0.0 ) + ( $price + $pre_compound ) * ( (float) $rate['rate'] / 100 );
        $pre_compound  = array_sum( $taxes );
      }
    }
    return $taxes;
  }

  private function price_line( array $line ): array {
    $taxable = ! empty( $line['taxable'] );
    $rates   = $taxable ? (array) ( $line['rates'] ?? [] ) : [];
    $base    = $this->line_subtotal( $line );
    $total   = $base['subtotal_ex'];
    foreach ( (array) ( $line['discounts'] ?? [] ) as $discount ) {
      $discount = (int) $discount;
      if ( $discount <= 0 ) {
        continue;
      }
      $ex    = $taxable && $this->prices_include_tax ? $discount - array_sum( $this->taxes( $discount, $rates, true ) ) : (float) $discount;
      $total = max( 0.0, $total - $ex );
    }
    $taxes       = $this->taxes( $total, $rates, false );
    $taxes_int   = array_map( [ $this, 'round_tax' ], $taxes );
    $sub_int     = array_map( [ $this, 'round_tax' ], $base['subtotal_taxes'] );
    $subtotal    = self::round_amount( $base['subtotal_ex'] );
    $total_minor = self::round_amount( $total );
    return [
      'subtotal_ex'         => $base['subtotal_ex'],
      'total_ex'            => $total,
      'subtotal_taxes'      => $base['subtotal_taxes'],
      'total_taxes'         => $taxes,
      'subtotal_tax'        => $base['subtotal_tax'],
      'total_tax'           => $this->line_tax_sum( $taxes ),
      'subtotal_minor'      => $subtotal,
      'total_minor'         => $total_minor,
      'discount_minor'      => $subtotal - $total_minor,
      'taxes'               => $taxes_int,
      'tax_minor'           => array_sum( $taxes_int ),
      'subtotal_taxes_minor' => $sub_int,
      'subtotal_tax_minor'  => array_sum( $sub_int ),
    ];
  }

  /** Line taxes rounded per rate, then summed. */
  private function totals_per_line( array $lines, array $rates, array $sums ): array {
    $tax = 0;
    foreach ( $rates as $rate ) {
      $tax += $rate['amount_minor'];
    }
    return [
      'lines'             => $lines,
      'rates'             => $rates,
      'subtotal_minor'    => $sums['sub_int'],
      'discount_minor'    => $sums['sub_int'] - $sums['total_int'],
      'tax_minor'         => $tax,
      'items_total_minor' => $sums['total_int'],
      'total_minor'       => $sums['total_int'] + $tax,
    ];
  }

  /** Unrounded values summed, each total rounded once (woocommerce_tax_round_at_subtotal). */
  private function totals_at_subtotal( array $lines, array $rates, array $sums ): array {
    $tax_float = 0.0;
    foreach ( $rates as $rate_id => $rate ) {
      $tax_float                         += $rate['amount'];
      $rates[ $rate_id ]['amount_minor'] = $this->round_tax( $rate['amount'] );
    }
    $tax   = $this->round_tax( $tax_float );
    $total = self::round_amount( $sums['total'] + $tax_float );
    return [
      'lines'             => $lines,
      'rates'             => $rates,
      'subtotal_minor'    => self::round_amount( $sums['sub'] ),
      'discount_minor'    => self::round_amount( $sums['sub'] - $sums['total'] ),
      'tax_minor'         => $tax,
      'items_total_minor' => $total - $tax,
      'total_minor'       => $total,
    ];
  }

  private function line_tax_sum( array $taxes ): float {
    if ( $this->round_at_subtotal ) {
      return (float) array_sum( $taxes );
    }
    $sum = 0;
    foreach ( $taxes as $tax ) {
      $sum += $this->round_tax( $tax );
    }
    return (float) $sum;
  }

  /** Drop float noise below the store rounding precision (in minor units). */
  private function normalize( float $minor ): float {
    return round( $minor, max( 0, $this->precision - $this->decimals ) );
  }
}
