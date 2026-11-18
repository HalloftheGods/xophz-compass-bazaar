<?php
/**
 * Tests for the 10-tier barcode waterfall pipeline: prefix classifiers and parsers.
 *
 * @package Xophz_Compass_Bazaar
 */

require_once dirname( __DIR__ ) . '/support/harness.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-xophz-compass-bazaar-barcode.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-xophz-compass-bazaar-barcode-sources.php';

$B = 'Xophz_Compass_Bazaar_Barcode';
$S = 'Xophz_Compass_Bazaar_Barcode_Sources';

// 1. Prefix Classifiers
check( 'is_isbn: 978 prefix', $S::is_isbn( '9780140328721' ), true );
check( 'is_isbn: 979 prefix', $S::is_isbn( '9791090636071' ), true );
check( 'is_isbn: 10-digit ISBN', $S::is_isbn( '0140328726' ), true );
check( 'is_isbn: standard 12-digit UPC is not ISBN', $S::is_isbn( '049000006343' ), false );

check( 'is_ndc_candidate: 12-digit UPC starting with 3', $S::is_ndc_candidate( '300450449108' ), true );
check( 'is_ndc_candidate: 10-digit NDC starting with 3', $S::is_ndc_candidate( '3004504491' ), true );
check( 'is_ndc_candidate: 12-digit UPC starting with 0 is not NDC', $S::is_ndc_candidate( '049000006343' ), false );

// 2. Open Library (Tier 5)
$ol_json = (string) json_encode( [
  'ISBN:9780140328721' => [
    'title'      => 'Matilda',
    'authors'    => [ [ 'name' => 'Roald Dahl' ] ],
    'cover'      => [ 'large' => 'https://covers.openlibrary.org/b/id/12345-L.jpg' ],
    'subjects'   => [ [ 'name' => 'Children\'s Fiction' ] ],
    'notes'      => 'A beloved classic novel.'
  ]
] );
$ol_parsed = $S::parse_open_library( 200, $ol_json, '9780140328721' );
check( 'open_library: found status', $ol_parsed['status'], 'found' );
check( 'open_library: title', $ol_parsed['product']['title'], 'Matilda' );
check( 'open_library: author/brand', $ol_parsed['product']['brand'], 'Roald Dahl' );
check( 'open_library: image_url', $ol_parsed['product']['image_url'], 'https://covers.openlibrary.org/b/id/12345-L.jpg' );
check( 'open_library: 404 is not_found', $S::parse_open_library( 404, '', '9780140328721' )['status'], 'not_found' );

// 3. Google Books (Tier 6)
$gb_json = (string) json_encode( [
  'items' => [
    [
      'volumeInfo' => [
        'title'       => 'The Hobbit',
        'authors'     => [ 'J.R.R. Tolkien' ],
        'description' => 'A fantasy adventure.',
        'categories'  => [ 'Fantasy' ],
        'imageLinks'  => [ 'thumbnail' => 'http://books.google.com/books/content?id=xyz' ]
      ]
    ]
  ]
] );
$gb_parsed = $S::parse_google_books( 200, $gb_json );
check( 'google_books: found status', $gb_parsed['status'], 'found' );
check( 'google_books: title', $gb_parsed['product']['title'], 'The Hobbit' );
check( 'google_books: author/brand', $gb_parsed['product']['brand'], 'J.R.R. Tolkien' );
check( 'google_books: image upgraded to https', $gb_parsed['product']['image_url'], 'https://books.google.com/books/content?id=xyz' );

// 4. openFDA NDC (Tier 7)
$fda_json = (string) json_encode( [
  'results' => [
    [
      'brand_name'   => 'Tylenol Extra Strength',
      'generic_name' => 'Acetaminophen',
      'labeler_name' => 'Johnson & Johnson Consumer Inc.',
      'dosage_form'  => 'TABLET, FILM COATED',
      'route'        => [ 'ORAL' ]
    ]
  ]
] );
$fda_parsed = $S::parse_open_fda( 200, $fda_json );
check( 'open_fda: found status', $fda_parsed['status'], 'found' );
check( 'open_fda: title carries generic', $fda_parsed['product']['title'], 'Tylenol Extra Strength (Acetaminophen)' );
check( 'open_fda: labeler', $fda_parsed['product']['brand'], 'Johnson & Johnson Consumer Inc.' );
check( 'open_fda: description', $fda_parsed['product']['description'], 'TABLET, FILM COATED - ORAL' );
check( 'open_fda: 404 is not_found', $S::parse_open_fda( 404, '' )['status'], 'not_found' );

// 5. Brocade.io (Tier 8)
$brocade_json = (string) json_encode( [
  'name'        => 'Wireless Bluetooth Mouse',
  'brand_name'  => 'Logitech',
  'category'    => 'Electronics > Computer Accessories',
  'description' => 'Precision optical mouse.',
  'images'      => [ 'https://example.com/mouse.jpg' ]
] );
$brocade_parsed = $S::parse_brocade( 200, $brocade_json );
check( 'brocade: found status', $brocade_parsed['status'], 'found' );
check( 'brocade: title', $brocade_parsed['product']['title'], 'Wireless Bluetooth Mouse' );
check( 'brocade: brand', $brocade_parsed['product']['brand'], 'Logitech' );
check( 'brocade: image', $brocade_parsed['product']['image_url'], 'https://example.com/mouse.jpg' );
check( 'brocade: 404 is not_found', $S::parse_brocade( 404, '' )['status'], 'not_found' );

// 6. Barcode Monster (Tier 10)
$monster_json = (string) json_encode( [
  'status'      => 'active',
  'description' => 'Stainless Steel Insulated Tumbler',
  'company'     => 'Yeti',
  'class'       => 'Kitchenware',
  'image_url'   => 'https://example.com/tumbler.jpg'
] );
$monster_parsed = $S::parse_barcode_monster( 200, $monster_json );
check( 'barcode_monster: found status', $monster_parsed['status'], 'found' );
check( 'barcode_monster: title', $monster_parsed['product']['title'], 'Stainless Steel Insulated Tumbler' );
// 7. Merge Outcomes & Sources Tracking
$outcomes_all_miss = [
  [ 'status' => 'not_found', 'source' => 'openfoodfacts' ],
  [ 'status' => 'not_found', 'source' => 'upcitemdb' ],
  [ 'status' => 'not_found', 'source' => 'barcodemonster' ],
  [ 'status' => 'bot_blocked', 'source' => 'amazon' ],
];
$merged_miss = $S::merge( $outcomes_all_miss );
check( 'merge: status not_found', $merged_miss['status'], 'not_found' );
check( 'merge: sources_tried populated', count( $merged_miss['sources_tried'] ), 4 );
check( 'merge: sources_tried includes amazon', in_array( 'amazon', $merged_miss['sources_tried'], true ), true );

$outcomes_win = [
  [ 'status' => 'not_found', 'source' => 'openfoodfacts' ],
  [ 'status' => 'found', 'source' => 'amazon', 'product' => [ 'title' => 'Sample' ] ],
];
$merged_win = $S::merge( $outcomes_win );
check( 'merge win: status found', $merged_win['status'], 'found' );
check( 'merge win: sources_tried contains queried sources', $merged_win['sources_tried'], [ 'openfoodfacts', 'amazon' ] );

bazaar_test_standalone( __FILE__ );
