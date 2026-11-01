<?php
/**
 * Staff PINs: format rules, hashing, verification with lockout, and setting.
 *
 * Spec: docs/plugins/bazaar-core-v2.md section 13.1.
 * - A PIN is 4 to 8 digits. Twenty very common PINs are refused.
 * - Hashing uses wp_hash_password; checking uses wp_check_password.
 * - Lockout: one atomic UPDATE claims the attempt before the PIN is checked
 *   and sets the lock on the fifth, so parallel guesses cannot all be checked
 *   while the row still looks unlocked (#6986). A locked PIN is refused
 *   (423 pin_locked, data.locked_until) without being checked, even when the
 *   PIN is right. A right PIN clears the count.
 * - Throttle: 20 failures per 15 minutes per device (X-Bazaar-Device), 20 per
 *   logged-in operator and 60 per remote address, across all staff, counted
 *   by Staff_Throttle before the check. The device id is client-chosen, so
 *   the operator and address caps are what an attacker cannot step around.
 * - The outbox records staff.pin.locked and staff.pin.set.
 *
 * Failure counts are written outside any caller transaction on purpose: a
 * caller that rolls back must not undo a failed attempt. Do not call verify()
 * inside Core_Db::tx() if that matters to you; a nested call joins the
 * transaction and a rollback would undo the count.
 *
 * @package Xophz_Compass_Bazaar
 */
class Xophz_Compass_Bazaar_Staff_Pins {

  const MIN_LENGTH   = 4;
  const MAX_LENGTH   = 8;
  const MAX_FAILURES = 5;
  const LOCK_SECONDS = 900;
  const DEVICE_MAX   = 20;
  const OPERATOR_MAX = 20;
  const IP_MAX       = 60;

  const COMMON = [
    '0000', '1111', '2222', '3333', '4444', '5555', '6666', '7777', '8888', '9999',
    '1234', '4321', '1212', '1122', '1313', '2000', '2001', '1004', '12345', '123456',
  ];

  /**
   * '' when the PIN is acceptable, else a reason: 'length', 'digits' or 'common'.
   *
   * @param mixed $pin
   */
  public static function format_error( $pin ): string {
    if ( ! is_string( $pin ) || ! preg_match( '/^[0-9]+$/', $pin ) ) {
      return 'digits';
    }
    $len = strlen( $pin );
    if ( $len < self::MIN_LENGTH || $len > self::MAX_LENGTH ) {
      return 'length';
    }
    return in_array( $pin, self::COMMON, true ) ? 'common' : '';
  }

  public static function format_message( string $reason ): string {
    switch ( $reason ) {
      case 'length':
        return 'A PIN must be 4 to 8 digits.';
      case 'digits':
        return 'A PIN can only contain digits.';
      case 'common':
        return 'That PIN is too common. Choose another.';
    }
    return '';
  }

  /**
   * Throttle buckets for one PIN attempt: bucket => failures allowed per
   * 15-minute window. The device id comes from the client's X-Bazaar-Device
   * header, so it is never the only key: the logged-in operator and the
   * remote address are capped too. A missing device counts as one shared
   * device; operator 0 and an empty or invalid address add no bucket.
   *
   * @return array<string,int>
   */
  public static function throttle_buckets( string $device_id, int $operator_user_id, string $remote_ip ): array {
    $buckets = [ 'pin:device:' . ( '' === $device_id ? 'none' : $device_id ) => self::DEVICE_MAX ];
    if ( $operator_user_id > 0 ) {
      $buckets[ 'pin:operator:' . $operator_user_id ] = self::OPERATOR_MAX;
    }
    $ip = Xophz_Compass_Bazaar_Staff_Throttle::ip_key( $remote_ip );
    if ( '' !== $ip ) {
      $buckets[ 'pin:ip:' . $ip ] = self::IP_MAX;
    }
    return $buckets;
  }

  /** Whether a locked_until value ('Y-m-d H:i:s' UTC or empty) is still in the future at $now. */
  public static function is_locked( $locked_until, int $now ): bool {
    return is_string( $locked_until ) && '' !== $locked_until && strtotime( $locked_until . ' UTC' ) > $now;
  }

  public static function hash( string $pin ): string {
    return wp_hash_password( $pin );
  }

