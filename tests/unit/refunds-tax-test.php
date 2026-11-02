<?php
/**
 * Refunds_Tax: half-up proration, the last unit taking the remainder, every
 * tax rate of a line, and exact sums over random partial-refund sequences.
 * Review fixes of #6995: negative fee lines spread over the lines (legacy
 * order discounts), per-rate settlement for line taxes stored unrounded
 * (WooCommerce rounding at subtotal) and the order's remaining amount.
 */
require_once dirname( __DIR__ ) . '/support/harness.php';
$plugin = dirname( dirname( __DIR__ ) );
require_once $plugin . '/includes/core/class-xophz-compass-bazaar-core-http-error.php';
require_once $plugin . '/includes/core/class-xophz-compass-bazaar-core-money.php';
require_once $plugin . '/includes/refunds/class-xophz-compass-bazaar-refunds-tax.php';
require_once $plugin . '/includes/refunds/class-xophz-compass-bazaar-refunds-amounts.php';

$T = 'Xophz_Compass_Bazaar_Refunds_Tax';

// prorate(): half-up, remainder on the last unit, bounds.
check( 'prorate: even share', $T::prorate( 1000, 4, 1, 4, 0 ), 250 );
check( 'prorate: half rounds up', $T::prorate( 1001, 2, 1, 2, 0 ), 501 );
check( 'prorate: below half rounds down', $T::prorate( 1000, 3, 1, 3, 0 ), 333 );
check( 'prorate: last unit takes the remainder', $T::prorate( 1000, 3, 1, 1, 666 ), 334 );
check( 'prorate: whole line in one go', $T::prorate( 1000, 3, 3, 3, 0 ), 1000 );
check( 'prorate: never above what remains', $T::prorate( 1000, 3, 1, 3, 900 ), 100 );
check( 'prorate: zero amount', $T::prorate( 0, 3, 1, 3, 0 ), 0 );
check( 'prorate: zero quantity refunded', $T::prorate( 1000, 3, 0, 3, 0 ), 0 );
check( 'prorate: negative amount rounds away from zero', $T::prorate( -1001, 2, 1, 2, 0 ), -501 );

// Random partial-refund sequences: per kind the shares add up to the amount exactly.
mt_srand( 20261101 );
$bad = 0;
for ( $n = 0; $n < 400; $n++ ) {
  $qty    = mt_rand( 1, 12 );
  $total  = mt_rand( 0, 250000 );
  $rates  = [];
  foreach ( array_slice( [ 11 => 1, 12 => 1, 13 => 1 ], 0, mt_rand( 1, 3 ), true ) as $rate_id => $unused ) {
    $rates[ $rate_id ] = mt_rand( 0, 40000 );
  }
  $item = [ 'qty' => $qty, 'refunded_qty' => 0, 'total_minor' => $total, 'taxes_minor' => $rates, 'refunded_total_minor' => 0, 'refunded_taxes_minor' => [] ];
  $sum_total = 0;
  $sum_tax   = array_fill_keys( array_keys( $rates ), 0 );
  while ( $item['qty'] - $item['refunded_qty'] > 0 ) {
    $remaining = $item['qty'] - $item['refunded_qty'];
    $take      = mt_rand( 1, $remaining );
    $calc      = $T::compute( [ 5 => $item ], [ [ 'order_item_id' => 5, 'qty' => $take ] ] );
    $line      = $calc['lines'][0];
    if ( $line['refund_total_minor'] < 0 || $line['refund_total_minor'] > $total - $sum_total ) {
      $bad++;
    }
    $sum_total                        += $line['refund_total_minor'];
    $item['refunded_qty']             += $take;
    $item['refunded_total_minor']      = $sum_total;
    foreach ( $rates as $rate_id => $amount ) {
      $part = $line['refund_tax'][ $rate_id ] ?? 0;
      if ( $part < 0 || $part > $amount - $sum_tax[ $rate_id ] ) {
        $bad++;
      }
      $sum_tax[ $rate_id ]                         += $part;
      $item['refunded_taxes_minor'][ $rate_id ]     = $sum_tax[ $rate_id ];
    }
    if ( $line['refund_tax_minor'] !== array_sum( $line['refund_tax'] ) || $calc['total_minor'] !== $line['refund_total_minor'] + $line['refund_tax_minor'] ) {
      $bad++;
    }
  }
  if ( $sum_total !== $total || $sum_tax !== $rates ) {
    $bad++;
  }
}
check( 'random sequences: 400 items refunded to the cent, no share out of bounds', $bad, 0 );

