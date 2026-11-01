<?php
/**
 * Integer minor-unit money helpers. Every API amount is an integer in minor
 * units; decimal strings exist only at the WooCommerce edge.
 *
 * Rounding is half away from zero ("half-up" for positive amounts). Anything
 * that does not fit a PHP integer throws; nothing is silently clamped.
 *
 * @package Xophz_Compass_Bazaar
 */
class Xophz_Compass_Bazaar_Core_Money {

  /**
   * Decimal amount to minor units. Numeric strings and ints are exact; floats
   * are first written with 10 decimals, then rounded half-up.
   *
   * @param int|float|string $amount
   * @param int|null         $decimals Default: the WooCommerce price decimals when available, else 2.
   * @throws InvalidArgumentException For non-numeric input or a result outside the integer range.
   */
  public static function to_minor( $amount, ?int $decimals = null ): int {
    $decimals = self::decimals( $decimals );
    if ( is_int( $amount ) ) {
      $text = (string) $amount;
    } elseif ( is_float( $amount ) ) {
      if ( ! is_finite( $amount ) ) {
        throw new InvalidArgumentException( 'Amount is not a finite number.' );
      }
      $text = sprintf( '%.10F', $amount );
    } elseif ( is_string( $amount ) ) {
      $text = trim( $amount );
    } else {
      throw new InvalidArgumentException( 'Amount must be a number or a numeric string.' );
    }
    if ( ! preg_match( '/^([+-]?)(\d+)(?:\.(\d*))?$/', $text, $m ) ) {
      throw new InvalidArgumentException( 'Amount is not a plain decimal number.' );
    }
    $negative = '-' === $m[1];
    $whole    = $m[2];
    $fraction = $m[3] ?? '';
    $digits   = ltrim( $whole . str_pad( substr( $fraction, 0, $decimals ), $decimals, '0' ), '0' );
    $round_up = strlen( $fraction ) > $decimals && (int) $fraction[ $decimals ] >= 5;
    $digits   = '' === $digits ? '0' : $digits;
    if ( $round_up ) {
      $digits = self::increment( $digits );
    }
    if ( strlen( $digits ) > 19 || (string) (int) $digits !== $digits ) {
      throw new InvalidArgumentException( 'Amount is outside the supported range.' );
    }
    $minor = (int) $digits;
    return $negative ? -$minor : $minor;
  }

  /** Minor units to a plain decimal string, for example 1050 -> '10.50'. */
  public static function from_minor( int $minor, ?int $decimals = null ): string {
    $decimals = self::decimals( $decimals );
    $sign     = $minor < 0 ? '-' : '';
    $digits   = PHP_INT_MIN === $minor ? substr( (string) $minor, 1 ) : (string) abs( $minor );
    if ( 0 === $decimals ) {
      return $sign . $digits;
    }
    $digits = str_pad( $digits, $decimals + 1, '0', STR_PAD_LEFT );
    return $sign . substr( $digits, 0, -$decimals ) . '.' . substr( $digits, -$decimals );
  }

  /**
   * Split $total across $weights by the largest-remainder method. Ties go to
   * the lower index. The parts always sum to $total.
   *
   * @param int[] $weights Non-negative integers with a positive sum.
   * @return int[] One part per weight, same keys order.
   * @throws InvalidArgumentException For empty, negative or all-zero weights, or on integer overflow.
   */
  public static function allocate( int $total, array $weights ): array {
    $weights = array_values( $weights );
    if ( ! $weights ) {
      throw new InvalidArgumentException( 'Weights are empty.' );
    }
    $sum = 0;
    $max = 0;
    foreach ( $weights as $weight ) {
      if ( ! is_int( $weight ) || $weight < 0 ) {
        throw new InvalidArgumentException( 'Weights must be non-negative integers.' );
      }
      $sum += $weight;
      $max  = max( $max, $weight );
      if ( ! is_int( $sum ) ) {
        throw new InvalidArgumentException( 'Weights overflow.' );
      }
    }
    if ( $sum <= 0 ) {
      throw new InvalidArgumentException( 'Weights must have a positive sum.' );
    }
    $abs = abs( $total );
    if ( $max > 0 && $abs > intdiv( PHP_INT_MAX, $max ) ) {
      throw new InvalidArgumentException( 'Total times weight overflows.' );
    }
    $parts      = [];
    $remainders = [];
    $given      = 0;
    foreach ( $weights as $i => $weight ) {
      $product          = $abs * $weight;
      $parts[ $i ]      = intdiv( $product, $sum );
      $remainders[ $i ] = $product - $parts[ $i ] * $sum;
      $given           += $parts[ $i ];
    }
    $order = array_keys( $weights );
    usort( $order, function ( $a, $b ) use ( $remainders ) {
      return $remainders[ $b ] <=> $remainders[ $a ] ?: $a <=> $b;
    } );
    for ( $left = $abs - $given, $k = 0; $left > 0; $left--, $k++ ) {
      $parts[ $order[ $k ] ]++;
    }
    return $total < 0 ? array_map( function ( $p ) { return -$p; }, $parts ) : $parts;
  }

  /** $base * $bp / 10000, half away from zero. 1 basis point = 0.01 percent. */
  public static function percent_bp( int $base, int $bp ): int {
    $abs = abs( $base );
    $bpa = abs( $bp );
    if ( $bpa > 0 && $abs > intdiv( PHP_INT_MAX - 5000, $bpa ) ) {
      throw new InvalidArgumentException( 'Base times basis points overflows.' );
    }
    $value = intdiv( $abs * $bpa + 5000, 10000 );
    return ( $base < 0 ) !== ( $bp < 0 ) ? -$value : $value;
  }

  private static function decimals( ?int $decimals ): int {
    if ( null === $decimals ) {
      $decimals = function_exists( 'wc_get_price_decimals' ) ? (int) wc_get_price_decimals() : 2;
    }
    if ( $decimals < 0 || $decimals > 8 ) {
      throw new InvalidArgumentException( 'Decimals must be between 0 and 8.' );
    }
    return $decimals;
  }

  /** Add one to a string of decimal digits. */
  private static function increment( string $digits ): string {
    $i = strlen( $digits ) - 1;
    while ( $i >= 0 ) {
      if ( '9' !== $digits[ $i ] ) {
        $digits[ $i ] = (string) ( (int) $digits[ $i ] + 1 );
        return $digits;
      }
      $digits[ $i ] = '0';
      $i--;
    }
    return '1' . $digits;
  }
}
