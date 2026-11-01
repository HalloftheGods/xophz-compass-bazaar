<?php
/**
 * Where a POS sale is taxed.
 *
 * Spec: docs/plugins/bazaar-core-v2.md sections 2 (sign-off: POS sales are
 * taxed at the store base address until registers carry their own) and 9.4.
 *
 * - for_location() is the store base address from WC()->countries, passed
 *   through the filter xophz_compass_bazaar_pos_tax_location($location,
 *   $location_id). A filtered value missing a key keeps the base value.
 * - register() (a hook module) filters woocommerce_order_get_tax_location
 *   for orders that carry _bazaar_checkout_id, so attaching a customer with
 *   an address never changes the tax of a POS order. Other orders are not
 *   touched. The location id is read from the order meta _bazaar_location_id
 *   (0 when absent).
 *
 * @package Xophz_Compass_Bazaar
 */
final class Xophz_Compass_Bazaar_Pricing_Tax_Location {

  const FILTER        = 'xophz_compass_bazaar_pos_tax_location';
  const CHECKOUT_META = '_bazaar_checkout_id';
  const LOCATION_META = '_bazaar_location_id';

  public static function register(): void {
    add_filter( 'woocommerce_order_get_tax_location', [ self::class, 'filter_order_location' ], 10, 2 );
  }

  /**
   * @return array{country:string,state:string,postcode:string,city:string,source:string}
   *   source is "store_base", or whatever the filter set.
   */
  public static function for_location( int $location_id = 0 ): array {
    $base     = self::store_base();
    $filtered = apply_filters( self::FILTER, $base, $location_id );
    if ( ! is_array( $filtered ) ) {
      return $base;
    }
    $out = [];
    foreach ( [ 'country', 'state', 'postcode', 'city', 'source' ] as $key ) {
      $out[ $key ] = isset( $filtered[ $key ] ) && is_scalar( $filtered[ $key ] ) ? (string) $filtered[ $key ] : $base[ $key ];
    }
    return $out;
  }

  /** The four-element list WC_Tax::get_rates_from_location() takes. */
  public static function as_rate_location( array $location ): array {
    return [ $location['country'] ?? '', $location['state'] ?? '', $location['postcode'] ?? '', $location['city'] ?? '' ];
  }

  /**
   * woocommerce_order_get_tax_location callback.
   *
   * @param mixed $args  {country, state, postcode, city} from WooCommerce.
   * @param mixed $order The order being taxed.
   * @return mixed
   */
  public static function filter_order_location( $args, $order ) {
    if ( ! is_array( $args ) || ! is_object( $order ) || ! method_exists( $order, 'get_meta' ) ) {
      return $args;
    }
    if ( '' === (string) $order->get_meta( self::CHECKOUT_META, true ) ) {
      return $args;
    }
    $location = self::for_location( (int) $order->get_meta( self::LOCATION_META, true ) );
    unset( $location['source'] );
    return array_merge( $args, $location );
  }

  /** @return array{country:string,state:string,postcode:string,city:string,source:string} */
  private static function store_base(): array {
    $countries = function_exists( 'WC' ) && WC() ? WC()->countries : null;
    return [
      'country'  => $countries ? (string) $countries->get_base_country() : '',
      'state'    => $countries ? (string) $countries->get_base_state() : '',
      'postcode' => $countries ? (string) $countries->get_base_postcode() : '',
      'city'     => $countries ? (string) $countries->get_base_city() : '',
      'source'   => 'store_base',
    ];
  }
}
