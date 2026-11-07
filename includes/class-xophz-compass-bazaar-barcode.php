<?php
/**
 * Barcode helpers for Quick Add: digits, GS1 check digits, UPC-E expansion
 * and the candidate forms a scanned code may be stored under.
 *
 * Pure PHP: no WordPress or WooCommerce calls, so tests/unit can load it alone.
 *
 * Guarantees:
 * - normalize() never throws and always returns the keys raw, digits,
 *   normalized, valid, kind and candidates.
 * - valid is true only for an 8, 12, 13 or 14 digit code whose GS1 check
 *   digit matches (an 8 digit code also counts when it is a valid UPC-E).
 * - candidates lists the code as typed first, then the digit forms; no
 *   duplicates and no empty strings. It is meant for exact SKU matches only.
 * - A code with anything other than digits, spaces and dashes (a house
 *   label such as WD-00042) is never valid, has an empty normalized form
 *   and has only its as-typed form as a candidate.
 *
 * Not guaranteed: a valid check digit does not prove the code was issued; an
 * 8 digit code that is both a valid EAN-8 and a valid UPC-E keeps its 8 digit
 * form as normalized and lists both readings among the candidates.
 *
 * @package Xophz_Compass_Bazaar
 */
class Xophz_Compass_Bazaar_Barcode {

  /** Longest raw string kept (a scanner never sends more for a product code). */
  const MAX_RAW = 64;

  /** Lengths that carry a GS1 check digit. */
  const GTIN_LENGTHS = [ 8, 12, 13, 14 ];

  /** The raw input with control characters removed, trimmed, at most MAX_RAW characters. */
  public static function clean_raw( $raw ): string {
    if ( ! is_scalar( $raw ) ) {
      return '';
    }
    $clean = (string) preg_replace( '/[\x00-\x1F\x7F]/', '', (string) $raw );
    return substr( trim( $clean ), 0, self::MAX_RAW );
  }

  /** Only the digits 0-9 of the input. */
  public static function digits( $raw ): string {
    return (string) preg_replace( '/\D/', '', self::clean_raw( $raw ) );
  }

  /**
   * The GS1 check digit for a body (the code without its last digit).
   * Weights 3,1,3,1... from the right-most body digit.
   */
  public static function check_digit( string $body ): int {
    if ( '' === $body || ! ctype_digit( $body ) ) {
      return -1;
    }
    $sum    = 0;
    $weight = 3;
    for ( $i = strlen( $body ) - 1; $i >= 0; $i-- ) {
      $sum   += (int) $body[ $i ] * $weight;
      $weight = 3 === $weight ? 1 : 3;
    }
    return ( 10 - ( $sum % 10 ) ) % 10;
  }

  /** True for an 8, 12, 13 or 14 digit string whose last digit is its GS1 check digit. */
  public static function has_valid_check_digit( string $code ): bool {
    if ( ! ctype_digit( $code ) || ! in_array( strlen( $code ), self::GTIN_LENGTHS, true ) ) {
      return false;
    }
    return self::check_digit( substr( $code, 0, -1 ) ) === (int) substr( $code, -1 );
  }

  /**
   * Expand an 8 digit UPC-E (number system 0 or 1) to its 12 digit UPC-A.
   * Returns null when the input is not 8 digits with number system 0 or 1, or
   * when the check digit does not match the expansion.
   */
  public static function upce_to_upca( string $upce ): ?string {
    if ( 8 !== strlen( $upce ) || ! ctype_digit( $upce ) || ! in_array( $upce[0], [ '0', '1' ], true ) ) {
      return null;
    }
    $ns    = $upce[0];
    $d     = substr( $upce, 1, 6 );
    $check = $upce[7];
    $last  = (int) $d[5];
    if ( $last <= 2 ) {
      $body = $ns . $d[0] . $d[1] . $d[5] . '0000' . $d[2] . $d[3] . $d[4];
    } elseif ( 3 === $last ) {
      $body = $ns . $d[0] . $d[1] . $d[2] . '00000' . $d[3] . $d[4];
    } elseif ( 4 === $last ) {
      $body = $ns . $d[0] . $d[1] . $d[2] . $d[3] . '00000' . $d[4];
    } else {
      $body = $ns . $d[0] . $d[1] . $d[2] . $d[3] . $d[4] . '0000' . $d[5];
    }
    $upca = $body . $check;
    return self::has_valid_check_digit( $upca ) ? $upca : null;
  }

