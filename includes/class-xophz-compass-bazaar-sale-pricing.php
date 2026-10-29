<?php

/**
 * Pure sale-price math for bulk promotions.
 *
 * Kept free of WordPress and WooCommerce calls so the rules can be tested
 * with plain PHP (see tests/sale-pricing.test.php).
 *
 * @package    Xophz_Compass_Bazaar
 * @subpackage Xophz_Compass_Bazaar/includes
 */
class Xophz_Compass_Bazaar_Sale_Pricing {

  const MODE_FIXED   = 'fixed';
  const MODE_PERCENT = 'percent';

  /**
   * Work out the sale price for one product or variation.
   *
   * Returns null when no sale should be set: the item has no regular price,
   * the input is out of range, or the result would not be below the regular
   * price (WooCommerce ignores a sale price that is not lower).
   *
   * @param mixed  $regular  Regular price as stored by WooCommerce ('' when unset).
   * @param string $mode     'fixed' (value is the sale price) or 'percent' (value is percent off).
   * @param mixed  $value    Sale price or percent.
   * @param int    $decimals Store price decimals.
   * @return float|null
   */
  public static function compute( $regular, string $mode, $value, int $decimals = 2 ): ?float {
    if ( '' === $regular || null === $regular || ! is_numeric( $regular ) || ! is_numeric( $value ) ) {
      return null;
    }

    $regular = (float) $regular;
    $value   = (float) $value;
    if ( $regular <= 0 ) {
      return null;
    }

    if ( self::MODE_PERCENT === $mode ) {
      if ( $value <= 0 || $value >= 100 ) {
        return null;
      }
      $sale = round( $regular * ( 1 - $value / 100 ), $decimals );
    } elseif ( self::MODE_FIXED === $mode ) {
      if ( $value < 0 ) {
        return null;
      }
      $sale = round( $value, $decimals );
    } else {
      return null;
    }

    return $sale < $regular ? $sale : null;
  }

  /**
   * Normalize optional schedule dates to WooCommerce's stored form.
   *
   * Matches WooCommerce's own product editor: the sale starts at 00:00:00 on
   * the from date and ends at 23:59:59 on the to date.
   *
   * @param string $from 'Y-m-d' or ''.
   * @param string $to   'Y-m-d' or ''.
   * @return array{from: string, to: string}|null Null when a date is malformed or to is before from.
   */
  public static function normalize_dates( string $from, string $to ): ?array {
    $from = trim( $from );
    $to   = trim( $to );

    foreach ( [ $from, $to ] as $date ) {
      if ( '' !== $date && ! self::is_ymd( $date ) ) {
        return null;
      }
    }

    if ( '' !== $from && '' !== $to && $to < $from ) {
      return null;
    }

    return [
      'from' => '' === $from ? '' : $from . ' 00:00:00',
      'to'   => '' === $to ? '' : $to . ' 23:59:59',
    ];
  }

  /**
   * Clean a posted id list: positive ints, no duplicates, capped at $max.
   *
   * @param mixed $ids Array or JSON string of ids.
   * @param int   $max Most ids accepted in one request.
   * @return int[]
   */
  public static function clean_ids( $ids, int $max ): array {
    if ( is_string( $ids ) ) {
      $ids = json_decode( $ids, true );
    }
    if ( ! is_array( $ids ) ) {
      return [];
    }
    $clean = array_values( array_unique( array_filter( array_map( 'intval', $ids ), function ( $id ) {
      return $id > 0;
    } ) ) );
    return array_slice( $clean, 0, max( 1, $max ) );
  }

  private static function is_ymd( string $date ): bool {
    if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m ) ) {
      return false;
    }
    return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] );
  }
}
