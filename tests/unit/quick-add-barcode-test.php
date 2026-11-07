<?php
/**
 * Quick Add barcode helper: normalization, GS1 check digits, UPC-E expansion
 * and candidate forms.
 *
 * @package Xophz_Compass_Bazaar
 */

require_once dirname( __DIR__ ) . '/support/harness.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-xophz-compass-bazaar-barcode.php';

$B = 'Xophz_Compass_Bazaar_Barcode';

// Check digits (published reference codes).
check( 'UPC-A check digit', $B::check_digit( '03600029145' ), 2 );
check( 'EAN-13 check digit', $B::check_digit( '400638133393' ), 1 );
check( 'EAN-8 check digit', $B::check_digit( '9638507' ), 4 );
check( 'check digit of non-digits', $B::check_digit( '12a' ), -1 );
check( 'valid UPC-A', $B::has_valid_check_digit( '036000291452' ), true );
check( 'bad UPC-A', $B::has_valid_check_digit( '036000291453' ), false );
check( 'valid EAN-13', $B::has_valid_check_digit( '4006381333931' ), true );
check( 'valid EAN-8', $B::has_valid_check_digit( '96385074' ), true );
check( 'valid GTIN-14 (padded UPC-A)', $B::has_valid_check_digit( '00036000291452' ), true );
check( 'length 11 is never valid', $B::has_valid_check_digit( '03600029145' ), false );

// UPC-E expansion (one example per last-digit rule).
check( 'UPC-E last digit 0-2', $B::upce_to_upca( '04252614' ), '042100005264' );
check( 'UPC-E bad check digit', $B::upce_to_upca( '04252615' ), null );
check( 'UPC-E number system 2 refused', $B::upce_to_upca( '24252614' ), null );
$exp3 = '0123' . '00000' . '45';
check( 'UPC-E last digit 3', $B::upce_to_upca( '0123453' . $B::check_digit( $exp3 ) ), $exp3 . $B::check_digit( $exp3 ) );
$exp4 = '01234' . '00000' . '5';
check( 'UPC-E last digit 4', $B::upce_to_upca( '0123454' . $B::check_digit( $exp4 ) ), $exp4 . $B::check_digit( $exp4 ) );
$exp7 = '012345' . '0000' . '7';
check( 'UPC-E last digit 5-9', $B::upce_to_upca( '0123457' . $B::check_digit( $exp7 ) ), $exp7 . $B::check_digit( $exp7 ) );

// normalize(): UPC-A as scanned.
$n = $B::normalize( '036000291452' );
check( 'UPC-A normalized', $n['normalized'], '036000291452' );
check( 'UPC-A valid', $n['valid'], true );
check( 'UPC-A kind', $n['kind'], 'upc-a' );
check( 'UPC-A candidates', $n['candidates'], [ '036000291452', '0036000291452', '00036000291452' ] );

// The same item read as EAN-13 / GTIN-14 normalizes to the same UPC-A.
check( 'EAN-13 with leading 0 -> UPC-A', $B::normalize( '0036000291452' )['normalized'], '036000291452' );
check( 'GTIN-14 with leading 00 -> UPC-A', $B::normalize( '00036000291452' )['normalized'], '036000291452' );
$n = $B::normalize( '0036000291452' );
check( 'EAN-13 candidates start with as-typed', $n['candidates'][0], '0036000291452' );
check( 'EAN-13 candidates include UPC-A', in_array( '036000291452', $n['candidates'], true ), true );
check( 'EAN-13 candidates include GTIN-14', in_array( '00036000291452', $n['candidates'], true ), true );

// A true EAN-13 stays 13 digits.
$n = $B::normalize( '4006381333931' );
check( 'EAN-13 normalized', $n['normalized'], '4006381333931' );
check( 'EAN-13 kind', $n['kind'], 'ean-13' );
check( 'EAN-13 candidates', $n['candidates'], [ '4006381333931', '04006381333931' ] );

