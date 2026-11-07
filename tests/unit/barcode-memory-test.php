<?php
/**
 * Barcode lookup helpers: the Open*Facts parser, merge rules, shared-code
 * ranges and the pure parts of the shared Barcode_Memory.
 *
 * @package Xophz_Compass_Bazaar
 */

require_once dirname( __DIR__ ) . '/support/harness.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-xophz-compass-bazaar-barcode.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-xophz-compass-bazaar-barcode-sources.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-xophz-compass-bazaar-barcode-memory.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-xophz-compass-bazaar-barcode-image.php';

$B = 'Xophz_Compass_Bazaar_Barcode';
$S = 'Xophz_Compass_Bazaar_Barcode_Sources';
$M = 'Xophz_Compass_Bazaar_Barcode_Memory';
$I = 'Xophz_Compass_Bazaar_Barcode_Image';

/** A body with its GS1 check digit added. */
$gtin = function ( string $body ) use ( $B ): string {
  return $body . $B::check_digit( $body );
};

// Sources.
check( 'beauty URL', $S::open_facts_url( 'openbeautyfacts', '3600523307357' ), 'https://world.openbeautyfacts.org/api/v2/product/3600523307357.json' );
check( 'URL encodes the code', $S::open_facts_url( 'openfoodfacts', 'a/b' ), 'https://world.openfoodfacts.org/api/v2/product/a%2Fb.json' );
check( 'three other sources', $S::OPEN_FACTS_OTHERS, [ 'openbeautyfacts', 'openpetfoodfacts', 'openproductsfacts' ] );
foreach ( [ 'openfoodfacts', 'openbeautyfacts', 'openpetfoodfacts', 'openproductsfacts' ] as $source ) {
  check( "images host: $source", in_array( "images.$source.org", $S::IMAGE_HOSTS, true ), true );
  check( "static host: $source", in_array( "static.$source.org", $S::IMAGE_HOSTS, true ), true );
}

// parse_open_facts().
$body = function ( array $product ): string {
  return (string) json_encode( [ 'status' => 1, 'product' => $product ] );
};
$p = $S::parse_open_facts( 200, $body( [ 'product_name' => 'Nutella', 'brands' => 'Ferrero', 'image_url' => 'https://images.openfoodfacts.org/x.jpg' ] ), 'openfoodfacts' );
check( 'found', $p['status'], 'found' );
check( 'source named', $p['source'], 'openfoodfacts' );
check( 'brand joins the title', $p['product']['title'], 'Ferrero Nutella' );
check( 'image kept', $p['product']['image_url'], 'https://images.openfoodfacts.org/x.jpg' );
check( 'brand already in title', $S::parse_open_facts( 200, $body( [ 'product_name' => 'Ferrero Nutella', 'brands' => 'ferrero' ] ), 'x' )['product']['title'], 'Ferrero Nutella' );
check( 'falls back to product_name_en', $S::parse_open_facts( 200, $body( [ 'product_name' => '', 'product_name_en' => 'Shampoo' ] ), 'x' )['product']['title'], 'Shampoo' );
check( 'no name is not_found', $S::parse_open_facts( 200, $body( [ 'brands' => 'Acme' ] ), 'x' )['status'], 'not_found' );
check( 'tags stripped', $S::parse_open_facts( 200, $body( [ 'product_name' => '<b>Soap</b><script>x()</script>' ] ), 'x' )['product']['title'], 'Soap' );
check( 'array field is empty', $S::parse_open_facts( 200, $body( [ 'product_name' => 'Soap', 'brands' => [ 'a' ] ] ), 'x' )['product']['brand'], '' );
check( '404 is not_found', $S::parse_open_facts( 404, '', 'x' )['status'], 'not_found' );
check( 'status 0 body is not_found', $S::parse_open_facts( 200, '{"status":0}', 'x' )['status'], 'not_found' );
check( '429 is rate_limited', $S::parse_open_facts( 429, '', 'x' )['status'], 'rate_limited' );
check( '500 is error', $S::parse_open_facts( 500, '', 'x' )['status'], 'error' );
check( 'no answer is error', $S::parse_open_facts( 0, '', 'x' )['status'], 'error' );
check( 'bad JSON is error', $S::parse_open_facts( 200, 'nope', 'x' )['status'], 'error' );

