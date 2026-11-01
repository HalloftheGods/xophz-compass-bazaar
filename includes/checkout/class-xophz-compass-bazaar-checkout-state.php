<?php
/**
 * The checkout state machine: the spec 11.2 transition table, and nothing else.
 *
 * Spec: docs/plugins/bazaar-core-v2.md section 11.2.
 *
 * | State            | Allowed next                         |
 * |------------------|--------------------------------------|
 * | received         | priced, failed                       |
 * | priced           | order_created, failed                |
 * | order_created    | committed, cancelled                 |
 * | committed        | completed, cancelled (with reversal) |
 * | completed        | none (terminal)                      |
 * | failed           | received (retryable rows only)       |
 * | cancelled        | none (terminal)                      |
 * | awaiting_payment | committed, cancelled (Phase 2)       |
 *
 * assert() throws on any other transition. Whether a failed row is retryable,
 * and who may cancel a committed row, are the caller's checks: this class
 * knows only the table.
 *
 * A restart (spec 11.3 step 1: an expired lease on a received or priced row,
 * or a retryable failed row) is not a table edge for received and priced.
 * restart_from() names the states a restart may start from; the repository
 * records it as one conditional UPDATE back to received.
 *
 * @package Xophz_Compass_Bazaar
 */
final class Xophz_Compass_Bazaar_Checkout_State {

  const RECEIVED         = 'received';
  const PRICED           = 'priced';
  const ORDER_CREATED    = 'order_created';
  const COMMITTED        = 'committed';
  const COMPLETED        = 'completed';
  const FAILED           = 'failed';
  const CANCELLED        = 'cancelled';
  const AWAITING_PAYMENT = 'awaiting_payment';

  /** State => the states it may move to. */
  const TRANSITIONS = [
    self::RECEIVED         => [ self::PRICED, self::FAILED ],
    self::PRICED           => [ self::ORDER_CREATED, self::FAILED ],
    self::ORDER_CREATED    => [ self::COMMITTED, self::CANCELLED ],
    self::COMMITTED        => [ self::COMPLETED, self::CANCELLED ],
    self::COMPLETED        => [],
    self::FAILED           => [ self::RECEIVED ],
    self::CANCELLED        => [],
    self::AWAITING_PAYMENT => [ self::COMMITTED, self::CANCELLED ],
  ];

  /** States a restart may start from (spec 11.3 step 1). */
  const RESTARTABLE = [ self::RECEIVED, self::PRICED, self::FAILED ];

  /** States with no next state. */
  const TERMINAL = [ self::COMPLETED, self::CANCELLED ];

  /** States the sweeper looks at when their lease has expired. */
  const IN_FLIGHT = [ self::RECEIVED, self::PRICED, self::ORDER_CREATED, self::COMMITTED ];

  /** @return string[] Every state, in table order. */
  public static function all(): array {
    return array_keys( self::TRANSITIONS );
  }

  public static function is_state( string $state ): bool {
    return array_key_exists( $state, self::TRANSITIONS );
  }

  public static function can( string $from, string $to ): bool {
    return self::is_state( $from ) && in_array( $to, self::TRANSITIONS[ $from ], true );
  }

  /** @throws LogicException When the table has no $from -> $to edge. */
  public static function assert( string $from, string $to ): void {
    if ( ! self::can( $from, $to ) ) {
      throw new LogicException( sprintf( 'Checkout cannot move from "%s" to "%s".', $from, $to ) );
    }
  }

  public static function is_terminal( string $state ): bool {
    return in_array( $state, self::TERMINAL, true );
  }

  /** @throws LogicException When a restart may not start from $from. */
  public static function restart_from( string $from ): string {
    if ( ! in_array( $from, self::RESTARTABLE, true ) ) {
      throw new LogicException( sprintf( 'Checkout cannot restart from "%s".', $from ) );
    }
    return self::RECEIVED;
  }
}
