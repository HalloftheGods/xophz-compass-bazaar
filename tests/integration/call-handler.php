<?php
/**
 * Call one Bazaar admin-ajax handler from WP-CLI, as an administrator with a
 * valid wp_rest nonce unless told otherwise. Used by pos-authorization.sh.
 *
 * Env: CALL_CLASS, CALL_METHOD, CALL_ARGS (JSON, copied into $_REQUEST and
 * $_GET), CALL_LOGGED_OUT=1, CALL_BAD_NONCE=1, CALL_NO_NONCE=1,
 * CALL_PORTAL=on|off (forces the test portal filter).
 */
$admins = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
wp_set_current_user( (int) $admins[0] );
$_SERVER['HTTP_X_WP_NONCE'] = wp_create_nonce( 'wp_rest' );

foreach ( json_decode( getenv( 'CALL_ARGS' ) ?: '{}', true ) as $key => $value ) {
  $_REQUEST[ $key ] = $_GET[ $key ] = is_array( $value ) ? wp_json_encode( $value ) : (string) $value;
}
if ( getenv( 'CALL_BAD_NONCE' ) ) {
  $_SERVER['HTTP_X_WP_NONCE'] = 'not-a-nonce';
}
if ( getenv( 'CALL_NO_NONCE' ) ) {
  unset( $_SERVER['HTTP_X_WP_NONCE'] );
}
if ( getenv( 'CALL_LOGGED_OUT' ) ) {
  wp_set_current_user( 0 );
}
if ( getenv( 'CALL_PORTAL' ) ) {
  $portal_on = 'on' === getenv( 'CALL_PORTAL' );
  add_filter( 'xophz_compass_bazaar_pos_test_portal', function () use ( $portal_on ) {
    return $portal_on;
  } );
}
$class  = getenv( 'CALL_CLASS' );
$method = getenv( 'CALL_METHOD' );
( new $class( 'xophz-compass-bazaar', 'integration' ) )->{$method}();
