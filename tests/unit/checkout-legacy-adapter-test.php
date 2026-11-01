<?php
/**
 * Checkout_Legacy_Adapter (WP18): the legacy create_pos_order fields mapped
 * to a v2 cart and tenders, the checkout key, the fixed message texts, and the
 * pipeline switch value. Pure parts only; the sale itself is covered by
 * tests/integration/checkout-legacy.sh.
 */
require_once dirname( __DIR__ ) . '/support/harness.php';
$plugin = dirname( dirname( __DIR__ ) );
require_once $plugin . '/includes/core/class-xophz-compass-bazaar-core-http-error.php';
require_once $plugin . '/includes/core/class-xophz-compass-bazaar-core-request.php';
require_once $plugin . '/includes/core/class-xophz-compass-bazaar-core-money.php';
require_once $plugin . '/includes/payments/class-xophz-compass-bazaar-payments-tender.php';
require_once $plugin . '/includes/checkout/class-xophz-compass-bazaar-checkout-legacy-adapter.php';

$A    = 'Xophz_Compass_Bazaar_Checkout_Legacy_Adapter';
$args = function ( array $fields ) {
  return (object) $fields;
};

// Texts the register already knows.
check( 'text: empty cart', $A::MSG_EMPTY, 'Cart is empty.' );
check( 'text: split mismatch', $A::MSG_SPLIT, 'Split payment sum does not match order total.' );

