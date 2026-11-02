<?php
/**
 * The legacy rollback switches and the shift email's pure parts (#6996):
 * Admin_Pos_Sale::route over both options, Checkout_Legacy_Writer::fold,
 * Admin_Pos_Shift::merge, recipients and request_context (the staff session
 * header). The live checks are in tests/integration/checkout-legacy.sh.
 */
require_once dirname( __DIR__ ) . '/support/harness.php';
$plugin = dirname( dirname( __DIR__ ) );
require_once $plugin . '/includes/security/class-xophz-compass-bazaar-security-context.php';
require_once $plugin . '/includes/security/class-xophz-compass-bazaar-security-guard.php';
require_once $plugin . '/includes/checkout/class-xophz-compass-bazaar-checkout-legacy-writer.php';
require_once $plugin . '/admin/class-xophz-compass-bazaar-admin-pos-sale.php';
require_once $plugin . '/admin/class-xophz-compass-bazaar-admin-pos-shift.php';

$S = 'Xophz_Compass_Bazaar_Admin_Pos_Sale';
$W = 'Xophz_Compass_Bazaar_Checkout_Legacy_Writer';
$H = 'Xophz_Compass_Bazaar_Admin_Pos_Shift';

// Both rollback switches (spec 17), every combination.
check( 'route: defaults run the adapter', $S::route( 'v2', true ), 'v2' );
check( 'route: pipeline legacy runs the old body', $S::route( 'legacy', true ), 'legacy' );
check( 'route: checkout v2 off runs the old body with the default pipeline', $S::route( 'v2', false ), 'legacy' );
check( 'route: both switches off run the old body', $S::route( 'legacy', false ), 'legacy' );
check( 'route: unknown or unset pipeline values are v2', [ $S::route( 'garbage', true ), $S::route( false, true ), $S::route( null, true ), $S::route( ' LEGACY ', true ) ], [ 'v2', 'v2', 'v2', 'legacy' ] );
check( 'route: checkout v2 off wins over an unknown pipeline value', $S::route( 'garbage', false ), 'legacy' );

// Rollback sales folded for the shift email.
$fold = $W::fold( [
  [ 'method' => 'cash', 'total_minor' => 2000, 'tip_minor' => 100, 'discount_minor' => 250, 'legs' => [] ],
  [ 'method' => 'bazaar_split', 'total_minor' => 1500, 'tip_minor' => 0, 'discount_minor' => 0, 'legs' => [ [ 'method' => 'cash', 'amount_minor' => 1000 ], [ 'method' => 'card', 'amount_minor' => 500 ] ] ],
  [ 'method' => 'CARD', 'total_minor' => 700 ],
] );
check( 'fold: orders, gross, tips and discounts add up', [ $fold['orders'], $fold['gross_minor'], $fold['tips_minor'], $fold['discounts_minor'] ], [ 3, 4200, 100, 250 ] );
check( 'fold: split legs count under their own method', $fold['by_method'], [ 'cash' => [ 'count' => 2, 'amount_minor' => 3000 ], 'card' => [ 'count' => 2, 'amount_minor' => 1200 ] ] );
check( 'fold: nothing gives zeros', $W::fold( [] ), [ 'orders' => 0, 'gross_minor' => 0, 'tips_minor' => 0, 'discounts_minor' => 0, 'by_method' => [] ] );

// Merged into the server summary.
$kind   = function ( string $m ) {
  return 'cash' === $m ? 'cash' : 'external_terminal';
};
$server = [ 'orders' => 1, 'gross_minor' => 999, 'tips_minor' => 1, 'discounts_minor' => 2, 'by_kind' => [ [ 'kind' => 'cash', 'method_id' => 'cash', 'count' => 1, 'amount_minor' => 999 ] ] ];
$merged = $H::merge( $server, $fold, $kind );
check( 'merge: totals include the rollback sales', [ $merged['orders'], $merged['gross_minor'], $merged['tips_minor'], $merged['discounts_minor'], $merged['legacy_orders'] ], [ 4, 5199, 101, 252, 3 ] );
check( 'merge: rollback methods are added with their kind', array_map( function ( $r ) {
  return $r['kind'] . ':' . $r['method_id'] . ':' . $r['amount_minor'];
}, $merged['by_kind'] ), [ 'cash:cash:999', 'cash:cash:3000', 'external_terminal:card:1200' ] );
check( 'merge: no rollback sales leaves the server figures', $H::merge( $server, $W::fold( [] ), $kind )['gross_minor'], 999 );
check( 'merge: legacy_orders is 0 without rollback sales', $H::merge( $server, $W::fold( [] ), $kind )['legacy_orders'], 0 );

