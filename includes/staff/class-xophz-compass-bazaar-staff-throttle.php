<?php
/**
 * Staff throttle: failure counters that hold under parallel requests.
 *
 * Spec: docs/plugins/bazaar-core-v2.md section 13.1 (review fix #6986).
 *
 * A bucket is a string key such as "pin:device:<id>" with a maximum. Its
 * count for one fixed 15-minute window is one wp_options row named
 * _bazaar_thr_<window, 10 digits>_<md5(bucket)>. The rows are written with
 * plain SQL and never read through the options API, so the object cache and
 * autoload never hold them.
 *
 * claim() counts first and decides after. One UPDATE per bucket adds one and
 * hands this connection the new value through LAST_INSERT_ID(expr), so N
 * parallel claims see N different values and only the first `max` pass,
 * however the requests interleave. A refused claim gives its slots back, so
 * refused requests never count. release() gives a passed claim back once
 * the work it guarded turned out not to be a failure (a right PIN, say).
 *
 * The first claim of a bucket in a window deletes the rows of earlier windows.
 *
 * @package Xophz_Compass_Bazaar
 */
class Xophz_Compass_Bazaar_Staff_Throttle {

  const WINDOW = 900;
  const PREFIX = '_bazaar_thr_';

  /** Fixed window number for a Unix time. */
  public static function window( int $now ): int {
    return intdiv( $now, self::WINDOW );
  }

  /** Seconds until the window holding $now ends (1 to WINDOW). */
  public static function seconds_left( int $now ): int {
    return self::WINDOW - ( $now % self::WINDOW );
  }

  /** The wp_options row name for a bucket in a window. Names sort by window. */
  public static function option_name( string $bucket, int $window ): string {
    return self::PREFIX . sprintf( '%010d', $window ) . '_' . md5( $bucket );
  }

  /**
   * Whether every bucket's count is within its maximum.
   *
   * @param array<string,int> $counts  bucket => count after the claim
   * @param array<string,int> $buckets bucket => maximum
   */
  public static function within( array $counts, array $buckets ): bool {
    foreach ( $buckets as $bucket => $max ) {
      if ( ! isset( $counts[ $bucket ] ) || (int) $counts[ $bucket ] > (int) $max ) {
        return false;
      }
    }
    return true;
  }

  /**
   * The throttle key for a remote address: IPv4 as is (also when sent as
   * ::ffff:a.b.c.d), IPv6 cut to its /64 because one host usually holds a
   * whole /64, and '' when the value is not an IP address.
   */
  public static function ip_key( string $ip ): string {
    if ( false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
      return $ip;
    }
    if ( false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
      return '';
    }
    $bin = inet_pton( $ip );
    if ( false === $bin || 16 !== strlen( $bin ) ) {
      return '';
    }
    if ( str_repeat( "\0", 10 ) . "\xff\xff" === substr( $bin, 0, 12 ) ) {
      return (string) inet_ntop( substr( $bin, 12 ) );
    }
    return bin2hex( substr( $bin, 0, 8 ) ) . '::/64';
  }

  /**
   * The caller's address from REMOTE_ADDR only (forwarded headers are set by
   * the client and are never trusted). '' under WP-CLI, where REMOTE_ADDR is
   * a made-up 127.0.0.1 shared by every command.
   */
  public static function remote_ip(): string {
    if ( defined( 'WP_CLI' ) && WP_CLI ) {
      return '';
    }
    return isset( $_SERVER['REMOTE_ADDR'] ) && is_string( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : '';
  }

  /**
   * Claim one slot in every bucket for $window. True when all of them are
   * within their maximum (the slots stay claimed); otherwise the slots are
   * given back and the answer is false.
   *
   * @param array<string,int> $buckets bucket => maximum
   */
  public static function claim( array $buckets, int $window ): bool {
    $counts = [];
    foreach ( $buckets as $bucket => $max ) {
      $counts[ $bucket ] = self::increment( (string) $bucket, $window );
    }
    if ( self::within( $counts, $buckets ) ) {
      return true;
    }
    self::release( $buckets, $window );
    return false;
  }

  /**
   * Give back one slot in every bucket for $window (never below zero).
   *
   * @param array<string,int> $buckets bucket => maximum
   */
  public static function release( array $buckets, int $window ): void {
    global $wpdb;
    foreach ( array_keys( $buckets ) as $bucket ) {
      Xophz_Compass_Bazaar_Core_Db::exec(
        "UPDATE {$wpdb->options} SET option_value = GREATEST(CAST(option_value AS SIGNED) - 1, 0) WHERE option_name = %s",
        [ self::option_name( (string) $bucket, $window ) ]
      );
    }
  }

  /** Add one to the bucket's row (creating it at 0 first) and return the new count. */
  private static function increment( string $bucket, int $window ): int {
    global $wpdb;
    $name = self::option_name( $bucket, $window );
    $add  = "UPDATE {$wpdb->options} SET option_value = LAST_INSERT_ID(CAST(option_value AS UNSIGNED) + 1) WHERE option_name = %s";
    if ( 1 !== Xophz_Compass_Bazaar_Core_Db::exec( $add, [ $name ] ) ) {
      Xophz_Compass_Bazaar_Core_Db::exec( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, '0', 'no')", [ $name ] );
      if ( 1 !== Xophz_Compass_Bazaar_Core_Db::exec( $add, [ $name ] ) ) {
        return PHP_INT_MAX; // No row to count on: refuse rather than guess.
      }
    }
    $count = (int) Xophz_Compass_Bazaar_Core_Db::get_var( 'SELECT LAST_INSERT_ID()' );
    if ( 1 === $count ) {
      self::purge( $window );
    }
    return $count;
  }

  /** Delete the counter rows of windows before $window. */
  private static function purge( int $window ): void {
    global $wpdb;
    Xophz_Compass_Bazaar_Core_Db::exec(
      "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name < %s",
      [ $wpdb->esc_like( self::PREFIX ) . '%', self::PREFIX . sprintf( '%010d', $window ) ]
    );
  }
}
