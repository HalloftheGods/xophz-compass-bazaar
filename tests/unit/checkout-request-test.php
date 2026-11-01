<?php
/**
 * Checkout_Request::from_array: normalization, the Idempotency-Key alias,
 * the 400 invalid_request error list, the cart's 422 price-override refusal,
 * and what the request hash ignores.
 */
require_once dirname( __DIR__ ) . '/support/harness.php';
$plugin = dirname( dirname( __DIR__ ) );
require_once $plugin . '/includes/core/class-xophz-compass-bazaar-core-http-error.php';
require_once $plugin . '/includes/core/class-xophz-compass-bazaar-core-canonical.php';
require_once $plugin . '/includes/pricing/class-xophz-compass-bazaar-pricing-cart-request.php';
require_once $plugin . '/includes/payments/class-xophz-compass-bazaar-payments-tender.php';
require_once $plugin . '/includes/checkout/class-xophz-compass-bazaar-checkout-request.php';

$C   = 'Xophz_Compass_Bazaar_Checkout_Request';
$key = '5c1e2b9a-3f4d-4c2a-9b1e-0a1b2c3d4e5f';

/** The Http_Error from_array() throws, or null. */
$fail = function ( array $data, string $header = '' ) use ( $C ) {
  try {
    $C::from_array( $data, $header );
  } catch ( Xophz_Compass_Bazaar_Core_Http_Error $e ) {
    return $e;
  }
  return null;
};
$paths = function ( $error ) {
  return $error ? array_column( $error->data()['errors'] ?? [], 'path' ) : [];
};
$body = function ( array $over = [] ) use ( $key ) {
  return array_merge( [
    'client_key'           => $key,
    'client_created_at'    => '2026-11-01T17:59:58Z',
    'expected_total_minor' => 2436,
    'quote_hash'           => str_repeat( 'ab', 32 ),
    'cart'                 => [ 'lines' => [ [ 'type' => 'product', 'product_id' => 12, 'quantity' => 2 ] ] ],
    'tenders'              => [
      [ 'method_id' => 'cash', 'amount_minor' => 2000, 'tendered_minor' => 2000 ],
      [ 'method_id' => 'bazaar_card_terminal', 'amount_minor' => 436, 'reference' => 'A1B2C3', 'card_brand' => 'visa', 'card_last4' => '1881' ],
    ],
    'approval_tokens'      => [ 'tok-1' ],
  ], $over );
};

// The spec 11.1 example parses.
$r = $C::from_array( $body() );
check( 'example: key', $r->client_key, $key );
check( 'example: created_at as UTC', $r->client_created_at, '2026-11-01 17:59:58' );
check( 'example: expected total', $r->expected_total_minor, 2436 );
check( 'example: cart parsed', $r->cart->lines[0], [ 'type' => 'product', 'product_id' => 12, 'variation_id' => 0, 'quantity' => 2 ] );
check( 'example: two tenders', [ $r->tenders[0]->method_id, $r->tenders[1]->reference ], [ 'cash', 'A1B2C3' ] );
check( 'example: tender total', $r->tender_total_minor(), 2436 );
check( 'example: approval tokens', $r->approval_tokens, [ 'tok-1' ] );
check( 'example: hash is sha256 hex', (bool) preg_match( '/^[0-9a-f]{64}$/', $r->request_hash() ), true );

// Normalization.
$r = $C::from_array( $body( [ 'client_key' => strtoupper( $key ), 'client_created_at' => '2026-11-01T19:59:58+02:00', 'quote_hash' => '', 'expected_total_minor' => null, 'approval_tokens' => null ] ) );
check( 'key is lowercased', $r->client_key, $key );
check( 'offset time converted to UTC', $r->client_created_at, '2026-11-01 17:59:58' );
check( 'optional fields empty', [ $r->quote_hash, $r->expected_total_minor, $r->approval_tokens ], [ '', null, [] ] );
$r = $C::from_array( $body( [ 'client_created_at' => null ] ) );
check( 'no created_at is null', $r->client_created_at, null );
check( 'legacy prefix accepted', $C::from_array( $body( [ 'client_key' => 'legacy:' . $key ] ) )->client_key, 'legacy:' . $key );

// Idempotency-Key header alias.
$no_key = $body();
unset( $no_key['client_key'] );
check( 'header: used when the body has no key', $C::from_array( $no_key, strtoupper( $key ) )->client_key, $key );
check( 'header: equal body key accepted', $C::from_array( $body(), $key )->client_key, $key );
check( 'header: a different body key is refused', $paths( $fail( $body(), '7d1e2b9a-3f4d-4c2a-9b1e-0a1b2c3d4e5f' ) ), [ 'client_key' ] );
check( 'header: none and no body key is refused', $paths( $fail( $no_key ) ), [ 'client_key' ] );

