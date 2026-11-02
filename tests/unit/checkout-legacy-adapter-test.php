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

// Split: the SPA sends the slip reference only in legs[] (finding 13).
$spa = [
  'paymentMethod' => 'bazaar_split',
  'splitPayments' => '[{"method":"cash","amount":10},{"method":"bazaar_card_terminal","amount":15.5}]',
  'legs'          => '[{"method":"cash","amount":10,"amount_minor":1000,"kind":"cash","tendered_minor":2000},{"method":"bazaar_card_terminal","amount":15.5,"amount_minor":1550,"kind":"external_terminal","reference":"SLIP-7001","cardLast4":"4242","cardBrand":"Visa"}]',
];
$t = $A::tenders( $args( $spa ), 2550 );
check( 'legs: the card reference, brand and last four come from legs[]', [ $t[1]['reference'], $t[1]['card_brand'], $t[1]['card_last4'] ], [ 'SLIP-7001', 'visa', '4242' ] );
check( 'legs: a cash leg keeps the tendered amount from legs[]', [ $t[0]['tendered_minor'], $t[0]['amount_minor'] ], [ 2000, 1000 ] );
$both = $spa;
$both['splitPayments'] = [ [ 'method' => 'cash', 'amount' => 10 ], [ 'method' => 'bazaar_card_terminal', 'amount' => 15.5, 'reference' => 'SLIP-OWN1' ] ];
check( 'legs: a reference in splitPayments wins', $A::tenders( $args( $both ), 2550 )[1]['reference'], 'SLIP-OWN1' );
$reordered = [
  'paymentMethod' => 'bazaar_split',
  'splitPayments' => [ [ 'method' => 'bazaar_card_terminal', 'amount' => 5 ], [ 'method' => 'cash', 'amount' => 5 ], [ 'method' => 'bazaar_card_terminal', 'amount' => 5 ] ],
  'legs'          => [ [ 'method' => 'cash', 'tendered_minor' => 400 ], [ 'method' => 'bazaar_card_terminal', 'reference' => 'SLIP-A111' ], [ 'method' => 'bazaar_card_terminal', 'reference' => 'SLIP-B222' ] ],
];
$t = $A::tenders( $args( $reordered ), 1500 );
check( 'legs: matched by method when the index differs, each used once', [ $t[0]['reference'], $t[2]['reference'] ], [ 'SLIP-A111', 'SLIP-B222' ] );
check( 'legs: a tendered amount below the leg is ignored', $t[1]['tendered_minor'], 500 );
check( 'legs: no legs[] keeps the old mapping', $A::tenders( $args( [ 'paymentMethod' => 'bazaar_split', 'splitPayments' => [ [ 'method' => 'bazaar_card_terminal', 'amount' => 1 ] ] ] ), 100 )[0]['reference'], '' );

// A committed row is a paid sale: success, never a failure to retry (finding 9).
check( 'committed: finalize pending answers success with the order', $A::committed_answer( [ 'state' => 'committed', 'order_id' => '41' ], 'wc_order_x' ), [ 'success' => true, 'order_id' => 41, 'order_key' => 'wc_order_x', 'finalize_pending' => true, 'message' => $A::MSG_FINALIZE_PENDING ] );
check( 'committed: a completed row answers plain success', $A::committed_answer( [ 'state' => 'completed', 'order_id' => 41 ] ), [ 'success' => true, 'order_id' => 41, 'order_key' => '' ] );
foreach ( [ 'failed', 'cancelled', 'order_created', 'priced', 'received' ] as $state ) {
  check( "committed: a $state row is not a sale", $A::committed_answer( [ 'state' => $state, 'order_id' => 41 ] ), null );
}
check( 'committed: no row is not a sale', $A::committed_answer( null ), null );
check( 'committed: a committed row without an order is not a sale', $A::committed_answer( [ 'state' => 'committed', 'order_id' => null ] ), null );

// The ignored cashier id is noted, as the old handler did.
check( 'cashier: another id gives the note', $A::ignored_cashier_note( $args( [ 'cashierId' => '9' ] ), 3 ), 'The register sent cashier id 9. It was ignored; the sale is recorded for user 3.' );
check( 'cashier: the acting user gives no note', $A::ignored_cashier_note( $args( [ 'cashierId' => 3 ] ), 3 ), '' );
check( 'cashier: none or junk gives no note', [ $A::ignored_cashier_note( $args( [] ), 3 ), $A::ignored_cashier_note( $args( [ 'cashierId' => 'abc' ] ), 3 ), $A::ignored_cashier_note( $args( [ 'cashierId' => 0 ] ), 3 ) ], [ '', '', '' ] );

bazaar_test_standalone( __FILE__ );
