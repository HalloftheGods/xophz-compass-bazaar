<?php
/**
 * `wp bazaar` commands for the stock ledger and the checkout sweeper.
 *
 * - wp bazaar stock backfill [--batch=<n>] [--async]: Stock_Backfill batches
 *   from the cursor until the pass is done (n is 1 to 1000, default 200), or
 *   with --async one Action Scheduler batch that queues the rest. Exit 1 when
 *   a product failed.
 * - wp bazaar stock verify [--format=<text|json>]: Stock_Audit::run(); exit 1
 *   when it reports a violation. Notices are printed and do not fail.
 * - wp bazaar stock reconcile [--pid=<id>]: Stock_Service::reconcile_from_wc
 *   (reason reconcile) for one product, or for every product
 *   Stock_Mode::drift_violations() lists. It moves the ledger toward
 *   WooCommerce `_stock` and never writes WooCommerce. Exit 1 when one failed.
 * - wp bazaar stock mode <mode> [--force-with-drift] [--reason=<text>]:
 *   Stock_Mode::set(); a refusal exits 1 with its code.
 * - wp bazaar checkouts sweep: Checkout_Sweeper::run() when that class
 *   exists; otherwise exit 1 and nothing is swept.
 *
 * register() adds nothing outside WP-CLI.
 *
 * @package Xophz_Compass_Bazaar
 */
final class Xophz_Compass_Bazaar_Cli_Commands {

  const SWEEPER = 'Xophz_Compass_Bazaar_Checkout_Sweeper';

