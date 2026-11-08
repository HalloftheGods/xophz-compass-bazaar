<?php
/**
 * Live checks of the barcode lookup and Barcode_Memory, run inside WordPress
 * by barcode-memory.sh. Prints one "name=value" line per fact.
 *
 * Needs outbound HTTPS for the two provider steps. Counts HTTP calls by
 * listening on pre_http_request, which both the single and parallel paths
 * fire. Removes every row and transient it made.
 *
 * Env: MEMORY_STEP one of the step names below.
 */
$admin  = 'Xophz_Compass_Bazaar_Admin';
$memory = 'Xophz_Compass_Bazaar_Barcode_Memory';
global $wpdb;
$table = $memory::table();

$GLOBALS['bazaar_http_calls'] = [];
add_filter( 'pre_http_request', function ( $pre, $args, $url ) {
  $GLOBALS['bazaar_http_calls'][] = $url;
  return $pre;
}, 1, 3 );

$reset = function ( string $code ) use ( $wpdb, $table, $memory, $admin ) {
  $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE code = %s", $code ) );
  wp_cache_delete( $code, $memory::CACHE_GROUP );
  delete_transient( $admin::LOOKUP_CACHE_PREFIX . md5( $code ) );
};
$row = function ( string $code ) use ( $wpdb, $table ) {
  return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE code = %s", $code ), ARRAY_A );
};
$say = function ( string $name, $value ) {
  echo $name . '=' . ( is_scalar( $value ) ? var_export( $value, true ) : wp_json_encode( $value ) ) . "\n";
};
$lookup = function ( string $code ) use ( $admin ) {
  $GLOBALS['bazaar_http_calls'] = [];
  $found = $admin::global_barcode_lookup( $code );
  return [ $found, count( $GLOBALS['bazaar_http_calls'] ) ];
};

$step = (string) getenv( 'MEMORY_STEP' );

if ( 'beauty' === $step ) {
  $reset( '3337875597197' );
  [ $found, $calls ] = $lookup( '3337875597197' );
  $say( 'status', $found['status'] );
  $say( 'source', $found['source'] );
  $say( 'title', $found['product']['title'] ?? '' );
  $say( 'image', $found['product']['image_url'] ?? '' );
  $say( 'calls', $calls );
  $say( 'stored', $row( '3337875597197' )['source'] ?? 'none' );
  $reset( '3337875597197' );
}

// Picture code: a UPCitemdb item (not in any Open*Facts database).
$picture_code = '885909950805';
if ( 'picture-clear' === $step ) {
  $reset( $picture_code );
  foreach ( wc_get_products( [ 'sku' => $picture_code, 'status' => [ 'publish', 'trash', 'draft' ], 'limit' => -1 ] ) as $product ) {
    $image = (int) $product->get_image_id();
    if ( $image > 0 ) {
      wp_delete_attachment( $image, true );
    }
    $product->delete( true );
  }
}
if ( 'picture-state' === $step ) {
  $id      = (int) wc_get_product_id_by_sku( $picture_code );
  $product = $id ? wc_get_product( $id ) : null;
  $say( 'has_picture', $product && $product->get_image_id() > 0 );
  $say( 'memory_image_uploads', false !== strpos( (string) ( $row( $picture_code )['image_url'] ?? '' ), '/wp-content/uploads/' ) );
  $say( 'memory_has_image', '' !== (string) ( $row( $picture_code )['image_url'] ?? '' ) );
}
if ( 'url-rules' === $step ) {
  $image = 'Xophz_Compass_Bazaar_Barcode_Image';
  foreach ( [ 'http://127.0.0.1/a.jpg', 'http://10.0.0.8/a.jpg', 'http://192.168.1.1/a.jpg', 'file:///etc/passwd', 'http://169.254.169.254/x', 'https://u:p@example.com/a.jpg' ] as $i => $url ) {
    $say( "unsafe_$i", null === $image::fetch( $url ) );
  }
  $say( 'html_is_not_a_picture', null === $image::fetch( 'https://example.com/' ) );
}

if ( 'food' === $step ) {
  $reset( '3017620422003' );
  [ $first, $calls ] = $lookup( '3017620422003' );
  $say( 'first_status', $first['status'] );
  $say( 'first_source', $first['source'] );
  $say( 'first_calls', $calls );
  $say( 'stored_source', $row( '3017620422003' )['source'] ?? 'none' );
  $hits = (int) ( $row( '3017620422003' )['hits'] ?? 0 );
  [ $second, $calls ] = $lookup( '3017620422003' );
  $say( 'second_status', $second['status'] );
  $say( 'second_source', $second['source'] );
  $say( 'second_calls', $calls );
  $say( 'second_title_same', $first['product']['title'] === $second['product']['title'] );
  $say( 'read_did_not_write', $hits === (int) ( $row( '3017620422003' )['hits'] ?? -1 ) );
  $say( 'no_owner_in_answer', false === strpos( (string) wp_json_encode( $second ), 'title_owner' ) );
  $reset( '3017620422003' );
}

if ( 'restricted' === $step ) {
  $code = '21234567890' . Xophz_Compass_Bazaar_Barcode::check_digit( '21234567890' );
  $reset( $code );
  // A provider that answers: the code must still stay out of the memory.
  add_filter( 'pre_http_request', function ( $pre, $args, $url ) {
    return [ 'response' => [ 'code' => 200 ], 'body' => wp_json_encode( [ 'status' => 1, 'product' => [ 'product_name' => 'Deli Ham' ] ] ), 'headers' => [], 'cookies' => [] ];
  }, 20, 3 );
  [ $found ] = $lookup( $code );
  $say( 'status', $found['status'] );
  $say( 'row', $row( $code ) ? 'stored' : 'absent' );
  $say( 'store_write', var_export( $memory::remember_store( $code, 'Deli Ham', '' ), true ) );
  $say( 'row_after_store', $row( $code ) ? 'stored' : 'absent' );
  $say( 'find', var_export( $memory::find( $code ), true ) );
  $reset( $code );
}

