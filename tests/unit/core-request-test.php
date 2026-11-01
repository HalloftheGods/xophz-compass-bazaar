<?php
/**
 * Core_Request: legacy argument decoding.
 */
require_once dirname( __DIR__ ) . '/support/harness.php';
require_once dirname( dirname( __DIR__ ) ) . '/includes/core/class-xophz-compass-bazaar-core-request.php';

$R = 'Xophz_Compass_Bazaar_Core_Request';

// array_param
$args = (object) [
  'list'   => [ 1, 2 ],
  'map'    => (object) [ 'a' => (object) [ 'b' => 1 ] ],
  'json'   => '[{"id":3}]',
  'jsonob' => '{"id":3}',
  'scalar' => '5',
  'bad'    => '{nope',
  'null'   => null,
];
check( 'array_param: array', $R::array_param( $args, 'list' ), [ 1, 2 ] );
check( 'array_param: object becomes a nested array', $R::array_param( $args, 'map' ), [ 'a' => [ 'b' => 1 ] ] );
check( 'array_param: JSON list string', $R::array_param( $args, 'json' ), [ [ 'id' => 3 ] ] );
check( 'array_param: JSON object string', $R::array_param( $args, 'jsonob' ), [ 'id' => 3 ] );
check( 'array_param: scalar string is []', $R::array_param( $args, 'scalar' ), [] );
check( 'array_param: malformed JSON is []', $R::array_param( $args, 'bad' ), [] );
check( 'array_param: null is []', $R::array_param( $args, 'null' ), [] );
check( 'array_param: missing is []', $R::array_param( $args, 'nope' ), [] );
check( 'array_param: array container', $R::array_param( [ 'x' => '[1]' ], 'x' ), [ 1 ] );
check( 'array_param: bad container is []', $R::array_param( 'str', 'x' ), [] );

// bool_param
$flags = (object) [ 't' => true, 'ts' => 'true', 'one' => 1, 'ones' => '1', 'f' => false, 'fs' => 'false', 'zero' => 0, 'yes' => 'yes', 'on' => 'on', 'two' => 2 ];
foreach ( [ 't', 'ts', 'one', 'ones' ] as $key ) {
  check( "bool_param: $key is true", $R::bool_param( $flags, $key ), true );
}
foreach ( [ 'f', 'fs', 'zero', 'yes', 'on', 'two', 'missing' ] as $key ) {
  check( "bool_param: $key is false", $R::bool_param( $flags, $key ), false );
}

// legacy_args
$_POST = [];
$_GET  = [];
check( 'legacy_args: empty object when nothing is posted and no Compass class', (array) $R::legacy_args(), [] );
check( 'legacy_args: returns an object', is_object( $R::legacy_args() ), true );
$_POST = [ 'a' => 'it\\\'s', 'only_post' => '1' ];
$_GET  = [ 'a' => 'get', 'only_get' => '2' ];
$got   = $R::legacy_args();
check( 'legacy_args: POST wins on a shared key and is unslashed once', $got->a, "it's" );
check( 'legacy_args: keeps GET-only keys', $got->only_get, '2' );
check( 'legacy_args: keeps POST-only keys', $got->only_post, '1' );
$_POST = [ 'k' => 'a\\\\b' ];
$_GET  = [];
check( 'legacy_args: one unslash, not two', $R::legacy_args()->k, 'a\\b' );
$_POST = [];
$_GET  = [];

bazaar_test_standalone( __FILE__ );