// A multi-rate line: every rate id is refunded, not just the first.
$multi = [ 7 => [ 'qty' => 3, 'refunded_qty' => 0, 'total_minor' => 3000, 'taxes_minor' => [ 21 => 247, 22 => 100, 23 => 1 ], 'refunded_total_minor' => 0, 'refunded_taxes_minor' => [] ] ];
$first = $T::compute( $multi, [ [ 'order_item_id' => 7, 'qty' => 1 ] ] );
check( 'multi-rate: first unit total', $first['lines'][0]['refund_total_minor'], 1000 );
check( 'multi-rate: first unit refunds all rates that round above zero', $first['lines'][0]['refund_tax'], [ 21 => 82, 22 => 33 ] );
check( 'multi-rate: totals', [ $first['subtotal_minor'], $first['tax_minor'], $first['total_minor'] ], [ 1000, 115, 1115 ] );
$multi[7]['refunded_qty']           = 2;
$multi[7]['refunded_total_minor']   = 2000;
$multi[7]['refunded_taxes_minor']   = [ 21 => 164, 22 => 66, 23 => 0 ];
$last = $T::compute( $multi, [ [ 'order_item_id' => 7, 'qty' => 1 ] ] );
check( 'multi-rate: last unit takes each rate\'s remainder', $last['lines'][0]['refund_tax'], [ 21 => 83, 22 => 34, 23 => 1 ] );
check( 'multi-rate: last unit total remainder', $last['lines'][0]['refund_total_minor'], 1000 );

// Several lines add up.
$items = [
  1 => [ 'qty' => 2, 'refunded_qty' => 0, 'total_minor' => 2500, 'taxes_minor' => [ 1 => 206 ], 'refunded_total_minor' => 0, 'refunded_taxes_minor' => [] ],
  2 => [ 'qty' => 1, 'refunded_qty' => 0, 'total_minor' => 799, 'taxes_minor' => [ 1 => 66 ], 'refunded_total_minor' => 0, 'refunded_taxes_minor' => [] ],
];
$two = $T::compute( $items, [ [ 'order_item_id' => 1, 'qty' => 1 ], [ 'order_item_id' => 2, 'qty' => 1 ] ] );
check( 'two lines: subtotal', $two['subtotal_minor'], 1250 + 799 );
check( 'two lines: tax', $two['tax_minor'], 103 + 66 );
check( 'two lines: total', $two['total_minor'], 1250 + 799 + 103 + 66 );

// Refusals.
$fail = function ( array $items, array $lines ) use ( $T ) {
  try {
    $T::compute( $items, $lines );
  } catch ( Xophz_Compass_Bazaar_Core_Http_Error $e ) {
    return [ $e->error_code(), $e->status(), $e->data()['order_item_id'] ];
  }
  return null;
};
check( 'refusal: unknown item', $fail( $items, [ [ 'order_item_id' => 9, 'qty' => 1 ] ] ), [ 'invalid_line', 422, 9 ] );
check( 'refusal: more than the item holds', $fail( $items, [ [ 'order_item_id' => 2, 'qty' => 2 ] ] ), [ 'invalid_line', 422, 2 ] );
$items[2]['refunded_qty'] = 1;
check( 'refusal: nothing left to refund', $fail( $items, [ [ 'order_item_id' => 2, 'qty' => 1 ] ] ), [ 'invalid_line', 422, 2 ] );

