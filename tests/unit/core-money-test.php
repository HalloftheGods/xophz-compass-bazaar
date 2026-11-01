<?php
/**
 * Core_Money: minor-unit conversion, allocation and basis-point percentages.
 */
require_once dirname( __DIR__ ) . '/support/harness.php';
require_once dirname( dirname( __DIR__ ) ) . '/includes/core/class-xophz-compass-bazaar-core-money.php';

$M = 'Xophz_Compass_Bazaar_Core_Money';

// to_minor: exact for numeric strings.
check( 'to_minor: 10.50', $M::to_minor( '10.50', 2 ), 1050 );
check( 'to_minor: 0.1 + no float drift', $M::to_minor( '0.10', 2 ), 10 );
check( 'to_minor: whole number string', $M::to_minor( '7', 2 ), 700 );
check( 'to_minor: one decimal given', $M::to_minor( '7.5', 2 ), 750 );
check( 'to_minor: half rounds up', $M::to_minor( '1.005', 2 ), 101 );
check( 'to_minor: below half rounds down', $M::to_minor( '1.004', 2 ), 100 );
check( 'to_minor: carries across nines', $M::to_minor( '9.999', 2 ), 1000 );
check( 'to_minor: negative', $M::to_minor( '-10.50', 2 ), -1050 );
check( 'to_minor: negative half rounds away from zero', $M::to_minor( '-1.005', 2 ), -101 );
check( 'to_minor: zero decimals', $M::to_minor( '999.5', 0 ), 1000 );
check( 'to_minor: three decimals', $M::to_minor( '1.2345', 3 ), 1235 );
check( 'to_minor: leading plus and spaces', $M::to_minor( ' +3.20 ', 2 ), 320 );
check( 'to_minor: int', $M::to_minor( 12, 2 ), 1200 );
check( 'to_minor: float is half-up, not binary', $M::to_minor( 1.005, 2 ), 101 );
check( 'to_minor: float 19.99', $M::to_minor( 19.99, 2 ), 1999 );
check( 'to_minor: float 0.29 * 100 drift case', $M::to_minor( 0.29, 2 ), 29 );
check( 'to_minor: default decimals are 2 without WooCommerce', $M::to_minor( '1.5' ), 150 );
check_throws( 'to_minor: text rejected', function () use ( $M ) { $M::to_minor( 'abc', 2 ); }, InvalidArgumentException::class );
check_throws( 'to_minor: empty rejected', function () use ( $M ) { $M::to_minor( '', 2 ); }, InvalidArgumentException::class );
check_throws( 'to_minor: exponent rejected', function () use ( $M ) { $M::to_minor( '1e3', 2 ); }, InvalidArgumentException::class );
check_throws( 'to_minor: comma rejected', function () use ( $M ) { $M::to_minor( '1,50', 2 ); }, InvalidArgumentException::class );
check_throws( 'to_minor: NAN rejected', function () use ( $M ) { $M::to_minor( NAN, 2 ); }, InvalidArgumentException::class );
check_throws( 'to_minor: null rejected', function () use ( $M ) { $M::to_minor( null, 2 ); }, InvalidArgumentException::class );
check_throws( 'to_minor: overflow rejected', function () use ( $M ) { $M::to_minor( '99999999999999999999', 2 ); }, InvalidArgumentException::class );
check_throws( 'to_minor: decimals out of range', function () use ( $M ) { $M::to_minor( '1', 9 ); }, InvalidArgumentException::class );

// from_minor
check( 'from_minor: 1050', $M::from_minor( 1050, 2 ), '10.50' );
check( 'from_minor: pads small values', $M::from_minor( 5, 2 ), '0.05' );
check( 'from_minor: zero', $M::from_minor( 0, 2 ), '0.00' );
check( 'from_minor: negative', $M::from_minor( -1050, 2 ), '-10.50' );
check( 'from_minor: negative small', $M::from_minor( -5, 2 ), '-0.05' );
check( 'from_minor: zero decimals', $M::from_minor( 1234, 0 ), '1234' );
check( 'from_minor: three decimals', $M::from_minor( 1235, 3 ), '1.235' );
check( 'from_minor: round trip', $M::from_minor( $M::to_minor( '123456.78', 2 ), 2 ), '123456.78' );
check( 'from_minor: PHP_INT_MIN does not overflow', $M::from_minor( PHP_INT_MIN, 0 ), (string) PHP_INT_MIN );

// allocate
check( 'allocate: even split', $M::allocate( 100, [ 1, 1 ] ), [ 50, 50 ] );
check( 'allocate: thirds, ties to lower index', $M::allocate( 100, [ 1, 1, 1 ] ), [ 34, 33, 33 ] );
check( 'allocate: weighted', $M::allocate( 10, [ 3, 7 ] ), [ 3, 7 ] );
check( 'allocate: largest remainder wins', $M::allocate( 5, [ 1, 2 ] ), [ 2, 3 ] );
check( 'allocate: zero weight gets nothing', $M::allocate( 10, [ 0, 1, 1 ] ), [ 0, 5, 5 ] );
check( 'allocate: zero total', $M::allocate( 0, [ 1, 2 ] ), [ 0, 0 ] );
check( 'allocate: negative total mirrors', $M::allocate( -100, [ 1, 1, 1 ] ), [ -34, -33, -33 ] );
check( 'allocate: single weight', $M::allocate( 99, [ 4 ] ), [ 99 ] );
foreach ( [ [ 1001, [ 3, 3, 3, 1 ] ], [ 7, [ 1, 1, 1, 1, 1, 1, 1, 1, 1 ] ], [ 12345, [ 17, 0, 5, 23 ] ] ] as $i => $case ) {
  check( "allocate: sums exactly #$i", array_sum( $M::allocate( $case[0], $case[1] ) ), $case[0] );
}
check_throws( 'allocate: empty weights', function () use ( $M ) { $M::allocate( 10, [] ); }, InvalidArgumentException::class );
check_throws( 'allocate: all-zero weights', function () use ( $M ) { $M::allocate( 10, [ 0, 0 ] ); }, InvalidArgumentException::class );
check_throws( 'allocate: negative weight', function () use ( $M ) { $M::allocate( 10, [ 1, -1 ] ); }, InvalidArgumentException::class );
check_throws( 'allocate: overflow', function () use ( $M ) { $M::allocate( PHP_INT_MAX, [ 2, 2 ] ); }, InvalidArgumentException::class );

// percent_bp
check( 'percent_bp: 10%', $M::percent_bp( 1000, 1000 ), 100 );
check( 'percent_bp: half rounds up', $M::percent_bp( 5, 1000 ), 1 );
check( 'percent_bp: below half rounds down', $M::percent_bp( 4, 1000 ), 0 );
check( 'percent_bp: 8.25% of 1999', $M::percent_bp( 1999, 825 ), 165 );
check( 'percent_bp: negative base', $M::percent_bp( -1999, 825 ), -165 );
check( 'percent_bp: negative rate', $M::percent_bp( 1999, -825 ), -165 );
check( 'percent_bp: zero', $M::percent_bp( 0, 825 ), 0 );
check_throws( 'percent_bp: overflow', function () use ( $M ) { $M::percent_bp( PHP_INT_MAX, 10000 ); }, InvalidArgumentException::class );

bazaar_test_standalone( __FILE__ );
