<?php
/**
 * Pricing_Tax_Calculator: pure, with injected rates (no WooCommerce).
 * Exclusive and inclusive prices, per-line versus subtotal rounding,
 * compound rates, discounts, tax rounding modes and order-item decimals.
 */
require_once dirname( __DIR__ ) . '/support/harness.php';
$plugin = dirname( dirname( __DIR__ ) );
require_once $plugin . '/includes/pricing/class-xophz-compass-bazaar-pricing-tax-calculator.php';

$T     = 'Xophz_Compass_Bazaar_Pricing_Tax_Calculator';
$rate  = [ 3 => [ 'rate' => 8.25, 'label' => 'CA', 'shipping' => 'yes', 'compound' => 'no' ] ];
$ten   = [ 1 => [ 'rate' => 10.0, 'label' => 'VAT', 'shipping' => 'yes', 'compound' => 'no' ] ];
$cmp   = [
  1 => [ 'rate' => 5.0, 'label' => 'State', 'shipping' => 'yes', 'compound' => 'no' ],
  2 => [ 'rate' => 2.0, 'label' => 'City', 'shipping' => 'yes', 'compound' => 'yes' ],
];
$line = function ( int $gross, array $rates, array $discounts = [], bool $taxable = true ) {
  return [ 'gross_minor' => $gross, 'taxable' => $taxable, 'rates' => $rates, 'discounts' => $discounts ];
};

// Exclusive prices, each line rounded (the WooCommerce default).
$ex  = new $T( [ 'prices_include_tax' => false, 'round_at_subtotal' => false ] );
$out = $ex->price_lines( [ 'l0' => $line( 2468, $rate ), 'l1' => $line( 2397, $rate ) ] );
check( 'exclusive/per-line: line 0 tax 203.61 rounds to 204', $out['lines']['l0']['tax_minor'], 204 );
check( 'exclusive/per-line: line 1 tax 197.7525 rounds to 198', $out['lines']['l1']['tax_minor'], 198 );
check( 'exclusive/per-line: tax is the sum of rounded lines', $out['tax_minor'], 402 );
check( 'exclusive/per-line: rate total', $out['rates'][3]['amount_minor'], 402 );
check( 'exclusive/per-line: subtotal', $out['subtotal_minor'], 4865 );
check( 'exclusive/per-line: total', $out['total_minor'], 5267 );
check( 'exclusive/per-line: no discount', $out['discount_minor'], 0 );

// Exclusive prices, rounded at subtotal: the unrounded taxes are summed first.
$sub = new $T( [ 'prices_include_tax' => false, 'round_at_subtotal' => true ] );
$out = $sub->price_lines( [ 'l0' => $line( 2468, $rate ), 'l1' => $line( 2397, $rate ) ] );
check( 'exclusive/subtotal: tax 401.3625 rounds once to 401', $out['tax_minor'], 401 );
check( 'exclusive/subtotal: total', $out['total_minor'], 5266 );
check( 'exclusive/subtotal: rate total rounded once', $out['rates'][3]['amount_minor'], 401 );
check( 'exclusive/subtotal: unrounded rate amount kept', abs( $out['rates'][3]['amount'] - 401.3625 ) < 1e-9, true );
check( 'exclusive/subtotal: lines still show their own rounding', $out['lines']['l0']['tax_minor'], 204 );

// Inclusive prices: the tax is inside the gross price.
$inc = new $T( [ 'prices_include_tax' => true, 'round_at_subtotal' => false ] );
$out = $inc->price_lines( [ 'l0' => $line( 1000, $ten ) ] );
check( 'inclusive: tax-exclusive subtotal 909.0909 rounds to 909', $out['lines']['l0']['subtotal_minor'], 909 );
check( 'inclusive: unrounded tax-exclusive subtotal kept', abs( $out['lines']['l0']['subtotal_ex'] - 909.0909 ) < 1e-9, true );
check( 'inclusive: tax 90.9091 rounds to 91', $out['tax_minor'], 91 );
check( 'inclusive: total equals the gross price', $out['total_minor'], 1000 );

$out = $inc->price_lines( [ 'l0' => $line( 1000, $ten ), 'l1' => $line( 1000, $ten ) ] );
check( 'inclusive/per-line: two lines', [ $out['tax_minor'], $out['total_minor'] ], [ 182, 2000 ] );
$out = ( new $T( [ 'prices_include_tax' => true, 'round_at_subtotal' => true ] ) )->price_lines( [ 'l0' => $line( 1000, $ten ), 'l1' => $line( 1000, $ten ) ] );
check( 'inclusive/subtotal: tax 181.8182 rounds once to 182', $out['tax_minor'], 182 );
check( 'inclusive/subtotal: total equals the gross prices', $out['total_minor'], 2000 );
check( 'inclusive/subtotal: items total is total less tax', $out['items_total_minor'], 1818 );