// spread_fees(): the finding's legacy order. 2 x 50.00 (8.25% tax 4.13 each),
// "POS Discount" -20.00 with -1.65 tax; the order total is 86.61.
$legacy = [
  1 => [ 'qty' => 1, 'refunded_qty' => 0, 'total_minor' => 5000, 'taxes_minor' => [ 4 => 413 ], 'refunded_total_minor' => 0, 'refunded_taxes_minor' => [] ],
  2 => [ 'qty' => 1, 'refunded_qty' => 0, 'total_minor' => 5000, 'taxes_minor' => [ 4 => 413 ], 'refunded_total_minor' => 0, 'refunded_taxes_minor' => [] ],
];
$discount = [ [ 'total_minor' => -2000, 'taxes_minor' => [ 4 => -165 ] ] ];
$spread   = $T::spread_fees( $legacy, $discount );
check( 'spread: the discount comes off each line by line total', [ $spread[1]['total_minor'], $spread[2]['total_minor'] ], [ 4000, 4000 ] );
check( 'spread: the discount tax by line tax, largest remainder (tie to the first)', [ $spread[1]['taxes_minor'], $spread[2]['taxes_minor'] ], [ [ 4 => 330 ], [ 4 => 331 ] ] );
check( 'spread: a positive fee (tip) is not spread', $T::spread_fees( $legacy, [ [ 'total_minor' => 500, 'taxes_minor' => [ 4 => 41 ] ] ] ), $legacy );
check( 'spread: no fees, items unchanged', $T::spread_fees( $legacy, [] ), $legacy );

$rate_left = [ 4 => Xophz_Compass_Bazaar_Refunds_Amounts::rounded_sum( [ '4.13', '4.13', '-1.65' ] ) ];
check( 'rounded_sum: line and fee taxes', $rate_left[4], 661 );
$a = $T::settle( $spread, $T::compute( $spread, [ [ 'order_item_id' => 1, 'qty' => 1 ] ] ), $rate_left, 8661 );
check( 'legacy discount: item A refunds its discounted price, not 54.13', [ $a['subtotal_minor'], $a['tax_minor'], $a['total_minor'] ], [ 4000, 330, 4330 ] );
$spread[1]['refunded_qty']         = 1;
$spread[1]['refunded_total_minor'] = 4000;
$spread[1]['refunded_taxes_minor'] = [ 4 => 330 ];
$b = $T::settle( $spread, $T::compute( $spread, [ [ 'order_item_id' => 2, 'qty' => 1 ] ] ), [ 4 => 661 - 330 ], 8661 - 4330 );
check( 'legacy discount: item B refunds the rest exactly (was refund_rejected)', $b['total_minor'], 4331 );
check( 'legacy discount: A + B equals the order total', $a['total_minor'] + $b['total_minor'], 8661 );

// A fee taxed at a rate only one line carries stays on that line.
$mixed = [
  1 => [ 'qty' => 1, 'refunded_qty' => 0, 'total_minor' => 1000, 'taxes_minor' => [ 4 => 100 ], 'refunded_total_minor' => 0, 'refunded_taxes_minor' => [] ],
  2 => [ 'qty' => 1, 'refunded_qty' => 0, 'total_minor' => 1000, 'taxes_minor' => [], 'refunded_total_minor' => 0, 'refunded_taxes_minor' => [] ],
];
$ms = $T::spread_fees( $mixed, [ [ 'total_minor' => -500, 'taxes_minor' => [ 4 => -25 ] ] ] );
check( 'spread: the fee tax lands only on lines carrying that rate', [ $ms[1]['taxes_minor'], $ms[2]['taxes_minor'], $ms[1]['total_minor'], $ms[2]['total_minor'] ], [ [ 4 => 75 ], [], 750, 750 ] );
check( 'spread: a discount above the lines is held to them', [ $T::spread_fees( $mixed, [ [ 'total_minor' => -9000 ] ] )[1]['total_minor'], $T::spread_fees( $mixed, [ [ 'total_minor' => -9000 ] ] )[2]['total_minor'] ], [ 0, 0 ] );

