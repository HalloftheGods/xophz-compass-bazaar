<?php
/**
 * Test-portal token amounts follow the store's price decimals (0, 2, 3), not a fixed 100.
 * Run alone: php wp-content/plugins/xophz-compass-bazaar/tests/unit/payments-test-portal-decimals-test.php
 */
require_once dirname( __DIR__ ) . '/support/harness.php';
require_once dirname( dirname( __DIR__ ) ) . '/includes/core/class-xophz-compass-bazaar-core-money.php';
require_once dirname( dirname( __DIR__ ) ) . '/includes/class-xophz-compass-bazaar-pos-authorization.php';

$A = 'Xophz_Compass_Bazaar_Pos_Authorization';
$M = 'Xophz_Compass_Bazaar_Core_Money';

// Issue side stores major units; checkout side feeds from_minor(amount_minor).
// [decimals, major amount issued, amount_minor on the tender]
$cases = [ [ 0, '1000', 1000 ], [ 2, '10.50', 1050 ], [ 3, '10.500', 10500 ] ];
foreach ( $cases as [ $d, $issued, $minor ] ) {
  $record = $A::record( 7, 'paypal', $issued, 1000, $d );
  check( "decimals $d: stored minor", $record['cents'], $minor );
  check( "decimals $d: matching tender passes", $A::check( $record, 7, 'paypal', $M::from_minor( $minor, $d ), 1001, $d ), '' );
  check( "decimals $d: one minor unit off is refused", $A::check( $record, 7, 'paypal', $M::from_minor( $minor + 1, $d ), 1001, $d ), 'issued for a different total' );
}

// The old bug: 0 decimals, 1000 issued, tender 1000 minor was compared as 10 -> never matched.
check( 'jpy: old /100 conversion would have refused', $A::check( $A::record( 7, 'paypal', '1000', 1000, 0 ), 7, 'paypal', 10, 1001, 0 ), 'issued for a different total' );
check( 'non-numeric amount never matches', $A::check( $A::record( 7, 'paypal', '1', 1000, 2 ), 7, 'paypal', 'abc', 1001, 2 ), 'issued for a different total' );

bazaar_test_standalone( __FILE__ );
