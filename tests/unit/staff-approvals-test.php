<?php
/**
 * Staff_Approvals::evaluate (the pure check behind peek and consume) and
 * token hashing. The database paths are covered by tests/integration/staff.sh.
 * Run alone: php wp-content/plugins/xophz-compass-bazaar/tests/unit/staff-approvals-test.php
 */
require_once dirname( __DIR__ ) . '/support/harness.php';
$root = dirname( dirname( __DIR__ ) );
require_once $root . '/includes/core/class-xophz-compass-bazaar-core-autoloader.php';
Xophz_Compass_Bazaar_Core_Autoloader::register( $root );

$A       = 'Xophz_Compass_Bazaar_Staff_Approvals';
$now     = strtotime( '2026-11-01 12:00:00 UTC' );
$subject = str_repeat( 'a', 64 );
$row     = [ 'id' => 9, 'scope' => 'refund', 'subject_hash' => $subject, 'amount_minor' => 5000, 'requested_by' => 7, 'consumed_at' => null, 'expires_at' => '2026-11-01 12:05:00' ];
$eval    = function ( array $changes = [], array $ask = [] ) use ( $A, $row, $subject, $now ) {
  $r = array_merge( $row, $changes );
  $a = array_merge( [ 'scope' => 'refund', 'subject' => $subject, 'amount' => 5000, 'requester' => 7, 'now' => $now ], $ask );
  return $A::evaluate( $r, $a['scope'], $a['subject'], $a['amount'], $a['requester'], $a['now'] );
};

check( 'a matching approval is accepted', $eval(), '' );
check( 'a smaller amount than approved is accepted', $eval( [], [ 'amount' => 1 ] ), '' );
check( 'no row is unknown', $A::evaluate( null, 'refund', $subject, 1, 7, $now ), 'unknown' );
check( 'a spent token is refused', $eval( [ 'consumed_at' => '2026-11-01 12:01:00' ] ), 'consumed' );
check( 'an expired token is refused', $eval( [], [ 'now' => strtotime( '2026-11-01 12:05:00 UTC' ) ] ), 'expired' );
check( 'one second before expiry is accepted', $eval( [], [ 'now' => strtotime( '2026-11-01 12:04:59 UTC' ) ] ), '' );
check( 'another scope is refused', $eval( [], [ 'scope' => 'discount' ] ), 'scope' );
check( 'another subject is refused', $eval( [], [ 'subject' => str_repeat( 'b', 64 ) ] ), 'subject' );
check( 'another requester is refused', $eval( [], [ 'requester' => 8 ] ), 'requester' );
check( 'a larger amount than approved is refused', $eval( [], [ 'amount' => 5001 ] ), 'amount' );
check( 'an object row works like an array row', $A::evaluate( (object) $row, 'refund', $subject, 5000, 7, $now ), '' );

check( 'token hash is sha256 hex', $A::hash_token( 'abc' ), 'ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad' );
check( 'TTL is 300 seconds', $A::TTL_SECONDS, 300 );

bazaar_test_standalone( __FILE__ );