  /**
   * Check $pin for $user_id. Returns normally on success and clears the
   * failure count. Otherwise throws Core_Http_Error:
   * - 429 pin_throttled: the device, the operator or the address used its failures.
   * - 423 pin_locked (data.locked_until): the PIN is locked.
   * - 403 pin_invalid: wrong PIN, no PIN set, or the staff row is inactive.
   *
   * The throttle slot and the attempt are both claimed before the slow hash
   * check. A right PIN and a refusal for a lock give the throttle slot back,
   * so only wrong PINs count against it.
   *
   * @throws Xophz_Compass_Bazaar_Core_Http_Error
   */
  public static function verify( int $user_id, string $pin, string $device_id = '' ): void {
    $window  = Xophz_Compass_Bazaar_Staff_Throttle::window( time() );
    $buckets = self::throttle_buckets( $device_id, get_current_user_id(), Xophz_Compass_Bazaar_Staff_Throttle::remote_ip() );
    if ( ! Xophz_Compass_Bazaar_Staff_Throttle::claim( $buckets, $window ) ) {
      throw new Xophz_Compass_Bazaar_Core_Http_Error( 'pin_throttled', 'Too many wrong PINs from this till. Try again in a few minutes.', 429 );
    }
    $wrong = false;
    try {
      self::check( $user_id, $pin, $wrong );
    } finally {
      if ( ! $wrong ) {
        Xophz_Compass_Bazaar_Staff_Throttle::release( $buckets, $window );
      }
    }
  }

  /**
   * Set a PIN with no proof check: the caller must have decided who may do
   * this (own PIN after a proof, or bazaar_manage_staff). Clears any lock.
   *
   * @throws Xophz_Compass_Bazaar_Core_Http_Error 422 pin_format when the PIN is refused.
   */
  public static function set_pin( int $user_id, string $pin, int $set_by ): void {
    $reason = self::format_error( $pin );
    if ( '' !== $reason ) {
      throw new Xophz_Compass_Bazaar_Core_Http_Error( 'pin_format', self::format_message( $reason ), 422, [ 'reason' => $reason ] );
    }
    $table = Xophz_Compass_Bazaar_Core_Db::table( 'staff' );
    $now   = Xophz_Compass_Bazaar_Core_Db::now();
    Xophz_Compass_Bazaar_Core_Db::tx( function () use ( $table, $user_id, $pin, $set_by, $now ) {
      Xophz_Compass_Bazaar_Core_Db::exec(
        "INSERT INTO $table (user_id, pin_hash, pin_set_at, pin_set_by, failed_attempts, locked_until, active, updated_at)
         VALUES (%d, %s, %s, %d, 0, NULL, 1, %s)
         ON DUPLICATE KEY UPDATE pin_hash = VALUES(pin_hash), pin_set_at = VALUES(pin_set_at), pin_set_by = VALUES(pin_set_by),
           failed_attempts = 0, locked_until = NULL, active = 1, updated_at = VALUES(updated_at)",
        [ $user_id, self::hash( $pin ), $now, $set_by, $now ]
      );
      Xophz_Compass_Bazaar_Events_Outbox::record( 'staff.pin.set', 'staff', $user_id, [ 'set_by' => $set_by ], $set_by );
    } );
  }

  /** Whether a PIN is set and the row is active. */
  public static function is_enrolled( int $user_id ): bool {
    $table = Xophz_Compass_Bazaar_Core_Db::table( 'staff' );
    return null !== Xophz_Compass_Bazaar_Core_Db::get_var( "SELECT user_id FROM $table WHERE user_id = %d AND active = 1 AND pin_hash <> ''", [ $user_id ] );
  }

