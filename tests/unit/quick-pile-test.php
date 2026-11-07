<?php
/**
 * Quick Add "Scan a pile" pure helpers: open_key building, count and id
 * parsing, thumb and title cleaning, and PileItem shaping.
 *
 * @package Xophz_Compass_Bazaar
 */

require_once dirname( __DIR__ ) . '/support/harness.php';
$bazaar_root = dirname( dirname( __DIR__ ) );
require_once $bazaar_root . '/includes/class-xophz-compass-bazaar-barcode.php';
require_once $bazaar_root . '/includes/class-xophz-compass-bazaar-quick-pile-store.php';
require_once $bazaar_root . '/includes/schema/class-xophz-compass-bazaar-schema-tables.php';

$QP = 'Xophz_Compass_Bazaar_Quick_Pile_Store';
$QB = 'Xophz_Compass_Bazaar_Barcode';

// Keys: a valid code is keyed by its normalized form, so every way of
// scanning or typing it lands on one row.
$upca   = $QB::normalize( '036000291452' );
$spaced = $QB::normalize( '0 36000 29145 2' );
check( 'pile: valid code key starts with g:', strpos( $QP::key_for( $upca ), 'g:' ), 0 );
check( 'pile: valid code key uses normalized', $QP::key_for( $upca ), 'g:' . $upca['normalized'] );
check( 'pile: spaced scan of same code -> same key', $QP::key_for( $spaced ), $QP::key_for( $upca ) );
check( 'pile: padded GTIN-14 of same code -> same key', $QP::key_for( $QB::normalize( '00036000291452' ) ), $QP::key_for( $upca ) );
check( 'pile: bad check digit -> raw key', $QP::key_for( $QB::normalize( '036000291453' ) ), 'r:036000291453' );
check( 'pile: house label -> raw key, trimmed', $QP::key_for( $QB::normalize( '  WD-00042 ' ) ), 'r:WD-00042' );
check( 'pile: empty code -> no key', $QP::key_for( $QB::normalize( '   ' ) ), '' );
check( 'pile: key without normalized but valid flag -> raw', $QP::key_for( [ 'valid' => true, 'normalized' => '', 'raw' => 'X1' ] ), 'r:X1' );
check( 'pile: key fits the open_key column', strlen( $QP::key_for( $QB::normalize( str_repeat( 'A', 200 ) ) ) ) <= 80, true );

// Counts: whole numbers 0..10000 only.
check( 'pile: qty 0', $QP::parse_qty( 0 ), 0 );
check( 'pile: qty 3', $QP::parse_qty( 3 ), 3 );
check( 'pile: qty 10000', $QP::parse_qty( 10000 ), 10000 );
check( 'pile: qty 10001 refused', $QP::parse_qty( 10001 ), null );
check( 'pile: qty -1 refused', $QP::parse_qty( -1 ), null );
check( 'pile: qty "7" string', $QP::parse_qty( '7' ), 7 );
check( 'pile: qty " 12 " string', $QP::parse_qty( ' 12 ' ), 12 );
check( 'pile: qty 4.0 float', $QP::parse_qty( 4.0 ), 4 );
check( 'pile: qty 2.5 refused', $QP::parse_qty( 2.5 ), null );
check( 'pile: qty "2.5" refused', $QP::parse_qty( '2.5' ), null );
check( 'pile: qty "-3" refused', $QP::parse_qty( '-3' ), null );
check( 'pile: qty null refused', $QP::parse_qty( null ), null );
check( 'pile: qty true refused', $QP::parse_qty( true ), null );
check( 'pile: qty "" refused', $QP::parse_qty( '' ), null );
check( 'pile: qty NAN refused', $QP::parse_qty( NAN ), null );

// Ids and uuids.
check( 'pile: id 5', $QP::parse_id( 5 ), 5 );
check( 'pile: id "42"', $QP::parse_id( '42' ), 42 );
check( 'pile: id 0 refused', $QP::parse_id( 0 ), 0 );
check( 'pile: id -2 refused', $QP::parse_id( -2 ), 0 );
check( 'pile: id "4x" refused', $QP::parse_id( '4x' ), 0 );
check( 'pile: id null refused', $QP::parse_id( null ), 0 );
check( 'pile: uuid ok', $QP::parse_uuid( 'a1-B2_c3' ), 'a1-B2_c3' );
check( 'pile: uuid with space refused', $QP::parse_uuid( 'a b' ), '' );
check( 'pile: uuid too long refused', $QP::parse_uuid( str_repeat( 'a', 65 ) ), '' );
check( 'pile: uuid number refused', $QP::parse_uuid( 12 ), '' );

// Thumbs: https only, no credentials or ports.
check( 'pile: https thumb kept', $QP::clean_thumb( 'https://images.openfoodfacts.org/a.jpg' ), 'https://images.openfoodfacts.org/a.jpg' );
check( 'pile: http thumb dropped', $QP::clean_thumb( 'http://example.com/a.jpg' ), '' );
check( 'pile: javascript thumb dropped', $QP::clean_thumb( 'javascript:alert(1)' ), '' );
check( 'pile: thumb with user dropped', $QP::clean_thumb( 'https://u:p@example.com/a.jpg' ), '' );
check( 'pile: thumb with port dropped', $QP::clean_thumb( 'https://example.com:8443/a.jpg' ), '' );
check( 'pile: thumb with quote dropped', $QP::clean_thumb( 'https://example.com/a".jpg' ), '' );
check( 'pile: too long thumb dropped', $QP::clean_thumb( 'https://example.com/' . str_repeat( 'a', 1000 ) ), '' );
check( 'pile: empty thumb', $QP::clean_thumb( '' ), '' );
check( 'pile: array thumb', $QP::clean_thumb( [ 'x' ] ), '' );

