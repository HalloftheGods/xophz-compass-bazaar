<?php
/**
 * Staff_Limits: defaults, role maximum, approvals needed, approver coverage.
 * Run alone: php wp-content/plugins/xophz-compass-bazaar/tests/unit/staff-limits-test.php
 */
require_once dirname( __DIR__ ) . '/support/harness.php';
$root = dirname( dirname( __DIR__ ) );
require_once $root . '/includes/core/class-xophz-compass-bazaar-core-autoloader.php';
Xophz_Compass_Bazaar_Core_Autoloader::register( $root );
if ( ! function_exists( 'get_option' ) ) {
  function get_option( $name, $default = false ) { return array_key_exists( $name, $GLOBALS['test_options'] ) ? $GLOBALS['test_options'][ $name ] : $default; }
}
$GLOBALS['test_options'] = [];

$L = 'Xophz_Compass_Bazaar_Staff_Limits';

$cashier = $L::from_roles( [ 'bazaar_cashier' ], $L::defaults() );
check( 'cashier: default limits', $cashier, [ 'discount_bp' => 1000, 'discount_minor' => 2000, 'refund_minor' => 0, 'custom_item_minor' => 5000, 'unlimited' => false ] );
check( 'unknown role has zero limits', $L::from_roles( [ 'subscriber' ], $L::defaults() )['discount_minor'], 0 );
check( 'no roles has zero limits', $L::from_roles( [], $L::defaults() )['custom_item_minor'], 0 );

$map = [ 'a' => [ 'discount_bp' => 500, 'discount_minor' => 9000, 'refund_minor' => 100, 'custom_item_minor' => 0 ], 'b' => [ 'discount_bp' => 2000, 'discount_minor' => 1, 'refund_minor' => 0, 'custom_item_minor' => 700 ] ];
$both = $L::from_roles( [ 'a', 'b' ], $map );
check( 'maximum over roles, key by key', [ $both['discount_bp'], $both['discount_minor'], $both['refund_minor'], $both['custom_item_minor'] ], [ 2000, 9000, 100, 700 ] );

// sanitize
check( 'sanitize: drops bad roles and non-arrays', $L::sanitize( [ 'Bad Role' => [], 'ok' => 'x', 5 => [ 'discount_bp' => 1 ] ] ), [] );
check( 'sanitize: clamps and fills', $L::sanitize( [ 'r' => [ 'discount_bp' => 99999, 'refund_minor' => -5, 'custom_item_minor' => '12', 'junk' => 1 ] ] ), [ 'r' => [ 'discount_bp' => 10000, 'discount_minor' => 0, 'refund_minor' => 0, 'custom_item_minor' => 12 ] ] );
check( 'sanitize: non-array input', $L::sanitize( 'x' ), [] );

// role_map: defaults, stored override, filter
check( 'role_map: defaults', $L::role_map()['bazaar_cashier']['discount_bp'], 1000 );
$GLOBALS['test_options'][ $L::OPTION ] = [ 'bazaar_cashier' => [ 'discount_bp' => 300 ] ];
$replaced = $L::role_map()['bazaar_cashier'];
check( 'role_map: a stored role replaces its default', [ $replaced['discount_bp'], $replaced['discount_minor'] ], [ 300, 0 ] );
$GLOBALS['test_filters'][ $L::FILTER ] = [ 'x' => [ 'refund_minor' => 4200 ] ];
check( 'role_map: filter result is used and sanitized', $L::role_map(), [ 'x' => [ 'discount_bp' => 0, 'discount_minor' => 0, 'refund_minor' => 4200, 'custom_item_minor' => 0 ] ] );
unset( $GLOBALS['test_filters'][ $L::FILTER ] );
$GLOBALS['test_options'] = [];

// basis points round up
check( 'bp: 10% exact', $L::basis_points( 1000, 10000 ), 1000 );
check( 'bp: rounds up', $L::basis_points( 1, 3 ), 3334 );
check( 'bp: zero base', $L::basis_points( 100, 0 ), 0 );

