<?php
/**
 * Refunds_Request::from_array: normalization, the Idempotency-Key alias, the
 * 400 invalid_request error list, tender shape, and what the request hash and
 * the approval subject hash cover.
 */
require_once dirname( __DIR__ ) . '/support/harness.php';
$plugin = dirname( dirname( __DIR__ ) );
require_once $plugin . '/includes/core/class-xophz-compass-bazaar-core-http-error.php';
require_once $plugin . '/includes/core/class-xophz-compass-bazaar-core-canonical.php';
require_once $plugin . '/includes/refunds/class-xophz-compass-bazaar-refunds-request.php';

$R   = 'Xophz_Compass_Bazaar_Refunds_Request';
$key = '5c1e2b9a-3f4d-4c2a-9b1e-0a1b2c3d4e5f';

/** The Http_Error from_array() throws, or null. */
$fail = function ( array $data, int $order = 42, string $header = '' ) use ( $R ) {
  try {
    $R::from_array( $data, $order, $header );
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
    'client_key'     => $key,
    'lines'          => [ [ 'order_item_id' => 9, 'qty' => 2 ], [ 'order_item_id' => 4, 'qty' => 1 ] ],
    'reason'         => 'Wrong size',
    'restock'        => false,
    'tenders'        => [ [ 'payment_uuid' => '0b9f6f0e-3c2a-4e7d-8a51-5d1f3a7c9e22', 'amount_minor' => 1500, 'reference' => 'AB12' ], [ 'method_id' => 'cash', 'amount_minor' => 500 ] ],
    'approval_token' => 'tok-1',
    'dry_run'        => false,
  ], $over );
};

// The spec 12 example parses.
$r = $R::from_array( $body(), 42 );
check( 'example: key', $r->client_key, $key );
check( 'example: order id from the route', $r->order_id, 42 );
check( 'example: lines sorted by item', $r->lines, [ [ 'order_item_id' => 4, 'qty' => 1 ], [ 'order_item_id' => 9, 'qty' => 2 ] ] );
check( 'example: reason', $r->reason, 'Wrong size' );
check( 'example: restock off', $r->restock, false );
check( 'example: tenders normalized', $r->tenders, [
  [ 'payment_uuid' => '0b9f6f0e-3c2a-4e7d-8a51-5d1f3a7c9e22', 'method_id' => '', 'amount_minor' => 1500, 'reference' => 'AB12' ],
  [ 'payment_uuid' => '', 'method_id' => 'cash', 'amount_minor' => 500, 'reference' => '' ],
] );
check( 'example: approval token', $r->approval_token, 'tok-1' );
check( 'example: not a dry run', $r->dry_run, false );

// Defaults and normalization.
$r = $R::from_array( [ 'client_key' => strtoupper( $key ), 'lines' => [ [ 'order_item_id' => '7', 'qty' => '3' ] ] ], 5 );
check( 'defaults: key lowercased', $r->client_key, $key );
check( 'defaults: digit strings become numbers', $r->lines, [ [ 'order_item_id' => 7, 'qty' => 3 ] ] );
check( 'defaults: restock on, no tenders, no token, not dry', [ $r->restock, $r->tenders, $r->approval_token, $r->dry_run, $r->reason ], [ true, [], '', false, '' ] );
$r = $R::from_array( $body( [ 'reason' => str_repeat( 'x', 300 ) ] ), 5 );
check( 'reason: cut to 191', strlen( $r->reason ), 191 );
$r = $R::from_array( [ 'lines' => [ [ 'order_item_id' => 1, 'qty' => 1 ] ] ], 5, strtoupper( $key ) );
check( 'header: Idempotency-Key is the client_key', $r->client_key, $key );
$r = $R::from_array( $body( [ 'client_key' => 'legacy:' . $key ] ), 5 );
check( 'key: legacy prefix allowed', $r->client_key, 'legacy:' . $key );

