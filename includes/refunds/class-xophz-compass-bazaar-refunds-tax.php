<?php
/**
 * Pure refund amounts per order line and per tax rate, in integer minor units.
 *
 * Spec: docs/plugins/bazaar-core-v2.md section 12, step 3.
 *
 * For one amount of an order item (its line total, or its tax for one rate r),
 * with Q the item quantity, q the quantity being refunded, `remaining` the
 * quantity not yet refunded and R the amount of that kind refunded so far:
 * - when q equals the remaining quantity, the refund is amount - R, so the
 *   last unit takes whatever rounding left over and the refunds of an item add
 *   up to its amount exactly;
 * - otherwise the refund is half_up(amount * q / Q), halves rounded away from
 *   zero.
 * The line total and every tax rate id of the item follow the same rule; the
 * old handler refunded only the first rate.
 *
 * Two adjustments around compute():
 * - spread_fees(): an order discount stored as a negative fee line (the legacy
 *   writer's "POS Discount" and custom discounts) is spread over the line
 *   items before compute(), the fee total by line total and each rate's fee
 *   tax by that rate's line tax, largest remainder (Core_Money::allocate). A
 *   refunded line then gives back what it cost after the discount.
 * - settle(): after compute(), each tax rate's refund is held to what the
 *   order still has of that rate, and a refund that leaves no unit of any
 *   item carrying the rate takes exactly that remainder (the per-rate form of
 *   the last-unit rule, for line taxes stored unrounded when WooCommerce
 *   rounds at subtotal). The whole refund is then held to the order's
 *   remaining refundable amount: a refund that completes the order takes the
 *   remainder, cut from line totals last line first and then from tax; any
 *   other refund above it is 422 refund_exceeds_order.
 *
 * Guaranteed: an amount of 0 or above is never refunded above what remains
 * (amount - R), and after settle() a refund never exceeds the order's
 * remaining amount. Not guaranteed: intermediate products (amount * q * 2)
 * must fit in a PHP integer, which holds for amounts below about 10^13 minor
 * units and quantities below 10^5. This class reads no order and writes
 * nothing.
 *
 * @package Xophz_Compass_Bazaar
 */
class Xophz_Compass_Bazaar_Refunds_Tax {

  /**
   * One amount's share for q of the remaining units.
   *
   * @param int $amount_minor    The item's whole amount (line total or one rate's tax).
   * @param int $qty_total       Q, the quantity on the order item.
   * @param int $qty_refund      q, the quantity being refunded.
   * @param int $qty_remaining   The quantity not yet refunded.
   * @param int $refunded_minor  R, already refunded for this amount.
   */
  public static function prorate( int $amount_minor, int $qty_total, int $qty_refund, int $qty_remaining, int $refunded_minor ): int {
    if ( $qty_total <= 0 || $qty_refund <= 0 ) {
      return 0;
    }
    if ( $qty_refund >= $qty_remaining ) {
      $share = $amount_minor - $refunded_minor;
    } else {
      $magnitude = intdiv( 2 * abs( $amount_minor ) * $qty_refund + $qty_total, 2 * $qty_total );
      $share     = $amount_minor < 0 ? -$magnitude : $magnitude;
    }
    if ( $amount_minor >= 0 ) {
      $share = max( 0, min( $share, $amount_minor - $refunded_minor ) );
    }
    return $share;
  }