// settle(): line taxes stored unrounded (round at subtotal). Two lines of 10.00
// with 0.825 tax each: per line 83 + 83, the order holds 1.65.
$sub = [
  1 => [ 'qty' => 1, 'refunded_qty' => 0, 'total_minor' => 1000, 'taxes_minor' => [ 4 => 83 ], 'refunded_total_minor' => 0, 'refunded_taxes_minor' => [] ],
  2 => [ 'qty' => 1, 'refunded_qty' => 0, 'total_minor' => 1000, 'taxes_minor' => [ 4 => 83 ], 'refunded_total_minor' => 0, 'refunded_taxes_minor' => [] ],
];
check( 'rounded_sum: 0.825 + 0.825 rounds once to 1.65', Xophz_Compass_Bazaar_Refunds_Amounts::rounded_sum( [ '0.825', '0.825' ] ), 165 );
$s1 = $T::settle( $sub, $T::compute( $sub, [ [ 'order_item_id' => 1, 'qty' => 1 ] ] ), [ 4 => 165 ], 2165 );
check( 'round at subtotal: the first line refunds its own 83', $s1['total_minor'], 1083 );
$sub[1]['refunded_qty']         = 1;
$sub[1]['refunded_total_minor'] = 1000;
$sub[1]['refunded_taxes_minor'] = [ 4 => 83 ];
$s2 = $T::settle( $sub, $T::compute( $sub, [ [ 'order_item_id' => 2, 'qty' => 1 ] ] ), [ 4 => 165 - 83 ], 2165 - 1083 );
check( 'round at subtotal: the last line takes the rate remainder 82 (was 83, refund_rejected)', [ $s2['lines'][0]['refund_tax'], $s2['total_minor'] ], [ [ 4 => 82 ], 1082 ] );
$sub[1]['refunded_qty'] = 0;
$sub[1]['refunded_total_minor'] = 0;
$sub[1]['refunded_taxes_minor'] = [];
$both = $T::settle( $sub, $T::compute( $sub, [ [ 'order_item_id' => 1, 'qty' => 1 ], [ 'order_item_id' => 2, 'qty' => 1 ] ] ), [ 4 => 165 ], 2165 );
check( 'round at subtotal: both lines at once refund the order exactly', [ $both['tax_minor'], $both['total_minor'], $both['lines'][1]['refund_tax'] ], [ 165, 2165, [ 4 => 82 ] ] );
$low = $sub;
$low[1]['taxes_minor'] = [ 4 => 82 ];
$low[2]['taxes_minor'] = [ 4 => 82 ];
$up = $T::settle( $low, $T::compute( $low, [ [ 'order_item_id' => 1, 'qty' => 1 ], [ 'order_item_id' => 2, 'qty' => 1 ] ] ), [ 4 => Xophz_Compass_Bazaar_Refunds_Amounts::rounded_sum( [ '0.824', '0.824' ] ) ], 2165 );
check( 'round at subtotal: 0.824 x 2 rounds to 1.65, the completing refund takes it', [ $up['tax_minor'], $up['lines'][1]['refund_tax'] ], [ 165, [ 4 => 83 ] ] );
$part = $T::settle( $sub, $T::compute( $sub, [ [ 'order_item_id' => 1, 'qty' => 1 ] ] ), [ 4 => 50 ], 2165 );
check( 'settle: a partial refund is held to what the rate has left', $part['lines'][0]['refund_tax'], [ 4 => 50 ] );
check( 'settle: rates the order does not list are left alone', $T::settle( $sub, $T::compute( $sub, [ [ 'order_item_id' => 1, 'qty' => 1 ] ] ), [], 2165 )['total_minor'], 1083 );

