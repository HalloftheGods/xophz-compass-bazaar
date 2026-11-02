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
 * 3. settle() with, per rate, the line items' and negative fees' tax built the
 *    way WooCommerce built the order's tax for that rate, less what was
 *    refunded, and with the order's remaining refundable amount.
 *
 * WooCommerce builds a rate's order tax (update_taxes) from every line item
 * and fee: with woocommerce_tax_round_at_subtotal 'no' (its default) each
 * item's tax is rounded on its own (round_line_tax, wc_get_tax_rounding_mode)
 * and the rounded amounts are summed; with 'yes' the unrounded amounts are
 * summed and rounded once. Which one an order used is read from the order's
 * own tax item (rate_per_line()), so a later change of the option does not
 * change what a past sale refunds; the option is the fallback only when the
 * order has no tax item for the rate.
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
    $mode  = function_exists( 'wc_get_tax_rounding_mode' ) ? (int) wc_get_tax_rounding_mode() : PHP_ROUND_HALF_UP;
    $items = [];
    $raw   = [];
    $all   = [];
    foreach ( $order->get_items() as $item_id => $item ) {
      $taxes = (array) ( $item->get_taxes()['total'] ?? [] );
      $rates = [];
      $done  = [];
      foreach ( $taxes as $rate_id => $amount ) {
        $rates[ $rate_id ] = self::round_line( $amount, $mode );
        $done[ $rate_id ]  = Xophz_Compass_Bazaar_Core_Money::to_minor( abs( (float) $order->get_tax_refunded_for_item( $item_id, $rate_id ) ) );
        $raw[ $rate_id ][] = $amount;
        $all[ $rate_id ][] = $amount;
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
      $total     = Xophz_Compass_Bazaar_Core_Money::to_minor( (string) $fee->get_total() );
      $fee_taxes = [];
      foreach ( (array) ( $fee->get_taxes()['total'] ?? [] ) as $rate_id => $amount ) {
        $all[ $rate_id ][] = $amount;
        if ( $total < 0 ) {
          $fee_taxes[ $rate_id ] = self::round_line( $amount, $mode );
          $raw[ $rate_id ][]     = $amount;
        }
      }
      if ( $total < 0 ) {
        $fees[] = [ 'total_minor' => $total, 'taxes_minor' => $fee_taxes ];
      }
    }
    $stored = [];
    foreach ( $order->get_items( 'tax' ) as $tax_item ) {
      $stored[ (int) $tax_item->get_rate_id() ] = (string) $tax_item->get_tax_total();
    }
    $option    = function_exists( 'get_option' ) && 'yes' === get_option( 'woocommerce_tax_round_at_subtotal' );
    $items     = Xophz_Compass_Bazaar_Refunds_Tax::spread_fees( $items, $fees );
    $calc      = Xophz_Compass_Bazaar_Refunds_Tax::compute( $items, $lines );
    $rate_left = [];
    foreach ( $raw as $rate_id => $amounts ) {
      $refunded = 0;
      foreach ( $items as $item ) {
        $refunded += (int) ( $item['refunded_taxes_minor'][ $rate_id ] ?? 0 );
      }
      $per_line              = self::rate_per_line( $stored[ (int) $rate_id ] ?? null, $all[ $rate_id ] ?? $amounts, $mode, ! $option );
      $rate_left[ $rate_id ] = self::rate_limit( $amounts, $per_line, $mode ) - $refunded;
    }
    $calc = Xophz_Compass_Bazaar_Refunds_Tax::settle( $items, $calc, $rate_left, Xophz_Compass_Bazaar_Core_Money::to_minor( (string) $order->get_remaining_refund_amount() ) );
    if ( $calc['total_minor'] <= 0 ) {
      throw new Xophz_Compass_Bazaar_Core_Http_Error( 'refund_empty', 'These lines add up to nothing to refund.', 422 );
    }
    return $calc;
  }

  /**
   * One stored line or fee tax in minor units, rounded the way WooCommerce's
   * round_line_tax() rounds it: half up, or half down (on the magnitude) when
   * the tax rounding mode is PHP_ROUND_HALF_DOWN. '' is 0.
   */
  public static function round_line( $amount, int $mode = PHP_ROUND_HALF_UP ): int {
    if ( '' === (string) $amount ) {
      return 0;
    }
    if ( PHP_ROUND_HALF_DOWN !== $mode ) {
      return Xophz_Compass_Bazaar_Core_Money::to_minor( is_float( $amount ) ? $amount : (string) $amount );
    }
    $extra = self::fine_decimals() - self::base_decimals();
    $fine  = Xophz_Compass_Bazaar_Core_Money::to_minor( is_float( $amount ) ? $amount : (string) $amount, self::fine_decimals() );
    $unit  = 10 ** $extra;
    $abs   = abs( $fine );
    $whole = intdiv( $abs, $unit );
    $rest  = $abs % $unit;
    if ( $rest * 2 > $unit ) {
      $whole++;
    }
    return $fine < 0 ? -$whole : $whole;
  }

  /**
   * Whether WooCommerce built this rate's order tax by rounding each item on
   * its own. $stored is the order tax item's tax_total (null when the order
   * has none for the rate, then $fallback decides); $all is every line item's
   * and fee's tax of the rate, tips included, as update_taxes() summed them.
   * Per line when the stored total equals the sum of the rounded amounts at
   * full stored precision; when the amounts were already rounded both rules
   * give the same limit.
   */
  public static function rate_per_line( ?string $stored, array $all, int $mode, bool $fallback ): bool {
    if ( null === $stored || '' === $stored ) {
      return $fallback;
    }
    $unit = 10 ** ( self::fine_decimals() - self::base_decimals() );
    $sum  = 0;
    foreach ( $all as $amount ) {
      $sum += self::round_line( $amount, $mode );
    }
    return Xophz_Compass_Bazaar_Core_Money::to_minor( $stored, self::fine_decimals() ) === $sum * $unit;
  }

  /** The refundable tax of one rate: the line items' and negative fees' tax, rounded as the order rounded it. */
  public static function rate_limit( array $amounts, bool $per_line, int $mode = PHP_ROUND_HALF_UP ): int {
    if ( ! $per_line ) {
      return self::rounded_sum( $amounts );
    }
    $sum = 0;
    foreach ( $amounts as $amount ) {
      $sum += self::round_line( $amount, $mode );
    }
    return $sum;
  }

  /** Decimal amounts summed at full stored precision, then rounded once to minor units. */
  public static function rounded_sum( array $amounts ): int {
    $fine = 0;
    foreach ( $amounts as $amount ) {
      $fine += '' === (string) $amount ? 0 : Xophz_Compass_Bazaar_Core_Money::to_minor( (string) $amount, self::fine_decimals() );
    }
    return Xophz_Compass_Bazaar_Core_Money::to_minor( Xophz_Compass_Bazaar_Core_Money::from_minor( $fine, self::fine_decimals() ), self::base_decimals() );
  }

  private static function base_decimals(): int {
    return function_exists( 'wc_get_price_decimals' ) ? (int) wc_get_price_decimals() : 2;
  }

  private static function fine_decimals(): int {
    return min( 8, self::base_decimals() + self::SUM_EXTRA_DECIMALS );
  }
}
