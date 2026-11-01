<?php
/**
 * REST routes for checkout v2 and the server shift summary (namespace
 * bazaar/v1, spec sections 11.1, 14.1 and 16).
 *
 * POST /pos/checkouts               sell. JSON body of spec 11.1; the
 *                                   Idempotency-Key header is an alias of
 *                                   client_key. Answers the spec 11.1 body
 *                                   (200), a replay of the stored response
 *                                   (same status, replayed true), or a spec
 *                                   14.3 error. 403 checkout_disabled while
 *                                   Checkout_Service::enabled() is false.
 * GET  /pos/checkouts/{key}         sell or manage_orders. The caller's own
 *                                   key; any creator's with manage_orders.
 *                                   The stored body once completed, else
 *                                   {client_key, checkout_id, state, order_id,
 *                                   retryable, error}. 404 checkout_not_found.
 * GET  /pos/shift-summary?since=    sell. The acting user's staff session,
 *                                   or the window from since (ISO 8601;
 *                                   default: today's midnight in the store's
 *                                   time zone) to now. Narrowed to the acting
 *                                   cashier unless they hold view_reports or
 *                                   manage_orders.
 *
 * @package Xophz_Compass_Bazaar
 */
class Xophz_Compass_Bazaar_Rest_Checkouts {

  const NAMESPACE_V1 = 'bazaar/v1';

  public function register_routes(): void {
    $caps = 'Xophz_Compass_Bazaar_Security_Capabilities';
    register_rest_route( self::NAMESPACE_V1, '/pos/checkouts', [
      'methods'             => 'POST',
      'callback'            => [ $this, 'submit' ],
      'permission_callback' => Xophz_Compass_Bazaar_Security_Guard::rest_permission( $caps::SELL ),
    ] );
    register_rest_route( self::NAMESPACE_V1, '/pos/checkouts/(?P<key>[a-z0-9:-]{8,64})', [
      'methods'             => 'GET',
      'callback'            => [ $this, 'status' ],
      'permission_callback' => Xophz_Compass_Bazaar_Security_Guard::rest_permission( $caps::SELL, $caps::MANAGE_ORDERS ),
    ] );
    register_rest_route( self::NAMESPACE_V1, '/pos/shift-summary', [
      'methods'             => 'GET',
      'callback'            => [ $this, 'shift_summary' ],
      'permission_callback' => Xophz_Compass_Bazaar_Security_Guard::rest_permission( $caps::SELL ),
    ] );
  }

  /** @return WP_REST_Response|WP_Error */
  public function submit( WP_REST_Request $request ) {
    return Xophz_Compass_Bazaar_Core_Rest::handle( function () use ( $request ) {
      if ( ! Xophz_Compass_Bazaar_Checkout_Service::enabled() ) {
        throw new Xophz_Compass_Bazaar_Core_Http_Error( 'checkout_disabled', 'Checkout v2 is turned off on this site.', 403 );
      }
      $checkout = Xophz_Compass_Bazaar_Checkout_Request::from_array(
        Xophz_Compass_Bazaar_Core_Rest::json_body( $request ),
        (string) ( $request->get_header( 'idempotency_key' ) ?? '' )
      );
      $result   = ( new Xophz_Compass_Bazaar_Checkout_Service() )->submit( $checkout, Xophz_Compass_Bazaar_Security_Guard::context() );
      $response = new WP_REST_Response( $result['body'], $result['status'] );
      $response->header( 'Cache-Control', 'no-store' );
      return $response;
    } );
  }

  /** @return WP_REST_Response|WP_Error */
  public function status( WP_REST_Request $request ) {
    return Xophz_Compass_Bazaar_Core_Rest::handle( function () use ( $request ) {
      $any  = Xophz_Compass_Bazaar_Security_Guard::can_any( Xophz_Compass_Bazaar_Security_Capabilities::MANAGE_ORDERS );
      $body = ( new Xophz_Compass_Bazaar_Checkout_Service() )->status( strtolower( (string) $request['key'] ), Xophz_Compass_Bazaar_Security_Guard::context(), $any );
      if ( null === $body ) {
        throw new Xophz_Compass_Bazaar_Core_Http_Error( 'checkout_not_found', 'No checkout with this key.', 404 );
      }
      $response = new WP_REST_Response( $body, 200 );
      $response->header( 'Cache-Control', 'no-store' );
      return $response;
    } );
  }

