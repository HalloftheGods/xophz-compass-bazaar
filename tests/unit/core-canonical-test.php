<?php
/**
 * Core_Canonical: key-sorted JSON and request hashing.
 */
require_once dirname( __DIR__ ) . '/support/harness.php';
require_once dirname( dirname( __DIR__ ) ) . '/includes/core/class-xophz-compass-bazaar-core-canonical.php';

$C = 'Xophz_Compass_Bazaar_Core_Canonical';

check( 'json: map keys sorted', $C::json( [ 'b' => 1, 'a' => 2 ] ), '{"a":2,"b":1}' );
check( 'json: nested maps sorted', $C::json( [ 'z' => [ 'y' => 1, 'x' => 2 ], 'a' => 0 ] ), '{"a":0,"z":{"x":2,"y":1}}' );
check( 'json: list order kept', $C::json( [ 3, 1, 2 ] ), '[3,1,2]' );
check( 'json: list of maps sorts the maps, not the list', $C::json( [ [ 'b' => 1, 'a' => 2 ], [ 'a' => 0 ] ] ), '[{"a":2,"b":1},{"a":0}]' );
check( 'json: empty array is a list', $C::json( [] ), '[]' );
check( 'json: empty map inside', $C::json( [ 'a' => [] ] ), '{"a":[]}' );
check( 'json: slashes and unicode not escaped', $C::json( [ 'u' => 'a/b é' ] ), '{"u":"a/b é"}' );
check( 'json: null, bool, int kept', $C::json( [ 'n' => null, 't' => true, 'i' => 0 ] ), '{"i":0,"n":null,"t":true}' );
check( 'json: objects are maps', $C::json( [ 'o' => (object) [ 'b' => 1, 'a' => 2 ] ] ), '{"o":{"a":2,"b":1}}' );
check( 'json: numeric-string keys sort as strings', $C::json( [ '10' => 'a', '9' => 'b', 'x' => 'c' ] ), '{"10":"a","9":"b","x":"c"}' );
check_throws( 'json: invalid UTF-8 throws', function () use ( $C ) { $C::json( [ 'a' => "\xB1\x31" ] ); }, JsonException::class );

check( 'hash: key order does not matter', $C::hash( [ 'a' => 1, 'b' => 2 ] ), $C::hash( [ 'b' => 2, 'a' => 1 ] ) );
check( 'hash: value change changes it', $C::hash( [ 'a' => 1 ] ) === $C::hash( [ 'a' => 2 ] ), false );
check( 'hash: list order matters', $C::hash( [ 1, 2 ] ) === $C::hash( [ 2, 1 ] ), false );
check( 'hash: sha256 of the canonical json', $C::hash( [ 'a' => 1 ] ), hash( 'sha256', '{"a":1}' ) );
check( 'hash: 64 hex chars', (bool) preg_match( '/^[0-9a-f]{64}$/', $C::hash( [ 'a' => 1 ] ) ), true );
check( 'hash: excluded key ignored', $C::hash( [ 'a' => 1, 'client_created_at' => 'x' ], [ 'client_created_at' ] ), $C::hash( [ 'a' => 1 ] ) );
check( 'hash: excluded key ignored when nested', $C::hash( [ 'a' => [ 'k' => 1, 'drop' => 2 ] ], [ 'drop' ] ), $C::hash( [ 'a' => [ 'k' => 1 ] ] ) );
check( 'hash: exclusion never drops list items', $C::hash( [ 'items' => [ 'drop', 'keep' ] ], [ '0' ] ), $C::hash( [ 'items' => [ 'drop', 'keep' ] ] ) );

bazaar_test_standalone( __FILE__ );