// create-undo: this step only sets up and reads; barcode-memory.sh drives the ajax.
$undo_code = '4006381333931';
if ( in_array( $step, [ 'clear', 'undo-cleanup', 'details-seed' ], true ) ) {
  $reset( $undo_code );
  foreach ( wc_get_products( [ 'sku' => $undo_code, 'status' => [ 'publish', 'trash', 'draft' ], 'limit' => -1 ] ) as $product ) {
    $product->delete( true );
  }
}
// A create also looks its code up for the descriptions. These checks are about the memory
// rows, so the lookup is answered from this site's cache: no HTTP call and no row written.
if ( 'clear' === $step ) {
  set_transient( $admin::LOOKUP_CACHE_PREFIX . md5( $undo_code ), [ 'status' => 'not_found', 'source' => '', 'product' => null, 'price_hint' => null ], 600 );
}
// A found outcome with a public description, for the descriptions check.
if ( 'details-seed' === $step ) {
  $found = [ 'title' => 'Provider Pencil', 'brand' => 'Acme', 'description' => 'Soft <b>graphite</b> pencil for paper and wood $2.50', 'category' => '', 'image_url' => '', 'image_urls' => [] ];
  set_transient( $admin::LOOKUP_CACHE_PREFIX . md5( $undo_code ), [ 'status' => 'found', 'source' => 'upcitemdb', 'product' => $found, 'price_hint' => null ], 600 );
}
if ( 'details-state' === $step ) {
  $product = current( wc_get_products( [ 'sku' => $undo_code, 'status' => [ 'publish' ], 'limit' => 1 ] ) );
  $say( 'description', $product ? $product->get_description() : 'no product' );
  $say( 'short', $product ? $product->get_short_description() : 'no product' );
  $say( 'memory_description', $row( $undo_code )['description'] ?? 'absent' );
}
if ( 'seed-provider' === $step ) {
  $memory::remember_provider( $undo_code, [ 'title' => 'Provider Pencil', 'brand' => 'Acme', 'image_url' => '' ] );
}
if ( 'row' === $step ) {
  $r = $row( $undo_code );
  $say( 'row', $r ? [ $r['title'], $r['brand'], $r['source'], '' === $r['title_owner'] ? 'no-owner' : 'owner', (int) $r['hits'] ] : 'absent' );
}

if ( 'owners' === $step ) {
  $code = '036000291452';
  $reset( $code );
  $memory::remember_provider( $code, [ 'title' => 'Provider Name $1.99', 'brand' => '', 'image_url' => '' ] );
  $r = $row( $code );
  $say( 'provider_title', $r['title'] );
  $say( 'provider_source', $r['source'] );
  $say( 'provider_owner', $r['title_owner'] );

  $undo = $memory::remember_store( $code, 'Store Name', '' );
  $r    = $row( $code );
  $say( 'store_over_provider', $r['title'] );
  $say( 'store_source', $r['source'] );
  $say( 'owner_length', strlen( $r['title_owner'] ) );

  $memory::remember_provider( $code, [ 'title' => 'Later Provider', 'brand' => 'Acme', 'image_url' => 'https://images.openfoodfacts.org/a.jpg' ] );
  $r = $row( $code );
  $say( 'provider_keeps_store_title', $r['title'] );
  $say( 'provider_fills_brand', $r['brand'] );
  $say( 'provider_fills_image', $r['image_url'] );
  $say( 'source_stays_store', $r['source'] );

  $memory::remember_store( $code, 'Same Owner Rename', '' );
  $say( 'same_owner_renames', $row( $code )['title'] );

  // Another site's row: this site may neither rename it nor restore over it.
  $wpdb->update( $table, [ 'title_owner' => 'aaaaaaaaaaaaaaaa', 'title' => 'Other Site Name' ], [ 'code' => $code ] );
  wp_cache_delete( $code, $memory::CACHE_GROUP );
  $memory::remember_store( $code, 'Rival Name', '' );
  $say( 'other_owner_kept', $row( $code )['title'] );
  $say( 'other_owner_marker_kept', $row( $code )['title_owner'] );
  $memory::restore( $undo );
  $say( 'restore_leaves_other_owner', $row( $code )['title'] );

  // Off switch.
  add_filter( $memory::OPTION_ENABLED, '__return_false' );
  $say( 'off_find', var_export( $memory::find( $code ), true ) );
  $hits = (int) $row( $code )['hits'];
  $memory::remember_provider( $code, [ 'title' => 'Off', 'brand' => '', 'image_url' => '' ] );
  $say( 'off_no_write', $hits === (int) $row( $code )['hits'] );
  remove_filter( $memory::OPTION_ENABLED, '__return_false' );

  // Row filter.
  $hide = function () { return null; };
  add_filter( $memory::ROW_FILTER, $hide );
  $say( 'filter_hides', var_export( $memory::find( $code ), true ) );
  remove_filter( $memory::ROW_FILTER, $hide );

  // forget(): WP-CLI is allowed.
  $say( 'forget', $memory::forget( $code ) );
  $say( 'forgotten', $row( $code ) ? 'stored' : 'absent' );
  $say( 'miss_cached_then_found', var_export( $memory::find( $code ), true ) );
  $memory::remember_provider( $code, [ 'title' => 'Back Again', 'brand' => '', 'image_url' => '' ] );
  $say( 'write_drops_cached_miss', $memory::find( $code )['title'] ?? 'stale' );
  $reset( $code );
}
