<?php
/**
 * Receipt money lines: they add up to the total, a discount counts once, tax
 * and fees show, refunds follow the total.
 *
 * @package Xophz_Compass_Bazaar
 */

require_once dirname( __DIR__ ) . '/support/harness.php';
require_once dirname( __DIR__, 2 ) . '/includes/core/class-xophz-compass-bazaar-core-money.php';
require_once dirname( __DIR__, 2 ) . '/includes/checkout/class-xophz-compass-bazaar-checkout-receipt-lines.php';

$R = 'Xophz_Compass_Bazaar_Checkout_Receipt_Lines';

$sum = function ( array $lines ): int {
  return array_sum( array_column( $lines, 'minor' ) );
};
$keys = function ( array $lines ): array {
  return array_column( $lines, 'key' );
};

// $20.00 of items, $2.00 off, 8% tax on $18.00 = $1.44, total $19.44.
$a = $R::lines( [ 'subtotal' => 2000, 'discount' => 200, 'tax' => 144, 'total' => 1944 ] );
check( 'receipt: lines add up to the total', $sum( $a ), 1944 );
check( 'receipt: discount shows once, as a negative', array_column( $a, 'minor', 'key' )['discount'], -200 );
check( 'receipt: order is subtotal, discounts, tax', $keys( $a ), [ 'subtotal', 'discount', 'tax' ] );

// No discount, no tax: Sales tax still shows (as $0.00), nothing else extra.
$b = $R::lines( [ 'subtotal' => 500, 'total' => 500 ] );
check( 'receipt: zero tax line stays', $keys( $b ), [ 'subtotal', 'tax' ] );

// Fees and shipping appear only when non-zero.
$c = $R::lines( [ 'subtotal' => 1000, 'fees' => 150, 'shipping' => 400, 'tax' => 100, 'total' => 1650 ] );
check( 'receipt: fees and shipping listed', $keys( $c ), [ 'subtotal', 'fees', 'shipping', 'tax' ] );
check( 'receipt: fees and shipping add up', $sum( $c ), 1650 );

// A gap becomes one visible Adjustment line.
$d = $R::lines( [ 'subtotal' => 1000, 'tax' => 83, 'total' => 1084 ] );
check( 'receipt: gap shows as Adjustment', array_column( $d, 'minor', 'key' )['adjustment'], 1 );
check( 'receipt: still adds up with an Adjustment', $sum( $d ), 1084 );

// A negative discount value is read as a discount.
$e = $R::lines( [ 'subtotal' => 1000, 'discount' => -100, 'total' => 900 ] );
check( 'receipt: discount sign is normalized', $sum( $e ), 900 );

// Refunds.
check( 'refund: none gives no lines', $R::refund_lines( 1944, 0 ), [] );
$f = $R::refund_lines( 1944, 500 );
check( 'refund: Refunded and Net after refund', array_column( $f, 'minor', 'key' ), [ 'refunded' => -500, 'net' => 1444 ] );

check( 'money: positive', $R::money( 450 ), '$4.50' );
check( 'money: negative', $R::money( -450 ), '-$4.50' );

// Quantity and unit price.
check( 'detail: whole quantity', $R::item_detail( '2', 300 ), '2 x $3.00' );
check( 'detail: ten keeps its zero', $R::item_detail( '10', 150 ), '10 x $1.50' );
check( 'detail: weighed quantity', $R::item_detail( '1.50', 400 ), '1.5 x $4.00' );

// Cash tendered and change.
check( 'cash: card sale has none', $R::cash_lines( [ [ 'tendered_minor' => null, 'change_minor' => null ] ] ), [] );
check( 'cash: no rows has none', $R::cash_lines( [] ), [] );
$g = $R::cash_lines( [ [ 'tendered_minor' => '2000', 'change_minor' => '56' ] ] );
check( 'cash: tendered and change', array_column( $g, 'minor', 'key' ), [ 'tendered' => 2000, 'change' => 56 ] );

bazaar_test_standalone( __FILE__ );
