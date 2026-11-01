<?php
/**
 * Fire Bazaar admin-ajax actions through WordPress (and so through
 * Security_Guard) from WP-CLI. Used by guard.sh through lib.sh (wp_file).
 *
 * Env:
 *   CALL_ACTION         one action, or several separated by commas (no wp_ajax_ prefix)
 *   CALL_ARGS           JSON object copied into $_REQUEST and $_POST
 *   CALL_USER           admin (default) | role:<role> (newest user with it) | id:<n> | logged-out
 *   CALL_BAD_NONCE=1    X-WP-Nonce is not a nonce
 *   CALL_NO_NONCE=1     no X-WP-Nonce header
 *   CALL_SECURITY_FIELD=1|bad  no header; a valid (or, with "bad", an invalid) nonce in the `security` field
 *   CALL_DRY_RUN=1      turn on the guard's dry run (refused unless the site is local or development)
 *   CALL_REPORT_ONLY=1  define XOPHZ_BAZAAR_GUARD_REPORT_ONLY as true for this process
 *
 * Defines DOING_AJAX, sets the nonce for the chosen user, then runs
 * do_action( 'wp_ajax_' . action ) for each action. wp_die is turned into an
 * exception so one process can answer several actions.
 *
 * Prints one line per action: "<action>\t<response body>". The body is
 * NOT_GUARDED (and the action is not fired) when the first callback on the
 * hook is not a Security_Guarded_Callback, and NO_RESPONSE when nothing
 * answered. Exits 1 on a setup error.
 */
if ( ! defined( 'DOING_AJAX' ) ) {
  define( 'DOING_AJAX', true );
}

$who = (string) ( getenv( 'CALL_USER' ) ?: 'admin' );
if ( 'logged-out' === $who ) {
  wp_set_current_user( 0 );
} elseif ( 'admin' === $who ) {
  $ids = get_users( [ 'role' => 'administrator', 'number' => 1, 'orderby' => 'ID', 'order' => 'ASC', 'fields' => 'ID' ] );
  wp_set_current_user( (int) ( $ids[0] ?? 0 ) );
} elseif ( 0 === strpos( $who, 'role:' ) ) {
  $ids = get_users( [ 'role' => substr( $who, 5 ), 'number' => 1, 'orderby' => 'ID', 'order' => 'DESC', 'fields' => 'ID' ] );
  wp_set_current_user( (int) ( $ids[0] ?? 0 ) );
} elseif ( 0 === strpos( $who, 'id:' ) ) {
  wp_set_current_user( (int) substr( $who, 3 ) );
} else {
  echo "call-ajax: unknown CALL_USER $who\n";
  exit( 1 );
}
if ( 'logged-out' !== $who && ! get_current_user_id() ) {
  echo "call-ajax: no user for CALL_USER $who\n";
  exit( 1 );
}

if ( getenv( 'CALL_DRY_RUN' ) ) {
  if ( ! in_array( wp_get_environment_type(), [ 'local', 'development' ], true ) ) {
    echo 'call-ajax: dry run needs a local or development site; this one is ' . wp_get_environment_type() . "\n";
    exit( 1 );
  }
  add_filter( 'xophz_compass_bazaar_guard_dry_run', '__return_true' );
}
if ( getenv( 'CALL_REPORT_ONLY' ) && ! defined( 'XOPHZ_BAZAAR_GUARD_REPORT_ONLY' ) ) {
  define( 'XOPHZ_BAZAAR_GUARD_REPORT_ONLY', true );
}

$nonce = wp_create_nonce( 'wp_rest' );
unset( $_SERVER['HTTP_X_WP_NONCE'], $_REQUEST['_wpnonce'], $_REQUEST['security'] );
$field = (string) getenv( 'CALL_SECURITY_FIELD' );
if ( '' !== $field ) {
  $_REQUEST['security'] = $_POST['security'] = 'bad' === $field ? 'not-a-nonce' : $nonce;
} elseif ( getenv( 'CALL_BAD_NONCE' ) ) {
  $_SERVER['HTTP_X_WP_NONCE'] = 'not-a-nonce';
} elseif ( ! getenv( 'CALL_NO_NONCE' ) ) {
  $_SERVER['HTTP_X_WP_NONCE'] = $nonce;
}

foreach ( json_decode( getenv( 'CALL_ARGS' ) ?: '{}', true ) ?: [] as $key => $value ) {
  $_REQUEST[ $key ] = $_POST[ $key ] = is_array( $value ) ? wp_json_encode( $value ) : (string) $value;
}
$request_base = $_REQUEST;

add_filter( 'wp_die_ajax_handler', function () {
  return function ( $message = '' ) {
    throw new RuntimeException( is_scalar( $message ) ? (string) $message : '', 4242 );
  };
} );

/** True when the first callback WordPress would run for the hook is the Bazaar guard. */
$first_is_guarded = function ( string $hook ): bool {
  global $wp_filter;
  if ( empty( $wp_filter[ $hook ] ) ) {
    return false;
  }
  $callbacks = $wp_filter[ $hook ]->callbacks;
  ksort( $callbacks );
  foreach ( $callbacks as $at_priority ) {
    foreach ( $at_priority as $callback ) {
      return $callback['function'] instanceof Xophz_Compass_Bazaar_Security_Guarded_Callback;
    }
  }
  return false;
};

foreach ( array_filter( array_map( 'trim', explode( ',', (string) getenv( 'CALL_ACTION' ) ) ) ) as $action ) {
  $_REQUEST           = $request_base;
  $_REQUEST['action'] = $_POST['action'] = $action;
  if ( ! $first_is_guarded( 'wp_ajax_' . $action ) ) {
    echo $action . "\tNOT_GUARDED\n";
    continue;
  }
  ob_start();
  try {
    do_action( 'wp_ajax_' . $action );
  } catch ( RuntimeException $e ) {
    if ( 4242 !== $e->getCode() ) {
      echo 'EXCEPTION ' . get_class( $e ) . ': ' . $e->getMessage();
    }
  }
  $body = trim( (string) ob_get_clean() );
  echo $action . "\t" . ( '' === $body ? 'NO_RESPONSE' : str_replace( "\n", ' ', $body ) ) . "\n";
}
