<?php
/**
 * Plain-PHP tests for Bazaar logic that does not need WordPress.
 *
 * Run: php wp-content/plugins/xophz-compass-bazaar/tests/run-tests.php
 * Exits 1 on any failure.
 */

// Minimal WordPress i18n stubs for the status filters.
function _x( $text ) { return $text; }
function _n_noop( $single, $plural ) { return [ 0 => $single, 1 => $plural, 'singular' => $single, 'plural' => $plural ]; }

$root = dirname( __DIR__ );
require_once $root . '/includes/class-xophz-compass-bazaar-sale-pricing.php';
require_once $root . '/admin/class-xophz-compass-bazaar-admin-fulfillment.php';

$failures = 0;
$passes   = 0;
function check( string $name, $actual, $expected ) {
  global $failures, $passes;
  if ( $actual === $expected ) {
    $passes++;
    return;
  }
  $failures++;
  fwrite( STDERR, "FAIL $name\n  expected: " . var_export( $expected, true ) . "\n  actual:   " . var_export( $actual, true ) . "\n" );
}

$P = 'Xophz_Compass_Bazaar_Sale_Pricing';

// compute(): percent off
check( 'percent: 20% off 10.00', $P::compute( '10.00', 'percent', '20' ), 8.0 );
check( 'percent: rounds to store decimals', $P::compute( '9.99', 'percent', '15', 2 ), 8.49 );
check( 'percent: 0 decimals', $P::compute( '999', 'percent', '33', 0 ), 669.0 );
check( 'percent: 0% is not a sale', $P::compute( '10', 'percent', '0' ), null );
check( 'percent: 100% rejected', $P::compute( '10', 'percent', '100' ), null );
check( 'percent: negative rejected', $P::compute( '10', 'percent', '-5' ), null );
check( 'percent: tiny price rounds back up to regular', $P::compute( '0.01', 'percent', '10' ), null );

// compute(): fixed price
check( 'fixed: below regular', $P::compute( '25', 'fixed', '19.99' ), 19.99 );
check( 'fixed: free is allowed', $P::compute( '25', 'fixed', '0' ), 0.0 );
check( 'fixed: equal to regular is not a sale', $P::compute( '25', 'fixed', '25' ), null );
check( 'fixed: above regular rejected', $P::compute( '25', 'fixed', '30' ), null );
check( 'fixed: negative rejected', $P::compute( '25', 'fixed', '-1' ), null );

// compute(): bad inputs
check( 'no regular price', $P::compute( '', 'percent', '10' ), null );
check( 'zero regular price', $P::compute( '0', 'fixed', '0' ), null );
check( 'non-numeric value', $P::compute( '10', 'percent', 'abc' ), null );
check( 'unknown mode', $P::compute( '10', 'bogo', '5' ), null );

// normalize_dates()
check( 'dates: both empty', $P::normalize_dates( '', '' ), [ 'from' => '', 'to' => '' ] );
check( 'dates: start and end of day', $P::normalize_dates( '2026-11-20', '2026-11-30' ), [ 'from' => '2026-11-20 00:00:00', 'to' => '2026-11-30 23:59:59' ] );
check( 'dates: open-ended end', $P::normalize_dates( '2026-11-20', '' ), [ 'from' => '2026-11-20 00:00:00', 'to' => '' ] );
check( 'dates: same day', $P::normalize_dates( '2026-11-27', '2026-11-27' ), [ 'from' => '2026-11-27 00:00:00', 'to' => '2026-11-27 23:59:59' ] );
check( 'dates: end before start', $P::normalize_dates( '2026-11-30', '2026-11-20' ), null );
check( 'dates: malformed', $P::normalize_dates( '11/20/2026', '' ), null );
check( 'dates: impossible day', $P::normalize_dates( '2026-02-30', '' ), null );

// clean_ids()
check( 'ids: JSON string, dedupe, drop non-positive', $P::clean_ids( '[3,"3",0,-2,7]', 25 ), [ 3, 7 ] );
check( 'ids: capped', $P::clean_ids( range( 1, 30 ), 25 ), range( 1, 25 ) );
check( 'ids: garbage', $P::clean_ids( 'nope', 25 ), [] );

// Fulfillment status filters
$F = new Xophz_Compass_Bazaar_Admin_Fulfillment( 'xophz-compass-bazaar', 'test' );
$statuses = $F->addOrderStatus( [ 'wc-pending' => 'Pending payment', 'wc-processing' => 'Processing', 'wc-completed' => 'Completed' ] );
check( 'status list: ready-pickup follows processing', array_keys( $statuses ), [ 'wc-pending', 'wc-processing', 'wc-ready-pickup', 'wc-completed' ] );
check( 'status list: label', $statuses['wc-ready-pickup'], 'Ready for pickup' );
check( 'status list: appended when processing is missing', array_keys( $F->addOrderStatus( [ 'wc-completed' => 'Completed' ] ) ), [ 'wc-completed', 'wc-ready-pickup' ] );
$post_statuses = $F->registerPostStatus( [] );
check( 'post status: registered for legacy storage', isset( $post_statuses['wc-ready-pickup'] ), true );
check( 'post status: listed in admin', $post_statuses['wc-ready-pickup']['show_in_admin_status_list'], true );
check( 'post status key fits the 20-char limit', strlen( 'wc-ready-pickup' ) <= 20, true );
check( 'paid statuses: added once', $F->addPaidStatus( $F->addPaidStatus( [ 'processing', 'completed' ] ) ), [ 'processing', 'completed', 'ready-pickup' ] );

echo "$passes passed, $failures failed\n";
exit( $failures > 0 ? 1 : 0 );
