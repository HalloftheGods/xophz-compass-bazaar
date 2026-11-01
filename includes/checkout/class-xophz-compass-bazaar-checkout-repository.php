<?php
/**
 * Rows of the bazaar_checkouts table: the claim, leases and conditional
 * transitions of spec 11.2 and 11.3.
 *
 * Guarantees:
 * - claim() is one INSERT; the unique key (created_by, client_key) makes a
 *   second claim of the same key fail, and the existing row is returned.
 * - Every transition, restart and lease change is one UPDATE filtered on the
 *   row id, its current state and its lease token. It returns null when no
 *   row changed (another request took the lease or moved the row), and the
 *   caller must then stop without side effects.
 * - Lease times are computed by MySQL (UTC_TIMESTAMP()), so PHP and database
 *   clock skew cannot make a lease look expired early.
 * - A transition to an in-flight state (priced, order_created, committed)
 *   renews the lease to LEASE_SECONDS from now in the same UPDATE, and
 *   renew_lease() renews it before a long step, so the 60 seconds count from
 *   the last step, not from the claim.
 * - lock_finalize() takes a MySQL named lock (GET_LOCK) per checkout, so two
 *   finalizers of one row (a slow request and a same-key retry or the
 *   sweeper) never run at once, even past an expired lease. The lock belongs
 *   to the database connection and ends with it.
 * - find_by_key() is scoped to created_by; find_any_by_key() is not, and is
 *   for callers already checked for bazaar_manage_orders.
 * Not guaranteed: it opens no transaction and calls nothing outside the
 * table; the service decides what each branch does.
 *
 * @package Xophz_Compass_Bazaar
 */
class Xophz_Compass_Bazaar_Checkout_Repository {

  const LEASE_SECONDS = 60;

  /** Columns a transition may set, with the cast applied to each. */
  const CHANGE_COLUMNS = [
    'retryable'       => 'bool',
    'order_id'        => 'nullable_int',
    'stock_owner'     => 'string',
    'subtotal_minor'  => 'int',
    'discount_minor'  => 'int',
    'tax_minor'       => 'int',
    'tip_minor'       => 'int',
    'total_minor'     => 'int',
    'error_code'      => 'string',
    'error_message'   => 'string',
    'response_status' => 'int',
    'response_json'   => 'nullable_string',
    'committed_at'    => 'nullable_string',
    'completed_at'    => 'nullable_string',
  ];

  const INT_COLUMNS = [
    'id', 'created_by', 'retryable', 'order_id', 'operator_user_id', 'cashier_user_id', 'staff_session_id',
    'register_id', 'location_id', 'subtotal_minor', 'discount_minor', 'tax_minor', 'tip_minor', 'total_minor',
    'attempts', 'response_status',
  ];

