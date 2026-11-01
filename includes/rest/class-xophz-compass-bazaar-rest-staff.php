<?php
/**
 * REST routes for staff PINs, staff sessions and manager approvals
 * (namespace bazaar/v1, spec sections 13 and 14.1).
 *
 * GET    /pos/staff                       sell
 * POST   /pos/staff/sessions              sell   {staff_user_id, pin, location_id?}
 * GET    /pos/staff/sessions/current      sell   (needs X-Bazaar-Staff-Session)
 * DELETE /pos/staff/sessions/current      sell   (needs X-Bazaar-Staff-Session)
 * PUT    /pos/staff/me/pin                sell   {pin, current_pin | current_password}
 * PUT    /pos/staff/{id}/pin              manage_staff {pin}
 * POST   /pos/approvals                   sell   {scope, subject_hash, amount_minor, basis_points, approver_user_id, approver_pin, reason}
 *
 * Errors use {code, message, data:{status}}; none answers 401 except the
 * guard's rest_not_logged_in. PIN errors: 403 pin_invalid, 423 pin_locked,
 * 429 pin_throttled, 422 pin_format.
 *
 * @package Xophz_Compass_Bazaar
 */
class Xophz_Compass_Bazaar_Rest_Staff {

  const NAMESPACE_V1 = 'bazaar/v1';

  public function register_routes(): void {
    $sell   = Xophz_Compass_Bazaar_Security_Guard::rest_permission( Xophz_Compass_Bazaar_Security_Capabilities::SELL );
    $manage = Xophz_Compass_Bazaar_Security_Guard::rest_permission( Xophz_Compass_Bazaar_Security_Capabilities::MANAGE_STAFF );
    $ns     = self::NAMESPACE_V1;

    register_rest_route( $ns, '/pos/staff', [ 'methods' => 'GET', 'callback' => [ $this, 'list_staff' ], 'permission_callback' => $sell ] );
    register_rest_route( $ns, '/pos/staff/sessions', [ 'methods' => 'POST', 'callback' => [ $this, 'start_session' ], 'permission_callback' => $sell ] );
    register_rest_route( $ns, '/pos/staff/sessions/current', [
      [ 'methods' => 'GET', 'callback' => [ $this, 'current_session' ], 'permission_callback' => $sell ],
      [ 'methods' => 'DELETE', 'callback' => [ $this, 'end_session' ], 'permission_callback' => $sell ],
    ] );
    register_rest_route( $ns, '/pos/staff/me/pin', [ 'methods' => 'PUT', 'callback' => [ $this, 'set_own_pin' ], 'permission_callback' => $sell ] );
    register_rest_route( $ns, '/pos/staff/(?P<id>\d+)/pin', [ 'methods' => 'PUT', 'callback' => [ $this, 'set_pin' ], 'permission_callback' => $manage ] );
    register_rest_route( $ns, '/pos/approvals', [ 'methods' => 'POST', 'callback' => [ $this, 'create_approval' ], 'permission_callback' => $sell ] );
  }

  public function list_staff( $request ) {
    return Xophz_Compass_Bazaar_Core_Rest::handle( function () {
      return [ 'staff' => Xophz_Compass_Bazaar_Staff_Directory::enrolled() ];
    } );
  }

  public function start_session( $request ) {
    return Xophz_Compass_Bazaar_Core_Rest::handle( function () use ( $request ) {
      $body    = Xophz_Compass_Bazaar_Core_Rest::json_body( $request );
      $context = Xophz_Compass_Bazaar_Security_Guard::context();
      $staff   = (int) ( $body['staff_user_id'] ?? 0 );
      $pin     = isset( $body['pin'] ) && is_scalar( $body['pin'] ) ? (string) $body['pin'] : '';
      if ( $staff <= 0 || '' === $pin ) {
        throw new Xophz_Compass_Bazaar_Core_Http_Error( 'invalid_request', 'staff_user_id and pin are required.', 400, [ 'errors' => [ 'staff_user_id', 'pin' ] ] );
      }
      return Xophz_Compass_Bazaar_Staff_Sessions::start( $staff, $pin, $context->operator_user_id, $context->device_id, (int) ( $body['location_id'] ?? 0 ) );
    } );
  }

