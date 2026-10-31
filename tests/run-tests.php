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

// In-memory transients and option stubs for the POS authorization tests.
$GLOBALS['test_transients'] = [];
$GLOBALS['test_environment'] = 'production';
$GLOBALS['test_filters'] = [];
function set_transient( $key, $value, $ttl ) { $GLOBALS['test_transients'][ $key ] = $value; return true; }
function get_transient( $key ) { return $GLOBALS['test_transients'][ $key ] ?? false; }
function delete_transient( $key ) { unset( $GLOBALS['test_transients'][ $key ] ); return true; }
function wp_generate_password( $length ) { return bin2hex( random_bytes( (int) ( $length / 2 ) ) ); }
function wp_get_environment_type() { return $GLOBALS['test_environment']; }
function apply_filters( $hook, $value ) { return array_key_exists( $hook, $GLOBALS['test_filters'] ) ? $GLOBALS['test_filters'][ $hook ] : $value; }

$root = dirname( __DIR__ );
require_once $root . '/includes/class-xophz-compass-bazaar-sale-pricing.php';
require_once $root . '/admin/class-xophz-compass-bazaar-admin-fulfillment.php';
require_once $root . '/includes/class-xophz-compass-bazaar-pos-authorization.php';

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

// POS digital payment authorization (#6783)
$A = 'Xophz_Compass_Bazaar_Pos_Authorization';
check( 'tender: cash needs no authorization', $A::requires_authorization( 'cash' ), false );
check( 'tender: cod needs no authorization', $A::requires_authorization( 'cod' ), false );
check( 'tender: card terminal by id', $A::requires_authorization( 'stripe' ), false );
check( 'tender: card terminal by title', $A::requires_authorization( 'woocommerce_payments', 'Credit Card (WooPayments)' ), false );
check( 'tender: bank transfer', $A::requires_authorization( 'bacs' ), false );
check( 'tender: split', $A::requires_authorization( 'bazaar_split' ), false );
check( 'tender: paypal is digital', $A::requires_authorization( 'ppcp-gateway', 'PayPal' ), true );
check( 'tender: affirm is digital', $A::requires_authorization( 'affirm' ), true );

$token = $A::issue( 7, 'paypal', '42.10', 1000 );
check( 'token: long and random', strlen( $token ) >= 20 && $token !== $A::issue( 7, 'paypal', '42.10', 1000 ), true );
check( 'token: only the hash is stored', in_array( $token, array_keys( $GLOBALS['test_transients'] ), true ), false );
check( 'token: valid once', $A::consume( $token, 7, 'paypal', 42.1, 1001 ), '' );
check( 'token: replay refused', $A::consume( $token, 7, 'paypal', 42.1, 1001 ), 'unknown or already used' );
check( 'token: missing refused', $A::consume( '', 7, 'paypal', 42.1, 1001 ), 'missing' );
check( 'token: forged refused', $A::consume( 'not-a-real-token-1234567890', 7, 'paypal', 42.1, 1001 ), 'unknown or already used' );
check( 'token: other cashier refused', $A::consume( $A::issue( 7, 'paypal', 10, 1000 ), 8, 'paypal', 10, 1001 ), 'issued to another cashier' );
check( 'token: other gateway refused', $A::consume( $A::issue( 7, 'paypal', 10, 1000 ), 7, 'affirm', 10, 1001 ), 'issued for another payment method' );
check( 'token: other total refused', $A::consume( $A::issue( 7, 'paypal', 10, 1000 ), 7, 'paypal', 10.01, 1001 ), 'issued for a different total' );
check( 'token: expired refused', $A::consume( $A::issue( 7, 'paypal', 10, 1000 ), 7, 'paypal', 10, 1000 + $A::TTL_SECONDS + 1 ), 'expired' );
$spent = $A::issue( 7, 'paypal', 10, 1000 );
$A::consume( $spent, 8, 'paypal', 10, 1001 );
check( 'token: a refused attempt still spends it', $A::consume( $spent, 7, 'paypal', 10, 1001 ), 'unknown or already used' );

check( 'test portal: off in production', $A::is_test_portal_enabled(), false );
$GLOBALS['test_environment'] = 'local';
check( 'test portal: on in local', $A::is_test_portal_enabled(), true );
$GLOBALS['test_filters']['xophz_compass_bazaar_pos_test_portal'] = false;
check( 'test portal: filter can turn it off', $A::is_test_portal_enabled(), false );

echo "$passes passed, $failures failed\n";
exit( $failures > 0 ? 1 : 0 );