// parse_upcitemdb(): up to five picture candidates, any host.
$upc = function ( array $item ): string {
  return (string) json_encode( [ 'items' => [ $item ] ] );
};
$many = [ 'http://a.example/1.jpg', 'https://b.example/2.jpg', 'https://b.example/2.jpg', 'ftp://c.example/3.jpg', 'https://d.example/4.jpg', 'https://e.example/5.jpg', 'https://f.example/6.jpg', 'https://g.example/7.jpg', [ 'x' ] ];
$p    = $S::parse_upcitemdb( 200, $upc( [ 'title' => 'WD-40', 'brand' => 'WD-40', 'images' => $many ] ) );
check( 'upc: found', $p['status'], 'found' );
check( 'upc: five candidates, repeats and other schemes gone', $p['product']['image_urls'], [ 'http://a.example/1.jpg', 'https://b.example/2.jpg', 'https://d.example/4.jpg', 'https://e.example/5.jpg', 'https://f.example/6.jpg' ] );
check( 'upc: image_url is the first', $p['product']['image_url'], 'http://a.example/1.jpg' );
check( 'upc: no images', $S::parse_upcitemdb( 200, $upc( [ 'title' => 'X' ] ) )['product']['image_urls'], [] );
check( 'upc: images not a list', $S::images( 'nope' ), [] );
check( 'upc: no items is not_found', $S::parse_upcitemdb( 200, '{"items":[]}' )['status'], 'not_found' );
check( 'upc: 400 is not_found', $S::parse_upcitemdb( 400, '' )['status'], 'not_found' );
check( 'upc: 429 is rate_limited', $S::parse_upcitemdb( 429, '' )['status'], 'rate_limited' );
check( 'upc: no answer is error', $S::parse_upcitemdb( 0, '' )['status'], 'error' );
check( 'open facts: one candidate', $S::parse_open_facts( 200, $body( [ 'product_name' => 'Soap', 'image_url' => 'https://images.openfoodfacts.org/x.jpg' ] ), 'x' )['product']['image_urls'], [ 'https://images.openfoodfacts.org/x.jpg' ] );

// Price hints: an outlier high never leaves the server.
check( 'hint: a range', $S::price_hint( 2.05, 8.2 ), [ 'low' => '2.05', 'high' => '8.20', 'source' => 'upcitemdb' ] );
check( 'hint: exactly 4 times keeps the range', $S::price_hint( 2, 8 )['high'], '8.00' );
check( 'hint: over 4 times drops the high', $S::price_hint( 2.05, 1518.58 ), [ 'low' => '2.05', 'high' => null, 'source' => 'upcitemdb' ] );
check( 'hint: no low', $S::price_hint( 0, 95.05 ), null );
check( 'hint: high under low', $S::price_hint( 5, 2 ), null );
check( 'hint: not numbers', $S::price_hint( 'a', null ), null );
check( 'upc: hint shaped', $S::parse_upcitemdb( 200, $upc( [ 'title' => 'X', 'lowest_recorded_price' => 1, 'highest_recorded_price' => 2605 ] ) )['price_hint']['high'], null );

