<?php
/**
 * A priced cart: the pricer's result, shared by the quote and checkout.
 *
 * Spec: docs/plugins/bazaar-core-v2.md sections 9.2 to 9.4.
 *
 * Every amount is an integer in minor units, except order_items() and
 * coupon_items(), which carry the major-unit decimal strings WooCommerce
 * order items store (unrounded when the store rounds tax at subtotal).
 *
 * Line amounts follow WooCommerce order items: subtotal_minor, discount_minor
 * and total_minor are tax-exclusive; unit_price_minor is the shop price as
 * entered (tax-inclusive when the store enters prices with tax). With
 * woocommerce_tax_round_at_subtotal = yes, each line amount is rounded on
 * its own, so lines and tax_lines can differ from the totals by a minor
 * unit; the totals are what a WooCommerce order computes.
 *
 * @package Xophz_Compass_Bazaar
 */
final class Xophz_Compass_Bazaar_Pricing_Priced_Cart {

  /** Keys every line exposes in the quote (spec 9.3), in order. */
  const QUOTE_LINE_KEYS = [
    'key', 'type', 'product_id', 'variation_id', 'name', 'sku', 'quantity', 'unit_price_minor',
    'subtotal_minor', 'discount_minor', 'total_minor', 'tax_minor', 'taxes', 'stock',
  ];

  /** @var array */
  private $data;

  /**
   * @param array $data Built by Pricing_Pricer: request (Pricing_Cart_Request), currency, decimals,
   *                    tax_enabled, prices_include_tax, round_at_subtotal, tax_location, lines,
   *                    coupons, discounts, tax_lines, totals, approvals, warnings, fingerprint,
   *                    expires_at.
   */
  public function __construct( array $data ) {
    $this->data = $data;
  }

  public function request(): Xophz_Compass_Bazaar_Pricing_Cart_Request {
    return $this->data['request'];
  }

  /** @return array{subtotal_minor:int,discount_minor:int,tax_minor:int,tip_minor:int,total_minor:int} */
  public function totals(): array {
    return $this->data['totals'];
  }

  public function total_minor(): int {
    return (int) $this->data['totals']['total_minor'];
  }

  public function tax_minor(): int {
    return (int) $this->data['totals']['tax_minor'];
  }

  /** The tax location used (country, state, postcode, city, source). */
  public function tax_location(): array {
    return $this->data['tax_location'];
  }

  /** Coupons with valid false (code and WooCommerce's message). */
  public function invalid_coupons(): array {
    return array_values( array_filter( $this->data['coupons'], function ( $c ) { return ! $c['valid']; } ) );
  }

  /** @return array[] {scope, amount_minor, basis_points, subject_hash} */
  public function approvals_needed(): array {
    $out = [];
    foreach ( $this->data['approvals'] as $approval ) {
      $out[] = array_merge( $approval, [ 'subject_hash' => $this->approval_subject( (string) $approval['scope'] ) ] );
    }
    return $out;
  }

  /** The spec 9.3 quote response. */
  public function to_quote_array(): array {
    $lines = [];
    foreach ( $this->data['lines'] as $line ) {
      $out = [];
      foreach ( self::QUOTE_LINE_KEYS as $key ) {
        $out[ $key ] = $line[ $key ] ?? null;
      }
      $out['taxes'] = self::rate_list( $line['taxes'] );
      $lines[]      = $out;
    }
    return [
      'currency'           => $this->data['currency'],
      'decimals'           => $this->data['decimals'],
      'tax_enabled'        => $this->data['tax_enabled'],
      'prices_include_tax' => $this->data['prices_include_tax'],
      'tax_location'       => $this->data['tax_location'],
      'lines'              => $lines,
      'coupons'            => array_map( function ( $c ) {
        return [
          'code'               => $c['code'],
          'discount_minor'     => $c['discount_minor'],
          'discount_tax_minor' => $c['discount_tax_minor'],
          'valid'              => $c['valid'],
          'message'            => $c['message'],
        ];
      }, $this->data['coupons'] ),
      'discounts'          => array_map( function ( $d ) {
        unset( $d['by_line'] );
        return $d;
      }, $this->data['discounts'] ),
      'tax_lines'          => array_values( $this->data['tax_lines'] ),
      'totals'             => $this->data['totals'],
      'approvals_needed'   => $this->approvals_needed(),
      'warnings'           => $this->data['warnings'],
      'quote_hash'         => $this->quote_hash(),
      'expires_at'         => $this->data['expires_at'],
    ];
  }

