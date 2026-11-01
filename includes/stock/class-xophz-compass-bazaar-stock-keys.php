<?php
/**
 * Builders for every stock batch key in spec 8.10.
 *
 * Every builder returns a key of at most MAX_LENGTH (191) characters, the
 * width of the idem_key column, or throws InvalidArgumentException. Ids must
 * be positive, client uuids are 1 to 64 of [A-Za-z0-9_-] and file hashes are
 * 64 lower-case hex characters, so the longest possible key is well under the
 * limit (see stock-planner-test.php).
 *
 * @package Xophz_Compass_Bazaar
 */
final class Xophz_Compass_Bazaar_Stock_Keys {

  const MAX_LENGTH = 191;

  /** opening:v1:{pid} */
  public static function opening( int $pid ): string {
    return self::finish( 'opening:v1:' . self::id( $pid ) );
  }

  /** reconcile:{pid}:{last_movement_id}:{wc_value}; wc_value is a plain decimal (5, 5.5, -2). */
  public static function reconcile( int $pid, int $last_movement_id, float $wc_value ): string {
    if ( $last_movement_id < 0 ) {
      throw new InvalidArgumentException( 'last_movement_id must be 0 or more.' );
    }
    return self::finish( 'reconcile:' . self::id( $pid ) . ':' . $last_movement_id . ':' . self::number( $wc_value ) );
  }

  /** order:{order_id}:sync:{last_order_movement_id}:{c|r}; c = committed, r = released. */
  public static function order_sync( int $order_id, int $last_order_movement_id, bool $committed ): string {
    if ( $last_order_movement_id < 0 ) {
      throw new InvalidArgumentException( 'last_order_movement_id must be 0 or more.' );
    }
    return self::finish( 'order:' . self::id( $order_id ) . ':sync:' . $last_order_movement_id . ':' . ( $committed ? 'c' : 'r' ) );
  }

  /** refund:{wc_refund_id}:restock */
  public static function refund_restock( int $wc_refund_id ): string {
    return self::finish( 'refund:' . self::id( $wc_refund_id ) . ':restock' );
  }

  /** po:{po_id}:receive */
  public static function po_receive( int $po_id ): string {
    return self::finish( 'po:' . self::id( $po_id ) . ':receive' );
  }

  /** adjust:{user_id}:{client_uuid}, for a manual adjust or count. */
  public static function adjust( int $user_id, string $client_uuid ): string {
    return self::finish( 'adjust:' . self::id( $user_id ) . ':' . self::uuid( $client_uuid ) );
  }

  /** import:{sha256 of the file}:{row}; row counts from 1. */
  public static function import( string $sha256, int $row ): string {
    if ( ! preg_match( '/^[0-9a-f]{64}$/', $sha256 ) ) {
      throw new InvalidArgumentException( 'An import key needs the 64-character lower-case sha256 of the file.' );
    }
    return self::finish( 'import:' . $sha256 . ':' . self::id( $row ) );
  }

  /** transfer:{client_uuid} */
  public static function transfer( string $client_uuid ): string {
    return self::finish( 'transfer:' . self::uuid( $client_uuid ) );
  }

  /** A quantity as it appears in a key: at most 4 decimals, no trailing zeros, never "-0". */
  public static function number( float $value ): string {
    $text = rtrim( rtrim( sprintf( '%.4F', Xophz_Compass_Bazaar_Stock_Line::qty( $value ) ), '0' ), '.' );
    return '-0' === $text ? '0' : $text;
  }

  private static function id( int $id ): int {
    if ( $id <= 0 ) {
      throw new InvalidArgumentException( 'Stock key ids must be positive.' );
    }
    return $id;
  }

  private static function uuid( string $client_uuid ): string {
    if ( ! preg_match( '/^[A-Za-z0-9_-]{1,64}$/', $client_uuid ) ) {
      throw new InvalidArgumentException( 'A client uuid must be 1 to 64 characters of letters, digits, _ or -.' );
    }
    return $client_uuid;
  }

  private static function finish( string $key ): string {
    if ( strlen( $key ) > self::MAX_LENGTH ) {
      throw new InvalidArgumentException( 'Stock key longer than 191 characters.' );
    }
    return $key;
  }
}
