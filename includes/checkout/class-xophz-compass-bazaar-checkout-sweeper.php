<?php
/**
 * Recovers checkouts whose lease expired (spec 11.4).
 *
 * register() hooks the action bazaar_checkout_sweep and schedules it every
 * 300 seconds through Action Scheduler (group bazaar) when Action Scheduler is
 * loaded, else through WP-Cron with a 300-second schedule. The schedule is
 * checked on admin_init and during cron runs only, so a store whose admin is
 * never opened and whose cron never runs is never swept.
 *
 * run($limit) takes the oldest expired rows (received, priced, order_created,
 * committed), takes over each lease, then:
 * - committed: finalizes it (money and stock are already consistent). The
 *   finalizer takes the finalize lock before it takes the lease, so a
 *   request still finalizing the row is waited for, never overlapped;
 * - order_created: cancels the order (never deletes it) and the row;
 * - received and priced: fails the row, retryable, with checkout_interrupted.
 * A row whose lease another request took first is skipped.
 *
 * @package Xophz_Compass_Bazaar
 */
class Xophz_Compass_Bazaar_Checkout_Sweeper {

  const HOOK          = 'bazaar_checkout_sweep';
  const GROUP         = 'bazaar';
  const INTERVAL      = 300;
  const CRON_SCHEDULE = 'bazaar_five_minutes';
  const DEFAULT_LIMIT = 50;

  public static function register(): void {
    add_action( self::HOOK, [ self::class, 'run_scheduled' ] );
    add_filter( 'cron_schedules', [ self::class, 'cron_schedules' ] );
    add_action( 'admin_init', [ self::class, 'ensure_scheduled' ] );
    if ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) {
      add_action( 'init', [ self::class, 'ensure_scheduled' ], 20 );
    }
  }

  /** @param array $schedules */
  public static function cron_schedules( $schedules ): array {
    $schedules = is_array( $schedules ) ? $schedules : [];
    $schedules[ self::CRON_SCHEDULE ] = [ 'interval' => self::INTERVAL, 'display' => 'Every 5 minutes (Bazaar checkout sweep)' ];
    return $schedules;
  }

  /** Schedule the recurring sweep once, through Action Scheduler or WP-Cron. Returns which one holds it. */
  public static function ensure_scheduled(): string {
    if ( function_exists( 'as_has_scheduled_action' ) && function_exists( 'as_schedule_recurring_action' ) ) {
      if ( ! as_has_scheduled_action( self::HOOK, [], self::GROUP ) ) {
        as_schedule_recurring_action( time() + self::INTERVAL, self::INTERVAL, self::HOOK, [], self::GROUP );
      }
      $legacy = wp_next_scheduled( self::HOOK );
      if ( $legacy ) {
        wp_unschedule_event( $legacy, self::HOOK );
      }
      return 'action_scheduler';
    }
    if ( ! wp_next_scheduled( self::HOOK ) ) {
      wp_schedule_event( time() + self::INTERVAL, self::CRON_SCHEDULE, self::HOOK );
    }
    return 'wp_cron';
  }

  public static function run_scheduled(): void {
    self::run( self::DEFAULT_LIMIT );
  }

  /**
   * @return array{finalized:int,cancelled:int,failed:int,skipped:int,finalize_failed:int}
   */
  public static function run( int $limit = self::DEFAULT_LIMIT, ?Xophz_Compass_Bazaar_Checkout_Service $service = null ): array {
    $service = $service ?? new Xophz_Compass_Bazaar_Checkout_Service();
    $counts  = [ 'finalized' => 0, 'cancelled' => 0, 'failed' => 0, 'skipped' => 0, 'finalize_failed' => 0 ];
    foreach ( Xophz_Compass_Bazaar_Checkout_Repository::expired( $limit ) as $row ) {
      try {
        $outcome = self::recover( $row, $service );
      } catch ( Throwable $e ) {
        error_log( 'Bazaar checkout sweep failed for checkout ' . (int) $row['id'] . ': ' . $e->getMessage() );
        $outcome = 'skipped';
      }
      $counts[ $outcome ]++;
    }
    return $counts;
  }

  /** One expired row. @return string A key of run()'s counts. */
  public static function recover( array $row, Xophz_Compass_Bazaar_Checkout_Service $service ): string {
    $S = 'Xophz_Compass_Bazaar_Checkout_State';
    if ( $S::COMMITTED === $row['state'] ) {
      $done = $service->resume( $row, true );
      if ( null === $done ) {
        return 'finalize_failed';
      }
      return $S::COMPLETED === $done['state'] ? 'finalized' : ( $S::CANCELLED === $done['state'] ? 'cancelled' : 'skipped' );
    }
    $row = Xophz_Compass_Bazaar_Checkout_Repository::take_expired( $row );
    if ( null === $row ) {
      return 'skipped';
    }
    if ( $S::ORDER_CREATED === $row['state'] ) {
      return $service->cancel( $row, Xophz_Compass_Bazaar_Checkout_Service::interrupted() ) ? 'cancelled' : 'skipped';
    }
    if ( $S::RECEIVED === $row['state'] || $S::PRICED === $row['state'] ) {
      $err  = new Xophz_Compass_Bazaar_Core_Http_Error( 'checkout_interrupted', 'The checkout stopped before an order was recorded. Nothing was charged.', 409 );
      Xophz_Compass_Bazaar_Checkout_Order_Writer::cancel_unrecorded( $row, 'checkout_interrupted', 'swept' );
      $done = Xophz_Compass_Bazaar_Checkout_Repository::transition( $row, $S::FAILED, Xophz_Compass_Bazaar_Checkout_Service::error_changes( $err, true ) );
      return $done ? 'failed' : 'skipped';
    }
    return 'skipped';
  }
}