// GTIN-14 with an indicator digit stays 14.
$g14 = '1003600029145' . $B::check_digit( '1003600029145' );
$n   = $B::normalize( $g14 );
check( 'GTIN-14 kind', $n['kind'], 'gtin-14' );
check( 'GTIN-14 normalized', $n['normalized'], $g14 );

// Scanner noise: spaces, dashes, a trailing newline from a keyboard wedge.
$n = $B::normalize( " 0 36000-29145 2\r\n" );
check( 'noise stripped', $n['normalized'], '036000291452' );
check( 'noise raw trimmed', $n['raw'], '0 36000-29145 2' );
check( 'noise candidates start with as-typed', $n['candidates'][0], '0 36000-29145 2' );
check( 'noise valid', $n['valid'], true );

// UPC-E normalizes to UPC-A and keeps the 8 digit form as a candidate.
$n = $B::normalize( '04252614' );
check( 'UPC-E normalized', $n['normalized'], '042100005264' );
check( 'UPC-E kind', $n['kind'], 'upc-e' );
check( 'UPC-E valid', $n['valid'], true );
check( 'UPC-E candidates include 8 digit', in_array( '04252614', $n['candidates'], true ), true );
check( 'UPC-E candidates include EAN-13', in_array( '0042100005264', $n['candidates'], true ), true );

// EAN-8 stays 8 digits.
$n = $B::normalize( '96385074' );
check( 'EAN-8 normalized', $n['normalized'], '96385074' );
check( 'EAN-8 kind', $n['kind'], 'ean-8' );
check( 'EAN-8 candidates include GTIN-14', in_array( '00000096385074', $n['candidates'], true ), true );

// Invalid and odd codes: digits kept, never valid.
$n = $B::normalize( '036000291453' );
check( 'bad check digit not valid', $n['valid'], false );
check( 'bad check digit normalized is digits', $n['normalized'], '036000291453' );
check( 'bad check digit candidates', $n['candidates'], [ '036000291453' ] );
$n = $B::normalize( '12345' );
check( 'short code not valid', $n['valid'], false );
check( 'short code kind', $n['kind'], 'unknown' );

// An in-house SKU with letters: no digits form, as-typed is the only candidate.
$n = $B::normalize( 'QA-' );
check( 'letters-only normalized empty', $n['normalized'], '' );
check( 'letters-only candidates', $n['candidates'], [ 'QA-' ] );
$n = $B::normalize( 'WD-00042' );
check( 'house SKU as typed first', $n['candidates'][0], 'WD-00042' );
check( 'house SKU not valid', $n['valid'], false );
check( 'house SKU candidates are only as typed', $n['candidates'], [ 'WD-00042' ] );
check( 'house SKU normalized empty', $n['normalized'], '' );
check( 'lettered code never matches its digits', $B::normalize( 'ABC-123' )['candidates'], [ 'ABC-123' ] );
$n = $B::normalize( 'X036000291452' );
check( 'letters before a valid UPC stay a house code', $n['candidates'], [ 'X036000291452' ] );
check( 'letters before a valid UPC not valid', $n['valid'], false );

// Junk input never throws.
check( 'empty', $B::normalize( '' )['candidates'], [] );
check( 'array input', $B::normalize( [ 'x' ] )['raw'], '' );
check( 'null input', $B::normalize( null )['normalized'], '' );
check( 'raw capped', strlen( $B::normalize( str_repeat( '1', 200 ) )['raw'] ), 64 );

// Open Food Facts query form.
check( 'OFF query pads UPC-A', $B::off_query_code( $B::normalize( '036000291452' ) ), '0036000291452' );
check( 'OFF query keeps EAN-8', $B::off_query_code( $B::normalize( '96385074' ) ), '96385074' );
check( 'OFF query keeps invalid digits', $B::off_query_code( $B::normalize( '12345' ) ), '12345' );

bazaar_test_standalone( __FILE__ );