// Titles: at most 200 characters, multibyte-safe.
check( 'pile: title trimmed', $QP::clip_title( '  Corn Flakes  ' ), 'Corn Flakes' );
check( 'pile: title cut to 200', strlen( $QP::clip_title( str_repeat( 'x', 250 ) ) ), 200 );
if ( function_exists( 'mb_strlen' ) ) {
  check( 'pile: multibyte title cut to 200 chars', mb_strlen( $QP::clip_title( str_repeat( 'é', 250 ) ) ), 200 );
}

// Dates.
check( 'pile: iso of UTC datetime', $QP::iso( '2026-11-06 14:05:00' ), '2026-11-06T14:05:00+00:00' );
check( 'pile: iso of zero date', $QP::iso( '0000-00-00 00:00:00' ), '' );
check( 'pile: iso of null', $QP::iso( null ), '' );

// PileItem shape.
$row  = [
  'id' => '12', 'open_key' => 'g:0036000291452', 'barcode' => '036000291452', 'normalized' => '0036000291452',
  'valid' => '1', 'qty' => '3', 'title' => 'Corn Flakes', 'thumb' => '', 'product_id' => null,
  'status' => 'open', 'created_by' => '7', 'claim_key' => null, 'saved_product_id' => null,
  'created_at' => '2026-11-06 14:05:00', 'updated_at' => '2026-11-06 14:06:30', 'done_at' => null,
];
$item = $QP::shape( $row, [ 7 => 'Grandpa Joe' ] );
check( 'pile: shape keys (exact contract, in order)', array_keys( $item ), [ 'pile_id', 'barcode', 'normalized', 'valid', 'qty', 'title', 'thumb', 'product_id', 'status', 'scanned_by', 'created_at', 'updated_at' ] );
check( 'pile: shape pile_id int', $item['pile_id'], 12 );
check( 'pile: shape valid bool', $item['valid'], true );
check( 'pile: shape qty int', $item['qty'], 3 );
check( 'pile: shape product_id null', $item['product_id'], null );
check( 'pile: shape status open', $item['status'], 'open' );
check( 'pile: shape scanned_by name', $item['scanned_by'], 'Grandpa Joe' );
check( 'pile: shape created_at iso', $item['created_at'], '2026-11-06T14:05:00+00:00' );
check( 'pile: shape updated_at iso', $item['updated_at'], '2026-11-06T14:06:30+00:00' );
check( 'pile: shape hides internal columns', isset( $item['open_key'] ) || isset( $item['claim_key'] ) || isset( $item['saved_product_id'] ), false );

$done = $QP::shape( (object) array_merge( $row, [ 'status' => 'done', 'product_id' => '55', 'valid' => '0', 'created_by' => '9' ] ), [ 7 => 'Grandpa Joe' ] );
check( 'pile: shape of object row', $done['pile_id'], 12 );
check( 'pile: shape status done', $done['status'], 'done' );
check( 'pile: shape product_id int', $done['product_id'], 55 );
check( 'pile: shape valid false', $done['valid'], false );
check( 'pile: shape unknown scanner -> empty', $done['scanned_by'], '' );
check( 'pile: shape odd status -> open', $QP::shape( array_merge( $row, [ 'status' => 'weird' ] ) )['status'], 'open' );
check( 'pile: shape product_id 0 -> null', $QP::shape( array_merge( $row, [ 'product_id' => '0' ] ) )['product_id'], null );

// Claim: scans beyond what the screen showed go back in the pile.
check( 'pile: extra: same count -> 0', $QP::extra_after_claim( 3, 3 ), 0 );
check( 'pile: extra: two more scanned meanwhile -> 2', $QP::extra_after_claim( 5, 3 ), 2 );
check( 'pile: extra: fewer on the row -> 0', $QP::extra_after_claim( 2, 3 ), 0 );
check( 'pile: extra: unknown seen -> 0', $QP::extra_after_claim( 5, null ), 0 );
check( 'pile: extra: seen 0 -> 0', $QP::extra_after_claim( 5, 0 ), 0 );

// Schema (Schema_Tables holds the SQL): open_key compares byte for byte.
$qp_sql = Xophz_Compass_Bazaar_Schema_Tables::definitions( 'wp_', '' )['quick_pile'];
$qp_src = (string) file_get_contents( $bazaar_root . '/includes/class-xophz-compass-bazaar-quick-pile-store.php' );
check( 'pile: open_key is binary', (bool) preg_match( '/^\s*open_key varbinary\(80\) DEFAULT NULL,$/m', $qp_sql ), true );
check( 'pile: the store names the same table', 0 === strpos( $qp_sql, 'CREATE TABLE wp_' . $QP::TABLE . ' (' ), true );
check( 'pile: no deprecated VALUES() in upsert', false === strpos( $qp_src, 'VALUES(updated_at)' ), true );
check( 'pile: house labels differing by case get different keys', $QP::key_for( $QB::normalize( 'WD-00042a' ) ) !== $QP::key_for( $QB::normalize( 'wd-00042A' ) ), true );

bazaar_test_standalone( __FILE__ );