// Picture URLs.
check( 'url: https', $I::accept_url( ' https://cdn.example.com/a.jpg?x=1 ' ), 'https://cdn.example.com/a.jpg?x=1' );
check( 'url: http', $I::accept_url( 'http://c.shld.net/a.gif' ), 'http://c.shld.net/a.gif' );
$refused = [
  'file:///etc/passwd', 'ftp://example.com/a.jpg', 'gopher://example.com/', 'data:image/png;base64,AAAA', 'javascript:alert(1)',
  'https://user:pass@example.com/a.jpg', 'https://user@example.com/a.jpg', 'https:///a.jpg', '//example.com/a.jpg', 'example.com/a.jpg',
  'http://169.254.169.254/latest/meta-data/', 'http://[::1]/a.jpg', 'https://example.com/a b.jpg', '',
];
foreach ( $refused as $url ) {
  check( "url: refused $url", $I::accept_url( $url ), '' );
}
check( 'url: not a string', $I::accept_url( [ 'https://example.com' ] ), '' );
check( 'url: too long', $I::accept_url( 'https://example.com/' . str_repeat( 'a', 2000 ) ), '' );
check( 'url list: cleaned and capped', $I::clean_list( [ 'ftp://a', 'https://a.example/1', 'https://a.example/1', 'https://b.example/2', 'https://c.example/3', 'https://d.example/4', 'https://e.example/5', 'https://f.example/6' ] ), [ 'https://a.example/1', 'https://b.example/2', 'https://c.example/3', 'https://d.example/4', 'https://e.example/5' ] );
check( 'url list: not a list', $I::clean_list( 'x' ), [] );
foreach ( [ 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp', 'image/svg+xml' => '' ] as $mime => $ext ) {
  check( "extension: $mime", $I::extension( $mime ), $ext );
}
check( 'pictures: svg is not allowed', in_array( 'image/svg+xml', $I::MIMES, true ), false );

// merge().
$found = function ( string $source ): array {
  return [ 'status' => 'found', 'source' => $source, 'product' => [ 'title' => $source ], 'price_hint' => null ];
};
$miss    = [ 'status' => 'not_found' ];
$limited = [ 'status' => 'rate_limited' ];
$broken  = [ 'status' => 'error' ];
check( 'first found wins', $S::merge( [ $miss, $found( 'a' ), $found( 'b' ) ] )['source'], 'a' );
check( 'found beats a 429', $S::merge( [ $limited, $found( 'a' ) ] )['status'], 'found' );
check( 'found beats an error', $S::merge( [ $broken, $found( 'a' ) ] )['status'], 'found' );
check( 'every source missed', $S::merge( [ $miss, $miss, $miss ] )['status'], 'not_found' );
check( 'one error is not not_found', $S::merge( [ $miss, $broken, $miss ] )['status'], 'error' );
check( 'one 429 is rate_limited', $S::merge( [ $miss, $limited, $miss ] )['status'], 'rate_limited' );
check( 'a 429 beats an error', $S::merge( [ $broken, $limited ] )['status'], 'rate_limited' );
check( 'nothing asked is error', $S::merge( [] )['status'], 'error' );
check( 'merged miss has no product', $S::merge( [ $miss ] )['product'], null );

// Barcode::is_shared_gtin(): ordinary codes.
check( 'shared: UPC-A', $B::is_shared_gtin( '036000291452' ), true );
check( 'shared: EAN-13', $B::is_shared_gtin( '3017620422003' ), true );
check( 'shared: ISBN stays', $B::is_shared_gtin( $gtin( '978030640615' ) ), true );
check( 'shared: EAN-8', $B::is_shared_gtin( '96385074' ), true );
check( 'shared: GTIN-14 case', $B::is_shared_gtin( $gtin( '1003600029145' ) ), true );
check( 'shared: bad check digit refused', $B::is_shared_gtin( '036000291453' ), false );
check( 'shared: not digits refused', $B::is_shared_gtin( 'WD-00042' ), false );
check( 'shared: empty refused', $B::is_shared_gtin( '' ), false );

// Restricted and in-store ranges.
check( 'restricted: EAN-8 from 0', $B::is_shared_gtin( $gtin( '0123456' ) ), false );
check( 'restricted: EAN-8 from 2', $B::is_shared_gtin( $gtin( '2123456' ) ), false );
foreach ( [ '02', '04', '05', '20', '25', '29', '98', '99' ] as $prefix ) {
  check( "restricted: GTIN-13 from $prefix", $B::is_shared_gtin( $gtin( $prefix . '1234567890' ) ), false );
}
check( 'restricted: UPC-A from 2', $B::is_shared_gtin( $gtin( '21234567890' ) ), false );
check( 'restricted: UPC-A from 4', $B::is_shared_gtin( $gtin( '41234567890' ) ), false );
check( 'restricted: UPC-A from 5', $B::is_shared_gtin( $gtin( '51234567890' ) ), false );
check( 'shared: UPC-A from 0', $B::is_shared_gtin( $gtin( '01234567890' ) ), true );
check( 'restricted: 00000 + GTIN-8 from 0', $B::is_shared_gtin( '00000' . $gtin( '0123456' ) ), false );
check( 'restricted: 00000 + GTIN-8 from 2', $B::is_shared_gtin( '00000' . $gtin( '2123456' ) ), false );
check( 'shared: 00000 + open GTIN-8', $B::is_shared_gtin( '0000096385074' ), true );
check( 'restricted: all zeros', $B::is_shared_gtin( '000000000000' ), false );
check( 'restricted: GTIN-14 indicator 9', $B::is_shared_gtin( $gtin( '9003600029145' ) ), false );
check( 'restricted: GTIN-14 with restricted part', $B::is_shared_gtin( $gtin( '1021234567890' ) ), false );
check( 'restricted: GTIN-14 with 00000 part', $B::is_shared_gtin( $gtin( '1000000123456' ) ), false );

// Memory key rules.
check( 'key: UPC-A', $M::key_for( $B::normalize( '036000291452' ) ), '036000291452' );
check( 'key: EAN-13 form is the same row', $M::key_for( $B::normalize( '0036000291452' ) ), '036000291452' );
check( 'key: GS1 form is the same row', $M::key_for( $B::normalize( '(01)00036000291452' ) ), '036000291452' );
check( 'key: bad check digit', $M::key_for( $B::normalize( '036000291453' ) ), '' );
check( 'key: restricted', $M::key_for( $B::normalize( $gtin( '21234567890' ) ) ), '' );
check( 'key: house label', $M::key_for( $B::normalize( 'WD-00042' ) ), '' );
check( 'key: empty', $M::key_for( $B::normalize( '' ) ), '' );
check( 'key fits the column', strlen( $M::key_for( $B::normalize( $gtin( '1003600029145' ) ) ) ) <= 14, true );

// Off switch values.
foreach ( [ '0', 'no', 'off', 'false', 'OFF', ' No ', 0 ] as $off ) {
  check( 'off value: ' . var_export( $off, true ), $M::is_off_value( $off ), true );
}
foreach ( [ '1', 'yes', 'on', '', null, [] ] as $on ) {
  check( 'not an off value: ' . var_export( $on, true ), $M::is_off_value( $on ), false );
}

// Owner marker.
$owner = $M::owner_marker( 3, 'salt' );
check( 'owner: 16 characters', strlen( $owner ), 16 );
check( 'owner: HMAC-SHA256 of the blog', $owner, substr( hash_hmac( 'sha256', 'bazaar_barcode_memory|3', 'salt' ), 0, 16 ) );
check( 'owner: stable', $M::owner_marker( 3, 'salt' ), $owner );
check( 'owner: differs by site', $M::owner_marker( 4, 'salt' ) !== $owner, true );
check( 'owner: differs by salt', $M::owner_marker( 3, 'pepper' ) !== $owner, true );

// Money stripping.
$unchanged = [ 'Coca-Cola 12 oz', 'WD-40 Multi-Use', "Campbell's Soup 10.75 oz", '7-Up 2 Liter', 'Pack of 1.5', 'USDA Choice Beef', 'Eurostar Puzzle' ];
foreach ( $unchanged as $title ) {
  check( "money: unchanged $title", $M::strip_money( $title ), $title );
}
$money = [
  'WD-40 12 oz $5.99'     => 'WD-40 12 oz',
  '$5.99 WD-40'           => 'WD-40',
  'Soda - $1,299.00'      => 'Soda',
  'Soda 99¢'              => 'Soda',
  'Soda 99 cents'         => 'Soda',
  'Soda £3 Bottle'        => 'Soda Bottle',
  'Soda €2,50 Bottle'     => 'Soda Bottle',
  'Soda 2.50 USD'         => 'Soda',
  'Soda USD 2.50'         => 'Soda',
  'Soda US$2.50'          => 'Soda',
  'Tide Pods was $12.99'  => 'Tide Pods',
  'Tide Pods only $12.99' => 'Tide Pods',
  '2 for $5 Tide Pods'    => 'Tide Pods',
  'Tide Pods $1 off'      => 'Tide Pods',
  'Tide Pods ($12.99)'    => 'Tide Pods',
  'Tide Pods $.99 each'   => 'Tide Pods',
  'Cola 12oz 1.99'        => 'Cola 12oz',
];
foreach ( $money as $title => $clean ) {
  check( "money: $title", $M::strip_money( $title ), $clean );
}
check( 'clean_title strips money and tags', $M::clean_title( '<b>Soda</b> $1.99' ), 'Soda' );
check( 'clean_title cuts to length', strlen( $M::clean_title( str_repeat( 'a', 500 ) ) ), 200 );
check( 'clean_title of nothing but money', $M::clean_title( '$1.99' ), '' );
check( 'clean_title of an array', $M::clean_title( [ 'x' ] ), '' );
check( 'clean_brand cuts to length', strlen( $M::clean_brand( str_repeat( 'a', 500 ) ) ), 100 );

// Pictures: any host, but never a store's own uploads.
$image = 'https://images.openbeautyfacts.org/images/products/1/front.jpg';
check( 'image: provider host', $M::clean_image( $image ), $image );
check( 'image: a retailer host', $M::clean_image( 'http://c.shld.net/a.gif' ), 'http://c.shld.net/a.gif' );
check( 'image: this install refused', $M::clean_image( 'https://shop.example.com/a.jpg', [ 'SHOP.example.com' ] ), '' );
check( 'image: uploads path refused on any host', $M::clean_image( 'https://other.example.com/wp-content/uploads/2026/11/a.jpg' ), '' );
check( 'image: login refused', $M::clean_image( 'https://a:b@images.openfoodfacts.org/a.jpg' ), '' );
check( 'image: file refused', $M::clean_image( 'file:///etc/passwd' ), '' );
check( 'image: too long refused', $M::clean_image( 'https://images.openfoodfacts.org/' . str_repeat( 'a', 500 ) ), '' );
check( 'image: not a string', $M::clean_image( null ), '' );

// A read shows public identity only.
$row = [ 'code' => '1', 'title' => 'T', 'brand' => 'B', 'image_url' => 'I', 'source' => 'store', 'title_owner' => 'abc', 'hits' => 2, 'created_at' => 'x', 'updated_at' => 'y' ];
check( 'view: public fields only', $M::view( $row ), [ 'title' => 'T', 'brand' => 'B', 'image_url' => 'I', 'source' => 'store' ] );
check( 'view: no row', $M::view( null ), null );

// The SQL: one statement, and assignments in the order MySQL needs.
$sql = $M::upsert_sql( 'wp_bazaar_barcode_memory' );
$at  = function ( string $needle ) use ( $sql ): int {
  $found = strpos( $sql, $needle );
  return false === $found ? -1 : $found;
};
check( 'sql: one upsert', substr_count( $sql, 'ON DUPLICATE KEY UPDATE' ), 1 );
check( 'sql: eight placeholders', substr_count( $sql, '%s' ), 8 );
$order = [ ' title = IF(', ' brand = IF(', ' image_url = IF(', ' title_owner = IF(', ' hits = hits + 1', ' updated_at = VALUES', ' source = IF(' ];
foreach ( $order as $needle ) {
  check( "sql: has$needle", $at( $needle ) > 0, true );
}
for ( $i = 1, $n = count( $order ); $i < $n; $i++ ) {
  check( "sql: {$order[ $i - 1 ]} before {$order[ $i ]}", $at( $order[ $i - 1 ] ) < $at( $order[ $i ] ), true );
}
$last = "source = IF(source = 'store', 'store', VALUES(source))";
check( 'sql: source is the last assignment', substr( $sql, -strlen( $last ) ), $last );
check( 'sql: a store title stays with its owner', false !== strpos( $sql, "title = IF(source = 'store' AND title_owner <> VALUES(title_owner), title, VALUES(title))" ), true );
check( 'sql: brand is only filled', false !== strpos( $sql, "brand = IF(brand = '', VALUES(brand), brand)" ), true );
check( 'sql: picture is only filled', false !== strpos( $sql, "image_url = IF(image_url = '', VALUES(image_url), image_url)" ), true );

// Table.
$create = $M::definition( 'wp_bazaar_barcode_memory', 'DEFAULT CHARSET=utf8mb4' );
check( 'table: two spaces after PRIMARY KEY', false !== strpos( $create, 'PRIMARY KEY  (code)' ), true );
check( 'table: code is the key', false !== strpos( $create, 'code varchar(14) NOT NULL' ), true );
check( 'table: owner is char(16)', false !== strpos( $create, 'title_owner char(16)' ), true );
foreach ( [ 'price', 'cost', 'stock', 'blog_id', 'site_id' ] as $word ) {
  check( "table: no $word column", false !== stripos( $create, $word ), false );
}

bazaar_test_standalone( __FILE__ );