  /**
   * Insert a received row with a fresh lease. Required: client_key,
   * created_by, request_hash, operator_user_id, cashier_user_id, currency.
   *
   * @return array{claimed:bool,row:array|null} claimed false with the existing row on a duplicate key.
   */
  public static function claim( array $fields ): array {
    $token = self::new_token();
    $now   = Xophz_Compass_Bazaar_Core_Db::now();
    $row   = [
      'client_key'        => (string) $fields['client_key'],
      'created_by'        => (int) $fields['created_by'],
      'request_hash'      => (string) $fields['request_hash'],
      'channel'           => (string) ( $fields['channel'] ?? 'pos' ),
      'state'             => Xophz_Compass_Bazaar_Checkout_State::RECEIVED,
      'operator_user_id'  => (int) $fields['operator_user_id'],
      'cashier_user_id'   => (int) $fields['cashier_user_id'],
      'staff_session_id'  => ! empty( $fields['staff_session_id'] ) ? (int) $fields['staff_session_id'] : null,
      'device_id'         => substr( (string) ( $fields['device_id'] ?? '' ), 0, 64 ),
      'location_id'       => (int) ( $fields['location_id'] ?? 0 ),
      'stock_owner'       => (string) ( $fields['stock_owner'] ?? 'wc' ),
      'currency'          => substr( (string) $fields['currency'], 0, 3 ),
      'quote_hash'        => (string) ( $fields['quote_hash'] ?? '' ),
      'lease_token'       => $token,
      'client_created_at' => $fields['client_created_at'] ?? null,
      'created_at'        => $now,
      'updated_at'        => $now,
    ];
    $columns = array_keys( $row );
    $marks   = [];
    $args    = [];
    foreach ( $row as $value ) {
      if ( null === $value ) {
        $marks[] = 'NULL';
        continue;
      }
      $marks[] = is_int( $value ) ? '%d' : '%s';
      $args[]  = $value;
    }
    $sql = 'INSERT INTO ' . self::table() . ' (' . implode( ',', $columns ) . ', lease_until, attempts) VALUES ('
      . implode( ',', $marks ) . ', UTC_TIMESTAMP() + INTERVAL ' . self::LEASE_SECONDS . ' SECOND, 1)';
    try {
      Xophz_Compass_Bazaar_Core_Db::exec( $sql, $args );
    } catch ( Xophz_Compass_Bazaar_Core_Duplicate_Key_Exception $e ) {
      return [ 'claimed' => false, 'row' => self::find_by_key( (int) $fields['created_by'], (string) $fields['client_key'] ) ];
    }
    return [ 'claimed' => true, 'row' => self::find_by_key( (int) $fields['created_by'], (string) $fields['client_key'] ) ];
  }