  /**
   * The lockout and the PIN check. Sets $wrong when the attempt counts as a
   * wrong PIN (which keeps its throttle slot).
   *
   * @throws Xophz_Compass_Bazaar_Core_Http_Error
   */
  private static function check( int $user_id, string $pin, bool &$wrong ): void {
    $table = Xophz_Compass_Bazaar_Core_Db::table( 'staff' );
    $row   = Xophz_Compass_Bazaar_Core_Db::get_row( "SELECT pin_hash, locked_until, active FROM $table WHERE user_id = %d", [ $user_id ] );
    if ( $row && self::is_locked( $row->locked_until, time() ) ) {
      throw self::locked_error( (string) $row->locked_until );
    }
    if ( ! $row || 1 !== (int) $row->active || '' === (string) $row->pin_hash ) {
      $wrong = true;
      throw self::invalid_error();
    }
    $attempt = self::claim_attempt( $user_id );
    if ( 0 === $attempt ) {
      // Locked by a parallel attempt since the read, or deactivated meanwhile.
      $locked = (string) self::locked_until( $user_id );
      if ( self::is_locked( $locked, time() ) ) {
        throw self::locked_error( $locked );
      }
      $wrong = true;
      throw self::invalid_error();
    }
    $set_lock = $attempt >= self::MAX_FAILURES;
    if ( '' === self::format_error( $pin ) && wp_check_password( $pin, (string) $row->pin_hash ) ) {
      self::clear_attempts( $user_id, $set_lock );
      return;
    }
    $wrong = true;
    if ( $set_lock ) {
      $locked = (string) self::locked_until( $user_id );
      Xophz_Compass_Bazaar_Events_Outbox::record( 'staff.pin.locked', 'staff', $user_id, [ 'locked_until' => $locked ], $user_id );
      throw self::locked_error( $locked );
    }
    throw self::invalid_error();
  }

  /**
   * Count one attempt before the PIN is checked. One UPDATE adds it, sets
   * locked_until on the fifth (and restarts the count), and matches no row
   * while the PIN is locked, inactive or unset. Returns this attempt's number
   * in the run (1 to MAX_FAILURES), or 0 when no row was claimed.
   *
   * locked_until is assigned first, from the old failed_attempts, because
   * MySQL evaluates SET assignments left to right. LAST_INSERT_ID(expr) hands
   * this connection the new number, so no second read can race another
   * request.
   */
  private static function claim_attempt( int $user_id ): int {
    $table   = Xophz_Compass_Bazaar_Core_Db::table( 'staff' );
    $max     = self::MAX_FAILURES;
    $claimed = Xophz_Compass_Bazaar_Core_Db::exec(
      "UPDATE $table SET locked_until = IF(failed_attempts + 1 >= $max, DATE_ADD(UTC_TIMESTAMP(), INTERVAL " . self::LOCK_SECONDS . " SECOND), locked_until),
         failed_attempts = IF(LAST_INSERT_ID(failed_attempts + 1) >= $max, 0, failed_attempts + 1), updated_at = %s
       WHERE user_id = %d AND active = 1 AND pin_hash <> '' AND (locked_until IS NULL OR locked_until <= UTC_TIMESTAMP())",
      [ Xophz_Compass_Bazaar_Core_Db::now(), $user_id ]
    );
    if ( 1 !== $claimed ) {
      return 0;
    }
    return max( 1, (int) Xophz_Compass_Bazaar_Core_Db::get_var( 'SELECT LAST_INSERT_ID()' ) );
  }

  /**
   * A right PIN clears the count. When this attempt set the lock it clears
   * that lock too; otherwise a lock that a parallel wrong PIN set meanwhile
   * stays.
   */
  private static function clear_attempts( int $user_id, bool $own_lock ): void {
    $table = Xophz_Compass_Bazaar_Core_Db::table( 'staff' );
    $sql   = "UPDATE $table SET failed_attempts = 0, locked_until = NULL, updated_at = %s WHERE user_id = %d";
    if ( ! $own_lock ) {
      $sql .= ' AND (locked_until IS NULL OR locked_until <= UTC_TIMESTAMP())';
    }
    Xophz_Compass_Bazaar_Core_Db::exec( $sql, [ Xophz_Compass_Bazaar_Core_Db::now(), $user_id ] );
  }

  /** @return string|null */
  private static function locked_until( int $user_id ) {
    $table = Xophz_Compass_Bazaar_Core_Db::table( 'staff' );
    return Xophz_Compass_Bazaar_Core_Db::get_var( "SELECT locked_until FROM $table WHERE user_id = %d", [ $user_id ] );
  }

  private static function locked_error( string $locked_until ): Xophz_Compass_Bazaar_Core_Http_Error {
    return new Xophz_Compass_Bazaar_Core_Http_Error( 'pin_locked', 'This PIN is locked after too many wrong attempts.', 423, [ 'locked_until' => $locked_until ] );
  }

  private static function invalid_error(): Xophz_Compass_Bazaar_Core_Http_Error {
    return new Xophz_Compass_Bazaar_Core_Http_Error( 'pin_invalid', 'The PIN is not correct.', 403 );
  }
}
