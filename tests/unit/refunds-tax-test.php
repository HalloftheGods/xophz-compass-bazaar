<?php
/**
 * Refunds_Tax: half-up proration, the last unit taking the remainder, every
 * tax rate of a line, and exact sums over random partial-refund sequences.
 */
require_once dirname( __DIR__ ) . '/support/harness.php';
$plugin = dirname( dirname( __DIR__ ) );
require_once $plugin . '/includes/core/class-xophz-compass-bazaar-core-http-error.php';
require_once $plugin . '/includes/refunds/class-xophz-compass-bazaar-refunds-tax.php';

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