// Recipients: the acting user and the operator, never a client-named id.
check( 'recipients: acting user and operator, deduplicated', $H::recipients( [ 'alice@example.test', 'op@example.test', 'ALICE@example.test' ] ), [ 'alice@example.test', 'op@example.test' ] );
check( 'recipients: empty and invalid addresses are dropped', $H::recipients( [ '', 'nope', null, 'op@example.test' ] ), [ 'op@example.test' ] );

// The staff session header (finding 11).
$live = function ( string $token, int $operator, string $device ) {
  return 'tok-alice' === $token && 7 === $operator && 'reg-1' === $device
    ? [ 'id' => 55, 'staff_user_id' => 12, 'operator_user_id' => 7, 'device_id' => 'reg-1', 'started_at' => '2026-11-01 14:00:00' ]
    : null;
};
$r = $H::request_context( 7, [ 'HTTP_X_BAZAAR_DEVICE' => 'reg-1' ], $live );
check( 'context: no header is the operator', [ $r['context']->acting_user_id, $r['context']->staff_session_id, $r['session'] ], [ 7, 0, null ] );
$r = $H::request_context( 7, [ 'HTTP_X_BAZAAR_DEVICE' => 'reg-1', 'HTTP_X_BAZAAR_STAFF_SESSION' => 'tok-alice' ], $live );
check( 'context: a live session makes its staff member the acting user', [ $r['context']->operator_user_id, $r['context']->acting_user_id, $r['context']->staff_session_id, $r['session']['started_at'] ], [ 7, 12, 55, '2026-11-01 14:00:00' ] );
check( 'context: the session scope follows it', $H::scope( $r['context'], false ), [ 'staff_session_id' => 55, 'cashier_user_id' => 12 ] );
// Rollback sales are never a staff session's (rf4 review: an unscoped query
// there added every register's sales to a view_reports staff member's email).
check( 'legacy query: a staff session with view_reports adds no rollback sales', $H::legacy_query( $r['context'], $H::scope( $r['context'], true ) ), null );
check( 'legacy query: a staff session without view_reports adds none either', $H::legacy_query( $r['context'], $H::scope( $r['context'], false ) ), null );
$op = Xophz_Compass_Bazaar_Security_Context::for_operator( 7 );
check( 'legacy query: the operator who sees all reads every cashier today', $H::legacy_query( $op, [ 'from' => '2026-11-01 05:00:00', 'to' => '2026-11-01 18:00:00' ] ), [ '2026-11-01 05:00:00', '2026-11-01 18:00:00', 0 ] );
check( 'legacy query: the operator own scope reads their own sales', $H::legacy_query( $op, [ 'from' => 'a', 'to' => 'b', 'cashier_user_id' => 7 ] ), [ 'a', 'b', 7 ] );
check( 'legacy query: a session scope is refused even from an operator context', $H::legacy_query( $op, [ 'staff_session_id' => 55 ] ), null );
check( 'context: a token from another device is refused', $H::request_context( 7, [ 'HTTP_X_BAZAAR_DEVICE' => 'reg-2', 'HTTP_X_BAZAAR_STAFF_SESSION' => 'tok-alice' ], $live ), null );
check( 'context: an unknown token is refused', $H::request_context( 7, [ 'HTTP_X_BAZAAR_DEVICE' => 'reg-1', 'HTTP_X_BAZAAR_STAFF_SESSION' => 'tok-bob' ], $live ), null );
check( 'context: an empty header is refused', $H::request_context( 7, [ 'HTTP_X_BAZAAR_STAFF_SESSION' => '  ' ], $live ), null );
check( 'context: a resolver that throws is refused', $H::request_context( 7, [ 'HTTP_X_BAZAAR_STAFF_SESSION' => 'x' ], function () {
  throw new RuntimeException( 'db down' );
} ), null );
check( 'context: a session of another operator is refused', $H::request_context( 7, [ 'HTTP_X_BAZAAR_DEVICE' => 'reg-1', 'HTTP_X_BAZAAR_STAFF_SESSION' => 'tok' ], function () {
  return [ 'id' => 1, 'staff_user_id' => 12, 'operator_user_id' => 8, 'device_id' => 'reg-1' ];
} ), null );

bazaar_test_standalone( __FILE__ );
