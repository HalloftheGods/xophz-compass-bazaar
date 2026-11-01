<?php
/**
 * Staff sessions: a PIN-verified staff user acting on an operator's device.
 *
 * Spec: docs/plugins/bazaar-core-v2.md section 13.2.
 * - start() checks the PIN (lockout applies), requires bazaar_sell on the
 *   staff user, ends the device's open session and stores a new one.
 * - The token is 32 random bytes in hex; only its sha256 is stored. start()
 *   returns the token once.
 * - A session lives 15 minutes idle (sliding) or 12 hours absolute.
 * - resolve() succeeds only for a session that has not ended or expired, whose
 *   operator and device match, and whose staff row is still active. It slides
 *   last_seen at most once a minute.
 *
 * Not guaranteed: a session does not re-check bazaar_sell on every request;
 * Security_Guard::rest_permission checks the staff user's capability each time.
 *
 * @package Xophz_Compass_Bazaar
 */
class Xophz_Compass_Bazaar_Staff_Sessions {

  const IDLE_SECONDS     = 900;
  const ABSOLUTE_SECONDS = 43200;
  const SLIDE_SECONDS    = 60;

  /** Whether a session row is usable at $now (UTC timestamp). */
  public static function is_live( $row, int $now ): bool {
    $row = (array) $row;
    if ( ! empty( $row['ended_at'] ) ) {
      return false;
    }
    return strtotime( (string) ( $row['idle_expires_at'] ?? '' ) . ' UTC' ) > $now
      && strtotime( (string) ( $row['expires_at'] ?? '' ) . ' UTC' ) > $now;
  }

  /** The new idle expiry after activity at $now: now + idle, never past the absolute expiry. */
  public static function next_idle( int $now, int $expires_ts ): int {
    return min( $now + self::IDLE_SECONDS, $expires_ts );
  }

  public static function hash_token( string $token ): string {
    return hash( 'sha256', $token );
  }

  /**
   * Start a session. Returns ['token' => string, 'session' => public fields].
   *
   * @throws Xophz_Compass_Bazaar_Core_Http_Error Pin errors, or 403 forbidden when the staff user cannot sell.
   */
  public static function start( int $staff_user_id, string $pin, int $operator_user_id, string $device_id, int $location_id = 0 ): array {
    Xophz_Compass_Bazaar_Staff_Pins::verify( $staff_user_id, $pin, $device_id );
    if ( ! user_can( $staff_user_id, Xophz_Compass_Bazaar_Security_Capabilities::SELL ) ) {
      throw new Xophz_Compass_Bazaar_Core_Http_Error( 'forbidden', 'This staff member cannot ring sales.', 403 );
    }
    $table = Xophz_Compass_Bazaar_Core_Db::table( 'staff_sessions' );
    $token = bin2hex( random_bytes( 32 ) );
    $now   = time();
    $stamp = gmdate( 'Y-m-d H:i:s', $now );
    $abs   = $now + self::ABSOLUTE_SECONDS;
    $id    = Xophz_Compass_Bazaar_Core_Db::tx( function () use ( $table, $token, $staff_user_id, $operator_user_id, $device_id, $location_id, $now, $stamp, $abs ) {
      Xophz_Compass_Bazaar_Core_Db::exec(
        "UPDATE $table SET ended_at = %s, end_reason = 'replaced' WHERE operator_user_id = %d AND device_id = %s AND ended_at IS NULL",
        [ $stamp, $operator_user_id, $device_id ]
      );
      $new = Xophz_Compass_Bazaar_Core_Db::insert( $table, [
        'token_hash'       => self::hash_token( $token ),
        'staff_user_id'    => $staff_user_id,
        'operator_user_id' => $operator_user_id,
        'device_id'        => $device_id,
        'location_id'      => max( 0, $location_id ),
        'started_at'       => $stamp,
        'last_seen_at'     => $stamp,
        'idle_expires_at'  => gmdate( 'Y-m-d H:i:s', self::next_idle( $now, $abs ) ),
        'expires_at'       => gmdate( 'Y-m-d H:i:s', $abs ),
        'end_reason'       => '',
      ] );
      Xophz_Compass_Bazaar_Events_Outbox::record( 'staff.session.started', 'staff_session', $new, [ 'staff_user_id' => $staff_user_id, 'device_id' => $device_id ], $operator_user_id );
      return $new;
    } );
    return [ 'token' => $token, 'session' => self::get( (int) $id ) ];
  }

