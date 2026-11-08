<?php
/**
 * Barcode_Details: what a lookup gives a product's descriptions, the
 * fill-only-empty rule, and the description column of the Barcode_Memory.
 *
 * @package Xophz_Compass_Bazaar
 */

require_once dirname( __DIR__ ) . '/support/harness.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-xophz-compass-bazaar-barcode.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-xophz-compass-bazaar-barcode-sources.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-xophz-compass-bazaar-barcode-details.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-xophz-compass-bazaar-barcode-memory.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-xophz-compass-bazaar-barcode-image.php';

$D = 'Xophz_Compass_Bazaar_Barcode_Details';
$M = 'Xophz_Compass_Bazaar_Barcode_Memory';
$S = 'Xophz_Compass_Bazaar_Barcode_Sources';

// clean_description().
check( 'clean: plain text stays', $D::clean_description( 'Hazelnut spread with cocoa' ), 'Hazelnut spread with cocoa' );
check( 'clean: tags and scripts go', $D::clean_description( '<p>Soap <b>bar</b></p><script>x()</script>' ), 'Soap bar' );
check( 'clean: whitespace collapses', $D::clean_description( "  Soap \n\n  bar \t " ), 'Soap bar' );
check( 'clean: money goes', $D::clean_description( 'Tide Pods was $12.99' ), 'Tide Pods' );
check( 'clean: sizes stay', $D::clean_description( 'Cola 12 oz cans' ), 'Cola 12 oz cans' );
check( 'clean: an array is empty', $D::clean_description( [ 'x' ] ), '' );
check( 'clean: null is empty', $D::clean_description( null ), '' );
$long = str_repeat( 'word ', 400 );
$cut  = $D::clean_description( $long );
check( 'clean: cut to the limit', strlen( $cut ) <= $D::DESCRIPTION_MAX, true );
check( 'clean: cut at a word', substr( $cut, -4 ), 'word' );
check( 'clean: one long word is cut hard', strlen( $D::clean_description( str_repeat( 'a', 3000 ) ) ), $D::DESCRIPTION_MAX );
check( 'clean: the memory uses the same cleaner', $M::clean_description( '<i>Soap</i> $2.00' ), 'Soap' );

// short_description().
check( 'short: short text is kept whole', $D::short_description( 'Hazelnut spread' ), 'Hazelnut spread' );
$sentence = 'Creamy hazelnut spread with cocoa for toast and pancakes. ' . str_repeat( 'More detail follows here. ', 20 );
check( 'short: a long text becomes its first sentence', $D::short_description( trim( $sentence ) ), 'Creamy hazelnut spread with cocoa for toast and pancakes.' );
$no_stop = str_repeat( 'granola ', 60 );
$short   = $D::short_description( trim( $no_stop ) );
check( 'short: no sentence end cuts at a word with dots', substr( $short, -3 ), '...' );
check( 'short: stays within the limit', strlen( $short ) <= $D::SHORT_MAX, true );
check( 'short: empty stays empty', $D::short_description( '' ), '' );

// category_hints().
check( 'hints: a comma list', $D::category_hints( 'Snacks, Sweet snacks,Biscuits' ), [ 'Snacks', 'Sweet snacks', 'Biscuits' ] );
check( 'hints: language prefixes go', $D::category_hints( 'en:Snacks, fr:Biscuits' ), [ 'Snacks', 'Biscuits' ] );
check( 'hints: repeats and blanks go', $D::category_hints( 'Snacks, ,Snacks' ), [ 'Snacks' ] );
check( 'hints: capped', count( $D::category_hints( 'a,b,c,d,e,f,g,h,i,j,k' ) ), $D::HINTS_MAX );
check( 'hints: not a string', $D::category_hints( [ 'Snacks' ] ), [] );

// from_product(): the shapes the providers give.
$off = $S::parse_open_facts( 200, (string) json_encode( [ 'status' => 1, 'product' => [ 'product_name' => 'Nutella', 'brands' => 'Ferrero', 'generic_name' => 'Hazelnut spread with cocoa', 'categories' => 'Spreads, Sweet spreads' ] ] ), 'openfoodfacts' );
$got = $D::from_product( $off['product'] );
check( 'product: open facts description', $got['description'], 'Hazelnut spread with cocoa' );
check( 'product: open facts short', $got['short_description'], 'Hazelnut spread with cocoa' );
check( 'product: open facts categories', $got['category_hints'], [ 'Spreads', 'Sweet spreads' ] );
$upc = $S::parse_upcitemdb( 200, (string) json_encode( [ 'items' => [ [ 'title' => 'WD-40', 'brand' => 'WD-40', 'description' => '<b>Multi-use</b> product, 8 oz', 'category' => 'Hardware > Lubricants' ] ] ] ) );
$got = $D::from_product( $upc['product'] );
check( 'product: upc description', $got['description'], 'Multi-use product, 8 oz' );
check( 'product: upc category path is split', $got['category_hints'], [ 'Hardware', 'Lubricants' ] );
check( 'product: no description gives none', $D::from_product( [ 'title' => 'Soap', 'brand' => 'Acme', 'description' => '', 'category' => '' ] ), $D::none() );
check( 'product: null gives none', $D::from_product( null ), $D::none() );
check( 'product: brand is not a detail', array_key_exists( 'brand', $D::none() ), false );

// fill(): only what is empty.
$details = [ 'description' => 'Long text', 'short_description' => 'Short text', 'category_hints' => [] ];
check( 'fill: both empty', $D::fill( [], $details ), [ 'description' => 'Long text', 'short_description' => 'Short text' ] );
check( 'fill: a written description stays', $D::fill( [ 'description' => 'Mine', 'short_description' => '' ], $details ), [ 'short_description' => 'Short text' ] );
check( 'fill: a written short stays', $D::fill( [ 'description' => '', 'short_description' => 'Mine' ], $details ), [ 'description' => 'Long text' ] );
check( 'fill: both written, nothing', $D::fill( [ 'description' => 'A', 'short_description' => 'B' ], $details ), [] );
check( 'fill: blanks count as empty', $D::fill( [ 'description' => "  \n", 'short_description' => ' ' ], $details ), [ 'description' => 'Long text', 'short_description' => 'Short text' ] );
check( 'fill: no details, nothing', $D::fill( [], $D::none() ), [] );

// Memory: the description column, view and upsert.
$create = $M::definition( 'wp_bazaar_barcode_memory', 'DEFAULT CHARSET=utf8mb4' );
check( 'table: description column', false !== strpos( $create, "description varchar(1000) NOT NULL DEFAULT ''" ), true );
check( 'table: the column fits the cap', $D::DESCRIPTION_MAX <= 1000, true );
check( 'view: description is public', $M::view( [ 'title' => 'T', 'description' => 'D', 'title_owner' => 'abc' ] )['description'], 'D' );
check( 'view: a row from before the column has none', $M::view( [ 'title' => 'T' ] )['description'], '' );
$sql = $M::upsert_sql( 'wp_bazaar_barcode_memory' );
check( 'sql: description is only filled', false !== strpos( $sql, "description = IF(description = '', VALUES(description), description)" ), true );
check( 'sql: description before source', strpos( $sql, ' description = IF(' ) < strpos( $sql, ' source = IF(' ), true );
check( 'sql: nine placeholders', substr_count( $sql, '%s' ), 9 );
check( 'sql: still one statement', substr_count( $sql, ';' ), 0 );
check( 'sql: no price column', false !== stripos( $sql, 'price' ), false );

bazaar_test_standalone( __FILE__ );
