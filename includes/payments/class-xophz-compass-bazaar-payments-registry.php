<?php
/**
 * Finds the provider for a payment method and lists the tenders the POS offers.
 *
 * @package Xophz_Compass_Bazaar
 */
class Xophz_Compass_Bazaar_Payments_Registry {

  /** Built-in tenders: always offered, in this order. */
  const BUILTIN_METHODS = [ 'cash', 'bazaar_card_terminal' ];

  /**
   * Providers keyed by id: the four built-ins, then whatever the filter
   * xophz_compass_bazaar_payment_providers adds. Anything in the filtered list
   * that does not implement Payments_Provider is dropped.
   *
   * @return Xophz_Compass_Bazaar_Payments_Provider[]
   */
  public static function providers(): array {
    $list = apply_filters( 'xophz_compass_bazaar_payment_providers', [
      new Xophz_Compass_Bazaar_Payments_Provider_Cash(),
      new Xophz_Compass_Bazaar_Payments_Provider_External_Terminal(),
      new Xophz_Compass_Bazaar_Payments_Provider_Manual(),
      new Xophz_Compass_Bazaar_Payments_Provider_Test_Portal(),
    ] );
    $out = [];
    foreach ( is_array( $list ) ? $list : [] as $provider ) {
      if ( $provider instanceof Xophz_Compass_Bazaar_Payments_Provider ) {
        $out[ $provider->id() ] = $provider;
      }
    }
    return $out;
  }

  /** The first available provider of the method's kind, or null (split, unknown kind, none available). */
  public static function for_method( string $method_id, string $title = '' ): ?Xophz_Compass_Bazaar_Payments_Provider {
    $kind = Xophz_Compass_Bazaar_Payments_Classifier::kind( $method_id, $title );
    foreach ( self::providers() as $provider ) {
      if ( $provider->kind() === $kind && $provider->available() ) {
        return $provider;
      }
    }
    return null;
  }

  /**
   * Tenders for the register: cash and the card terminal, then enabled
   * gateways of kind manual, then digital gateways only while the test portal
   * is on. Card-pattern gateways are never listed.
   *
   * @param array|null $gateways List of [id, title] for the enabled WooCommerce gateways.
   *                             Null reads them from WooCommerce when it is loaded.
   * @return array[] Each has method_id, label, kind, provider, requires.
   */
  public static function pos_tenders( ?array $gateways = null ): array {
    $tenders = [];
    foreach ( self::BUILTIN_METHODS as $method_id ) {
      $tender = self::tender( $method_id, '' );
      if ( null !== $tender ) {
        $tenders[ $method_id ] = $tender;
      }
    }
    foreach ( $gateways ?? self::woocommerce_gateways() as $gateway ) {
      $method_id = strtolower( (string) ( $gateway['id'] ?? '' ) );
      $title     = (string) ( $gateway['title'] ?? '' );
      if ( '' === $method_id || isset( $tenders[ $method_id ] ) ) {
        continue;
      }
      $kind = Xophz_Compass_Bazaar_Payments_Classifier::kind( $method_id, $title );
      if ( 'manual' !== $kind && 'digital' !== $kind ) {
        continue;
      }
      $tender = self::tender( $method_id, $title );
      if ( null !== $tender ) {
        $tenders[ $method_id ] = $tender;
      }
    }
    return array_values( $tenders );
  }

  private static function tender( string $method_id, string $title ): ?array {
    $provider = self::for_method( $method_id, $title );
    if ( null === $provider ) {
      return null;
    }
    return [
      'method_id' => $method_id,
      'label'     => '' !== $title ? $title : $provider->label( $method_id ),
      'kind'      => Xophz_Compass_Bazaar_Payments_Classifier::kind( $method_id, $title ),
      'provider'  => $provider->id(),
      'requires'  => $provider->requirements(),
    ];
  }

  /** Enabled WooCommerce gateways; empty when WooCommerce is not loaded. */
  private static function woocommerce_gateways(): array {
    if ( ! function_exists( 'WC' ) || ! WC() || ! WC()->payment_gateways() ) {
      return [];
    }
    $out = [];
    foreach ( WC()->payment_gateways()->payment_gateways() as $gateway ) {
      if ( 'yes' === $gateway->enabled ) {
        $out[] = [ 'id' => (string) $gateway->id, 'title' => (string) $gateway->get_title() ];
      }
    }
    return $out;
  }
}