// Per-line rounding (woocommerce_tax_round_at_subtotal 'no', WooCommerce's
// default): update_taxes() rounds each item's tax on its own and sums them, so
// two lines of 0.825 make an order tax of 1.66 and each line refunds 0.83.
$A = 'Xophz_Compass_Bazaar_Refunds_Amounts';
check( 'round_line: half up by default', [ $A::round_line( '0.825' ), $A::round_line( '-0.825' ), $A::round_line( '0.8249' ), $A::round_line( '' ) ], [ 83, -83, 82, 0 ] );
check( 'round_line: half down on the magnitude', [ $A::round_line( '0.825', PHP_ROUND_HALF_DOWN ), $A::round_line( '-0.825', PHP_ROUND_HALF_DOWN ), $A::round_line( '0.8251', PHP_ROUND_HALF_DOWN ), $A::round_line( 0.83, PHP_ROUND_HALF_DOWN ) ], [ 82, -82, 83, 83 ] );
check( 'rate_per_line: an order tax of 1.66 from 0.825 + 0.825 was rounded per line', $A::rate_per_line( '1.66', [ '0.825', '0.825' ], PHP_ROUND_HALF_UP, false ), true );
check( 'rate_per_line: an order tax of 1.65 was rounded once', $A::rate_per_line( '1.65', [ '0.825', '0.825' ], PHP_ROUND_HALF_UP, true ), false );
check( 'rate_per_line: a taxable tip counts in the order tax it is matched against', $A::rate_per_line( '2.49', [ '0.825', '0.825', '0.825' ], PHP_ROUND_HALF_UP, false ), true );
check( 'rate_per_line: no stored tax item falls back to the option', [ $A::rate_per_line( null, [ '0.825' ], PHP_ROUND_HALF_UP, true ), $A::rate_per_line( '', [ '0.825' ], PHP_ROUND_HALF_UP, false ) ], [ true, false ] );
check( 'rate_limit: per line 83 + 83, once 165', [ $A::rate_limit( [ '0.825', '0.825' ], true ), $A::rate_limit( [ '0.825', '0.825' ], false ) ], [ 166, 165 ] );
check( 'rate_limit: per line with negative fee tax', $A::rate_limit( [ '4.125', '4.125', '-1.6525' ], true ), 413 + 413 - 165 );
$pl = $sub;
$p1 = $T::settle( $pl, $T::compute( $pl, [ [ 'order_item_id' => 1, 'qty' => 1 ] ] ), [ 4 => $A::rate_limit( [ '0.825', '0.825' ], true ) ], 2166 );
$pl[1]['refunded_qty']         = 1;
$pl[1]['refunded_total_minor'] = 1000;
$pl[1]['refunded_taxes_minor'] = [ 4 => 83 ];
$p2 = $T::settle( $pl, $T::compute( $pl, [ [ 'order_item_id' => 2, 'qty' => 1 ] ] ), [ 4 => 166 - 83 ], 2166 - 1083 );
check( 'round per line: 2 x 10.00 with 0.825 tax refund 1083 + 1083 = 2166 (was 1083 + 1082)', [ $p1['total_minor'], $p2['total_minor'], $p1['total_minor'] + $p2['total_minor'] ], [ 1083, 1083, 2166 ] );

