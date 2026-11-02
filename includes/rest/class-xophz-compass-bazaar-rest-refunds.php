<?php
/**
 * REST route for refunds v2 (namespace bazaar/v1, spec sections 12 and 14.1).
 *
 * POST /orders/{id}/refunds   needs bazaar_refund. JSON body of spec 12:
 *                             {client_key, lines:[{order_item_id, qty}], reason,
 *                             restock, tenders?, approval_token?, dry_run?}.
 *                             The order id comes from the route. The
 *                             Idempotency-Key header is an alias of client_key.
 *                             Answers the refund (200), a replay of the stored
 *                             answer (replayed true), a dry run (200, nothing
 *                             written), or a spec 14.3 error: 400
 *                             invalid_request, 403 approval_required or
 *                             approval_invalid, 404 order_not_found, 409
 *                             order_busy, order_not_refundable,
 *                             refund_interrupted or refund_conflict, 422
 *                             idempotency_key_reused, invalid_line,
 *                             tender_total_mismatch, tender_unsupported,
 *                             reference_required, refund_exceeds_payments,
 *                             refund_exceeds_order, refund_empty or
 *                             refund_rejected.
 *
 * Refunds_Service decides everything else; this class only parses and maps.
 *
 * @package Xophz_Compass_Bazaar
 */
class Xophz_Compass_Bazaar_Rest_Refunds {

  const NAMESPACE_V1 = 'bazaar/v1';

  public function register_routes(): void {
    register_rest_route( self::NAMESPACE_V1, '/orders/(?P<id>[0-9]+)/refunds', [
      'methods'             => 'POST',
      'callback'            => [ $this, 'submit' ],
      'permission_callback' => Xophz_Compass_Bazaar_Security_Guard::rest_permission( Xophz_Compass_Bazaar_Security_Capabilities::REFUND ),
    ] );
  }

  /** @return WP_REST_Response|WP_Error */
  public function submit( WP_REST_Request $request ) {
    return Xophz_Compass_Bazaar_Core_Rest::handle( function () use ( $request ) {
      $refund   = Xophz_Compass_Bazaar_Refunds_Request::from_array(
        Xophz_Compass_Bazaar_Core_Rest::json_body( $request ),
        (int) $request['id'],
        (string) ( $request->get_header( 'idempotency_key' ) ?? '' )
      );
      $result   = ( new Xophz_Compass_Bazaar_Refunds_Service() )->refund( $refund, Xophz_Compass_Bazaar_Security_Guard::context() );
      $response = new WP_REST_Response( $result['body'], $result['status'] );
      $response->header( 'Cache-Control', 'no-store' );
      return $response;
    } );
  }
}