  /**
   * Amounts for a set of lines.
   *
   * @param array $items Keyed by order item id. Each: qty (Q), refunded_qty,
   *                     total_minor (line total without tax), taxes_minor
   *                     (rate id => line tax), refunded_total_minor and
   *                     refunded_taxes_minor (rate id => tax refunded so far).
   * @param array $lines [{order_item_id, qty}].
   * @return array{lines:array[],subtotal_minor:int,tax_minor:int,total_minor:int}
   *         Each line: order_item_id, qty, refund_total_minor, refund_tax (rate id => minor),
   *         refund_tax_minor.
   * @throws Xophz_Compass_Bazaar_Core_Http_Error 422 invalid_line for an unknown item or a quantity above what remains.
   */
  public static function compute( array $items, array $lines ): array {
    $out      = [];
    $subtotal = 0;
    $tax      = 0;
    foreach ( $lines as $line ) {
      $id  = (int) ( $line['order_item_id'] ?? 0 );
      $qty = (int) ( $line['qty'] ?? 0 );
      if ( ! isset( $items[ $id ] ) || $qty <= 0 ) {
        throw self::invalid( $id, 'This order has no such line to refund.' );
      }
      $item      = $items[ $id ];
      $total_qty = (int) $item['qty'];
      $remaining = $total_qty - (int) ( $item['refunded_qty'] ?? 0 );
      if ( $qty > $remaining ) {
        throw self::invalid( $id, 'Only ' . max( 0, $remaining ) . ' of this line can still be refunded.', [ 'remaining_qty' => max( 0, $remaining ) ] );
      }
      $total = self::prorate( (int) $item['total_minor'], $total_qty, $qty, $remaining, (int) ( $item['refunded_total_minor'] ?? 0 ) );
      $rates = [];
      foreach ( (array) ( $item['taxes_minor'] ?? [] ) as $rate_id => $rate_tax ) {
        $done = (int) ( $item['refunded_taxes_minor'][ $rate_id ] ?? 0 );
        $part = self::prorate( (int) $rate_tax, $total_qty, $qty, $remaining, $done );
        if ( 0 !== $part ) {
          $rates[ $rate_id ] = $part;
        }
      }
      $line_tax = array_sum( $rates );
      $out[]    = [
        'order_item_id'      => $id,
        'qty'                => $qty,
        'refund_total_minor' => $total,
        'refund_tax'         => $rates,
        'refund_tax_minor'   => $line_tax,
      ];
      $subtotal += $total;
      $tax      += $line_tax;
    }
    return [ 'lines' => $out, 'subtotal_minor' => $subtotal, 'tax_minor' => $tax, 'total_minor' => $subtotal + $tax ];
  }

  /**
   * Spread negative fee lines over the line items (see the class comment).
   * Positive fees are not spread: fee lines are not refunded.
   *
   * @param array $items compute()'s items.
   * @param array $fees  [{total_minor (below 0), taxes_minor: rate id => minor}].
   * @return array The items with total_minor and taxes_minor lowered by their shares.
   */
  public static function spread_fees( array $items, array $fees ): array {
    $ids = array_keys( $items );
    foreach ( $fees as $fee ) {
      $total = (int) ( $fee['total_minor'] ?? 0 );
      if ( $total >= 0 || ! $ids ) {
        continue;
      }
      $by_total = array_map( function ( $id ) use ( $items ) {
        return max( 0, (int) $items[ $id ]['total_minor'] );
      }, $ids );
      if ( array_sum( $by_total ) <= 0 ) {
        continue;
      }
      $total = max( $total, -array_sum( $by_total ) );
      foreach ( Xophz_Compass_Bazaar_Core_Money::allocate( $total, $by_total ) as $k => $share ) {
        $items[ $ids[ $k ] ]['total_minor'] = (int) $items[ $ids[ $k ] ]['total_minor'] + $share;
      }
      foreach ( (array) ( $fee['taxes_minor'] ?? [] ) as $rate_id => $fee_tax ) {
        $fee_tax = (int) $fee_tax;
        if ( 0 === $fee_tax ) {
          continue;
        }
        $by_rate = array_map( function ( $id ) use ( $items, $rate_id ) {
          return max( 0, (int) ( $items[ $id ]['taxes_minor'][ $rate_id ] ?? 0 ) );
        }, $ids );
        $weights = array_sum( $by_rate ) > 0 ? $by_rate : $by_total;
        if ( $fee_tax < 0 && array_sum( $by_rate ) > 0 ) {
          $fee_tax = max( $fee_tax, -array_sum( $by_rate ) );
        }
        foreach ( Xophz_Compass_Bazaar_Core_Money::allocate( $fee_tax, $weights ) as $k => $share ) {
          if ( 0 !== $share ) {
            $items[ $ids[ $k ] ]['taxes_minor'][ $rate_id ] = (int) ( $items[ $ids[ $k ] ]['taxes_minor'][ $rate_id ] ?? 0 ) + $share;
          }
        }
      }
    }
    return $items;
  }

