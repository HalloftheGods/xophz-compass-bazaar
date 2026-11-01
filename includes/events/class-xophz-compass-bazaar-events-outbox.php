<?php
/**
 * Transactional outbox: one row per business event, written in the caller's
 * transaction.
 *
 * Guarantees: the row commits or rolls back with the surrounding
 * Core_Db::tx(), and the WordPress action fires only after it committed.
 * Not guaranteed: delivery. Phase 1 has no dispatcher, so dispatched_at stays
 * NULL and listeners on the action are best effort.
 *
 * @package Xophz_Compass_Bazaar
 */
class Xophz_Compass_Bazaar_Events_Outbox {

  /**
   * Record an event, then fire do_action( 'xophz_compass_bazaar_event',
   * $type, $aggregate_type, $aggregate_id, $payload, $event_uuid, $actor )
   * after the outermost commit (at once when no transaction is open).
   */
  public static function record( string $type, string $aggregate_type, int $aggregate_id, array $payload, int $actor = 0 ): void {
    $uuid = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : self::uuid4();
    Xophz_Compass_Bazaar_Core_Db::insert(
      Xophz_Compass_Bazaar_Core_Db::table( 'events' ),
      [
        'event_uuid'     => $uuid,
        'type'           => $type,
        'aggregate_type' => $aggregate_type,
        'aggregate_id'   => $aggregate_id,
        'actor_user_id'  => $actor,
        'payload'        => Xophz_Compass_Bazaar_Core_Canonical::json( $payload ),
        'created_at'     => Xophz_Compass_Bazaar_Core_Db::now(),
      ]
    );
    Xophz_Compass_Bazaar_Core_Db::after_commit( function () use ( $type, $aggregate_type, $aggregate_id, $payload, $uuid, $actor ) {
      do_action( 'xophz_compass_bazaar_event', $type, $aggregate_type, $aggregate_id, $payload, $uuid, $actor );
    } );
  }

  private static function uuid4(): string {
    $b    = random_bytes( 16 );
    $b[6] = chr( ( ord( $b[6] ) & 0x0f ) | 0x40 );
    $b[8] = chr( ( ord( $b[8] ) & 0x3f ) | 0x80 );
    return vsprintf( '%s%s-%s-%s-%s-%s%s%s', str_split( bin2hex( $b ), 4 ) );
  }
}
