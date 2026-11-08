<?php
/**
 * Receipt store details (blank stays blank) and the local QR code.
 *
 * @package Xophz_Compass_Bazaar
 */

require_once dirname( __DIR__ ) . '/support/harness.php';
require_once dirname( __DIR__, 2 ) . '/includes/checkout/class-xophz-compass-bazaar-checkout-receipt-store.php';
require_once dirname( __DIR__, 2 ) . '/includes/checkout/class-xophz-compass-bazaar-checkout-qr.php';

$S = 'Xophz_Compass_Bazaar_Checkout_Receipt_Store';
$Q = 'Xophz_Compass_Bazaar_Checkout_Qr';

check( 'store: full address', $S::address_lines( [ 'address' => '1 Main St', 'address2' => 'Unit 2', 'city' => 'Town', 'state' => 'CO', 'postcode' => '80000' ] ), [ '1 Main St', 'Unit 2', 'Town, CO 80000' ] );
check( 'store: blank settings give no lines', $S::address_lines( [ 'address' => ' ', 'city' => '' ] ), [] );
check( 'store: city only', $S::address_lines( [ 'city' => 'Town' ] ), [ 'Town' ] );
check( 'store: first filled skips blanks', $S::first_filled( [ '', '  ', ' 555 ', 'x' ] ), '555' );
check( 'store: all blank is blank', $S::first_filled( [ '', false, null ] ), '' );

// QR: right size for the version, finder patterns in place, same text same grid.
$m = $Q::matrix( 'http://localhost/?xophz_bazaar_receipt=1&key=wc_order_abc' );
check( 'qr: 57 bytes fit version 4 (33 modules)', count( $m ), 33 );
check( 'qr: top-left finder corner is dark', $m[0][0], true );
check( 'qr: finder inner ring is light', $m[1][1], false );
check( 'qr: finder centre is dark', $m[3][3], true );
check( 'qr: deterministic', $Q::matrix( 'abc' ) === $Q::matrix( 'abc' ), true );
check( 'qr: empty text gives none', $Q::matrix( '' ), null );
check( 'qr: too long gives none', $Q::svg( str_repeat( 'a', 300 ) ), '' );
check( 'qr: svg has no external reference', (bool) preg_match( '/href|<image|<script|url\(/', $Q::svg( 'hello' ) ), false );
check( 'qr: version 9 grid for 200 bytes', count( $Q::matrix( str_repeat( 'k', 200 ) ) ), 53 );

bazaar_test_standalone( __FILE__ );
