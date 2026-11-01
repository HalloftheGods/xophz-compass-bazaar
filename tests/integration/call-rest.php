<?php
/**
 * Run one REST request in-process with rest_do_request(), as a chosen user.
 * Used by the integration scripts through lib.sh (rest).
 *
 * Env: REST_METHOD (default GET), REST_ROUTE (for example /bazaar/v1/pos/config),
 * REST_BODY (JSON text, optional), REST_USER (user ID or login; "0" = logged
 * out; empty = the first administrator).
 *
 * Prints one JSON line: {"status":<int>,"data":<response data>}.
 * Nonce checks are skipped on purpose: rest_do_request() does not go through
 * the cookie-nonce step, so this checks permission callbacks and handlers,
 * not CSRF.
 */
$method = strtoupper( getenv( 'REST_METHOD' ) ?: 'GET' );
$route  = (string) getenv( 'REST_ROUTE' );
$body   = (string) getenv( 'REST_BODY' );
$who    = (string) getenv( 'REST_USER' );

if ( '0' === $who ) {
  wp_set_current_user( 0 );
} elseif ( '' === $who ) {
  $admins = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
  wp_set_current_user( (int) $admins[0] );
} else {
  $user = ctype_digit( $who ) ? get_user_by( 'id', (int) $who ) : get_user_by( 'login', $who );
  if ( ! $user ) {
    echo wp_json_encode( [ 'status' => 0, 'data' => [ 'error' => 'unknown REST_USER' ] ] ) . "\n";
    exit( 1 );
  }
  wp_set_current_user( $user->ID );
}

$request = new WP_REST_Request( $method, $route );
if ( '' !== $body ) {
  $request->set_header( 'Content-Type', 'application/json' );
  $request->set_body( $body );
}
$response = rest_do_request( $request );
$server   = rest_get_server();
echo wp_json_encode( [ 'status' => $response->get_status(), 'data' => $server->response_to_data( $response, false ) ] ) . "\n";
