<?php
/**
 * The one place that decides what kind of tender a payment method is.
 * The legacy CASH, CARD and BANK patterns live in Pos_Authorization (kept for
 * the old checkout); this class reads them from there so they stay in step.
 *
 * Kinds: cash, manual, external_terminal, split, digital.
 *
 * @package Xophz_Compass_Bazaar
 */
class Xophz_Compass_Bazaar_Payments_Classifier {

  const KINDS = [ 'cash', 'manual', 'external_terminal', 'split', 'digital' ];

  /** Methods that are manual whatever their title. */
  const MANUAL_METHODS = [ 'bacs', 'cheque', 'cod' ];

  const CARD_TERMINAL_METHOD = 'bazaar_card_terminal';

  /**
   * Same split between digital and not-digital as
   * Pos_Authorization::requires_authorization. An empty method id is manual
   * (not digital, so it never asks for a token); the registry offers no tender for it.
   * The filter xophz_compass_bazaar_tender_kind(kind, method_id, title) may
   * change the answer; a value outside KINDS is ignored.
   */
  public static function kind( string $method_id, string $title = '' ): string {
    $kind     = self::builtin_kind( strtolower( $method_id ), strtolower( $title ) );
    $filtered = apply_filters( 'xophz_compass_bazaar_tender_kind', $kind, $method_id, $title );
    return is_string( $filtered ) && in_array( $filtered, self::KINDS, true ) ? $filtered : $kind;
  }

  private static function builtin_kind( string $method, string $label ): string {
    if ( '' === $method ) {
      return 'manual';
    }
    if ( 'bazaar_split' === $method ) {
      return 'split';
    }
    if ( 'cash' === $method ) {
      return 'cash';
    }
    if ( in_array( $method, self::MANUAL_METHODS, true ) ) {
      return 'manual';
    }
    if ( self::CARD_TERMINAL_METHOD === $method ) {
      return 'external_terminal';
    }
    foreach ( Xophz_Compass_Bazaar_Pos_Authorization::CARD_PATTERNS as $pattern ) {
      if ( false !== strpos( $method, $pattern ) || false !== strpos( $label, $pattern ) ) {
        return 'external_terminal';
      }
    }
    foreach ( Xophz_Compass_Bazaar_Pos_Authorization::BANK_PATTERNS as $pattern ) {
      if ( false !== strpos( $method, $pattern ) ) {
        return 'manual';
      }
    }
    return 'digital';
  }
}