// approvals_needed
$scopes = function ( array $needed ) { return array_column( $needed, 'scope' ); };
check( 'discount at 10% needs nothing', $L::approvals_needed( $cashier, [ 'discount_minor' => 1000, 'discount_base_minor' => 10000 ] ), [] );
check( 'discount of 20.00 on 500.00 (4%) needs nothing', $L::approvals_needed( $cashier, [ 'discount_minor' => 2000, 'discount_base_minor' => 50000 ] ), [] );
check( 'discount of 30.00 on 100.00 needs approval', $L::approvals_needed( $cashier, [ 'discount_minor' => 3000, 'discount_base_minor' => 10000 ] ), [ [ 'scope' => 'discount', 'amount_minor' => 3000, 'basis_points' => 3000 ] ] );
check( 'discount just over 10% is still fine under the 20.00 limit; 5.00 on a 1.00 base is under the minor limit', [ $scopes( $L::approvals_needed( $cashier, [ 'discount_minor' => 1001, 'discount_base_minor' => 10000 ] ) ), $scopes( $L::approvals_needed( $cashier, [ 'discount_minor' => 500, 'discount_base_minor' => 100 ] ) ) ], [ [], [] ] );
check( 'without bazaar_discount every discount needs approval', $scopes( $L::approvals_needed( $cashier, [ 'can_discount' => false, 'discount_minor' => 1, 'discount_base_minor' => 100000 ] ) ), [ 'discount' ] );
check( 'no discount needs nothing even without the cap', $L::approvals_needed( $cashier, [ 'can_discount' => false ] ), [] );
check( 'custom item at 50.00 is fine, 50.01 is not', [ $L::approvals_needed( $cashier, [ 'custom_item_minor' => 5000 ] ), $scopes( $L::approvals_needed( $cashier, [ 'custom_item_minor' => 5001 ] ) ) ], [ [], [ 'custom_item' ] ] );
check( 'every refund needs approval for a cashier', $L::approvals_needed( $cashier, [ 'refund_minor' => 1 ] ), [ [ 'scope' => 'refund', 'amount_minor' => 1, 'basis_points' => 0 ] ] );
check( 'a zero refund needs nothing', $L::approvals_needed( $cashier, [ 'refund_minor' => 0 ] ), [] );
check( 'price override always needs approval', $scopes( $L::approvals_needed( $cashier, [ 'price_override' => true ] ) ), [ 'price_override' ] );
check( 'several needs come in a fixed order', $scopes( $L::approvals_needed( $cashier, [ 'discount_minor' => 9000, 'discount_base_minor' => 10000, 'custom_item_minor' => 9000, 'refund_minor' => 5, 'price_override' => true ] ) ), [ 'discount', 'custom_item', 'refund', 'price_override' ] );
check( 'unlimited needs nothing', $L::approvals_needed( $L::unlimited(), [ 'discount_minor' => 99999, 'refund_minor' => 99999, 'price_override' => true ] ), [] );

// covers
$supervisor = $L::from_roles( [ 'sup' ], [ 'sup' => [ 'discount_bp' => 5000, 'discount_minor' => 0, 'refund_minor' => 20000, 'custom_item_minor' => 0 ] ] );
check( 'covers: refund within limit', $L::covers( $supervisor, 'refund', 20000 ), true );
check( 'covers: refund above limit', $L::covers( $supervisor, 'refund', 20001 ), false );
check( 'covers: discount by basis points', [ $L::covers( $supervisor, 'discount', 99999, 5000 ), $L::covers( $supervisor, 'discount', 99999, 5001 ) ], [ true, false ] );
check( 'covers: price override only when unlimited', [ $L::covers( $supervisor, 'price_override', 0 ), $L::covers( $L::unlimited(), 'price_override', 0 ) ], [ false, true ] );
check( 'covers: unknown scope is never covered', $L::covers( $L::unlimited(), 'nonsense', 1 ), false );

bazaar_test_standalone( __FILE__ );