// Key: legacy:<uuid4>, new each call.
$k1 = $A::key();
check( 'key: legacy uuid4', 1 === preg_match( '/^legacy:[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $k1 ), true );
check( 'key: never repeats', $k1 === $A::key(), false );

// Items: JSON strings, objects and arrays all decode; skipped entries stay skipped.
$cart = $A::cart( $args( [ 'items' => '[{"product_id":12,"quantity":"2"},{"product_id":5,"quantity":0},{"product_id":0,"quantity":1},{"product_id":7,"variation_id":9,"quantity":1}]' ] ) );
check( 'items: stringified list decodes', $cart['lines'], [
  [ 'type' => 'product', 'product_id' => 12, 'variation_id' => 0, 'quantity' => 2 ],
  [ 'type' => 'product', 'product_id' => 7, 'variation_id' => 9, 'quantity' => 1 ],
] );
check( 'items: array input', count( $A::cart( $args( [ 'items' => [ [ 'product_id' => 3, 'quantity' => 1 ] ] ] ) )['lines'] ), 1 );
check( 'items: none gives no lines', $A::cart( $args( [ 'items' => 'nope' ] ) )['lines'], [] );

// Custom lines: negative id with title and price.
$cart = $A::cart( $args( [ 'items' => [
  [ 'product_id' => -1, 'title' => 'Gift wrap', 'price' => '4.50', 'quantity' => 2 ],
  [ 'product_id' => -2, 'title' => 'No price', 'quantity' => 1 ],
  [ 'product_id' => -3, 'price' => 3, 'quantity' => 1 ],
  [ 'product_id' => -4, 'name' => 'Untaxed', 'price' => 1, 'quantity' => 1, 'taxable' => false ],
] ] ) );
check( 'custom: title and price become a custom line', $cart['lines'][0], [ 'type' => 'custom', 'name' => 'Gift wrap', 'unit_price_minor' => 450, 'quantity' => 2, 'taxable' => true ] );
check( 'custom: lines without title or price are skipped', count( $cart['lines'] ), 2 );
check( 'custom: taxable false is kept', $cart['lines'][1]['taxable'], false );

// Discounts.
$cart = $A::cart( $args( [
  'items'           => [ [ 'product_id' => 1, 'quantity' => 1 ] ],
  'discount'        => '2.50',
  'customDiscounts' => '[{"name":"Loyalty","type":"percent","amount":"12.5"},{"type":"fixed","amount":3},{"type":"percentage","amount":250},{"type":"fixed","amount":0}]',
] ) );
check( 'discount: flat becomes POS Discount', $cart['discounts'][0], [ 'name' => 'POS Discount', 'type' => 'fixed', 'amount_minor' => 250 ] );
check( 'discount: percent becomes basis points', $cart['discounts'][1], [ 'name' => 'Loyalty', 'type' => 'percent', 'value_bp' => 1250 ] );
check( 'discount: fixed keeps its type and a default name', $cart['discounts'][2], [ 'name' => 'Custom Discount', 'type' => 'fixed', 'amount_minor' => 300 ] );
check( 'discount: percent above 100 is capped', $cart['discounts'][3]['value_bp'], 10000 );
check( 'discount: zero amounts are dropped', count( $cart['discounts'] ), 4 );

// Coupons, tip, customer.
$cart = $A::cart( $args( [
  'items'          => [ [ 'product_id' => 1, 'quantity' => 1 ] ],
  'appliedCoupons' => '["SAVE10"," save10 ",5,""]',
  'tipAmount'      => '1.25',
  'customerId'     => '4',
  'customerEmail'  => 'not-an-email',
  'customerName'   => 'Ada Lovelace',
  'createAccount'  => 'true',
  'marketingOptIn' => '1',
  'smsOptIn'       => 'no',
] ) );
check( 'coupons: strings only, trimmed', $cart['coupons'], [ 'SAVE10', 'save10' ] );
check( 'tip: minor units', $cart['tip_minor'], 125 );
check( 'customer: id, name and flags', [ $cart['customer']['id'], $cart['customer']['name'], $cart['customer']['create_account'], $cart['customer']['marketing_opt_in'], $cart['customer']['sms_opt_in'] ], [ 4, 'Ada Lovelace', true, true, false ] );
check( 'customer: a bad email is dropped', $cart['customer']['email'], '' );

// Tenders: single.
$t = $A::tenders( $args( [] ), 1999 );
check( 'tender: cash is the default and takes the total', [ $t[0]['method_id'], $t[0]['amount_minor'], count( $t ) ], [ 'cash', 1999, 1 ] );
check( 'tender: cash is handed over exactly (no change tracking in the old request)', $A::tenders( $args( [] ), 1999 )[0]['tendered_minor'], 1999 );
$t = $A::tenders( $args( [ 'paymentMethod' => 'paypal', 'authorizationToken' => 'tok-1' ] ), 2500 );
check( 'tender: a digital tender has no tendered amount', $t[0]['tendered_minor'], null );
check( 'tender: token rides on the tender', [ $t[0]['method_id'], $t[0]['authorization_token'] ], [ 'paypal', 'tok-1' ] );
$t = $A::tenders( $args( [ 'paymentMethod' => 'bazaar_card_terminal', 'terminalReference' => 'AB-1234', 'cardBrand' => 'VISA', 'cardLast4' => '4242' ] ), 800 );
check( 'tender: card reference, brand and last four', [ $t[0]['reference'], $t[0]['card_brand'], $t[0]['card_last4'] ], [ 'AB-1234', 'visa', '4242' ] );
$t = $A::tenders( $args( [ 'paymentMethod' => 'bazaar_card_terminal', 'cardBrand' => 'diners', 'cardLast4' => '12' ] ), 800 );
check( 'tender: unknown brand becomes other, bad last four is dropped', [ $t[0]['reference'], $t[0]['card_brand'], $t[0]['card_last4'] ], [ '', 'other', '' ] );
$t = $A::tenders( $args( [ 'paymentMethod' => 'cash' ] ), 0 );
check( 'tender: a zero total gives one tender of 0', $t[0]['amount_minor'], 0 );

// Tenders: split.
$split = '[{"method":"cash","amount":"10.00"},{"method":"bazaar_card_terminal","amount":15.5,"reference":"SLIP-9001","cardLast4":"1111"},{"method":"cash","amount":0}]';
$t     = $A::tenders( $args( [ 'paymentMethod' => 'bazaar_split', 'splitPayments' => $split ] ), 2550 );
check( 'split: legs mapped, zero legs dropped', [ count( $t ), $t[0]['amount_minor'], $t[1]['method_id'], $t[1]['amount_minor'], $t[1]['reference'], $t[1]['card_last4'] ], [ 2, 1000, 'bazaar_card_terminal', 1550, 'SLIP-9001', '1111' ] );
check( 'split: a leg token falls back to the sale token', $A::tenders( $args( [ 'paymentMethod' => 'bazaar_split', 'authorizationToken' => 'sale-tok', 'splitPayments' => [ [ 'method' => 'paypal', 'amount' => 5, 'authorizationToken' => 'leg-tok' ], [ 'method' => 'affirm', 'amount' => 5 ] ] ] ), 1000 )[1]['authorization_token'], 'sale-tok' );
check( 'split: a sum off by one minor unit is a mismatch', $A::tenders( $args( [ 'paymentMethod' => 'bazaar_split', 'splitPayments' => $split ] ), 2551 ), null );
check( 'split: no legs is a mismatch for a priced total', $A::tenders( $args( [ 'paymentMethod' => 'bazaar_split' ] ), 100 ), null );

// Every mapped tender is accepted by Payments_Tender::from_array.
$ok = true;
foreach ( $A::tenders( $args( [ 'paymentMethod' => 'bazaar_split', 'splitPayments' => $split ] ), 2550 ) as $tender ) {
  try {
    Xophz_Compass_Bazaar_Payments_Tender::from_array( $tender, false );
  } catch ( Xophz_Compass_Bazaar_Core_Http_Error $e ) {
    $ok = false;
  }
}
check( 'split: mapped legs pass the tender shape check', $ok, true );

bazaar_test_standalone( __FILE__ );
