<?php
/**
 * Attribution for stock changes the ledger observes (spec 8.6 and 8.8).
 *
 * A stack of {reason, source}. Code that is about to make WooCommerce change
 * stock pushes a context, and the observer (WP13) reads current() to record
 * the change under that reason instead of 'external'. Legacy write sites run
 * inside with() in shadow mode.
 *
 * Guarantees: per-request, in-memory only; with() always pops what it pushed,
 * also when the callable throws. Not guaranteed: anything across requests or
 * processes, and nothing here writes stock or the ledger.
 *
 * @package Xophz_Compass_Bazaar
 */
final class Xophz_Compass_Bazaar_Stock_Context {

  /** @var array[] Each ['reason' => string, 'source' => ['type' => string, 'id' => int]]. */
  private static $stack = [];

  /**
   * @param array $source Optional ['type' => string (32 characters or fewer), 'id' => int].
   * @throws InvalidArgumentException On a reason that is not a Stock_Batch reason.
   */
  public static function push( string $reason, array $source = [] ): void {
    if ( ! in_array( $reason, Xophz_Compass_Bazaar_Stock_Batch::REASONS, true ) ) {
      throw new InvalidArgumentException( "Unknown stock reason: $reason" );
    }
    self::$stack[] = [
      'reason' => $reason,
      'source' => [
        'type' => substr( (string) ( $source['type'] ?? '' ), 0, 32 ),
        'id'   => max( 0, (int) ( $source['id'] ?? 0 ) ),
      ],
    ];
  }

  /** Remove and return the innermost context, null when none is open. */
  public static function pop(): ?array {
    return array_pop( self::$stack );
  }

  /** The innermost context, null when none is open. */
  public static function current(): ?array {
    return self::$stack ? self::$stack[ count( self::$stack ) - 1 ] : null;
  }

  /** Number of open contexts. */
  public static function depth(): int {
    return count( self::$stack );
  }

  /**
   * Run $fn with a context pushed, and pop it afterwards, also on a throw.
   *
   * @return mixed What $fn returns.
   */
  public static function with( string $reason, callable $fn, array $source = [] ) {
    self::push( $reason, $source );
    $depth = count( self::$stack );
    try {
      return $fn();
    } finally {
      while ( count( self::$stack ) >= $depth ) {
        array_pop( self::$stack );
      }
    }
  }

  /** Drop every open context (tests and the start of a request). */
  public static function reset(): void {
    self::$stack = [];
  }
}
