<?php
/**
 * Checkout_Finalizer::stock_steps(): the from/to the commit records per line,
 * keyed to the product that holds the stock (#6985 review).
 */
require_once dirname( __DIR__ ) . '/support/harness.php';
require_once dirname( dirname( __DIR__ ) ) . '/includes/checkout/class-xophz-compass-bazaar-checkout-finalizer.php';

$F = 'Xophz_Compass_Bazaar_Checkout_Finalizer';

check( 'stock steps: one line, from = after + qty', $F::stock_steps( [ [ 'product_id' => 5, 'stock_id' => 5, 'quantity' => 2 ] ], [ 5 => '8' ] ), [ [ 'product_id' => 5, 'stock_id' => 5, 'from' => 10.0, 'to' => 8.0 ] ] );

// A variation whose parent (40) manages stock reads the parent's _stock and keeps its own product id for the note.
check( 'stock steps: parent-managed variation uses the parent level', $F::stock_steps( [ [ 'product_id' => 41, 'stock_id' => 40, 'quantity' => 1 ] ], [ 40 => '3' ] ), [ [ 'product_id' => 41, 'stock_id' => 40, 'from' => 4.0, 'to' => 3.0 ] ] );

// Two lines of one holder step through consecutive values in line order.
check( 'stock steps: lines sharing a holder are consecutive', $F::stock_steps( [
  [ 'product_id' => 41, 'stock_id' => 40, 'quantity' => 2 ],
  [ 'product_id' => 9, 'stock_id' => 9, 'quantity' => 1 ],
  [ 'product_id' => 42, 'stock_id' => 40, 'quantity' => 3 ],
], [ 40 => '5', 9 => '0' ] ), [
  [ 'product_id' => 41, 'stock_id' => 40, 'from' => 10.0, 'to' => 8.0 ],
  [ 'product_id' => 9, 'stock_id' => 9, 'from' => 1.0, 'to' => 0.0 ],
  [ 'product_id' => 42, 'stock_id' => 40, 'from' => 8.0, 'to' => 5.0 ],
] );

check( 'stock steps: an unmanaged holder (null or empty _stock) gives no entry', $F::stock_steps( [
  [ 'product_id' => 1, 'stock_id' => 1, 'quantity' => 1 ],
  [ 'product_id' => 2, 'stock_id' => 2, 'quantity' => 1 ],
], [ 1 => null, 2 => '' ] ), [] );

check( 'stock steps: fractional quantities and negative levels', $F::stock_steps( [ [ 'product_id' => 7, 'stock_id' => 7, 'quantity' => 1.5 ] ], [ 7 => '-0.5' ] ), [ [ 'product_id' => 7, 'stock_id' => 7, 'from' => 1.0, 'to' => -0.5 ] ] );
