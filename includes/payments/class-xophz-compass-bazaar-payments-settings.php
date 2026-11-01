<?php
/**
 * Payment settings read by providers.
 *
 * @package Xophz_Compass_Bazaar
 */
class Xophz_Compass_Bazaar_Payments_Settings {

  const REFERENCE_PATTERN = '/^[A-Za-z0-9-]{4,64}\z/';

  /**
   * Whether an external-terminal sale must carry a slip reference. On unless
   * the option bazaar_require_terminal_reference is set to a false value
   * (0, "0", "no", "false", "off"). The filter
   * xophz_compass_bazaar_require_terminal_reference has the last word.
   */
  public static function require_terminal_reference(): bool {
    $value = function_exists( 'get_option' ) ? get_option( 'bazaar_require_terminal_reference', true ) : true;
    if ( is_string( $value ) ) {
      $value = strtolower( trim( $value ) );
      $on    = ! in_array( $value, [ '0', 'no', 'false', 'off' ], true );
    } else {
      $on = null === $value || (bool) $value;
    }
    return (bool) apply_filters( 'xophz_compass_bazaar_require_terminal_reference', $on );
  }

  /** 4 to 64 letters, digits or hyphens. */
  public static function is_valid_reference( string $reference ): bool {
    return 1 === preg_match( self::REFERENCE_PATTERN, $reference );
  }
}
