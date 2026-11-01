<?php
/**
 * The list of staff who can start a PIN session on a device.
 *
 * Spec: docs/plugins/bazaar-core-v2.md section 13.2. A user is listed when
 * their staff row is active with a PIN set and they hold bazaar_sell. Each
 * entry is {id, display_name, avatar_url}. Email and phone are never
 * returned.
 *
 * @package Xophz_Compass_Bazaar
 */
class Xophz_Compass_Bazaar_Staff_Directory {

  /** Most entries returned. */
  const LIMIT = 200;

  /**
   * Pure shaping step: keep entries with a positive id, in id order.
   *
   * @param array<int,array> $entries
   * @return array<int,array{id:int,display_name:string,avatar_url:string}>
   */
  public static function shape( array $entries ): array {
    $out = [];
    foreach ( $entries as $e ) {
      $id = (int) ( $e['id'] ?? 0 );
      if ( $id <= 0 ) {
        continue;
      }
      $name  = trim( (string) ( $e['display_name'] ?? '' ) );
      $out[] = [ 'id' => $id, 'display_name' => '' === $name ? 'Unknown' : $name, 'avatar_url' => (string) ( $e['avatar_url'] ?? '' ) ];
    }
    usort( $out, function ( $a, $b ) {
      return strcasecmp( $a['display_name'], $b['display_name'] ) ?: $a['id'] <=> $b['id'];
    } );
    return $out;
  }

  /** @return array<int,array{id:int,display_name:string,avatar_url:string}> */
  public static function enrolled(): array {
    $table = Xophz_Compass_Bazaar_Core_Db::table( 'staff' );
    $ids   = Xophz_Compass_Bazaar_Core_Db::get_results( "SELECT user_id FROM $table WHERE active = 1 AND pin_hash <> '' ORDER BY user_id LIMIT " . (int) self::LIMIT );
    $out   = [];
    foreach ( $ids as $row ) {
      $uid = (int) $row->user_id;
      if ( ! user_can( $uid, Xophz_Compass_Bazaar_Security_Capabilities::SELL ) ) {
        continue;
      }
      $user = get_userdata( $uid );
      if ( ! $user ) {
        continue;
      }
      $avatar = get_avatar_url( $uid, [ 'size' => 96 ] );
      $out[]  = [ 'id' => $uid, 'display_name' => (string) $user->display_name, 'avatar_url' => is_string( $avatar ) ? $avatar : '' ];
    }
    return self::shape( $out );
  }
}