// Discounts come off the gross price and are converted at the line's rates.
$out = $inc->price_lines( [ 'l0' => $line( 1000, $ten, [ 100 ] ) ] );
check( 'inclusive discount: line total 818.1818 rounds to 818', $out['lines']['l0']['total_minor'], 818 );
check( 'inclusive discount: tax on the discounted line', $out['tax_minor'], 82 );
check( 'inclusive discount: total is the discounted gross', $out['total_minor'], 900 );
check( 'inclusive discount: discount is tax-exclusive', $out['discount_minor'], 91 );
$out = $ex->price_lines( [ 'l0' => $line( 2500, $rate, [ 250 ] ) ] );
check( 'exclusive discount: spec 9.3 example line', [ $out['lines']['l0']['total_minor'], $out['tax_minor'], $out['total_minor'] ], [ 2250, 186, 2436 ] );
$out = $ex->price_lines( [ 'l0' => $line( 500, $rate, [ 400, 400 ] ) ] );
check( 'discounts never take a line below zero', [ $out['lines']['l0']['total_minor'], $out['tax_minor'] ], [ 0, 0 ] );

// Compound rate: applied on top of the price plus the regular tax.
$out = $ex->price_lines( [ 'l0' => $line( 1000, $cmp ) ] );
check( 'compound/exclusive: regular 5% is 50', $out['lines']['l0']['taxes'][1], 50 );
check( 'compound/exclusive: compound 2% of 1050 is 21', $out['lines']['l0']['taxes'][2], 21 );
check( 'compound/exclusive: total', $out['total_minor'], 1071 );
$out = $inc->price_lines( [ 'l0' => $line( 1071, $cmp ) ] );
check( 'compound/inclusive: tax-exclusive subtotal', $out['lines']['l0']['subtotal_minor'], 1000 );
check( 'compound/inclusive: taxes by rate', $out['lines']['l0']['taxes'], [ 1 => 50, 2 => 21 ] );
check( 'compound/inclusive: total equals gross', $out['total_minor'], 1071 );

// Not taxable: no tax, and an inclusive price is not reduced.
$out = $inc->price_lines( [ 'l0' => $line( 1000, $ten, [], false ) ] );
check( 'not taxable: no tax', [ $out['tax_minor'], $out['lines']['l0']['taxes'] ], [ 0, [] ] );
check( 'not taxable: inclusive price kept whole', $out['lines']['l0']['subtotal_minor'], 1000 );
$out = $ex->price_lines( [ 'l0' => $line( 1000, [] ) ] );
check( 'no rates: no tax', $out['total_minor'], 1000 );

// Tax rounding mode: WooCommerce rounds half down when prices include tax.
$up   = new $T( [ 'tax_rounding_mode' => PHP_ROUND_HALF_UP ] );
$down = new $T( [ 'tax_rounding_mode' => PHP_ROUND_HALF_DOWN ] );
check( 'rounding: 100.5 half up', $up->price_lines( [ 'l0' => $line( 1005, $ten ) ] )['tax_minor'], 101 );
check( 'rounding: 100.5 half down', $down->price_lines( [ 'l0' => $line( 1005, $ten ) ] )['tax_minor'], 100 );

// The price WC_Discounts sees: tax-exclusive subtotal plus its tax when prices include tax.
check( 'discount price: exclusive', $ex->line_subtotal( $line( 2468, $rate ) )['discount_price'], 2468.0 );
check( 'discount price: inclusive adds the rounded line tax', abs( $inc->line_subtotal( $line( 1000, $ten ) )['discount_price'] - 1000.0909 ) < 1e-9, true );

// Coupon tax share (set_coupon_discount_amounts).
check( 'discount tax: inclusive, rounded per line', $inc->discount_tax( 100, $ten, true ), 9.0 );
check( 'discount tax: exclusive', $ex->discount_tax( 250, $rate, true ), 21.0 );
check( 'discount tax: not taxable', $ex->discount_tax( 250, $rate, false ), 0.0 );

// Order-item decimals.
check( 'decimal: unrounded minor to major', $inc->to_decimal( 818.1818 ), '8.181818' );
check( 'decimal: whole amount', $inc->to_decimal( 2500.0 ), '25' );
check( 'decimal: zero', $inc->to_decimal( 0.0 ), '0' );

// An injected calc callable replaces WC_Tax / the pure formulas.
$fixed = new $T( [ 'calc' => function ( $price, $rates, $inclusive ) { return [ 9 => 0.5 ]; } ] );
check( 'injected calc: used and converted to minor units', $fixed->taxes( 1000, $ten, false ), [ 9 => 50.0 ] );

bazaar_test_standalone( __FILE__ );