// Schema errors: 400 invalid_request listing every path.
$e = $fail( [
  'client_key'           => 'not-a-uuid',
  'client_created_at'    => '2026-11-01 17:59:58',
  'expected_total_minor' => 12.5,
  'quote_hash'           => 'xyz',
  'cart'                 => [ 'lines' => [ [ 'product_id' => 1, 'quantity' => 0 ] ] ],
  'tenders'              => [ [ 'method_id' => 'cash', 'amount_minor' => 0 ], 'nope', [ 'method_id' => 'Bad Id!', 'amount_minor' => 5 ] ],
  'approval_tokens'      => [ '', 7 ],
] );
check( 'errors: 400 invalid_request', $e ? [ $e->error_code(), $e->status() ] : null, [ 'invalid_request', 400 ] );
check( 'errors: every path, cart paths prefixed', $paths( $e ), [
  'client_key', 'client_created_at', 'expected_total_minor', 'quote_hash', 'cart.lines[0].quantity',
  'tenders[0].amount_minor', 'tenders[1]', 'tenders[2].method_id', 'approval_tokens[0]', 'approval_tokens[1]',
] );
check( 'errors: a v4 uuid is required (v1 refused)', $paths( $fail( $body( [ 'client_key' => '5c1e2b9a-3f4d-1c2a-9b1e-0a1b2c3d4e5f' ] ) ) ), [ 'client_key' ] );
check( 'errors: missing cart', $paths( $fail( $body( [ 'cart' => null ] ) ) ), [ 'cart' ] );
check( 'errors: no tenders', $paths( $fail( $body( [ 'tenders' => [] ] ) ) ), [ 'tenders' ] );
check( 'errors: tenders must be a list', $paths( $fail( $body( [ 'tenders' => [ 'a' => [ 'method_id' => 'cash', 'amount_minor' => 1 ] ] ] ) ) ), [ 'tenders' ] );
check( 'errors: at most 10 tenders', $paths( $fail( $body( [ 'tenders' => array_fill( 0, 11, [ 'method_id' => 'cash', 'amount_minor' => 1 ] ) ] ) ) ), [ 'tenders' ] );
check( 'errors: negative expected total', $paths( $fail( $body( [ 'expected_total_minor' => -1 ] ) ) ), [ 'expected_total_minor' ] );
check( 'errors: approval tokens must be a list', $paths( $fail( $body( [ 'approval_tokens' => 'tok' ] ) ) ), [ 'approval_tokens' ] );
check( 'errors: impossible date', $paths( $fail( $body( [ 'client_created_at' => '2026-13-45T10:00:00Z' ] ) ) ), [ 'client_created_at' ] );
$e = $fail( $body( [ 'cart' => [ 'lines' => [ [ 'product_id' => 12, 'quantity' => 1, 'price_override_minor' => 100 ] ] ] ] ) );
check( 'override: the cart refuses with 422 unsupported_field', $e ? [ $e->error_code(), $e->status() ] : null, [ 'unsupported_field', 422 ] );
check( 'unknown keys are ignored', $C::from_array( $body( [ 'register' => 'r1' ] ) )->client_key, $key );

// The request hash.
$h = $C::from_array( $body() )->request_hash();
check( 'hash: same body, same hash', $C::from_array( $body() )->request_hash(), $h );
$reordered = array_reverse( $body(), true );
check( 'hash: key order does not matter', $C::from_array( $reordered )->request_hash(), $h );
check( 'hash: approval tokens ignored', $C::from_array( $body( [ 'approval_tokens' => [ 'other', 'tokens' ] ] ) )->request_hash(), $h );
check( 'hash: client_created_at ignored', $C::from_array( $body( [ 'client_created_at' => '2026-11-02T08:00:00Z' ] ) )->request_hash(), $h );
$with_token                              = $body();
$with_token['tenders'][0]['authorization_token'] = 'portal-token';
check( 'hash: authorization_token ignored', $C::from_array( $with_token )->request_hash(), $h );
check( 'hash: header alias hashes like the body key', $C::from_array( $no_key, $key )->request_hash(), $h );
$changed                            = $body();
$changed['tenders'][1]['reference'] = 'Z9Y8X7';
check( 'hash: a tender reference counts', $C::from_array( $changed )->request_hash() !== $h, true );
check( 'hash: the expected total counts', $C::from_array( $body( [ 'expected_total_minor' => 2437 ] ) )->request_hash() !== $h, true );
check( 'hash: the cart counts', $C::from_array( $body( [ 'cart' => [ 'lines' => [ [ 'product_id' => 12, 'quantity' => 3 ] ] ] ] ) )->request_hash() !== $h, true );
check( 'hash: the key counts', $C::from_array( $body( [ 'client_key' => '7d1e2b9a-3f4d-4c2a-9b1e-0a1b2c3d4e5f' ] ) )->request_hash() !== $h, true );
check( 'to_array keeps the excluded fields', array_keys( $C::from_array( $body() )->to_array() ), [ 'client_key', 'client_created_at', 'expected_total_minor', 'quote_hash', 'cart', 'tenders', 'approval_tokens' ] );

bazaar_test_standalone( __FILE__ );