  public static function register(): void {
    if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! class_exists( 'WP_CLI' ) ) {
      return;
    }
    $flag  = function ( string $name, string $description ): array {
      return [ 'type' => 'flag', 'name' => $name, 'optional' => true, 'description' => $description ];
    };
    $assoc = function ( string $name, string $description ): array {
      return [ 'type' => 'assoc', 'name' => $name, 'optional' => true, 'description' => $description ];
    };
    WP_CLI::add_command( 'bazaar stock backfill', [ self::class, 'stock_backfill' ], [
      'shortdesc' => 'Write opening ledger balances for products that manage stock; WooCommerce is not written.',
      'synopsis'  => [ $assoc( 'batch', 'Products per batch, 1 to 1000 (default 200).' ), $flag( 'async', 'Queue the pass on Action Scheduler instead of running it now.' ) ],
    ] );
    WP_CLI::add_command( 'bazaar stock verify', [ self::class, 'stock_verify' ], [
      'shortdesc' => 'Run the read-only stock audit; exits 1 on any violation.',
      'synopsis'  => [ $assoc( 'format', 'text (default) or json.' ) ],
    ] );
    WP_CLI::add_command( 'bazaar stock reconcile', [ self::class, 'stock_reconcile' ], [
      'shortdesc' => 'Record the difference between WooCommerce _stock and the ledger, moving the ledger toward WooCommerce.',
      'synopsis'  => [ $assoc( 'pid', 'One product or variation id (default: every drifted product).' ) ],
    ] );
    WP_CLI::add_command( 'bazaar stock mode', [ self::class, 'stock_mode' ], [
      'shortdesc' => 'Set the ledger mode: off, shadow or ledger.',
      'synopsis'  => [
        [ 'type' => 'positional', 'name' => 'mode', 'optional' => false, 'description' => 'off, shadow or ledger.' ],
        $flag( 'force-with-drift', 'Enter ledger mode although the audit or the engine check fails (needs --reason).' ),
        $assoc( 'reason', 'Why; kept in the mode history and the PHP error log for a forced change.' ),
      ],
    ] );
    WP_CLI::add_command( 'bazaar checkouts sweep', [ self::class, 'checkouts_sweep' ], [
      'shortdesc' => 'Run the checkout sweeper once, when this build has one.',
    ] );
  }

  public static function stock_backfill( array $args, array $assoc ): void {
    $batch = (int) ( $assoc['batch'] ?? Xophz_Compass_Bazaar_Stock_Backfill::BATCH_SIZE );
    if ( $batch < 1 || $batch > Xophz_Compass_Bazaar_Stock_Backfill::MAX_BATCH ) {
      WP_CLI::error( '--batch must be 1 to ' . Xophz_Compass_Bazaar_Stock_Backfill::MAX_BATCH . '.' );
    }
    if ( ! empty( $assoc['async'] ) ) {
      if ( ! Xophz_Compass_Bazaar_Stock_Backfill::schedule() ) {
        WP_CLI::error( 'Action Scheduler is not loaded; nothing was queued.' );
      }
      WP_CLI::success( 'Backfill queued on Action Scheduler (hook ' . Xophz_Compass_Bazaar_Stock_Backfill::HOOK . ').' );
      return;
    }
    $after  = Xophz_Compass_Bazaar_Stock_Backfill::resume_from();
    $totals = [ 'processed' => 0, 'initialized' => 0, 'errors' => 0 ];
    do {
      $result = Xophz_Compass_Bazaar_Stock_Backfill::run_batch( $after, $batch );
      foreach ( $result['errors'] as $error ) {
        WP_CLI::warning( sprintf( 'Product %d: %s', $error['id'], $error['message'] ) );
      }
      $totals['processed']   += $result['processed'];
      $totals['initialized'] += $result['initialized'];
      $totals['errors']      += count( $result['errors'] );
      WP_CLI::log( sprintf( 'Ids %d to %d: %d products, %d initialized, %d errors.', $after + 1, $result['last_id'], $result['processed'], $result['initialized'], count( $result['errors'] ) ) );
      $after = (int) $result['last_id'];
    } while ( ! $result['done'] );
    $summary = sprintf( '%d products checked, %d initialized, %d errors. Mode: %s%s.', $totals['processed'], $totals['initialized'], $totals['errors'], $result['mode'], $result['mode_changed'] ? ' (switched from off)' : '' );
    if ( $totals['errors'] ) {
      WP_CLI::error( $summary );
    }
    WP_CLI::success( $summary );
  }

  public static function stock_verify( array $args, array $assoc ): void {
    $report = Xophz_Compass_Bazaar_Stock_Audit::run();
    if ( 'json' === ( $assoc['format'] ?? 'text' ) ) {
      WP_CLI::line( (string) wp_json_encode( $report ) );
    } else {
      foreach ( $report['violations'] as $entry ) {
        WP_CLI::line( 'violation ' . $entry['type'] . ': ' . $entry['message'] );
      }
      foreach ( $report['notices'] as $entry ) {
        WP_CLI::line( 'notice ' . $entry['type'] . ': ' . $entry['message'] );
      }
    }
    if ( $report['violations'] ) {
      WP_CLI::warning( count( $report['violations'] ) . ' stock audit violations.' );
      WP_CLI::halt( 1 );
    }
    WP_CLI::success( 'Stock audit: no violations (' . count( $report['notices'] ) . ' notices).' );
  }

  public static function stock_reconcile( array $args, array $assoc ): void {
    if ( isset( $assoc['pid'] ) ) {
      $pid = (int) $assoc['pid'];
      if ( $pid <= 0 ) {
        WP_CLI::error( '--pid must be a positive id.' );
      }
      $pids = [ $pid ];
    } else {
      $pids = array_map( 'intval', array_column( Xophz_Compass_Bazaar_Stock_Mode::drift_violations(), 'stock_product_id' ) );
    }
    $meta   = new Xophz_Compass_Bazaar_Stock_Meta( [ 'actor_user_id' => max( 0, (int) get_current_user_id() ) ] );
    $failed = 0;
    foreach ( $pids as $pid ) {
      try {
        $result = Xophz_Compass_Bazaar_Stock_Factory::service()->reconcile_from_wc( $pid, 'reconcile', $meta, [ 'type' => 'cli' ] );
      } catch ( Throwable $e ) {
        $failed++;
        WP_CLI::warning( sprintf( 'Product %d: %s', $pid, $e->getMessage() ) );
        continue;
      }
      if ( null === $result ) {
        WP_CLI::log( sprintf( 'Product %d: no difference recorded.', $pid ) );
        continue;
      }
      $delta = array_sum( array_map( 'floatval', array_column( $result->lines(), 'qty_delta' ) ) );
      WP_CLI::log( sprintf( 'Product %d: recorded %s at location 0 (batch #%d).', $pid, Xophz_Compass_Bazaar_Stock_Keys::number( $delta ), $result->batch_id() ) );
    }
    $summary = sprintf( '%d products reconciled, %d failed.', count( $pids ) - $failed, $failed );
    if ( $failed ) {
      WP_CLI::error( $summary );
    }
    WP_CLI::success( $summary );
  }

  public static function stock_mode( array $args, array $assoc ): void {
    try {
      $result = Xophz_Compass_Bazaar_Stock_Mode::set(
        (string) ( $args[0] ?? '' ),
        [
          'force_with_drift' => ! empty( $assoc['force-with-drift'] ),
          'reason'           => (string) ( $assoc['reason'] ?? '' ),
          'actor'            => (int) get_current_user_id(),
        ]
      );
    } catch ( Xophz_Compass_Bazaar_Core_Http_Error $e ) {
      WP_CLI::error( sprintf( '%s: %s %s', $e->error_code(), $e->getMessage(), (string) wp_json_encode( $e->data() ) ) );
    }
    WP_CLI::line( (string) wp_json_encode( $result ) );
    WP_CLI::success( $result['changed'] ? sprintf( 'Mode %s (was %s)%s.', $result['mode'], $result['previous'], $result['forced'] ? ', forced' : '' ) : sprintf( 'Mode already %s; nothing changed.', $result['mode'] ) );
  }

  public static function checkouts_sweep( array $args, array $assoc ): void {
    if ( ! class_exists( self::SWEEPER ) || ! method_exists( self::SWEEPER, 'run' ) ) {
      WP_CLI::error( 'Checkout_Sweeper is not available in this build; nothing was swept.' );
    }
    $method = new ReflectionMethod( self::SWEEPER, 'run' );
    $class  = self::SWEEPER;
    $result = $method->isStatic() ? $class::run() : ( new $class() )->run();
    WP_CLI::success( 'Checkout sweep finished: ' . (string) wp_json_encode( $result ) );
  }
}