  /**
   * Hold compute()'s result to the order's per-rate and total limits (see the
   * class comment).
   *
   * @param array $items     The items compute() ran on.
   * @param array $calc      compute()'s output.
   * @param array $rate_left Rate id => tax of that rate the order's line items
   *                         still hold (their tax summed and rounded once, less
   *                         what was refunded). Rates not listed are left alone.
   * @param int   $order_left The order's remaining refundable amount.
   * @return array compute()'s shape.
   * @throws Xophz_Compass_Bazaar_Core_Http_Error 422 refund_exceeds_order.
   */
  public static function settle( array $items, array $calc, array $rate_left, int $order_left ): array {
    $taking = [];
    foreach ( $calc['lines'] as $line ) {
      $taking[ $line['order_item_id'] ] = ( $taking[ $line['order_item_id'] ] ?? 0 ) + (int) $line['qty'];
    }
    $after = [];
    foreach ( $items as $id => $item ) {
      $after[ $id ] = (int) $item['qty'] - (int) ( $item['refunded_qty'] ?? 0 ) - ( $taking[ $id ] ?? 0 );
    }
    $lines = $calc['lines'];
    foreach ( $rate_left as $rate_id => $left ) {
      $carriers = array_filter( array_keys( $items ), function ( $id ) use ( $items, $rate_id ) {
        return 0 !== (int) ( $items[ $id ]['taxes_minor'][ $rate_id ] ?? 0 );
      } );
      $mine = array_keys( array_filter( $lines, function ( $line ) use ( $carriers ) {
        return in_array( $line['order_item_id'], $carriers, true );
      } ) );
      if ( ! $mine ) {
        continue;
      }
      $current  = array_sum( array_map( function ( $k ) use ( $lines, $rate_id ) {
        return (int) ( $lines[ $k ]['refund_tax'][ $rate_id ] ?? 0 );
      }, $mine ) );
      $complete = ! array_filter( $carriers, function ( $id ) use ( $after ) {
        return $after[ $id ] > 0;
      } );
      $target   = $complete ? max( 0, (int) $left ) : min( $current, max( 0, (int) $left ) );
      $delta    = $target - $current;
      for ( $n = count( $mine ) - 1; $n >= 0 && 0 !== $delta; $n-- ) {
        $k    = $mine[ $n ];
        $have = (int) ( $lines[ $k ]['refund_tax'][ $rate_id ] ?? 0 );
        $move = $delta > 0 ? $delta : max( $delta, -$have );
        $lines[ $k ]['refund_tax'][ $rate_id ] = $have + $move;
        $delta -= $move;
      }
    }
    $calc = self::totals( $lines );
    $over = $calc['total_minor'] - max( 0, $order_left );
    if ( $over <= 0 ) {
      return $calc;
    }
    if ( array_filter( $after, function ( $q ) { return $q > 0; } ) ) {
      throw new Xophz_Compass_Bazaar_Core_Http_Error( 'refund_exceeds_order', 'This refund is more than the order still has to refund.', 422, [ 'refund_minor' => $calc['total_minor'], 'remaining_minor' => max( 0, $order_left ) ] );
    }
    for ( $k = count( $lines ) - 1; $k >= 0 && $over > 0; $k-- ) {
      $cut = min( $over, max( 0, (int) $lines[ $k ]['refund_total_minor'] ) );
      $lines[ $k ]['refund_total_minor'] -= $cut;
      $over -= $cut;
    }
    for ( $k = count( $lines ) - 1; $k >= 0 && $over > 0; $k-- ) {
      foreach ( array_reverse( array_keys( $lines[ $k ]['refund_tax'] ), true ) as $rate_id ) {
        $cut = min( $over, max( 0, (int) $lines[ $k ]['refund_tax'][ $rate_id ] ) );
        $lines[ $k ]['refund_tax'][ $rate_id ] -= $cut;
        $over -= $cut;
      }
    }
    return self::totals( $lines );
  }

  /** compute()'s shape from lines whose amounts changed; zero rates are dropped. */
  private static function totals( array $lines ): array {
    $subtotal = 0;
    $tax      = 0;
    foreach ( $lines as $k => $line ) {
      $rates = array_filter( (array) $line['refund_tax'], function ( $minor ) {
        return 0 !== (int) $minor;
      } );
      $lines[ $k ]['refund_tax']       = $rates;
      $lines[ $k ]['refund_tax_minor'] = array_sum( $rates );
      $subtotal                       += (int) $line['refund_total_minor'];
      $tax                            += $lines[ $k ]['refund_tax_minor'];
    }
    return [ 'lines' => array_values( $lines ), 'subtotal_minor' => $subtotal, 'tax_minor' => $tax, 'total_minor' => $subtotal + $tax ];
  }

  private static function invalid( int $order_item_id, string $message, array $data = [] ): Xophz_Compass_Bazaar_Core_Http_Error {
    return new Xophz_Compass_Bazaar_Core_Http_Error( 'invalid_line', $message, 422, array_merge( [ 'order_item_id' => $order_item_id ], $data ) );
  }
}
