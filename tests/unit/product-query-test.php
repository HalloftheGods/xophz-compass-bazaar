<?php
/**
 * Product list requests: which arguments survive Product_Query::clean_args().
 * The SQL helpers need a database and are covered by the live check in
 * the task notes (search for "Men's" and a no-match search).
 *
 * @package Xophz_Compass_Bazaar
 */

require_once dirname( __DIR__ ) . '/support/harness.php';
require_once dirname( __DIR__, 2 ) . '/admin/class-xophz-compass-bazaar-admin-product-query.php';

if ( ! function_exists( 'sanitize_title' ) ) {
  function sanitize_title( $title ) { return strtolower( trim( preg_replace( '/[^A-Za-z0-9]+/', '-', (string) $title ), '-' ) ); }
}
if ( ! function_exists( 'wc_get_product_types' ) ) {
  function wc_get_product_types() { return [ 'simple' => 'Simple', 'variable' => 'Variable', 'grouped' => 'Grouped', 'external' => 'External' ]; }
}

$Q     = 'Xophz_Compass_Bazaar_Admin_Product_Query';
$clean = function ( array $body ) use ( $Q ) { return $Q::clean_args( json_decode( json_encode( $body ) ) ); };

$a = $clean( [ 'page' => 3, 'limit' => 25, 'category' => 'shirts', 'stock_status' => 'instock', 'type' => 'simple' ] );
check( 'page kept', $a['page'], 3 );
check( 'limit kept', $a['limit'], 25 );
check( 'category becomes a list of slugs', $a['category'], [ 'shirts' ] );
check( 'stock_status kept', $a['stock_status'], 'instock' );
check( 'known type kept', $a['type'], 'simple' );

$a = $clean( [ 'include' => [ 1 ], 'status' => 'trash', 'orderby' => 'rand', 'meta_query' => [ 1 ], 'return' => 'objects' ] );
check( 'unknown args dropped', array_keys( $a ), [ 'filters', 'page', 'limit' ] );

check( 'limit capped', $clean( [ 'limit' => 99999 ] )['limit'], 200 );
check( 'limit floor', $clean( [ 'limit' => 0 ] )['limit'], 1 );
check( 'page floor', $clean( [ 'page' => -4 ] )['page'], 1 );
check( 'bad stock_status dropped', isset( $clean( [ 'stock_status' => 'x' ] )['stock_status'] ), false );
check( 'unknown type dropped', isset( $clean( [ 'type' => 'evil' ] )['type'] ), false );

$a = $clean( [ 'type' => 'downloadable' ] );
check( 'downloadable maps to the WooCommerce flag', [ $a['downloadable'] ?? null, isset( $a['type'] ) ], [ true, false ] );
$a = $clean( [ 'type' => 'virtual' ] );
check( 'virtual maps to the WooCommerce flag', [ $a['virtual'] ?? null, isset( $a['type'] ) ], [ true, false ] );

check( 'filters as an empty list become empty', $clean( [ 'filters' => [] ] )['filters'], [] );
check( 'blank terms dropped', $clean( [ 'filters' => [ 'title' => '  ', 'sku' => '' ] ] )['filters'], [] );
check( 'apostrophe survives untouched', $clean( [ 'filters' => [ 'title' => "Men's" ] ] )['filters'], [ 'title' => "Men's" ] );
check( 'sku string becomes a list', $clean( [ 'filters' => [ 'sku' => 'AB1' ] ] )['filters'], [ 'sku' => [ 'AB1' ] ] );
check( 'sku array kept, non-strings dropped', $clean( [ 'filters' => [ 'sku' => [ 'AB1', 5, [ 'x' ], 'CD2' ] ] ] )['filters'], [ 'sku' => [ 'AB1', 'CD2' ] ] );
check( 'search means a title search', $clean( [ 'search' => 'mug' ] )['filters'], [ 'title' => 'mug' ] );
check( 'explicit title beats search', $clean( [ 'search' => 'mug', 'filters' => [ 'title' => 'cup' ] ] )['filters'], [ 'title' => 'cup' ] );
check( 'non-object body is harmless', $Q::clean_args( 'junk' ), [ 'filters' => [], 'page' => 1, 'limit' => 10 ] );

bazaar_test_standalone( __FILE__ );