// Refusals report every problem at once.
$e = $fail( [ 'client_key' => 'nope', 'lines' => 'x', 'restock' => 'yes', 'tenders' => 'x', 'approval_token' => [ 1 ], 'reason' => [] ] );
check( 'invalid: 400 invalid_request', [ $e->error_code(), $e->status() ], [ 'invalid_request', 400 ] );
check( 'invalid: all paths listed', $paths( $e ), [ 'client_key', 'lines', 'reason', 'restock', 'tenders', 'approval_token' ] );
check( 'invalid: route order id', $paths( $fail( $body(), 0 ) ), [ 'order_id' ] );
check( 'key: header differs from body', $paths( $fail( $body(), 5, '11111111-1111-4111-8111-111111111111' ) ), [ 'client_key' ] );
check( 'key: missing', $paths( $fail( [ 'lines' => [ [ 'order_item_id' => 1, 'qty' => 1 ] ] ] ) ), [ 'client_key' ] );
check( 'key: not version 4', $paths( $fail( $body( [ 'client_key' => '5c1e2b9a-3f4d-1c2a-9b1e-0a1b2c3d4e5f' ] ) ) ), [ 'client_key' ] );
check( 'lines: empty', $paths( $fail( $body( [ 'lines' => [] ] ) ) ), [ 'lines' ] );
check( 'lines: missing', $paths( $fail( [ 'client_key' => $key ] ) ), [ 'lines' ] );
check( 'lines: too many', $paths( $fail( $body( [ 'lines' => array_fill( 0, 101, [ 'order_item_id' => 1, 'qty' => 1 ] ) ] ) ) ), [ 'lines' ] );
check( 'lines: bad item and qty', $paths( $fail( $body( [ 'lines' => [ [ 'order_item_id' => 0, 'qty' => 1 ], [ 'order_item_id' => 2, 'qty' => 0 ], [ 'order_item_id' => 3, 'qty' => 1.5 ], 'x' ] ] ) ) ), [ 'lines.0.order_item_id', 'lines.1.qty', 'lines.2.qty', 'lines.3.order_item_id' ] );
check( 'lines: an item twice', $paths( $fail( $body( [ 'lines' => [ [ 'order_item_id' => 2, 'qty' => 1 ], [ 'order_item_id' => 2, 'qty' => 1 ] ] ] ) ) ), [ 'lines.1.order_item_id' ] );
check( 'tenders: both uuid and method', $paths( $fail( $body( [ 'tenders' => [ [ 'payment_uuid' => '0b9f6f0e-3c2a-4e7d-8a51-5d1f3a7c9e22', 'method_id' => 'cash', 'amount_minor' => 5 ] ] ] ) ) ), [ 'tenders.0' ] );
check( 'tenders: neither', $paths( $fail( $body( [ 'tenders' => [ [ 'amount_minor' => 5 ] ] ] ) ) ), [ 'tenders.0' ] );
check( 'tenders: bad uuid, method, amount and reference', $paths( $fail( $body( [ 'tenders' => [
  [ 'payment_uuid' => 'zzz', 'amount_minor' => 5 ],
  [ 'method_id' => 'Bad Method', 'amount_minor' => 5 ],
  [ 'method_id' => 'cash', 'amount_minor' => 0 ],
  [ 'method_id' => 'cash', 'amount_minor' => 5, 'reference' => str_repeat( 'r', 65 ) ],
] ] ) ) ), [ 'tenders.0.payment_uuid', 'tenders.1.method_id', 'tenders.2.amount_minor', 'tenders.3.reference' ] );
check( 'tenders: too many', $paths( $fail( $body( [ 'tenders' => array_fill( 0, 11, [ 'method_id' => 'cash', 'amount_minor' => 5 ] ) ] ) ) ), [ 'tenders' ] );
check( 'tenders: not a list', $paths( $fail( $body( [ 'tenders' => [ 'a' => [ 'method_id' => 'cash', 'amount_minor' => 5 ] ] ] ) ) ), [ 'tenders' ] );
check( 'token: too long', $paths( $fail( $body( [ 'approval_token' => str_repeat( 't', 201 ) ] ) ) ), [ 'approval_token' ] );

// Hashes.
$base = $R::from_array( $body(), 42 );
check( 'request hash: sha256 hex', (bool) preg_match( '/^[0-9a-f]{64}$/', $base->request_hash() ), true );
check( 'request hash: ignores the approval token', $R::from_array( $body( [ 'approval_token' => 'other' ] ), 42 )->request_hash(), $base->request_hash() );
check( 'request hash: ignores dry_run', $R::from_array( $body( [ 'dry_run' => true ] ), 42 )->request_hash(), $base->request_hash() );
check( 'request hash: ignores line order', $R::from_array( $body( [ 'lines' => array_reverse( $body()['lines'] ) ] ), 42 )->request_hash(), $base->request_hash() );
check( 'request hash: changes with a quantity', $R::from_array( $body( [ 'lines' => [ [ 'order_item_id' => 9, 'qty' => 1 ], [ 'order_item_id' => 4, 'qty' => 1 ] ] ] ), 42 )->request_hash() !== $base->request_hash(), true );
check( 'request hash: changes with the order', $R::from_array( $body(), 43 )->request_hash() !== $base->request_hash(), true );
check( 'request hash: changes with restock', $R::from_array( $body( [ 'restock' => true ] ), 42 )->request_hash() !== $base->request_hash(), true );

$subject = $base->subject_hash( 2000 );
check( 'subject: sha256 hex', (bool) preg_match( '/^[0-9a-f]{64}$/', $subject ), true );
check( 'subject: same for lines in any order', $R::subject_for( 42, [ [ 'order_item_id' => 9, 'qty' => 2 ], [ 'order_item_id' => 4, 'qty' => 1 ] ], 2000 ), $subject );
check( 'subject: ignores extra line keys', $R::subject_for( 42, [ [ 'order_item_id' => 4, 'qty' => 1, 'x' => 1 ], [ 'order_item_id' => 9, 'qty' => 2 ] ], 2000 ), $subject );
check( 'subject: changes with the amount', $base->subject_hash( 2001 ) !== $subject, true );
check( 'subject: changes with the order', $R::subject_for( 43, $base->lines, 2000 ) !== $subject, true );
check( 'subject: changes with a quantity', $R::subject_for( 42, [ [ 'order_item_id' => 9, 'qty' => 1 ], [ 'order_item_id' => 4, 'qty' => 1 ] ], 2000 ) !== $subject, true );
check( 'subject: is the hash of order, sorted lines and amount', $subject, hash( 'sha256', '{"amount_minor":2000,"lines":[{"order_item_id":4,"qty":1},{"order_item_id":9,"qty":2}],"order_id":42}' ) );