  /**
   * The 0-padded forms (12, 13 and 14 digits) of a valid GTIN, as far as the
   * code fits. Leading zeros do not change a GS1 check digit, so each form is
   * valid when the input is.
   *
   * @return string[] length => form, for the lengths that fit.
   */
  public static function padded_forms( string $gtin ): array {
    $core  = ltrim( $gtin, '0' );
    $forms = [];
    foreach ( [ 12, 13, 14 ] as $len ) {
      if ( strlen( $core ) <= $len ) {
        $forms[ $len ] = str_pad( $core, $len, '0', STR_PAD_LEFT );
      }
    }
    return $forms;
  }

  /**
   * Read a scanned or typed code.
   *
   * normalized: the canonical form used as a new product's SKU and as the
   * provider cache key. A valid 12/13/14 digit code becomes its shortest
   * standard form (12 digit UPC-A when it fits, else 13 digit EAN, else 14);
   * a valid EAN-8 stays 8 digits; a UPC-E that is not a valid EAN-8 becomes
   * its UPC-A. A code with letters is ''. Anything else is its digits ('' when
   * there are none).
   *
   * @return array{raw:string,digits:string,normalized:string,valid:bool,kind:string,candidates:string[]}
   *   kind: 'upc-a', 'ean-13', 'gtin-14', 'ean-8', 'upc-e' or 'unknown'.
   */
  public static function normalize( $raw ): array {
    $clean  = self::clean_raw( $raw );
    $digits = self::digits( $clean );
    $len    = strlen( $digits );
    $kind   = 'unknown';
    $norm   = $digits;
    $valid  = false;
    $forms  = [];
    // A code with letters (a house label such as WD-00042) is only ever
    // matched as typed: its digits alone would be some other item's SKU.
    $house  = (bool) preg_match( '/[^\d\s-]/', $clean );
    if ( $house ) {
      $norm = '';
      $len  = 0;
    }

    if ( 8 === $len ) {
      $ean8 = self::has_valid_check_digit( $digits );
      $upca = self::upce_to_upca( $digits );
      if ( $ean8 ) {
        $valid   = true;
        $kind    = 'ean-8';
        $forms[] = $digits;
        $forms   = array_merge( $forms, array_values( self::padded_forms( $digits ) ) );
      }
      if ( null !== $upca ) {
        $valid = true;
        if ( ! $ean8 ) {
          $kind = 'upc-e';
          $norm = $upca;
        }
        $forms[] = $digits;
        $forms   = array_merge( $forms, array_values( self::padded_forms( $upca ) ) );
      }
    } elseif ( in_array( $len, [ 12, 13, 14 ], true ) && self::has_valid_check_digit( $digits ) ) {
      $valid  = true;
      $padded = self::padded_forms( $digits );
      if ( isset( $padded[12] ) ) {
        $norm = $padded[12];
        $kind = 'upc-a';
      } elseif ( isset( $padded[13] ) ) {
        $norm = $padded[13];
        $kind = 'ean-13';
      } else {
        $norm = $padded[14];
        $kind = 'gtin-14';
      }
      $forms = array_values( $padded );
    }

    $candidates = $house ? [ $clean ] : array_merge( [ $clean, $digits, $norm ], $forms );
    $candidates = array_values( array_unique( array_filter( $candidates, function ( $c ) {
      return is_string( $c ) && '' !== $c;
    } ) ) );

    return [
      'raw'        => $clean,
      'digits'     => $digits,
      'normalized' => $norm,
      'valid'      => $valid,
      'kind'       => $kind,
      'candidates' => $candidates,
    ];
  }

  /**
   * The form to send to Open Food Facts: 13 digits when the code fits (OFF
   * stores UPC-A codes with a leading 0), else the normalized form.
   */
  public static function off_query_code( array $normalized ): string {
    $norm = (string) ( $normalized['normalized'] ?? '' );
    if ( empty( $normalized['valid'] ) || 'ean-8' === ( $normalized['kind'] ?? '' ) ) {
      return $norm;
    }
    return self::padded_forms( $norm )[13] ?? $norm;
  }
}
