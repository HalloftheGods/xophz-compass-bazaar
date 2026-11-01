<?php
/**
 * Staff_Pins format and throttle rules, Staff_Sessions expiry rules and
 * Staff_Directory shaping. The database paths (lockout, sessions) are
 * covered by tests/integration/staff.sh.
 * Run alone: php wp-content/plugins/xophz-compass-bazaar/tests/unit/staff-pins-test.php
 */
require_once dirname( __DIR__ ) . '/support/harness.php';
$root = dirname( dirname( __DIR__ ) );
require_once $root . '/includes/core/class-xophz-compass-bazaar-core-autoloader.php';
Xophz_Compass_Bazaar_Core_Autoloader::register( $root );

$P = 'Xophz_Compass_Bazaar_Staff_Pins';
check( 'a 4-digit PIN is fine', $P::format_error( '2947' ), '' );
check( 'an 8-digit PIN is fine', $P::format_error( '29471836' ), '' );
check( '3 digits is too short', $P::format_error( '294' ), 'length' );
check( '9 digits is too long', $P::format_error( '294718365' ), 'length' );
check( 'letters are refused', $P::format_error( '29a7' ), 'digits' );
check( 'empty is refused', $P::format_error( '' ), 'digits' );
check( 'an int is refused', $P::format_error( 2947 ), 'digits' );
check( 'a space is refused', $P::format_error( '2947 ' ), 'digits' );
check( 'twenty common PINs', count( $P::COMMON ), 20 );
check( 'the common PINs are distinct', count( array_unique( $P::COMMON ) ), 20 );
check( 'every common PIN is refused', array_values( array_unique( array_map( [ $P, 'format_error' ], $P::COMMON ) ) ), [ 'common' ] );
check( 'a message exists for each reason', array_map( function ( $r ) use ( $P ) { return '' !== $P::format_message( $r ); }, [ 'length', 'digits', 'common' ] ), [ true, true, true ] );
check( 'lockout is five failures for 15 minutes', [ $P::MAX_FAILURES, $P::LOCK_SECONDS ], [ 5, 900 ] );

// is_locked
$now = strtotime( '2026-11-01 12:00:00 UTC' );
check( 'locked in the future', $P::is_locked( '2026-11-01 12:00:01', $now ), true );
check( 'not locked at the boundary', $P::is_locked( '2026-11-01 12:00:00', $now ), false );
check( 'not locked when empty or null', [ $P::is_locked( '', $now ), $P::is_locked( null, $now ) ], [ false, false ] );

// device throttle
$s = false;
for ( $i = 1; $i <= 20; $i++ ) {
  check( "throttle: not blocked before failure $i", $P::throttle_blocked( $s, $now ), false );
  $s = $P::throttle_next( $s, $now + $i );
}
check( 'throttle: blocked after 20 failures', $P::throttle_blocked( $s, $now + 21 ), true );
check( 'throttle: window starts at the first failure', $s['t'], $now + 1 );
check( 'throttle: open again after 15 minutes', $P::throttle_blocked( $s, $now + 1 + 900 ), false );
check( 'throttle: a failure after the window restarts the count', $P::throttle_next( $s, $now + 1 + 900 ), [ 'n' => 1, 't' => $now + 901 ] );
check( 'throttle: garbage state is a fresh window', $P::throttle_next( 'x', $now ), [ 'n' => 1, 't' => $now ] );

// sessions
$S   = 'Xophz_Compass_Bazaar_Staff_Sessions';
$row = [ 'ended_at' => null, 'idle_expires_at' => '2026-11-01 12:10:00', 'expires_at' => '2026-11-01 23:00:00' ];
check( 'session: live', $S::is_live( $row, $now ), true );
check( 'session: idle expiry ends it', $S::is_live( $row, strtotime( '2026-11-01 12:10:00 UTC' ) ), false );
check( 'session: absolute expiry ends it', $S::is_live( array_merge( $row, [ 'idle_expires_at' => '2026-11-02 00:00:00' ] ), strtotime( '2026-11-01 23:00:00 UTC' ) ), false );
check( 'session: ended is not live', $S::is_live( array_merge( $row, [ 'ended_at' => '2026-11-01 11:00:00' ] ), $now ), false );
check( 'session: an object row works', $S::is_live( (object) $row, $now ), true );
check( 'session: idle slides 15 minutes', $S::next_idle( $now, $now + 43200 ), $now + 900 );
check( 'session: idle never passes the absolute expiry', $S::next_idle( $now, $now + 60 ), $now + 60 );
check( 'session: 15 minutes idle and 12 hours absolute', [ $S::IDLE_SECONDS, $S::ABSOLUTE_SECONDS ], [ 900, 43200 ] );
check( 'session: token hash is 64 hex', (bool) preg_match( '/^[0-9a-f]{64}$/', $S::hash_token( 'x' ) ), true );

// directory
$D = 'Xophz_Compass_Bazaar_Staff_Directory';
check( 'directory: sorted by name, bad ids dropped, blank name is Unknown, no other fields', $D::shape( [
  [ 'id' => 3, 'display_name' => 'zed', 'avatar_url' => 'u3', 'email' => 'x@example.test' ],
  [ 'id' => 0, 'display_name' => 'nobody' ],
  [ 'id' => 2, 'display_name' => ' ', 'avatar_url' => 'u2' ],
  [ 'id' => 1, 'display_name' => 'Amy' ],
] ), [
  [ 'id' => 1, 'display_name' => 'Amy', 'avatar_url' => '' ],
  [ 'id' => 2, 'display_name' => 'Unknown', 'avatar_url' => 'u2' ],
  [ 'id' => 3, 'display_name' => 'zed', 'avatar_url' => 'u3' ],
] );

bazaar_test_standalone( __FILE__ );