// Random orders with unrounded line taxes, built as WooCommerce builds the
// order tax in each rounding setting, refunded in random steps: the refunds
// add up to the order total exactly and no step is refused.
mt_srand( 69951 );
$bad = 0;
for ( $n = 0; $n < 400; $n++ ) {
  $per_line = 0 === $n % 2;
  $mode     = 0 === $n % 3 ? PHP_ROUND_HALF_DOWN : PHP_ROUND_HALF_UP;
  $eff      = [];
  $raw_tax  = [];
  $sum      = 0;
  for ( $i = 1, $count = mt_rand( 1, 5 ); $i <= $count; $i++ ) {
    $total     = mt_rand( 1, 20000 );
    $raw       = Xophz_Compass_Bazaar_Core_Money::from_minor( $total * 825, 6 );
    $raw_tax[] = $raw;
    $eff[ $i ] = [ 'qty' => mt_rand( 1, 4 ), 'refunded_qty' => 0, 'total_minor' => $total, 'taxes_minor' => [ 4 => $A::round_line( $raw, $mode ) ], 'refunded_total_minor' => 0, 'refunded_taxes_minor' => [] ];
    $sum      += $total;
  }
  $order_tax = $per_line ? array_sum( array_map( function ( $r ) use ( $A, $mode ) { return $A::round_line( $r, $mode ); }, $raw_tax ) ) : $A::rounded_sum( $raw_tax );
  $stored    = $per_line ? Xophz_Compass_Bazaar_Core_Money::from_minor( $order_tax, 2 ) : Xophz_Compass_Bazaar_Core_Money::from_minor( array_sum( array_map( function ( $r ) { return Xophz_Compass_Bazaar_Core_Money::to_minor( $r, 6 ); }, $raw_tax ) ), 6 );
  $limit     = $A::rate_limit( $raw_tax, $A::rate_per_line( $stored, $raw_tax, $mode, ! $per_line ), $mode );
  if ( $limit !== $order_tax ) {
    $bad++;
  }
  $order_tot = $sum + $order_tax;
  $refunded  = 0;
  $guard     = 0;
  while ( $guard++ < 40 ) {
    $open = array_keys( array_filter( $eff, function ( $it ) { return $it['qty'] > $it['refunded_qty']; } ) );
    if ( ! $open ) {
      break;
    }
    $id   = $open[ mt_rand( 0, count( $open ) - 1 ) ];
    $take = mt_rand( 1, $eff[ $id ]['qty'] - $eff[ $id ]['refunded_qty'] );
    $done = array_sum( array_map( function ( $it ) { return (int) ( $it['refunded_taxes_minor'][4] ?? 0 ); }, $eff ) );
    try {
      $calc = $T::settle( $eff, $T::compute( $eff, [ [ 'order_item_id' => $id, 'qty' => $take ] ] ), [ 4 => $limit - $done ], $order_tot - $refunded );
    } catch ( Xophz_Compass_Bazaar_Core_Http_Error $e ) {
      $bad++;
      break;
    }
    $line                                 = $calc['lines'][0];
    $refunded                            += $calc['total_minor'];
    $eff[ $id ]['refunded_qty']          += $take;
    $eff[ $id ]['refunded_total_minor']  += $line['refund_total_minor'];
    $eff[ $id ]['refunded_taxes_minor'][4] = ( $eff[ $id ]['refunded_taxes_minor'][4] ?? 0 ) + ( $line['refund_tax'][4] ?? 0 );
  }
  if ( $refunded !== $order_tot ) {
    $bad++;
  }
}
check( 'random unrounded taxes: 400 orders, per line and at subtotal, half up and half down, refunded to the cent', $bad, 0 );

// settle(): the order's remaining amount.
$fail_settle = function ( array $items, array $lines, int $left ) use ( $T ) {
  try {
    $T::settle( $items, $T::compute( $items, $lines ), [], $left );
  } catch ( Xophz_Compass_Bazaar_Core_Http_Error $e ) {
    return [ $e->error_code(), $e->status(), $e->data()['remaining_minor'] ?? null ];
  }
  return null;
};
check( 'order cap: a partial refund above what the order has left is refused', $fail_settle( $sub, [ [ 'order_item_id' => 1, 'qty' => 1 ] ], 900 ), [ 'refund_exceeds_order', 422, 900 ] );
$cap = $T::settle( $sub, $T::compute( $sub, [ [ 'order_item_id' => 1, 'qty' => 1 ], [ 'order_item_id' => 2, 'qty' => 1 ] ] ), [], 2000 );
check( 'order cap: the completing refund takes the remainder, line totals first', [ $cap['total_minor'], $cap['lines'][1]['refund_total_minor'], $cap['lines'][1]['refund_tax'] ], [ 2000, 834, [ 4 => 83 ] ] );

