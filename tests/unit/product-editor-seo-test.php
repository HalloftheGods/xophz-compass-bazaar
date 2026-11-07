<?php
/**
 * Product editor SEO fields: only the allowlisted keys pass, and a key the
 * request did not send stays out so a save cannot blank it.
 *
 * @package Xophz_Compass_Bazaar
 */

require_once dirname( __DIR__ ) . '/support/harness.php';
require_once dirname( __DIR__, 2 ) . '/admin/class-xophz-compass-bazaar-admin-product-seo.php';

if ( ! function_exists( 'esc_url_raw' ) ) {
  function esc_url_raw( $url ) { return preg_match( '#^https?://#i', $url ) ? $url : ''; }
}

$S = 'Xophz_Compass_Bazaar_Admin_Product_Seo';

$meta = json_decode( json_encode( [
  '_wds_title'                => '  <b>Tea</b> shop ',
  '_wds_canonical'            => 'javascript:alert(1)',
  '_wds_redirect'             => 'https://example.com/new',
  '_wds_opengraph'            => [ 'title' => 'OG', 'description' => 'Desc', 'images' => [ 'x' ] ],
  '_wds_meta-robots-noindex'  => true,
  '_wds_meta-robots-nofollow' => false,
  '_edit_lock'                => 'nope',
  '_sku'                      => 'nope',
] ) );
$clean = $S::clean( $meta );

check( 'text is stripped and trimmed', $clean['_wds_title'], 'Tea shop' );
check( 'a bad canonical URL is emptied', $clean['_wds_canonical'], '' );
check( 'a good redirect URL is kept', $clean['_wds_redirect'], 'https://example.com/new' );
check( 'Open Graph keeps only title and description', $clean['_wds_opengraph'], [ 'title' => 'OG', 'description' => 'Desc' ] );
check( 'true flag', $clean['_wds_meta-robots-noindex'], true );
check( 'false flag', $clean['_wds_meta-robots-nofollow'], false );
check( 'a key not on the list is dropped', array_key_exists( '_edit_lock', $clean ) || array_key_exists( '_sku', $clean ), false );
check( 'a key not sent is not blanked', array_key_exists( '_wds_metadesc', $clean ), false );
check( 'non-array meta gives nothing', $S::clean( 'x' ), [] );
check( 'the string "false" is a false flag', $S::clean( [ '_wds_meta-robots-noindex' => 'false' ] )['_wds_meta-robots-noindex'], false );

bazaar_test_standalone( __FILE__ );
