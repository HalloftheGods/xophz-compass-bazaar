<?php
/**
 * The store's own details for the top and bottom of a printed receipt.
 *
 * Nothing is hardcoded: the address is the one in WooCommerce > Settings >
 * General, the name is the site name. WooCommerce core has no phone field, so
 * the phone is read from WooCommerce's Point of Sale store phone when it is
 * set, then from xophz_bazaar_store_phone. The return policy comes from
 * xophz_bazaar_return_policy, then from WooCommerce's POS return policy. Every
 * option is blank by default, and a blank line is left off the receipt.
 *
 * @package Xophz_Compass_Bazaar
 */
class Xophz_Compass_Bazaar_Checkout_Receipt_Store {

  const PHONE_OPTION  = 'xophz_bazaar_store_phone';
  const POLICY_OPTION = 'xophz_bazaar_return_policy';

  /**
   * @return array{name:string,address_lines:string[],phone:string,policy:string}
   */
  public static function info(): array {
    $base = [
      'address'  => (string) get_option( 'woocommerce_store_address', '' ),
      'address2' => (string) get_option( 'woocommerce_store_address_2', '' ),
      'city'     => (string) get_option( 'woocommerce_store_city', '' ),
      'postcode' => (string) get_option( 'woocommerce_store_postcode', '' ),
      'state'    => '',
    ];
    // "US:CO" is the country and state in one setting; the state is what a receipt shows.
    $country = (string) get_option( 'woocommerce_default_country', '' );
    if ( false !== strpos( $country, ':' ) ) {
      $base['state'] = (string) substr( strrchr( $country, ':' ), 1 );
    }
    return [
      'name'          => (string) get_bloginfo( 'name' ),
      'address_lines' => self::address_lines( $base ),
      'phone'         => self::first_filled( [ get_option( 'woocommerce_pos_store_phone', '' ), get_option( self::PHONE_OPTION, '' ) ] ),
      'policy'        => self::first_filled( [ get_option( self::POLICY_OPTION, '' ), get_option( 'woocommerce_pos_refund_returns_policy', '' ) ] ),
    ];
  }

  /**
   * Address lines in mailing order, blanks dropped.
   *
   * @param array $a address, address2, city, state, postcode.
   * @return string[]
   */
  public static function address_lines( array $a ): array {
    $get      = function ( string $key ) use ( $a ): string {
      return isset( $a[ $key ] ) ? trim( (string) $a[ $key ] ) : '';
    };
    $region   = trim( $get( 'state' ) . ' ' . $get( 'postcode' ) );
    $locality = '' !== $get( 'city' ) && '' !== $region ? $get( 'city' ) . ', ' . $region : trim( $get( 'city' ) . ' ' . $region );
    return array_values( array_filter( [ $get( 'address' ), $get( 'address2' ), $locality ], 'strlen' ) );
  }

  /** The first value that has text in it, trimmed; '' when none do. */
  public static function first_filled( array $values ): string {
    foreach ( $values as $value ) {
      $text = is_scalar( $value ) ? trim( (string) $value ) : '';
      if ( '' !== $text ) {
        return $text;
      }
    }
    return '';
  }
}
