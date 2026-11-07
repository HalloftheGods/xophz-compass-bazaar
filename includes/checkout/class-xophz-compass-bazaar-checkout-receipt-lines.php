<?php
/**
 * The money lines of a printed receipt, in minor units, so they add up.
 *
 * Pure PHP: no WordPress or WooCommerce calls.
 *
 * Guaranteed: the amounts of lines() add up to the total, because the order's
 * own total is the last word. When the parts do not reach it (a rounding
 * difference, a fee WooCommerce taxed on its own) one "Adjustment" line shows
 * the difference instead of a receipt that does not add up. Subtotal is the
 * price of the items before any discount (WooCommerce get_subtotal()); a
 * discount is a negative amount. Zero-value lines other than Subtotal and
 * Sales tax are left out.
 *
 * @package Xophz_Compass_Bazaar
 */
class Xophz_Compass_Bazaar_Checkout_Receipt_Lines {

  /**
   * @param array $parts Minor units: subtotal, discount (positive), fees,
   *                     shipping, tax, total. Missing keys count as 0.
   * @return array<int,array{key:string,label:string,minor:int}>
   */
  public static function lines( array $parts ): array {
    $get      = function ( string $key ) use ( $parts ): int {
      return isset( $parts[ $key ] ) ? (int) $parts[ $key ] : 0;
    };
    $subtotal = $get( 'subtotal' );
    $lines    = [ [ 'key' => 'subtotal', 'label' => 'Subtotal', 'minor' => $subtotal ] ];
    $sum      = $subtotal;

    $optional = [
      [ 'discount', 'Discounts', -abs( $get( 'discount' ) ) ],
      [ 'fees', 'Fees', $get( 'fees' ) ],
      [ 'shipping', 'Shipping', $get( 'shipping' ) ],
    ];
    foreach ( $optional as $row ) {
      if ( 0 !== $row[2] ) {
        $lines[] = [ 'key' => $row[0], 'label' => $row[1], 'minor' => $row[2] ];
        $sum    += $row[2];
      }
    }

    $lines[] = [ 'key' => 'tax', 'label' => 'Sales tax', 'minor' => $get( 'tax' ) ];
    $sum    += $get( 'tax' );

    $gap = $get( 'total' ) - $sum;
    if ( 0 !== $gap ) {
      $lines[] = [ 'key' => 'adjustment', 'label' => 'Adjustment', 'minor' => $gap ];
    }
    return $lines;
  }

  /**
   * The lines after the total: what was refunded and what is left. Empty when
   * nothing was refunded.
   *
   * @return array<int,array{key:string,label:string,minor:int}>
   */
  public static function refund_lines( int $total, int $refunded ): array {
    if ( $refunded <= 0 ) {
      return [];
    }
    return [
      [ 'key' => 'refunded', 'label' => 'Refunded', 'minor' => -$refunded ],
      [ 'key' => 'net', 'label' => 'Net after refund', 'minor' => $total - $refunded ],
    ];
  }

  /** A signed minor amount as text: $4.50 or -$4.50. */
  public static function money( int $minor ): string {
    $text = Xophz_Compass_Bazaar_Core_Money::from_minor( abs( $minor ) );
    return ( $minor < 0 ? '-$' : '$' ) . $text;
  }
}
