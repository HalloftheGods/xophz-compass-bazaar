<?php
/**
 * Custom (register) discounts as line-level reductions. Pure: no WordPress.
 *
 * Spec: docs/plugins/bazaar-core-v2.md section 9.2 step 3.
 *
 * Discounts apply one after another, each to what is left after the coupons
 * and the discounts before it, so together they never exceed the cart.
 * - percent: Core_Money::percent_bp(base, value_bp), half-up.
 * - fixed:   amount_minor, capped at the base.
 * Each amount is split across the lines by largest remainder in proportion
 * to what is left on each line (Core_Money::allocate), so the parts sum
 * exactly and no line goes below zero.
 *
 * All amounts are integers in minor units, in the store's price basis
 * (tax-inclusive when the store enters prices with tax).
 *
 * @package Xophz_Compass_Bazaar
 */
final class Xophz_Compass_Bazaar_Pricing_Discount_Math {

  /**
   * The amount one discount takes from $base.
   *
   * @param array $discount {type: percent, value_bp} or {type: fixed, amount_minor}.
   * @throws InvalidArgumentException For an unknown type or a negative value.
   */
  public static function amount( array $discount, int $base ): int {
    if ( $base <= 0 ) {
      return 0;
    }
    $type = $discount['type'] ?? '';
    if ( 'percent' === $type ) {
      $bp = (int) ( $discount['value_bp'] ?? 0 );
      if ( $bp < 0 ) {
        throw new InvalidArgumentException( 'Basis points must not be negative.' );
      }
      return min( $base, Xophz_Compass_Bazaar_Core_Money::percent_bp( $base, $bp ) );
    }
    if ( 'fixed' === $type ) {
      $amount = (int) ( $discount['amount_minor'] ?? 0 );
      if ( $amount < 0 ) {
        throw new InvalidArgumentException( 'A fixed discount must not be negative.' );
      }
      return min( $base, $amount );
    }
    throw new InvalidArgumentException( 'Unknown discount type.' );
  }

  /**
   * Split $amount over $weights by largest remainder. All zeros when the
   * amount or the weights are zero.
   *
   * @param int[] $weights Non-negative integers, keyed as the result.
   * @return int[] Same keys as $weights.
   */
  public static function allocate( int $amount, array $weights ): array {
    $keys = array_keys( $weights );
    if ( $amount <= 0 || array_sum( $weights ) <= 0 ) {
      return array_fill_keys( $keys, 0 );
    }
    $parts = Xophz_Compass_Bazaar_Core_Money::allocate( $amount, array_values( $weights ) );
    return array_combine( $keys, $parts );
  }

  /**
   * Apply every discount in order.
   *
   * @param array[] $discounts  Normalized discounts (Pricing_Cart_Request::$discounts).
   * @param int[]   $remaining  Line key => amount left on the line before these discounts.
   * @return array{base_minor:int,amount_minor:int,by_line:int[],remaining:int[],discounts:array[]}
   *   base_minor is the total before the discounts; each entry of discounts is
   *   the input discount plus amount_minor and by_line.
   */
  public static function apply_all( array $discounts, array $remaining ): array {
    foreach ( $remaining as $key => $value ) {
      if ( ! is_int( $value ) || $value < 0 ) {
        throw new InvalidArgumentException( 'Line amounts must be non-negative integers.' );
      }
    }
    $base    = array_sum( $remaining );
    $by_line = array_fill_keys( array_keys( $remaining ), 0 );
    $applied = [];
    foreach ( $discounts as $discount ) {
      $amount = self::amount( $discount, array_sum( $remaining ) );
      $split  = self::allocate( $amount, $remaining );
      foreach ( $split as $key => $part ) {
        $remaining[ $key ] -= $part;
        $by_line[ $key ]   += $part;
      }
      $applied[] = array_merge( $discount, [ 'amount_minor' => $amount, 'by_line' => $split ] );
    }
    return [
      'base_minor'   => $base,
      'amount_minor' => array_sum( $by_line ),
      'by_line'      => $by_line,
      'remaining'    => $remaining,
      'discounts'    => $applied,
    ];
  }
}