  /** @return WP_REST_Response|WP_Error */
  public function shift_summary( WP_REST_Request $request ) {
    return Xophz_Compass_Bazaar_Core_Rest::handle( function () use ( $request ) {
      $ctx   = Xophz_Compass_Bazaar_Security_Guard::context();
      $since = (string) ( $request->get_param( 'since' ) ?? '' );
      $scope = [];
      if ( '' === $since && $ctx->staff_session_id > 0 ) {
        $scope['staff_session_id'] = $ctx->staff_session_id;
      } else {
        $scope['from'] = '' === $since ? self::local_midnight_utc() : self::utc( $since );
        $scope['to']   = gmdate( 'Y-m-d H:i:s', time() + 1 );
      }
      $caps = 'Xophz_Compass_Bazaar_Security_Capabilities';
      if ( ! Xophz_Compass_Bazaar_Security_Guard::can_any( $caps::VIEW_REPORTS, $caps::MANAGE_ORDERS ) ) {
        $scope['cashier_user_id'] = $ctx->acting_user_id;
      }
      return self::summary( $scope );
    } );
  }

  /**
   * The spec 14.1 shift body: payments from Payments_Shift_Summary, order
   * count, tips and discounts from completed checkouts in the same scope.
   * gross_minor counts captured, pending and authorized captures.
   */
  public static function summary( array $scope ): array {
    $sum     = Xophz_Compass_Bazaar_Payments_Shift_Summary::summarize( $scope );
    $by_kind = [];
    foreach ( $sum['methods'] as $method_id => $m ) {
      $by_kind[] = [ 'kind' => $m['kind'], 'method_id' => (string) $method_id, 'count' => $m['count'], 'amount_minor' => $m['captured_minor'] + $m['pending_minor'] ];
    }
    $where = ! empty( $scope['staff_session_id'] ) ? 'staff_session_id = %d' : 'completed_at >= %s AND completed_at < %s';
    $args  = ! empty( $scope['staff_session_id'] ) ? [ (int) $scope['staff_session_id'] ] : [ $scope['from'], $scope['to'] ];
    if ( ! empty( $scope['cashier_user_id'] ) ) {
      $where .= ' AND cashier_user_id = %d';
      $args[] = (int) $scope['cashier_user_id'];
    }
    $row = Xophz_Compass_Bazaar_Core_Db::get_row(
      'SELECT COUNT(*) AS n, COALESCE(SUM(tip_minor), 0) AS tips, COALESCE(SUM(discount_minor), 0) AS discounts FROM '
      . Xophz_Compass_Bazaar_Core_Db::table( 'checkouts' ) . " WHERE state = 'completed' AND $where",
      $args
    );
    return [
      'window'          => [
        'from'             => $scope['from'] ?? null,
        'to'               => $scope['to'] ?? null,
        'staff_session_id' => (int) ( $scope['staff_session_id'] ?? 0 ),
        'cashier_user_id'  => (int) ( $scope['cashier_user_id'] ?? 0 ),
      ],
      'orders'          => $row ? (int) $row->n : 0,
      'gross_minor'     => $sum['totals']['captured_minor'] + $sum['totals']['pending_minor'],
      'tips_minor'      => $row ? (int) $row->tips : 0,
      'discounts_minor' => $row ? (int) $row->discounts : 0,
      'refunds_minor'   => $sum['totals']['refunded_minor'],
      'by_kind'         => $by_kind,
      'void_required'   => $sum['void_required'],
      'totals'          => $sum['totals'],
    ];
  }

  /** Today's 00:00 in the store's time zone, as UTC 'Y-m-d H:i:s'. */
  private static function local_midnight_utc(): string {
    $zone = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
    return ( new DateTimeImmutable( 'today', $zone ) )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
  }

  /** @throws Xophz_Compass_Bazaar_Core_Http_Error 400 invalid_request */
  private static function utc( string $since ): string {
    try {
      $time = new DateTimeImmutable( $since, new DateTimeZone( 'UTC' ) );
    } catch ( Exception $e ) {
      throw new Xophz_Compass_Bazaar_Core_Http_Error( 'invalid_request', 'since must be an ISO 8601 time.', 400, [ 'errors' => [ [ 'path' => 'since', 'message' => 'Must be an ISO 8601 time.' ] ] ] );
    }
    return $time->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
  }
}
