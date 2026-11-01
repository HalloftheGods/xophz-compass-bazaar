<?php
/**
 * Reads and writes rows of the bazaar_payments table: captures and refunds.
 *
 * It stores what it is given. It does not call providers, decide whether a
 * refund is allowed, or open a transaction; callers wrap it in Core_Db::tx().
 * Amounts are integer minor units.
 *
 * @package Xophz_Compass_Bazaar
 */
class Xophz_Compass_Bazaar_Payments_Repository {

  const DIRECTIONS = [ 'capture', 'refund' ];

  /** Capture statuses that count toward a checkout total (invariant I4). */
  const COUNTED_STATUSES = [ 'captured', 'pending', 'authorized' ];

  /** Columns a caller may set on insert, with the cast applied to each. */
  const COLUMNS = [
    'checkout_id'         => 'nullable_int',
    'refund_id'           => 'nullable_int',
    'parent_payment_id'   => 'nullable_int',
    'order_id'            => 'int',
    'seq'                 => 'int',
    'provider'            => 'string',
    'method_id'           => 'string',
    'kind'                => 'string',
    'status'              => 'string',
    'verified'            => 'bool',
    'amount_minor'        => 'int',
    'tendered_minor'      => 'nullable_int',
    'change_minor'        => 'nullable_int',
    'currency'            => 'string',
    'reference'           => 'string',
    'provider_ref'        => 'string',
    'card_brand'          => 'string',
    'card_last4'          => 'string',
    'operator_user_id'    => 'int',
    'cashier_user_id'     => 'int',
    'staff_session_id'    => 'nullable_int',
    'approval_id'         => 'nullable_int',
    'register_id'         => 'nullable_int',
    'register_session_id' => 'nullable_int',
  ];

  /**
   * Insert a capture row. Required fields: provider, method_id, kind, status,
   * amount_minor, currency. A payment_uuid is generated when absent.
   *
   * @param array $fields Keys from COLUMNS, plus payment_uuid and provider_payload (array or string).
   * @return int The new row id.
   * @throws InvalidArgumentException When a required field is missing or the amount is negative.
   * @throws Xophz_Compass_Bazaar_Core_Duplicate_Key_Exception On a repeated payment_uuid or (checkout_id, seq).
   */
  public static function insert_capture( array $fields ): int {
    return self::insert( 'capture', $fields );
  }

  /**
   * Insert a refund row that points at the capture it was taken from. Required
   * fields: refund_id, parent_payment_id, provider, method_id, kind, status,
   * amount_minor, currency. The row's own refunded_minor stays 0.
   *
   * @throws InvalidArgumentException When a required field is missing.
   * @throws Xophz_Compass_Bazaar_Core_Duplicate_Key_Exception On a repeated (refund_id, parent_payment_id).
   */
  public static function insert_refund_row( array $fields ): int {
    foreach ( [ 'refund_id', 'parent_payment_id' ] as $key ) {
      if ( empty( $fields[ $key ] ) ) {
        throw new InvalidArgumentException( 'A refund payment row needs ' . $key . '.' );
      }
    }
    return self::insert( 'refund', $fields );
  }

