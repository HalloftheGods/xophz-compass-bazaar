<?php
/**
 * Pricing_Cart_Request::from_array: normalization, limits, the 400
 * invalid_request error list and the 422 price-override refusal.
 */
require_once dirname( __DIR__ ) . '/support/harness.php';
$plugin = dirname( dirname( __DIR__ ) );
require_once $plugin . '/includes/core/class-xophz-compass-bazaar-core-http-error.php';
require_once $plugin . '/includes/pricing/class-xophz-compass-bazaar-pricing-cart-request.php';

$R = 'Xophz_Compass_Bazaar_Pricing_Cart_Request';

/** The Http_Error from_array() throws, or null. */
$fail = function ( array $data ) use ( $R ) {
  try {
    $R::from_array( $data );
  } catch ( Xophz_Compass_Bazaar_Core_Http_Error $e ) {
    return $e;
  }
  return null;
};
$paths = function ( $error ) {
  return $error ? array_column( $error->data()['errors'] ?? [], 'path' ) : [];
};
$product = [ 'type' => 'product', 'product_id' => 12, 'quantity' => 2 ];

// The spec 9.1 example parses.
$req = $R::from_array( [
  'location_id' => 0,
  'lines'       => [ $product, [ 'type' => 'custom', 'name' => ' Gift wrap ', 'unit_price_minor' => 300, 'quantity' => 1, 'taxable' => true ] ],
  'coupons'     => [ ' Spring ' ],
  'discounts'   => [ [ 'name' => 'Loyalty', 'type' => 'percent', 'value_bp' => 1000 ], [ 'name' => 'Damaged', 'type' => 'fixed', 'amount_minor' => 500 ] ],
  'tip_minor'   => 0,
  'customer'    => [ 'id' => 0, 'email' => '', 'phone' => '', 'name' => '', 'create_account' => false, 'marketing_opt_in' => false, 'sms_opt_in' => false ],
  'client_key'  => 'ignored-by-the-cart',
] );
check( 'example: product line normalized', $req->lines[0], [ 'type' => 'product', 'product_id' => 12, 'variation_id' => 0, 'quantity' => 2 ] );
check( 'example: custom line trimmed', $req->lines[1], [ 'type' => 'custom', 'name' => 'Gift wrap', 'unit_price_minor' => 300, 'quantity' => 1, 'taxable' => true ] );
check( 'example: coupon trimmed and lowercased', $req->coupons, [ 'spring' ] );
check( 'example: discounts', $req->discounts[1], [ 'name' => 'Damaged', 'type' => 'fixed', 'amount_minor' => 500 ] );
check( 'example: to_array keys', array_keys( $req->to_array() ), [ 'location_id', 'lines', 'coupons', 'discounts', 'tip_minor', 'customer' ] );

// Defaults.
$req = $R::from_array( [ 'lines' => [ [ 'product_id' => 5, 'quantity' => 1 ], [ 'type' => 'custom', 'name' => 'Fee', 'unit_price_minor' => 0, 'quantity' => 1 ] ] ] );
check( 'defaults: type product', $req->lines[0]['type'], 'product' );
check( 'defaults: custom lines are taxable', $req->lines[1]['taxable'], true );
check( 'defaults: no coupons, discounts, tip, location', [ $req->coupons, $req->discounts, $req->tip_minor, $req->location_id ], [ [], [], 0, 0 ] );
check( 'defaults: empty customer', $req->customer, [ 'id' => 0, 'email' => '', 'phone' => '', 'name' => '', 'create_account' => false, 'marketing_opt_in' => false, 'sms_opt_in' => false ] );

// price_override_minor: 422 unsupported_field, checked before the schema.
$e = $fail( [ 'lines' => [ array_merge( $product, [ 'price_override_minor' => 100 ] ) ] ] );
check( 'override: 422', $e ? [ $e->error_code(), $e->status() ] : null, [ 'unsupported_field', 422 ] );
check( 'override: names the field', $e ? $e->data()['field'] : null, 'lines[0].price_override_minor' );
$e = $fail( [ 'lines' => 'bad', 'price_override_minor' => 1 ] );
check( 'override: top level also refused first', $e ? $e->error_code() : null, 'unsupported_field' );

// Schema errors: 400 invalid_request with every path.
$e = $fail( [] );
check( 'missing lines: 400 invalid_request', $e ? [ $e->error_code(), $e->status() ] : null, [ 'invalid_request', 400 ] );
check( 'missing lines: path', $paths( $e ), [ 'lines' ] );
check( 'empty lines refused', $paths( $fail( [ 'lines' => [] ] ) ), [ 'lines' ] );
check( 'too many lines refused', $paths( $fail( [ 'lines' => array_fill( 0, 201, $product ) ] ) ), [ 'lines' ] );
check( '200 lines accepted', count( $R::from_array( [ 'lines' => array_fill( 0, 200, $product ) ] )->lines ), 200 );
check( 'lines must be a list', $paths( $fail( [ 'lines' => [ 'a' => $product ] ] ) ), [ 'lines' ] );