  /**
   * sha256 of the canonical priced lines, coupons and discounts, plus the
   * scope. Equal carts give equal subjects; any change to a priced amount,
   * quantity, coupon or discount gives a different one.
   */
  public function approval_subject( string $scope ): string {
    $lines = array_map( function ( $line ) {
      return [
        'key'              => $line['key'],
        'type'             => $line['type'],
        'product_id'       => $line['product_id'],
        'variation_id'     => $line['variation_id'],
        'name'             => $line['name'],
        'quantity'         => $line['quantity'],
        'unit_price_minor' => $line['unit_price_minor'],
        'subtotal_minor'   => $line['subtotal_minor'],
        'discount_minor'   => $line['discount_minor'],
        'total_minor'      => $line['total_minor'],
        'tax_minor'        => $line['tax_minor'],
      ];
    }, $this->data['lines'] );
    $coupons = array_map( function ( $c ) {
      return [ 'code' => $c['code'], 'valid' => $c['valid'], 'discount_minor' => $c['discount_minor'] ];
    }, $this->data['coupons'] );
    $discounts = array_map( function ( $d ) {
      unset( $d['by_line'] );
      return $d;
    }, $this->data['discounts'] );
    return Xophz_Compass_Bazaar_Core_Canonical::hash( [
      'scope'     => $scope,
      'lines'     => $lines,
      'coupons'   => $coupons,
      'discounts' => $discounts,
    ] );
  }

  /**
   * HMAC-SHA256 over the canonical cart request, the total and the tax
   * settings fingerprint, keyed with wp_salt('auth') unless $key is given.
   * Advisory: checkout enforces expected_total_minor, not this hash.
   */
  public function quote_hash( ?string $key = null ): string {
    $key = $key ?? wp_salt( 'auth' );
    return hash_hmac( 'sha256', Xophz_Compass_Bazaar_Core_Canonical::json( [
      'cart'        => $this->request()->to_array(),
      'total_minor' => $this->total_minor(),
      'tax'         => $this->data['fingerprint'],
    ] ), $key );
  }

  /**
   * What Checkout_Order_Writer writes per line (spec 9.4): the quote line
   * plus tax_class, taxable, and order (subtotal, total, subtotal_tax,
   * total_tax as decimal strings, taxes {subtotal: {rate_id: decimal},
   * total: {rate_id: decimal}}).
   */
  public function order_items(): array {
    return $this->data['lines'];
  }

  /** Coupon items for the order: code, discount and discount_tax (decimal strings) plus their minor-unit values. Valid coupons only. */
  public function coupon_items(): array {
    $out = [];
    foreach ( $this->data['coupons'] as $coupon ) {
      if ( $coupon['valid'] ) {
        $out[] = $coupon;
      }
    }
    return $out;
  }

  /** The tip as an untaxed fee (empty when there is no tip). */
  public function fee_items(): array {
    $tip = (int) $this->data['totals']['tip_minor'];
    if ( $tip <= 0 ) {
      return [];
    }
    return [ [
      'name'       => 'Tip',
      'tax_status' => 'none',
      'total'      => Xophz_Compass_Bazaar_Core_Money::from_minor( $tip, (int) $this->data['decimals'] ),
      'total_minor' => $tip,
    ] ];
  }

  /** @param int[] $taxes rate id => amount @return array[] [{rate_id, amount_minor}] */
  private static function rate_list( array $taxes ): array {
    $out = [];
    foreach ( $taxes as $rate_id => $amount ) {
      $out[] = [ 'rate_id' => (int) $rate_id, 'amount_minor' => (int) $amount ];
    }
    return $out;
  }
}