  public static function find( int $id ): ?array {
    return self::one( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', [ $id ] );
  }

  /** The row of this creator's key. */
  public static function find_by_key( int $created_by, string $client_key ): ?array {
    return self::one( 'SELECT * FROM ' . self::table() . ' WHERE created_by = %d AND client_key = %s', [ $created_by, $client_key ] );
  }

  /** The newest row with this key from any creator (for bazaar_manage_orders callers only). */
  public static function find_any_by_key( string $client_key ): ?array {
    return self::one( 'SELECT * FROM ' . self::table() . ' WHERE client_key = %s ORDER BY id DESC LIMIT 1', [ $client_key ] );
  }

  public static function find_by_order( int $order_id ): ?array {
    return self::one( 'SELECT * FROM ' . self::table() . ' WHERE order_id = %d', [ $order_id ] );
  }

  /** SELECT … FOR UPDATE. Meaningful only inside Core_Db::tx(). */
  public static function lock( int $id ): ?array {
    return self::one( 'SELECT * FROM ' . self::table() . ' WHERE id = %d FOR UPDATE', [ $id ] );
  }

  /** True while the row's lease has not expired (by the database clock). */
  public static function lease_live( array $row ): bool {
    if ( empty( $row['lease_until'] ) ) {
      return false;
    }
    return '1' === (string) Xophz_Compass_Bazaar_Core_Db::get_var( 'SELECT %s > UTC_TIMESTAMP()', [ (string) $row['lease_until'] ] );
  }

  /**
   * Move $row to $to, conditional on its id, state and lease token.
   *
   * @return array|null The updated row, or null when no row changed.
   * @throws LogicException When the state table has no such edge.
   */
  public static function transition( array $row, string $to, array $changes = [] ): ?array {
    Xophz_Compass_Bazaar_Checkout_State::assert( (string) $row['state'], $to );
    $sets = [ 'state = %s', 'updated_at = %s' ];
    $args = [ $to, Xophz_Compass_Bazaar_Core_Db::now() ];
    if ( in_array( $to, Xophz_Compass_Bazaar_Checkout_State::IN_FLIGHT, true ) ) {
      $sets[] = 'lease_until = UTC_TIMESTAMP() + INTERVAL ' . self::LEASE_SECONDS . ' SECOND';
    }
    foreach ( self::CHANGE_COLUMNS as $column => $cast ) {
      if ( ! array_key_exists( $column, $changes ) ) {
        continue;
      }
      $value = $changes[ $column ];
      if ( null === $value && 0 === strpos( $cast, 'nullable' ) ) {
        $sets[] = "$column = NULL";
        continue;
      }
      if ( 'bool' === $cast ) {
        $sets[] = "$column = %d";
        $args[] = $value ? 1 : 0;
      } elseif ( 'int' === $cast || 'nullable_int' === $cast ) {
        $sets[] = "$column = %d";
        $args[] = (int) $value;
      } else {
        $sets[] = "$column = %s";
        $args[] = 'error_message' === $column ? substr( (string) $value, 0, 255 ) : (string) $value;
      }
    }
    list( $where, $where_args ) = self::owned( $row );
    $n = Xophz_Compass_Bazaar_Core_Db::exec( 'UPDATE ' . self::table() . ' SET ' . implode( ', ', $sets ) . " WHERE $where", array_merge( $args, $where_args ) );
    return 1 === $n ? self::find( (int) $row['id'] ) : null;
  }

  /**
   * Restart a row from received with a new lease (spec 11.3 step 1). A
   * failed row must be retryable; a received or priced row must have an
   * expired lease. Clears the cached error and response.
   *
   * @return array|null The restarted row, or null when no row changed.
   * @throws LogicException When the state may not restart.
   */
  public static function restart( array $row ): ?array {
    $from = (string) $row['state'];
    Xophz_Compass_Bazaar_Checkout_State::restart_from( $from );
    $guard = Xophz_Compass_Bazaar_Checkout_State::FAILED === $from ? 'retryable = 1' : '(lease_until IS NULL OR lease_until < UTC_TIMESTAMP())';
    list( $where, $where_args ) = self::owned( $row );
    $n = Xophz_Compass_Bazaar_Core_Db::exec(
      'UPDATE ' . self::table() . " SET state = %s, lease_token = %s, lease_until = UTC_TIMESTAMP() + INTERVAL " . self::LEASE_SECONDS . ' SECOND,'
      . " attempts = attempts + 1, retryable = 0, error_code = '', error_message = '', response_status = 0, response_json = NULL, updated_at = %s"
      . " WHERE $where AND $guard",
      array_merge( [ Xophz_Compass_Bazaar_Checkout_State::RECEIVED, self::new_token(), Xophz_Compass_Bazaar_Core_Db::now() ], $where_args )
    );
    return 1 === $n ? self::find( (int) $row['id'] ) : null;
  }

  /**
   * Take over an expired lease, keeping the state (recovery of committed or
   * order_created rows, and the sweeper).
   *
   * @return array|null The row with the new lease, or null when the lease was live or someone else took it.
   */
  public static function take_expired( array $row ): ?array {
    list( $where, $where_args ) = self::owned( $row );
    $n = Xophz_Compass_Bazaar_Core_Db::exec(
      'UPDATE ' . self::table() . ' SET lease_token = %s, lease_until = UTC_TIMESTAMP() + INTERVAL ' . self::LEASE_SECONDS . ' SECOND, attempts = attempts + 1, updated_at = %s'
      . " WHERE $where AND (lease_until IS NULL OR lease_until < UTC_TIMESTAMP())",
      array_merge( [ self::new_token(), Xophz_Compass_Bazaar_Core_Db::now() ], $where_args )
    );
    return 1 === $n ? self::find( (int) $row['id'] ) : null;
  }

  /**
   * Renew this request's lease to LEASE_SECONDS from now, keeping state and token.
   *
   * @return array|null The row with the new lease, or null when the lease was lost.
   */
  public static function renew_lease( array $row ): ?array {
    list( $where, $where_args ) = self::owned( $row );
    $n = Xophz_Compass_Bazaar_Core_Db::exec(
      'UPDATE ' . self::table() . ' SET lease_until = UTC_TIMESTAMP() + INTERVAL ' . self::LEASE_SECONDS . " SECOND, updated_at = %s WHERE $where",
      array_merge( [ Xophz_Compass_Bazaar_Core_Db::now() ], $where_args )
    );
    $now = self::find( (int) $row['id'] );
    if ( 1 === $n ) {
      return $now;
    }
    // MySQL counts changed rows: a renewal in the same second as the last one changes nothing but still holds.
    $held = $now && (string) $now['state'] === (string) $row['state'] && (string) $now['lease_token'] === (string) ( $row['lease_token'] ?? '' );
    return $held ? $now : null;
  }

  /** Take the finalize lock of checkout $id, waiting up to $wait seconds. True when this connection holds it. */
  public static function lock_finalize( int $id, int $wait = 5 ): bool {
    return '1' === (string) Xophz_Compass_Bazaar_Core_Db::get_var( 'SELECT GET_LOCK(%s, %d)', [ self::finalize_lock_name( $id ), max( 0, $wait ) ] );
  }

  public static function unlock_finalize( int $id ): void {
    Xophz_Compass_Bazaar_Core_Db::get_var( 'SELECT RELEASE_LOCK(%s)', [ self::finalize_lock_name( $id ) ] );
  }

  /** Under MySQL's 64-character limit, and unique per database and table prefix. */
  public static function finalize_lock_name( int $id ): string {
    global $wpdb;
    $db = defined( 'DB_NAME' ) ? (string) DB_NAME : '';
    return 'bzco:' . substr( sha1( $db . '|' . ( isset( $wpdb->prefix ) ? (string) $wpdb->prefix : '' ) ), 0, 16 ) . ':' . $id;
  }

  /** End this request's lease early so a same-key retry or the sweeper can continue at once. */
  public static function release_lease( array $row ): bool {
    list( $where, $where_args ) = self::owned( $row );
    $n = Xophz_Compass_Bazaar_Core_Db::exec(
      'UPDATE ' . self::table() . " SET lease_until = UTC_TIMESTAMP() - INTERVAL 1 SECOND, updated_at = %s WHERE $where",
      array_merge( [ Xophz_Compass_Bazaar_Core_Db::now() ], $where_args )
    );
    return 1 === $n;
  }

  /**
   * Rows in flight (received, priced, order_created, committed) whose lease
   * expired, oldest lease first.
   *
   * @return array[]
   */
  public static function expired( int $limit = 50 ): array {
    $states = Xophz_Compass_Bazaar_Checkout_State::IN_FLIGHT;
    $marks  = implode( ',', array_fill( 0, count( $states ), '%s' ) );
    $rows   = Xophz_Compass_Bazaar_Core_Db::get_results(
      'SELECT * FROM ' . self::table() . " WHERE state IN ($marks) AND lease_until < UTC_TIMESTAMP() ORDER BY lease_until ASC, id ASC LIMIT %d",
      array_merge( $states, [ max( 1, min( 500, $limit ) ) ] )
    );
    return array_map( [ self::class, 'normalize' ], $rows );
  }

  /** The stored response body (decoded), or null. */
  public static function response_body( array $row ): ?array {
    if ( empty( $row['response_json'] ) ) {
      return null;
    }
    $body = json_decode( (string) $row['response_json'], true );
    return is_array( $body ) ? $body : null;
  }

  /** @param object|array $row */
  public static function normalize( $row ): array {
    $row = (array) $row;
    foreach ( self::INT_COLUMNS as $column ) {
      if ( array_key_exists( $column, $row ) && null !== $row[ $column ] ) {
        $row[ $column ] = (int) $row[ $column ];
      }
    }
    return $row;
  }

  /** WHERE clause for "this row, in this state, under this lease". */
  private static function owned( array $row ): array {
    $sql  = 'id = %d AND state = %s AND ';
    $args = [ (int) $row['id'], (string) $row['state'] ];
    if ( null === ( $row['lease_token'] ?? null ) ) {
      return [ $sql . 'lease_token IS NULL', $args ];
    }
    $args[] = (string) $row['lease_token'];
    return [ $sql . 'lease_token = %s', $args ];
  }

  private static function one( string $sql, array $args ): ?array {
    $row = Xophz_Compass_Bazaar_Core_Db::get_row( $sql, $args );
    return $row ? self::normalize( $row ) : null;
  }

  private static function new_token(): string {
    return bin2hex( random_bytes( 16 ) );
  }

  private static function table(): string {
    return Xophz_Compass_Bazaar_Core_Db::table( 'checkouts' );
  }
}
