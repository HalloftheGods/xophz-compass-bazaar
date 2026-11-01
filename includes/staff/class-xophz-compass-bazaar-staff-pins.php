<?php
/**
 * Staff PINs: format rules, hashing, verification with lockout, and setting.
 *
 * Spec: docs/plugins/bazaar-core-v2.md section 13.1.
 * - A PIN is 4 to 8 digits. Twenty very common PINs are refused.
 * - Hashing uses wp_hash_password; checking uses wp_check_password.
 * - Lockout: one UPDATE counts a failure and sets the lock on the fifth.
 *   A locked PIN is refused (423 pin_locked, data.locked_until) without
 *   being checked, even when the PIN is right.
 * - Per-device throttle: 20 failures per 15 minutes across all staff, kept in
 *   a transient. The count is read and written in two steps, so two requests
 *   at the same instant can undercount by one; it is a brake, not a counter
 *   of record.
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

  const MIN_LENGTH        = 4;
  const MAX_LENGTH        = 8;
  const MAX_FAILURES      = 5;
  const LOCK_SECONDS      = 900;
  const DEVICE_MAX        = 20;
  const DEVICE_WINDOW     = 900;
  const DEVICE_KEY_PREFIX = 'bazaar_pin_dev_';

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

  /** Next throttle state after a failure at $now. State: ['n' => failures, 't' => window start]. */
  public static function throttle_next( $state, int $now ): array {
    if ( ! is_array( $state ) || ! isset( $state['n'], $state['t'] ) || $now - (int) $state['t'] >= self::DEVICE_WINDOW ) {
      return [ 'n' => 1, 't' => $now ];
    }
    return [ 'n' => (int) $state['n'] + 1, 't' => (int) $state['t'] ];
  }

  /** True when the state says this device used up its failures and the window is still open. */
  public static function throttle_blocked( $state, int $now ): bool {
    return is_array( $state ) && isset( $state['n'], $state['t'] )
      && $now - (int) $state['t'] < self::DEVICE_WINDOW
      && (int) $state['n'] >= self::DEVICE_MAX;
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
   * - 429 pin_throttled: the device used its failures.
   * - 423 pin_locked (data.locked_until): the PIN is locked.
   * - 403 pin_invalid: wrong PIN, no PIN set, or the staff row is inactive.
   *
   * @throws Xophz_Compass_Bazaar_Core_Http_Error
   */
  public static function verify( int $user_id, string $pin, string $device_id = '' ): void {
    $now       = time();
    $throttle  = self::device_key( $device_id );
    if ( self::throttle_blocked( get_transient( $throttle ), $now ) ) {
      throw new Xophz_Compass_Bazaar_Core_Http_Error( 'pin_throttled', 'Too many wrong PINs from this device. Try again in a few minutes.', 429 );
    }
    $table = Xophz_Compass_Bazaar_Core_Db::table( 'staff' );
    $row   = Xophz_Compass_Bazaar_Core_Db::get_row( "SELECT pin_hash, failed_attempts, locked_until, active FROM $table WHERE user_id = %d", [ $user_id ] );
    if ( $row && self::is_locked( $row->locked_until, $now ) ) {
      throw self::locked_error( (string) $row->locked_until );
    }
    $enrolled = $row && (int) $row->active === 1 && '' !== (string) $row->pin_hash;
    if ( $enrolled && '' === self::format_error( $pin ) && wp_check_password( $pin, (string) $row->pin_hash ) ) {
      if ( (int) $row->failed_attempts > 0 ) {
        Xophz_Compass_Bazaar_Core_Db::exec( "UPDATE $table SET failed_attempts = 0, locked_until = NULL, updated_at = %s WHERE user_id = %d", [ Xophz_Compass_Bazaar_Core_Db::now(), $user_id ] );
      }
      return;
    }
    set_transient( $throttle, self::throttle_next( get_transient( $throttle ), $now ), self::DEVICE_WINDOW );
    if ( $enrolled ) {
      self::count_failure( $user_id, (int) $row->failed_attempts );
    }
    throw new Xophz_Compass_Bazaar_Core_Http_Error( 'pin_invalid', 'The PIN is not correct.', 403 );
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

  private static function device_key( string $device_id ): string {
    return self::DEVICE_KEY_PREFIX . md5( '' === $device_id ? 'none' : $device_id );
  }

  private static function locked_error( string $locked_until ): Xophz_Compass_Bazaar_Core_Http_Error {
    return new Xophz_Compass_Bazaar_Core_Http_Error( 'pin_locked', 'This PIN is locked after too many wrong attempts.', 423, [ 'locked_until' => $locked_until ] );
  }

  /**
   * One UPDATE counts the failure; the fifth sets the lock and starts the
   * count again. locked_until is assigned first because MySQL evaluates
   * SET assignments left to right.
   */
  private static function count_failure( int $user_id, int $before ): void {
    $table = Xophz_Compass_Bazaar_Core_Db::table( 'staff' );
    $max   = self::MAX_FAILURES;
    Xophz_Compass_Bazaar_Core_Db::exec(
      "UPDATE $table SET locked_until = IF(failed_attempts + 1 >= $max, DATE_ADD(UTC_TIMESTAMP(), INTERVAL " . self::LOCK_SECONDS . " SECOND), locked_until),
         failed_attempts = IF(failed_attempts + 1 >= $max, 0, failed_attempts + 1), updated_at = %s WHERE user_id = %d",
      [ Xophz_Compass_Bazaar_Core_Db::now(), $user_id ]
    );
    if ( $before + 1 < $max ) {
      return;
    }
    $locked = Xophz_Compass_Bazaar_Core_Db::get_var( "SELECT locked_until FROM $table WHERE user_id = %d", [ $user_id ] );
    if ( self::is_locked( (string) $locked, time() ) ) {
      Xophz_Compass_Bazaar_Events_Outbox::record( 'staff.pin.locked', 'staff', $user_id, [ 'locked_until' => (string) $locked ], $user_id );
      throw self::locked_error( (string) $locked );
    }
  }
}
