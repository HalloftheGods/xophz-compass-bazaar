<?php
/**
 * Reads and writes rows of the bazaar_refunds table: one row per refund
 * request, keyed by (created_by, client_key) so a retry finds the same row.
 *
 * It stores state and answers; it does not decide whether a refund is allowed
 * or talk to WooCommerce. States: claimed (work started), completed, failed.
 *
 * @package Xophz_Compass_Bazaar
 */
class Xophz_Compass_Bazaar_Refunds_Repository {

  const STATE_CLAIMED   = 'claimed';
  const STATE_COMPLETED = 'completed';
  const STATE_FAILED    = 'failed';

  /**
   * Claim a client key. The first call inserts a 'claimed' row. A later call
   * with the same (created_by, client_key) inserts nothing and returns the
   * stored row, with `conflict` true when its request hash differs.
   *
   * @param array $fields order_id and request_hash are required; optional:
   *                      amount_minor, tax_minor, restock, reason, cashier_user_id,
   *                      staff_session_id, approval_id.
   * @return array{row: object, replayed: bool, conflict: bool}
   * @throws InvalidArgumentException When the key, user, order or hash is missing.
   */
  public static function claim( int $created_by, string $client_key, array $fields ): array {
    $order_id = (int) ( $fields['order_id'] ?? 0 );
    $hash     = (string) ( $fields['request_hash'] ?? '' );
    if ( $created_by <= 0 || '' === $client_key || strlen( $client_key ) > 64 || $order_id <= 0 || '' === $hash ) {
      throw new InvalidArgumentException( 'A refund claim needs created_by, a client_key of at most 64 characters, order_id and request_hash.' );
    }
    $now = Xophz_Compass_Bazaar_Core_Db::now();
    $row = [
      'client_key'       => $client_key,
      'created_by'       => $created_by,
      'request_hash'     => $hash,
      'order_id'         => $order_id,
      'state'            => self::STATE_CLAIMED,
      'amount_minor'     => (int) ( $fields['amount_minor'] ?? 0 ),
      'tax_minor'        => (int) ( $fields['tax_minor'] ?? 0 ),
      'restock'          => ( $fields['restock'] ?? true ) ? 1 : 0,
      'reason'           => substr( (string) ( $fields['reason'] ?? '' ), 0, 191 ),
      'cashier_user_id'  => (int) ( $fields['cashier_user_id'] ?? 0 ),
      'staff_session_id' => isset( $fields['staff_session_id'] ) ? (int) $fields['staff_session_id'] : null,
      'approval_id'      => isset( $fields['approval_id'] ) ? (int) $fields['approval_id'] : null,
      'created_at'       => $now,
      'updated_at'       => $now,
    ];
    try {
      $id = Xophz_Compass_Bazaar_Core_Db::insert( self::table(), $row );
      return [ 'row' => self::find( $id ), 'replayed' => false, 'conflict' => false ];
    } catch ( Xophz_Compass_Bazaar_Core_Duplicate_Key_Exception $e ) {
      $existing = self::find_by_key( $created_by, $client_key );
      if ( ! $existing ) {
        throw $e;
      }
      return [ 'row' => $existing, 'replayed' => true, 'conflict' => ! hash_equals( (string) $existing->request_hash, $hash ) ];
    }
  }

  /** @return object|null */
  public static function find( int $id ) {
    return Xophz_Compass_Bazaar_Core_Db::get_row( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', [ $id ] );
  }

  /** @return object|null */
  public static function find_by_key( int $created_by, string $client_key ) {
    return Xophz_Compass_Bazaar_Core_Db::get_row(
      'SELECT * FROM ' . self::table() . ' WHERE created_by = %d AND client_key = %s',
      [ $created_by, $client_key ]
    );
  }

  /** @return object[] Refunds of an order, oldest first. */
  public static function for_order( int $order_id ): array {
    return Xophz_Compass_Bazaar_Core_Db::get_results(
      'SELECT * FROM ' . self::table() . ' WHERE order_id = %d ORDER BY id ASC',
      [ $order_id ]
    );
  }

  /**
   * Record the computed amounts on a claimed refund. Changes nothing once the
   * refund is completed or failed.
   *
   * @return bool True when the row changed.
   */
  public static function set_amounts( int $id, int $amount_minor, int $tax_minor ): bool {
    return Xophz_Compass_Bazaar_Core_Db::exec(
      'UPDATE ' . self::table() . ' SET amount_minor = %d, tax_minor = %d, updated_at = %s WHERE id = %d AND state = %s',
      [ $amount_minor, $tax_minor, Xophz_Compass_Bazaar_Core_Db::now(), $id, self::STATE_CLAIMED ]
    ) > 0;
  }

  /**
   * Stamp the manager approval that covered a claimed refund (spec section
   * 13.4). Runs inside the transaction that consumes the approval, so the
   * refund row names its approval whether or not payment rows are written.
   * Changes nothing once the refund is completed or failed.
   *
   * @return bool True when the row changed.
   */
  public static function set_approval( int $id, int $approval_id ): bool {
    if ( $approval_id <= 0 ) {
      return false;
    }
    return Xophz_Compass_Bazaar_Core_Db::exec(
      'UPDATE ' . self::table() . ' SET approval_id = %d, updated_at = %s WHERE id = %d AND state = %s',
      [ $approval_id, Xophz_Compass_Bazaar_Core_Db::now(), $id, self::STATE_CLAIMED ]
    ) > 0;
  }

  /**
   * Mark a claimed refund completed, storing the WooCommerce refund id and the
   * response to replay. Only a claimed row moves.
   *
   * @return bool True when the row moved.
   */
  public static function complete( int $id, int $wc_refund_id, array $response ): bool {
    return Xophz_Compass_Bazaar_Core_Db::exec(
      'UPDATE ' . self::table() . ' SET state = %s, wc_refund_id = %d, response_json = %s, error_code = %s, updated_at = %s WHERE id = %d AND state = %s',
      [
        self::STATE_COMPLETED, $wc_refund_id, Xophz_Compass_Bazaar_Core_Canonical::json( $response ), '',
        Xophz_Compass_Bazaar_Core_Db::now(), $id, self::STATE_CLAIMED,
      ]
    ) > 0;
  }

  /**
   * Mark a claimed refund failed with an error code and the response to
   * replay. Only a claimed row moves.
   *
   * @return bool True when the row moved.
   */
  public static function fail( int $id, string $error_code, array $response = [] ): bool {
    return Xophz_Compass_Bazaar_Core_Db::exec(
      'UPDATE ' . self::table() . ' SET state = %s, error_code = %s, response_json = %s, updated_at = %s WHERE id = %d AND state = %s',
      [
        self::STATE_FAILED, substr( $error_code, 0, 48 ), Xophz_Compass_Bazaar_Core_Canonical::json( $response ),
        Xophz_Compass_Bazaar_Core_Db::now(), $id, self::STATE_CLAIMED,
      ]
    ) > 0;
  }

  /** @return array The stored response, or [] when none is stored. */
  public static function stored_response( $row ): array {
    $decoded = ( is_object( $row ) && isset( $row->response_json ) ) ? json_decode( (string) $row->response_json, true ) : null;
    return is_array( $decoded ) ? $decoded : [];
  }

  private static function table(): string {
    return Xophz_Compass_Bazaar_Core_Db::table( 'refunds' );
  }
}
