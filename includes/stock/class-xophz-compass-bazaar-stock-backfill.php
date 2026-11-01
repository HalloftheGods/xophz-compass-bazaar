<?php
/**
 * Opening balances for every product that manages its own stock (spec 8.8).
 *
 * run_batch() walks products and variations in ascending id order and calls
 * Stock_Service::ensure_initialized() for each one whose `_manage_stock` is
 * 'yes' and that has a `_stock` row (status other than trash and auto-draft).
 * The cursor is kept in the option bazaar_stock_backfill. When a pass reaches
 * the end with no errors and the mode is off, the mode moves to shadow.
 *
 * Guarantees:
 * - It never writes WooCommerce data: the opening batch is written to the
 *   ledger only, and products without a `_stock` row are skipped (they are
 *   initialized lazily on first touch) because locking them would add one.
 * - Re-running is a no-op for products already initialized.
 * - register() adds the Action Scheduler hook bazaar_stock_backfill_batch;
 *   each scheduled batch queues the next one until the pass is done.
 *
 * Not guaranteed:
 * - Nothing schedules a pass on its own: call schedule(), or run
 *   `wp bazaar stock backfill`.
 * - Two passes running at once both initialize safely (the service locks
 *   each product), but the cursor option is last-writer-wins.
 * - A product that fails (logged in the cursor's last_errors, at most 20) is
 *   left for lazy initialization, and the mode is then not switched.
 *
 * @package Xophz_Compass_Bazaar
 */
final class Xophz_Compass_Bazaar_Stock_Backfill {

  const HOOK       = 'bazaar_stock_backfill_batch';
  const OPTION     = 'bazaar_stock_backfill';
  const GROUP      = 'bazaar';
  const BATCH_SIZE = 200;
  const MAX_BATCH  = 1000;
  const MAX_ERRORS = 20;

  const CURSOR_DEFAULTS = [
    'after_id'    => 0,
    'done'        => false,
    'batches'     => 0,
    'processed'   => 0,
    'initialized' => 0,
    'errors'      => 0,
    'last_errors' => [],
    'started_at'  => '',
    'updated_at'  => '',
    'finished_at' => '',
  ];

  public static function register(): void {
    add_action( self::HOOK, [ self::class, 'run_scheduled' ], 10, 1 );
  }

  /** The stored cursor with every CURSOR_DEFAULTS key. */
  public static function cursor(): array {
    $stored = get_option( self::OPTION, [] );
    return array_merge( self::CURSOR_DEFAULTS, is_array( $stored ) ? array_intersect_key( $stored, self::CURSOR_DEFAULTS ) : [] );
  }

  /** Where the next pass starts: the cursor, or 0 when the last pass finished. */
  public static function resume_from(): int {
    $cursor = self::cursor();
    return $cursor['done'] ? 0 : max( 0, (int) $cursor['after_id'] );
  }

  /**
   * Queue a batch through Action Scheduler, starting at $after_id (default
   * resume_from()). False when Action Scheduler is not loaded; true when a
   * batch was queued or one is already pending.
   */
  public static function schedule( ?int $after_id = null ): bool {
    if ( ! function_exists( 'as_enqueue_async_action' ) ) {
      return false;
    }
    if ( function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( self::HOOK, null, self::GROUP ) ) {
      return true;
    }
    $after = null === $after_id ? self::resume_from() : max( 0, $after_id );
    return (bool) as_enqueue_async_action( self::HOOK, [ $after ], self::GROUP );
  }

  /** The Action Scheduler callback: one batch, then the next one queued until done. */
  public static function run_scheduled( $after_id = 0 ): void {
    $result = self::run_batch( (int) $after_id, self::BATCH_SIZE );
    if ( ! $result['done'] && function_exists( 'as_enqueue_async_action' ) ) {
      as_enqueue_async_action( self::HOOK, [ $result['last_id'] ], self::GROUP );
    }
  }

