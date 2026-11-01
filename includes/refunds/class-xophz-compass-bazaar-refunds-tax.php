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
 * Guaranteed: an amount of 0 or above is never refunded above what remains
 * (amount - R). Not guaranteed: intermediate products (amount * q * 2) must fit
 * in a PHP integer, which holds for amounts below about 10^13 minor units and
 * quantities below 10^5. This class reads no order and writes nothing.
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

  private static function invalid( int $order_item_id, string $message, array $data = [] ): Xophz_Compass_Bazaar_Core_Http_Error {
    return new Xophz_Compass_Bazaar_Core_Http_Error( 'invalid_line', $message, 422, array_merge( [ 'order_item_id' => $order_item_id ], $data ) );
  }
}
