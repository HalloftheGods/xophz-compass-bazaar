<?php
/**
 * Pricing_Discount_Math: percent and fixed amounts, largest-remainder
 * allocation, and sequential application across lines.
 */
require_once dirname( __DIR__ ) . '/support/harness.php';
$plugin = dirname( dirname( __DIR__ ) );
require_once $plugin . '/includes/core/class-xophz-compass-bazaar-core-money.php';
require_once $plugin . '/includes/pricing/class-xophz-compass-bazaar-pricing-discount-math.php';

$D = 'Xophz_Compass_Bazaar_Pricing_Discount_Math';

// amount()
check( 'percent: 10% of 2500', $D::amount( [ 'type' => 'percent', 'value_bp' => 1000 ], 2500 ), 250 );
check( 'percent: half rounds up', $D::amount( [ 'type' => 'percent', 'value_bp' => 1250 ], 1004 ), 126 );
check( 'percent: 100% is the base', $D::amount( [ 'type' => 'percent', 'value_bp' => 10000 ], 999 ), 999 );
check( 'fixed: below the base', $D::amount( [ 'type' => 'fixed', 'amount_minor' => 500 ], 2500 ), 500 );
check( 'fixed: capped at the base', $D::amount( [ 'type' => 'fixed', 'amount_minor' => 5000 ], 2500 ), 2500 );
check( 'zero base gives zero', $D::amount( [ 'type' => 'fixed', 'amount_minor' => 500 ], 0 ), 0 );
check_throws( 'unknown type refused', function () use ( $D ) { $D::amount( [ 'type' => 'bogo' ], 100 ); }, InvalidArgumentException::class );
check_throws( 'negative fixed refused', function () use ( $D ) { $D::amount( [ 'type' => 'fixed', 'amount_minor' => -1 ], 100 ); }, InvalidArgumentException::class );

// allocate()
check( 'allocate: proportional, keys kept', $D::allocate( 400, [ 'l0' => 1000, 'l1' => 3000 ] ), [ 'l0' => 100, 'l1' => 300 ] );
check( 'allocate: remainder ties go to the lower index', $D::allocate( 100, [ 'a' => 1, 'b' => 1, 'c' => 1 ] ), [ 'a' => 34, 'b' => 33, 'c' => 33 ] );
check( 'allocate: largest remainder wins', $D::allocate( 10, [ 'a' => 1, 'b' => 2 ] ), [ 'a' => 3, 'b' => 7 ] );
check( 'allocate: zero-weight line gets nothing', $D::allocate( 5, [ 'a' => 0, 'b' => 3, 'c' => 4 ] ), [ 'a' => 0, 'b' => 2, 'c' => 3 ] );
check( 'allocate: zero amount', $D::allocate( 0, [ 'a' => 5 ] ), [ 'a' => 0 ] );
check( 'allocate: all-zero weights', $D::allocate( 5, [ 'a' => 0, 'b' => 0 ] ), [ 'a' => 0, 'b' => 0 ] );
$parts = $D::allocate( 997, [ 'a' => 333, 'b' => 333, 'c' => 334 ] );
check( 'allocate: parts sum exactly', array_sum( $parts ), 997 );

// apply_all(): each discount applies to what is left.
$res = $D::apply_all(
  [ [ 'name' => 'Loyalty', 'type' => 'percent', 'value_bp' => 1000 ], [ 'name' => 'Damaged', 'type' => 'fixed', 'amount_minor' => 500 ] ],
  [ 'l0' => 1000, 'l1' => 3000 ]
);
check( 'apply_all: base before discounts', $res['base_minor'], 4000 );
check( 'apply_all: first discount amount', $res['discounts'][0]['amount_minor'], 400 );
check( 'apply_all: first discount by line', $res['discounts'][0]['by_line'], [ 'l0' => 100, 'l1' => 300 ] );
check( 'apply_all: second discount on what is left', $res['discounts'][1]['by_line'], [ 'l0' => 125, 'l1' => 375 ] );
check( 'apply_all: total', $res['amount_minor'], 900 );
check( 'apply_all: by line', $res['by_line'], [ 'l0' => 225, 'l1' => 675 ] );
check( 'apply_all: remaining', $res['remaining'], [ 'l0' => 775, 'l1' => 2325 ] );
check( 'apply_all: input fields kept', $res['discounts'][1]['name'], 'Damaged' );

$res = $D::apply_all( [ [ 'type' => 'fixed', 'amount_minor' => 900 ], [ 'type' => 'fixed', 'amount_minor' => 900 ] ], [ 'l0' => 600, 'l1' => 400 ] );
check( 'apply_all: never more than the cart', $res['amount_minor'], 1000 );
check( 'apply_all: second fixed capped at what is left', $res['discounts'][1]['amount_minor'], 100 );
check( 'apply_all: no line below zero', min( $res['remaining'] ) >= 0, true );

$res = $D::apply_all( [], [ 'l0' => 10 ] );
check( 'apply_all: no discounts', [ $res['amount_minor'], $res['remaining'] ], [ 0, [ 'l0' => 10 ] ] );
check_throws( 'apply_all: negative line refused', function () use ( $D ) { $D::apply_all( [], [ 'l0' => -1 ] ); }, InvalidArgumentException::class );

// Property: 500 seeded random carts keep sum and bounds.
mt_srand( 6948 );
$ok = true;
for ( $n = 0; $n < 500; $n++ ) {
  $lines = [];
  for ( $i = 0, $c = mt_rand( 1, 6 ); $i < $c; $i++ ) {
    $lines[ "l$i" ] = mt_rand( 0, 50000 );
  }
  $discounts = [];
  for ( $i = 0, $c = mt_rand( 0, 3 ); $i < $c; $i++ ) {
    $discounts[] = mt_rand( 0, 1 ) ? [ 'type' => 'percent', 'value_bp' => mt_rand( 1, 10000 ) ] : [ 'type' => 'fixed', 'amount_minor' => mt_rand( 1, 60000 ) ];
  }
  $res = $D::apply_all( $discounts, $lines );
  foreach ( $lines as $key => $amount ) {
    $ok = $ok && $res['remaining'][ $key ] >= 0 && $res['remaining'][ $key ] + $res['by_line'][ $key ] === $amount;
  }
  $ok = $ok && $res['amount_minor'] <= array_sum( $lines );
}
check( 'property: 500 random carts keep line sums and never go negative', $ok, true );

bazaar_test_standalone( __FILE__ );
