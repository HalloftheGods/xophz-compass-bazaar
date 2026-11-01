<?php
/**
 * Checkout_Tender_Plan::repeated_uuid (#6985): a payment_uuid repeated within
 * one request is found before any order exists (case-insensitive; empty
 * UUIDs are the server's to mint and never collide).
 */
require_once dirname( __DIR__ ) . '/support/harness.php';
$plugin = dirname( dirname( __DIR__ ) );
require_once $plugin . '/includes/core/class-xophz-compass-bazaar-core-http-error.php';
require_once $plugin . '/includes/payments/class-xophz-compass-bazaar-payments-tender.php';
require_once $plugin . '/includes/checkout/class-xophz-compass-bazaar-checkout-tender-plan.php';

$P  = 'Xophz_Compass_Bazaar_Checkout_Tender_Plan';
$tn = function ( string $uuid = '' ) {
  return Xophz_Compass_Bazaar_Payments_Tender::from_array( [ 'method_id' => 'cash', 'amount_minor' => 100, 'payment_uuid' => $uuid ] );
};
$u1 = '3f2b8c1e-9a4d-4e5f-8a6b-1c2d3e4f5a6b';
$u2 = '7a1b2c3d-4e5f-4a6b-9c8d-0e1f2a3b4c5d';

check( 'tender plan: no tenders, no repeat', $P::repeated_uuid( [] ), null );
check( 'tender plan: distinct uuids', $P::repeated_uuid( [ $tn( $u1 ), $tn( $u2 ) ] ), null );
check( 'tender plan: empty uuids never collide', $P::repeated_uuid( [ $tn(), $tn(), $tn( $u1 ) ] ), null );
check( 'tender plan: the second use is reported', $P::repeated_uuid( [ $tn( $u1 ), $tn( $u2 ), $tn( $u1 ) ] ), 2 );
check( 'tender plan: case does not hide a repeat', $P::repeated_uuid( [ $tn( $u1 ), $tn( strtoupper( $u1 ) ) ] ), 1 );

bazaar_test_standalone( __FILE__ );
