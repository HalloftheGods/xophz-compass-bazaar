<?php
/**
 * Pos_Authorization::peek: same answers as consume, never spends the token.
 * Run alone: php wp-content/plugins/xophz-compass-bazaar/tests/unit/pos-authorization-peek-test.php
 */
require_once dirname( __DIR__ ) . '/support/harness.php';
require_once dirname( dirname( __DIR__ ) ) . '/includes/class-xophz-compass-bazaar-pos-authorization.php';

$A = 'Xophz_Compass_Bazaar_Pos_Authorization';

$token = $A::issue( 7, 'paypal', '42.10', 1000 );
check( 'peek: valid token passes', $A::peek( $token, 7, 'paypal', 42.1, 1001 ), '' );
check( 'peek: still valid the second time', $A::peek( $token, 7, 'paypal', 42.1, 1001 ), '' );
check( 'peek: token still stored', count( $GLOBALS['test_transients'] ) >= 1, true );
check( 'consume after peek works', $A::consume( $token, 7, 'paypal', 42.1, 1001 ), '' );
check( 'consume works only once', $A::consume( $token, 7, 'paypal', 42.1, 1001 ), 'unknown or already used' );
check( 'peek after consume: unknown', $A::peek( $token, 7, 'paypal', 42.1, 1001 ), 'unknown or already used' );

check( 'peek: empty token is missing', $A::peek( '', 7, 'paypal', 1, 1001 ), 'missing' );
check( 'peek: forged token', $A::peek( 'not-a-real-token-1234567890', 7, 'paypal', 1, 1001 ), 'unknown or already used' );

// Refusals do not spend the token either: the real spend is consume().
$t = $A::issue( 7, 'paypal', 10, 1000 );
check( 'peek: other cashier', $A::peek( $t, 8, 'paypal', 10, 1001 ), 'issued to another cashier' );
check( 'peek: other gateway', $A::peek( $t, 7, 'affirm', 10, 1001 ), 'issued for another payment method' );
check( 'peek: other total', $A::peek( $t, 7, 'paypal', 10.01, 1001 ), 'issued for a different total' );
check( 'peek: expired', $A::peek( $t, 7, 'paypal', 10, 1000 + $A::TTL_SECONDS + 1 ), 'expired' );
check( 'peek: refusals left the token valid', $A::peek( $t, 7, 'paypal', 10, 1001 ), '' );
check( 'consume still accepts it', $A::consume( $t, 7, 'paypal', 10, 1001 ), '' );

bazaar_test_standalone( __FILE__ );
