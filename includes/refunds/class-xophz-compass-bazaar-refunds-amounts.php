<?php
/**
 * Refund amounts of one request, read from the WooCommerce order.
 *
 * Spec: docs/plugins/bazaar-core-v2.md section 12, steps 2 and 3. Reads each
 * line item's quantity, total and tax per rate and what was refunded of them,
 * then runs the pure steps of Refunds_Tax:
 * 1. spread_fees() with the order's negative fee lines (order discounts the
 *    legacy writer stores as fees), so a line refunds its discounted price;
 * 2. compute() per line and rate;
 * 3. settle() with, per rate, the line items' and negative fees' tax summed
 *    unrounded and rounded once (what WooCommerce records on the order when it
 *    rounds at subtotal) less what was refunded, and with the order's
 *    remaining refundable amount.
 *
 * Reads only; writes nothing.
 *
 * @package Xophz_Compass_Bazaar
 */
class Xophz_Compass_Bazaar_Refunds_Amounts {

  /** Extra decimals kept while summing unrounded line taxes. */
  const SUM_EXTRA_DECIMALS = 4;

  /**
   * @param array $lines [{order_item_id, qty}].
   * @return array Refunds_Tax::compute() output.
   * @throws Xophz_Compass_Bazaar_Core_Http_Error 422 invalid_line, refund_empty, refund_exceeds_order.
   */
  public static function for_lines( WC_Order $order, array $lines ): array {
    $items = [];
    $raw   = [];
    foreach ( $order->get_items() as $item_id => $item ) {
      $taxes = (array) ( $item->get_taxes()['total'] ?? [] );
      $rates = [];
      $done  = [];
      foreach ( $taxes as $rate_id => $amount ) {
        $rates[ $rate_id ] = self::minor( $amount );
        $done[ $rate_id ]  = Xophz_Compass_Bazaar_Core_Money::to_minor( abs( (float) $order->get_tax_refunded_for_item( $item_id, $rate_id ) ) );
        $raw[ $rate_id ][] = $amount;
      }
      $items[ (int) $item_id ] = [
        'qty'                  => (int) $item->get_quantity(),
        'refunded_qty'         => absint( $order->get_qty_refunded_for_item( $item_id ) ),
        'total_minor'          => Xophz_Compass_Bazaar_Core_Money::to_minor( (string) $item->get_total() ),
        'taxes_minor'          => $rates,
        'refunded_total_minor' => Xophz_Compass_Bazaar_Core_Money::to_minor( abs( (float) $order->get_total_refunded_for_item( $item_id ) ) ),
        'refunded_taxes_minor' => $done,
      ];
    }
    $fees = [];
    foreach ( $order->get_fees() as $fee ) {
      $total = Xophz_Compass_Bazaar_Core_Money::to_minor( (string) $fee->get_total() );
      if ( $total >= 0 ) {
        continue;
      }
      $fee_taxes = [];
      foreach ( (array) ( $fee->get_taxes()['total'] ?? [] ) as $rate_id => $amount ) {
        $fee_taxes[ $rate_id ] = self::minor( $amount );
        $raw[ $rate_id ][]     = $amount;
      }
      $fees[] = [ 'total_minor' => $total, 'taxes_minor' => $fee_taxes ];
    }
    $items     = Xophz_Compass_Bazaar_Refunds_Tax::spread_fees( $items, $fees );
    $calc      = Xophz_Compass_Bazaar_Refunds_Tax::compute( $items, $lines );
    $rate_left = [];
    foreach ( $raw as $rate_id => $amounts ) {
      $refunded = 0;
      foreach ( $items as $item ) {
        $refunded += (int) ( $item['refunded_taxes_minor'][ $rate_id ] ?? 0 );
      }
      $rate_left[ $rate_id ] = self::rounded_sum( $amounts ) - $refunded;
    }
    $calc = Xophz_Compass_Bazaar_Refunds_Tax::settle( $items, $calc, $rate_left, Xophz_Compass_Bazaar_Core_Money::to_minor( (string) $order->get_remaining_refund_amount() ) );
    if ( $calc['total_minor'] <= 0 ) {
      throw new Xophz_Compass_Bazaar_Core_Http_Error( 'refund_empty', 'These lines add up to nothing to refund.', 422 );
    }
    return $calc;
  }

  /** One stored tax amount in minor units ('' is 0). */
  private static function minor( $amount ): int {
    return '' === (string) $amount ? 0 : Xophz_Compass_Bazaar_Core_Money::to_minor( (string) $amount );
  }

  /** Decimal amounts summed at full stored precision, then rounded once to minor units. */
  public static function rounded_sum( array $amounts ): int {
    $base  = function_exists( 'wc_get_price_decimals' ) ? (int) wc_get_price_decimals() : 2;
    $extra = min( 8, $base + self::SUM_EXTRA_DECIMALS );
    $fine  = 0;
    foreach ( $amounts as $amount ) {
      $fine += '' === (string) $amount ? 0 : Xophz_Compass_Bazaar_Core_Money::to_minor( (string) $amount, $extra );
    }
    return Xophz_Compass_Bazaar_Core_Money::to_minor( Xophz_Compass_Bazaar_Core_Money::from_minor( $fine, $extra ), $base );
  }
}