$e = $fail( [ 'lines' => [
  [ 'product_id' => 1, 'quantity' => 0 ],
  [ 'product_id' => 1, 'quantity' => 10000 ],
  [ 'product_id' => 1, 'quantity' => '2' ],
  [ 'product_id' => 1, 'quantity' => 1.5 ],
  [ 'product_id' => 0, 'quantity' => 1 ],
  [ 'quantity' => 1 ],
  [ 'type' => 'bundle', 'quantity' => 1 ],
  [ 'type' => 'custom', 'name' => '', 'unit_price_minor' => -1, 'quantity' => 1, 'taxable' => 'yes' ],
  'not-an-object',
] ] );
check( 'line errors: every problem listed', $paths( $e ), [
  'lines[0].quantity', 'lines[1].quantity', 'lines[2].quantity', 'lines[3].quantity',
  'lines[4].product_id', 'lines[5].product_id', 'lines[6].type',
  'lines[7].name', 'lines[7].unit_price_minor', 'lines[7].taxable', 'lines[8]',
] );
check( 'line errors: messages are text', is_string( $e->data()['errors'][0]['message'] ?? null ), true );
check( 'amount above MAX_AMOUNT refused', $paths( $fail( [ 'lines' => [ [ 'type' => 'custom', 'name' => 'x', 'unit_price_minor' => $R::MAX_AMOUNT + 1, 'quantity' => 1 ] ] ] ) ), [ 'lines[0].unit_price_minor' ] );
check( 'long custom name refused', $paths( $fail( [ 'lines' => [ [ 'type' => 'custom', 'name' => str_repeat( 'x', 201 ), 'unit_price_minor' => 1, 'quantity' => 1 ] ] ] ) ), [ 'lines[0].name' ] );

// Coupons and discounts.
check( 'coupons: duplicate refused', $paths( $fail( [ 'lines' => [ $product ], 'coupons' => [ 'Spring', 'spring' ] ] ) ), [ 'coupons[1]' ] );
check( 'coupons: empty and non-string refused', $paths( $fail( [ 'lines' => [ $product ], 'coupons' => [ ' ', 7 ] ] ) ), [ 'coupons[0]', 'coupons[1]' ] );
check( 'coupons: more than 10 refused', $paths( $fail( [ 'lines' => [ $product ], 'coupons' => array_map( function ( $i ) { return "c$i"; }, range( 1, 11 ) ) ] ) ), [ 'coupons' ] );
check( 'discounts: more than 10 refused', $paths( $fail( [ 'lines' => [ $product ], 'discounts' => array_fill( 0, 11, [ 'type' => 'fixed', 'amount_minor' => 1 ] ) ] ) ), [ 'discounts' ] );
check( 'discounts: bad values', $paths( $fail( [ 'lines' => [ $product ], 'discounts' => [
  [ 'type' => 'percent', 'value_bp' => 10001 ],
  [ 'type' => 'percent', 'value_bp' => 0 ],
  [ 'type' => 'fixed', 'amount_minor' => 0 ],
  [ 'type' => 'fixed' ],
  [ 'type' => 'amount', 'amount_minor' => 5 ],
] ] ) ), [ 'discounts[0].value_bp', 'discounts[1].value_bp', 'discounts[2].amount_minor', 'discounts[3].amount_minor', 'discounts[4].type' ] );

// Tip, location and customer.
check( 'tip: negative refused', $paths( $fail( [ 'lines' => [ $product ], 'tip_minor' => -5 ] ) ), [ 'tip_minor' ] );
check( 'location: negative refused', $paths( $fail( [ 'lines' => [ $product ], 'location_id' => -1 ] ) ), [ 'location_id' ] );
check( 'customer: bad email and flag', $paths( $fail( [ 'lines' => [ $product ], 'customer' => [ 'email' => 'nope', 'sms_opt_in' => 1 ] ] ) ), [ 'customer.email', 'customer.sms_opt_in' ] );
check( 'customer: must be an object', $paths( $fail( [ 'lines' => [ $product ], 'customer' => 'x' ] ) ), [ 'customer' ] );
$req = $R::from_array( [ 'lines' => [ $product ], 'customer' => [ 'id' => 9, 'email' => 'a@example.com', 'create_account' => true ] ] );
check( 'customer: values kept', [ $req->customer['id'], $req->customer['email'], $req->customer['create_account'] ], [ 9, 'a@example.com', true ] );

bazaar_test_standalone( __FILE__ );