  public function current_session( $request ) {
    return Xophz_Compass_Bazaar_Core_Rest::handle( function () {
      $context = Xophz_Compass_Bazaar_Security_Guard::context();
      if ( ! $context->is_staff_session() ) {
        throw new Xophz_Compass_Bazaar_Core_Http_Error( 'no_staff_session', 'There is no staff session on this request.', 404 );
      }
      return [ 'session' => Xophz_Compass_Bazaar_Staff_Sessions::get( $context->staff_session_id ) ];
    } );
  }

  public function end_session( $request ) {
    return Xophz_Compass_Bazaar_Core_Rest::handle( function () {
      $context = Xophz_Compass_Bazaar_Security_Guard::context();
      if ( ! $context->is_staff_session() ) {
        throw new Xophz_Compass_Bazaar_Core_Http_Error( 'no_staff_session', 'There is no staff session on this request.', 404 );
      }
      return [ 'ended' => Xophz_Compass_Bazaar_Staff_Sessions::end( $context->staff_session_id, 'ended' ) ];
    } );
  }

  /**
   * Set the acting user's own PIN. The proof is the current PIN (lockout
   * applies) or the current WordPress password. Wrong passwords are not
   * counted or locked here.
   */
  public function set_own_pin( $request ) {
    return Xophz_Compass_Bazaar_Core_Rest::handle( function () use ( $request ) {
      $body    = Xophz_Compass_Bazaar_Core_Rest::json_body( $request );
      $context = Xophz_Compass_Bazaar_Security_Guard::context();
      $uid     = $context->acting_user_id;
      $pin     = isset( $body['pin'] ) && is_scalar( $body['pin'] ) ? (string) $body['pin'] : '';
      if ( isset( $body['current_pin'] ) && is_scalar( $body['current_pin'] ) && '' !== (string) $body['current_pin'] ) {
        Xophz_Compass_Bazaar_Staff_Pins::verify( $uid, (string) $body['current_pin'], $context->device_id );
      } elseif ( isset( $body['current_password'] ) && is_string( $body['current_password'] ) && '' !== $body['current_password'] ) {
        $user = get_userdata( $uid );
        if ( ! $user || ! wp_check_password( $body['current_password'], $user->user_pass, $uid ) ) {
          throw new Xophz_Compass_Bazaar_Core_Http_Error( 'proof_invalid', 'The current password is not correct.', 403 );
        }
      } else {
        throw new Xophz_Compass_Bazaar_Core_Http_Error( 'invalid_request', 'Send current_pin or current_password.', 400, [ 'errors' => [ 'current_pin', 'current_password' ] ] );
      }
      Xophz_Compass_Bazaar_Staff_Pins::set_pin( $uid, $pin, $uid );
      return [ 'updated' => true ];
    } );
  }

  public function set_pin( $request ) {
    return Xophz_Compass_Bazaar_Core_Rest::handle( function () use ( $request ) {
      $body   = Xophz_Compass_Bazaar_Core_Rest::json_body( $request );
      $target = (int) $request['id'];
      if ( $target <= 0 || ! get_userdata( $target ) ) {
        throw new Xophz_Compass_Bazaar_Core_Http_Error( 'not_found', 'No such user.', 404 );
      }
      $pin = isset( $body['pin'] ) && is_scalar( $body['pin'] ) ? (string) $body['pin'] : '';
      Xophz_Compass_Bazaar_Staff_Pins::set_pin( $target, $pin, Xophz_Compass_Bazaar_Security_Guard::context()->acting_user_id );
      return [ 'updated' => true ];
    } );
  }

  public function create_approval( $request ) {
    return Xophz_Compass_Bazaar_Core_Rest::handle( function () use ( $request ) {
      $body    = Xophz_Compass_Bazaar_Core_Rest::json_body( $request );
      $context = Xophz_Compass_Bazaar_Security_Guard::context();
      $body['device_id']        = $context->device_id;
      $body['staff_session_id'] = $context->staff_session_id;
      $body['approver_pin']     = isset( $body['approver_pin'] ) && is_scalar( $body['approver_pin'] ) ? (string) $body['approver_pin'] : '';
      return Xophz_Compass_Bazaar_Staff_Approvals::create( $context->acting_user_id, $body );
    } );
  }
}
