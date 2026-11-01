<?php
/**
 * GET /bazaar/v1/pos/config: what the register needs before a sale.
 *
 * Spec: docs/plugins/bazaar-core-v2.md section 14.1. Permission: bazaar_sell.
 *
 * Each feature is read only when the class that owns it is installed;
 * without it the feature reads false (ledger_mode reads "off"):
 * - checkout_v2:                Checkout_Service::enabled()
 * - staff_pin:                  true when Staff_Sessions exists
 * - ledger_mode:                Stock_Mode::current()
 * - require_terminal_reference: Payments_Settings::require_terminal_reference()
 * - test_portal:                Pos_Authorization::is_test_portal_enabled()
 *
 * limits is Staff_Limits::for_user() for the acting user, or null when that
 * class is not installed (the server then lists no approvals). Locations
 * come from Stock_Locations::all() ([{id: 0, name: "Store"}] without it).
 * Writes nothing.
 *
 * @package Xophz_Compass_Bazaar
 */
class Xophz_Compass_Bazaar_Rest_Pos_Config {

  const REST_NAMESPACE = 'bazaar/v1';
  const ROUTE          = '/pos/config';

  public function register_routes(): void {
    register_rest_route( self::REST_NAMESPACE, self::ROUTE, [
      'methods'             => 'GET',
      'callback'            => [ $this, 'config' ],
      'permission_callback' => Xophz_Compass_Bazaar_Security_Guard::rest_permission( Xophz_Compass_Bazaar_Security_Capabilities::SELL ),
    ] );
  }

  /** @return WP_REST_Response|WP_Error */
  public function config( WP_REST_Request $request ) {
    return Xophz_Compass_Bazaar_Core_Rest::handle( function () {
      $context = Xophz_Compass_Bazaar_Security_Guard::context();
      $response = new WP_REST_Response( self::build( $context ), 200 );
      $response->header( 'Cache-Control', 'no-store' );
      return $response;
    } );
  }

  /** The spec 14.1 config for $context. */
  public static function build( Xophz_Compass_Bazaar_Security_Context $context ): array {
    return [
      'features'            => self::features(),
      'currency'            => function_exists( 'get_woocommerce_currency' ) ? (string) get_woocommerce_currency() : '',
      'decimals'            => function_exists( 'wc_get_price_decimals' ) ? (int) wc_get_price_decimals() : 2,
      'prices_include_tax'  => function_exists( 'wc_prices_include_tax' ) && wc_prices_include_tax(),
      'tax_enabled'         => function_exists( 'wc_tax_enabled' ) && wc_tax_enabled(),
      'locations'           => self::locations(),
      'default_location_id' => class_exists( 'Xophz_Compass_Bazaar_Stock_Locations' ) ? Xophz_Compass_Bazaar_Stock_Locations::default_location_id() : 0,
      'tenders'             => Xophz_Compass_Bazaar_Payments_Registry::pos_tenders(),
      'limits'              => self::limits( $context->acting_user_id ),
      'actor'               => self::actor( $context ),
    ];
  }

  private static function features(): array {
    $checkout = 'Xophz_Compass_Bazaar_Checkout_Service';
    $mode     = 'Xophz_Compass_Bazaar_Stock_Mode';
    $auth     = 'Xophz_Compass_Bazaar_Pos_Authorization';
    return [
      'checkout_v2'                => class_exists( $checkout ) && method_exists( $checkout, 'enabled' ) && (bool) $checkout::enabled(),
      'staff_pin'                  => class_exists( 'Xophz_Compass_Bazaar_Staff_Sessions' ),
      'ledger_mode'                => class_exists( $mode ) ? (string) $mode::current() : 'off',
      'require_terminal_reference' => class_exists( 'Xophz_Compass_Bazaar_Payments_Settings' ) && Xophz_Compass_Bazaar_Payments_Settings::require_terminal_reference(),
      'test_portal'                => class_exists( $auth ) && method_exists( $auth, 'is_test_portal_enabled' ) && $auth::is_test_portal_enabled(),
    ];
  }

  private static function locations(): array {
    if ( class_exists( 'Xophz_Compass_Bazaar_Stock_Locations' ) ) {
      return Xophz_Compass_Bazaar_Stock_Locations::all();
    }
    return [ [ 'id' => 0, 'name' => __( 'Store', 'xophz-compass-bazaar' ) ] ];
  }

  private static function limits( int $user_id ): ?array {
    $limits = 'Xophz_Compass_Bazaar_Staff_Limits';
    if ( ! class_exists( $limits ) || ! method_exists( $limits, 'for_user' ) ) {
      return null;
    }
    $values = $limits::for_user( $user_id );
    return [
      'discount_bp'       => (int) ( $values['discount_bp'] ?? 0 ),
      'discount_minor'    => (int) ( $values['discount_minor'] ?? 0 ),
      'refund_minor'      => (int) ( $values['refund_minor'] ?? 0 ),
      'custom_item_minor' => (int) ( $values['custom_item_minor'] ?? 0 ),
      'unlimited'         => ! empty( $values['unlimited'] ),
    ];
  }

  private static function actor( Xophz_Compass_Bazaar_Security_Context $context ): array {
    $caps = [];
    foreach ( Xophz_Compass_Bazaar_Security_Capabilities::ALL as $cap ) {
      if ( user_can( $context->acting_user_id, $cap ) ) {
        $caps[] = $cap;
      }
    }
    return [
      'operator' => self::person( $context->operator_user_id ),
      'cashier'  => self::person( $context->acting_user_id ),
      'via'      => $context->via,
      'caps'     => $caps,
    ];
  }

  /** @return array{id:int,name:string} */
  private static function person( int $user_id ): array {
    $user = $user_id > 0 ? get_userdata( $user_id ) : false;
    return [ 'id' => $user_id, 'name' => $user ? (string) $user->display_name : '' ];
  }
}