  /** @return object|null */
  public static function find( int $id ) {
    return Xophz_Compass_Bazaar_Core_Db::get_row( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', [ $id ] );
  }

  /** @return object|null */
  public static function find_by_uuid( string $uuid ) {
    return Xophz_Compass_Bazaar_Core_Db::get_row( 'SELECT * FROM ' . self::table() . ' WHERE payment_uuid = %s', [ $uuid ] );
  }

  /** @return object[] Capture rows of a checkout, in seq order. */
  public static function for_checkout( int $checkout_id ): array {
    return Xophz_Compass_Bazaar_Core_Db::get_results(
      'SELECT * FROM ' . self::table() . " WHERE checkout_id = %d AND direction = 'capture' ORDER BY seq ASC",
      [ $checkout_id ]
    );
  }

  /**
   * Rows of an order. Captures come newest first, which is the default order
   * for choosing where a refund goes; refund rows follow, oldest first.
   *
   * @return object[]
   */
  public static function for_order( int $order_id, string $direction = 'capture' ): array {
    $direction = in_array( $direction, self::DIRECTIONS, true ) ? $direction : 'capture';
    $order     = 'capture' === $direction ? 'DESC' : 'ASC';
    return Xophz_Compass_Bazaar_Core_Db::get_results(
      'SELECT * FROM ' . self::table() . " WHERE order_id = %d AND direction = %s ORDER BY id $order",
      [ $order_id, $direction ]
    );
  }

  /** @return object[] Refund rows of one refund. */
  public static function for_refund( int $refund_id ): array {
    return Xophz_Compass_Bazaar_Core_Db::get_results(
      'SELECT * FROM ' . self::table() . " WHERE refund_id = %d AND direction = 'refund' ORDER BY id ASC",
      [ $refund_id ]
    );
  }

  /**
   * SUM(amount_minor) of a checkout's captures whose status counts toward the
   * total (invariant I4).
   */
  public static function counted_total( int $checkout_id ): int {
    $marks = implode( ',', array_fill( 0, count( self::COUNTED_STATUSES ), '%s' ) );
    $sum   = Xophz_Compass_Bazaar_Core_Db::get_var(
      'SELECT COALESCE(SUM(amount_minor), 0) FROM ' . self::table() . " WHERE checkout_id = %d AND direction = 'capture' AND status IN ($marks)",
      array_merge( [ $checkout_id ], self::COUNTED_STATUSES )
    );
    return (int) $sum;
  }

  /**
   * Set a row's status, and optionally its verified flag, reference and
   * provider reference. Returns false when no row changed (unknown id or the
   * same values).
   *
   * @param array $changes Keys among verified, reference, provider_ref, card_brand, card_last4.
   */
  public static function update_status( int $id, string $status, array $changes = [] ): bool {
    $sets = [ 'status = %s', 'updated_at = %s' ];
    $args = [ $status, Xophz_Compass_Bazaar_Core_Db::now() ];
    if ( array_key_exists( 'verified', $changes ) ) {
      $sets[] = 'verified = %d';
      $args[] = $changes['verified'] ? 1 : 0;
    }
    foreach ( [ 'reference', 'provider_ref', 'card_brand', 'card_last4' ] as $key ) {
      if ( array_key_exists( $key, $changes ) ) {
        $sets[] = "$key = %s";
        $args[] = (string) $changes[ $key ];
      }
    }
    $args[] = $id;
    return Xophz_Compass_Bazaar_Core_Db::exec( 'UPDATE ' . self::table() . ' SET ' . implode( ', ', $sets ) . ' WHERE id = %d', $args ) > 0;
  }

  /**
   * Take $amount_minor of refund capacity from one capture with a single
   * conditional UPDATE. It succeeds only when the row is a captured capture and
   * refunded_minor + amount stays at or below amount_minor, so two parallel
   * refunds of the last unit cannot both win (invariant I5).
   *
   * @return bool True when the amount was taken; false when it was not
   *              (not a captured capture, not enough left, or amount <= 0).
   */
  public static function allocate_refund( int $payment_id, int $amount_minor ): bool {
    if ( $amount_minor <= 0 ) {
      return false;
    }
    $rows = Xophz_Compass_Bazaar_Core_Db::exec(
      'UPDATE ' . self::table() . ' SET refunded_minor = refunded_minor + %d, updated_at = %s'
      . " WHERE id = %d AND direction = 'capture' AND status = 'captured' AND refunded_minor + %d <= amount_minor",
      [ $amount_minor, Xophz_Compass_Bazaar_Core_Db::now(), $payment_id, $amount_minor ]
    );
    return 1 === $rows;
  }

  /**
   * Give back an earlier allocate_refund when the refund later failed. Never
   * takes refunded_minor below zero.
   *
   * @return bool True when the amount was returned.
   */
  public static function release_refund( int $payment_id, int $amount_minor ): bool {
    if ( $amount_minor <= 0 ) {
      return false;
    }
    $rows = Xophz_Compass_Bazaar_Core_Db::exec(
      'UPDATE ' . self::table() . ' SET refunded_minor = refunded_minor - %d, updated_at = %s'
      . " WHERE id = %d AND direction = 'capture' AND refunded_minor >= %d",
      [ $amount_minor, Xophz_Compass_Bazaar_Core_Db::now(), $payment_id, $amount_minor ]
    );
    return 1 === $rows;
  }

  private static function table(): string {
    return Xophz_Compass_Bazaar_Core_Db::table( 'payments' );
  }

  private static function insert( string $direction, array $fields ): int {
    foreach ( [ 'provider', 'method_id', 'kind', 'status', 'currency' ] as $key ) {
      if ( ! isset( $fields[ $key ] ) || '' === (string) $fields[ $key ] ) {
        throw new InvalidArgumentException( 'A payment row needs ' . $key . '.' );
      }
    }
    if ( ! isset( $fields['amount_minor'] ) || (int) $fields['amount_minor'] < 0 ) {
      throw new InvalidArgumentException( 'A payment row needs a non-negative amount_minor.' );
    }
    $now = Xophz_Compass_Bazaar_Core_Db::now();
    $row = [
      'payment_uuid' => ! empty( $fields['payment_uuid'] ) ? (string) $fields['payment_uuid'] : wp_generate_uuid4(),
      'direction'    => $direction,
      'seq'          => 1,
      'created_at'   => $now,
      'updated_at'   => $now,
    ];
    foreach ( self::COLUMNS as $column => $cast ) {
      if ( ! array_key_exists( $column, $fields ) ) {
        continue;
      }
      $value = $fields[ $column ];
      if ( 'int' === $cast ) {
        $row[ $column ] = (int) $value;
      } elseif ( 'bool' === $cast ) {
        $row[ $column ] = $value ? 1 : 0;
      } elseif ( 'nullable_int' === $cast ) {
        $row[ $column ] = null === $value ? null : (int) $value;
      } else {
        $row[ $column ] = (string) $value;
      }
    }
    if ( isset( $fields['provider_payload'] ) ) {
      $row['provider_payload'] = is_string( $fields['provider_payload'] )
        ? $fields['provider_payload']
        : Xophz_Compass_Bazaar_Core_Canonical::json( (array) $fields['provider_payload'] );
    }
    return Xophz_Compass_Bazaar_Core_Db::insert( self::table(), $row );
  }
}