  /**
   * The session for $token, or null. Slides the idle expiry at most once a minute.
   *
   * @return array|null id, staff_user_id, operator_user_id, device_id, location_id, started_at, last_seen_at, idle_expires_at, expires_at
   */
  public static function resolve( string $token, int $operator_user_id, string $device_id ): ?array {
    if ( '' === $token ) {
      return null;
    }
    $table = Xophz_Compass_Bazaar_Core_Db::table( 'staff_sessions' );
    $staff = Xophz_Compass_Bazaar_Core_Db::table( 'staff' );
    $row   = Xophz_Compass_Bazaar_Core_Db::get_row(
      "SELECT s.* FROM $table s INNER JOIN $staff t ON t.user_id = s.staff_user_id AND t.active = 1 WHERE s.token_hash = %s",
      [ self::hash_token( $token ) ]
    );
    $now = time();
    if ( ! $row || ! self::is_live( $row, $now ) || (int) $row->operator_user_id !== $operator_user_id || (string) $row->device_id !== $device_id ) {
      return null;
    }
    if ( $now - strtotime( $row->last_seen_at . ' UTC' ) >= self::SLIDE_SECONDS ) {
      $idle = gmdate( 'Y-m-d H:i:s', self::next_idle( $now, strtotime( $row->expires_at . ' UTC' ) ) );
      Xophz_Compass_Bazaar_Core_Db::exec( "UPDATE $table SET last_seen_at = %s, idle_expires_at = %s WHERE id = %d AND ended_at IS NULL", [ gmdate( 'Y-m-d H:i:s', $now ), $idle, (int) $row->id ] );
      $row->last_seen_at    = gmdate( 'Y-m-d H:i:s', $now );
      $row->idle_expires_at = $idle;
    }
    return self::public_fields( $row );
  }

  /** End a session by id. Returns true when a live session was ended. */
  public static function end( int $session_id, string $reason = 'ended' ): bool {
    $table = Xophz_Compass_Bazaar_Core_Db::table( 'staff_sessions' );
    $n     = Xophz_Compass_Bazaar_Core_Db::exec(
      "UPDATE $table SET ended_at = %s, end_reason = %s WHERE id = %d AND ended_at IS NULL",
      [ Xophz_Compass_Bazaar_Core_Db::now(), substr( $reason, 0, 24 ), $session_id ]
    );
    return $n > 0;
  }

  /** @return array|null The public fields of a session row, whatever its state. */
  public static function get( int $session_id ): ?array {
    $table = Xophz_Compass_Bazaar_Core_Db::table( 'staff_sessions' );
    $row   = Xophz_Compass_Bazaar_Core_Db::get_row( "SELECT * FROM $table WHERE id = %d", [ $session_id ] );
    return $row ? self::public_fields( $row ) : null;
  }

  /** Never includes the token hash. */
  private static function public_fields( $row ): array {
    return [
      'id'               => (int) $row->id,
      'staff_user_id'    => (int) $row->staff_user_id,
      'operator_user_id' => (int) $row->operator_user_id,
      'device_id'        => (string) $row->device_id,
      'location_id'      => (int) $row->location_id,
      'started_at'       => (string) $row->started_at,
      'last_seen_at'     => (string) $row->last_seen_at,
      'idle_expires_at'  => (string) $row->idle_expires_at,
      'expires_at'       => (string) $row->expires_at,
    ];
  }
}