// Random legacy orders with a negative fee, refunded in random steps: the
// refunds add up to the order total exactly and no step is refused.
mt_srand( 6995 );
$bad = 0;
for ( $n = 0; $n < 300; $n++ ) {
  $order_items = [];
  $raw_tax     = [];
  $sum_lines   = 0;
  for ( $i = 1, $count = mt_rand( 1, 4 ); $i <= $count; $i++ ) {
    $total = mt_rand( 1, 20000 );
    $tax   = intdiv( $total * 825 + 5000, 10000 );
    $order_items[ $i ] = [ 'qty' => mt_rand( 1, 5 ), 'refunded_qty' => 0, 'total_minor' => $total, 'taxes_minor' => [ 4 => $tax ], 'refunded_total_minor' => 0, 'refunded_taxes_minor' => [] ];
    $raw_tax[]  = Xophz_Compass_Bazaar_Core_Money::from_minor( $tax, 2 );
    $sum_lines += $total;
  }
  $fee_total  = -mt_rand( 0, $sum_lines );
  $fee_tax    = -intdiv( abs( $fee_total ) * 825 + 5000, 10000 );
  $raw_tax[]  = Xophz_Compass_Bazaar_Core_Money::from_minor( $fee_tax, 2 );
  $order_tax  = Xophz_Compass_Bazaar_Refunds_Amounts::rounded_sum( $raw_tax );
  $order_tot  = $sum_lines + $fee_total + $order_tax;
  $eff        = $T::spread_fees( $order_items, [ [ 'total_minor' => $fee_total, 'taxes_minor' => [ 4 => $fee_tax ] ] ] );
  $refunded   = 0;
  $guard      = 0;
  while ( $guard++ < 40 ) {
    $open = array_keys( array_filter( $eff, function ( $it ) { return $it['qty'] > $it['refunded_qty']; } ) );
    if ( ! $open ) {
      break;
    }
    $id   = $open[ mt_rand( 0, count( $open ) - 1 ) ];
    $take = mt_rand( 1, $eff[ $id ]['qty'] - $eff[ $id ]['refunded_qty'] );
    $done = array_sum( array_map( function ( $it ) { return (int) ( $it['refunded_taxes_minor'][4] ?? 0 ); }, $eff ) );
    try {
      $calc = $T::settle( $eff, $T::compute( $eff, [ [ 'order_item_id' => $id, 'qty' => $take ] ] ), [ 4 => $order_tax - $done ], $order_tot - $refunded );
    } catch ( Xophz_Compass_Bazaar_Core_Http_Error $e ) {
      $bad++;
      break;
    }
    $line = $calc['lines'][0];
    if ( $line['refund_total_minor'] < 0 || ( $line['refund_tax'][4] ?? 0 ) < 0 || $calc['total_minor'] > $order_tot - $refunded ) {
      $bad++;
    }
    $refunded                                  += $calc['total_minor'];
    $eff[ $id ]['refunded_qty']                += $take;
    $eff[ $id ]['refunded_total_minor']        += $line['refund_total_minor'];
    $eff[ $id ]['refunded_taxes_minor'][4]      = ( $eff[ $id ]['refunded_taxes_minor'][4] ?? 0 ) + ( $line['refund_tax'][4] ?? 0 );
  }
  if ( $refunded !== $order_tot ) {
    $bad++;
  }
}
check( 'random legacy orders: 300 orders with a discount fee refunded to the cent, never refused or over', $bad, 0 );

bazaar_test_standalone( __FILE__ );
