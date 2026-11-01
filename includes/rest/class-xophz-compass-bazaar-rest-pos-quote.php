<?php
/**
 * POST /bazaar/v1/pos/quote: price a cart on the server.
 *
 * Spec: docs/plugins/bazaar-core-v2.md sections 9.3 and 14.1.
 *
 * - Permission: bazaar_sell (Security_Guard::rest_permission), checked for
 *   the acting user (the PIN staff user when a staff session header is sent).
 * - Body: the cart request of spec 9.1 as JSON (at most 256 KiB).
 * - Answers 200 with the spec 9.3 quote, 400 invalid_request,
 *   422 unsupported_field, 422 invalid_line or 403 forbidden.
 * - Writes no orders, coupons, payments or ledger rows (see Pricing_Pricer
 *   for the one ledger-mode exception). The quote is advisory: checkout
 *   prices the cart again.
 *
 * @package Xophz_Compass_Bazaar
 */
class Xophz_Compass_Bazaar_Rest_Pos_Quote {

  const REST_NAMESPACE = 'bazaar/v1';
  const ROUTE          = '/pos/quote';

  public function register_routes(): void {
    register_rest_route( self::REST_NAMESPACE, self::ROUTE, [
      'methods'             => 'POST',
      'callback'            => [ $this, 'quote' ],
      'permission_callback' => Xophz_Compass_Bazaar_Security_Guard::rest_permission( Xophz_Compass_Bazaar_Security_Capabilities::SELL ),
    ] );
  }

  /** @return WP_REST_Response|WP_Error */
  public function quote( WP_REST_Request $request ) {
    return Xophz_Compass_Bazaar_Core_Rest::handle( function () use ( $request ) {
      $cart   = Xophz_Compass_Bazaar_Pricing_Cart_Request::from_array( Xophz_Compass_Bazaar_Core_Rest::json_body( $request ) );
      $priced = ( new Xophz_Compass_Bazaar_Pricing_Pricer() )->price( $cart, Xophz_Compass_Bazaar_Security_Guard::context() );
      $response = new WP_REST_Response( $priced->to_quote_array(), 200 );
      $response->header( 'Cache-Control', 'no-store' );
      return $response;
    } );
  }
}