  /**
   * Initialize up to $limit products with ids above $after_id. $after_id 0
   * starts a new pass (the cursor's totals restart).
   *
   * @return array after_id, last_id, processed, initialized, errors (each
   *   ['id', 'message']), done, mode, mode_changed.
   */
  public static function run_batch( int $after_id, int $limit = self::BATCH_SIZE ): array {
    $after_id = max( 0, $after_id );
    $limit    = max( 1, min( self::MAX_BATCH, $limit ) );
    $ids      = self::candidates( $after_id, $limit );
    $service  = Xophz_Compass_Bazaar_Stock_Factory::service();
    $store    = new Xophz_Compass_Bazaar_Stock_Store_Wpdb();
    $count    = 0;
    $errors   = [];
    foreach ( $ids as $id ) {
      try {
        $had = $store->has_balances( $id );
        $service->ensure_initialized( $id );
        if ( ! $had && $store->has_balances( $id ) ) {
          $count++;
        }
      } catch ( Throwable $e ) {
        $errors[] = [ 'id' => $id, 'message' => substr( $e->getMessage(), 0, 255 ) ];
      }
    }
    $last   = $ids ? (int) end( $ids ) : $after_id;
    $done   = count( $ids ) < $limit;
    $cursor = self::advance( $after_id, $last, count( $ids ), $count, $errors, $done );
    $result = [
      'after_id'     => $after_id,
      'last_id'      => $last,
      'processed'    => count( $ids ),
      'initialized'  => $count,
      'errors'       => $errors,
      'done'         => $done,
      'mode'         => Xophz_Compass_Bazaar_Stock_Mode::current(),
      'mode_changed' => false,
    ];
    if ( $done && 0 === (int) $cursor['errors'] && Xophz_Compass_Bazaar_Stock_Mode::OFF === $result['mode'] ) {
      Xophz_Compass_Bazaar_Stock_Mode::set( Xophz_Compass_Bazaar_Stock_Mode::SHADOW, [ 'reason' => 'stock backfill finished', 'actor' => (int) get_current_user_id() ] );
      $result['mode']         = Xophz_Compass_Bazaar_Stock_Mode::current();
      $result['mode_changed'] = Xophz_Compass_Bazaar_Stock_Mode::SHADOW === $result['mode'];
    }
    return $result;
  }

  /** @return int[] Product and variation ids above $after_id that manage stock and have a `_stock` row. */
  public static function candidates( int $after_id, int $limit ): array {
    global $wpdb;
    $rows = Xophz_Compass_Bazaar_Core_Db::get_results(
      "SELECT DISTINCT p.ID FROM {$wpdb->posts} p"
        . " JOIN {$wpdb->postmeta} ms ON ms.post_id = p.ID AND ms.meta_key = '_manage_stock' AND ms.meta_value = 'yes'"
        . " WHERE p.post_type IN ('product', 'product_variation') AND p.post_status NOT IN ('trash', 'auto-draft') AND p.ID > %d"
        . " AND EXISTS (SELECT 1 FROM {$wpdb->postmeta} s WHERE s.post_id = p.ID AND s.meta_key = '_stock')"
        . ' ORDER BY p.ID LIMIT %d',
      [ $after_id, $limit ]
    );
    return array_map(
      function ( $row ) {
        return (int) $row->ID;
      },
      $rows
    );
  }

  private static function advance( int $after_id, int $last, int $processed, int $initialized, array $errors, bool $done ): array {
    $now    = Xophz_Compass_Bazaar_Core_Db::now();
    $cursor = 0 === $after_id ? array_merge( self::CURSOR_DEFAULTS, [ 'started_at' => $now ] ) : self::cursor();
    $cursor['after_id']     = $last;
    $cursor['done']         = $done;
    $cursor['batches']      = (int) $cursor['batches'] + 1;
    $cursor['processed']    = (int) $cursor['processed'] + $processed;
    $cursor['initialized']  = (int) $cursor['initialized'] + $initialized;
    $cursor['errors']       = (int) $cursor['errors'] + count( $errors );
    $cursor['last_errors']  = array_slice( array_merge( (array) $cursor['last_errors'], $errors ), -self::MAX_ERRORS );
    $cursor['updated_at']   = $now;
    $cursor['finished_at']  = $done ? $now : '';
    update_option( self::OPTION, $cursor, false );
    return $cursor;
  }
}
