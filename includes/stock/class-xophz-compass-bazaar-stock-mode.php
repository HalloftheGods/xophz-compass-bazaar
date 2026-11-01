<?php
/**
 * The ledger mode (spec 8.8 and 17): off, shadow or ledger.
 *
 * Storage: the option bazaar_ledger_mode holds the plain mode string, so
 * `wp option update bazaar_ledger_mode shadow` stays a working rollback. The
 * history of changes made through set() is kept in the option
 * bazaar_ledger_mode_history (the newest HISTORY_MAX entries).
 *
 * Guarantees:
 * - current() is 'off' unless the option holds one of the three modes.
 * - set( 'ledger' ) from another mode is refused (409 ledger_mode_refused)
 *   unless Schema_Installer engines are all InnoDB and the audit reports zero
 *   violations, or $opts has force_with_drift together with a non-empty
 *   reason. A forced change is recorded as forced, with the reason, in the
 *   history and in the PHP error log.
 * - The audit is $opts['audit'] when given (a callable returning an int, or
 *   an array whose count is the number of violations), else Stock_Audit::run
 *   when that class exists (WP13), else drift_violations().
 *
 * Not guaranteed: set() does not lock; two concurrent set() calls both pass
 * their checks and the later write wins.
 *
 * @package Xophz_Compass_Bazaar
 */
final class Xophz_Compass_Bazaar_Stock_Mode {

  const OPTION         = 'bazaar_ledger_mode';
  const HISTORY_OPTION = 'bazaar_ledger_mode_history';
  const HISTORY_MAX    = 50;
  const OFF            = 'off';
  const SHADOW         = 'shadow';
  const LEDGER         = 'ledger';
  const MODES          = [ self::OFF, self::SHADOW, self::LEDGER ];
  const TABLES         = [ 'stock_batches', 'stock_movements', 'stock_balances', 'checkouts', 'payments', 'refunds', 'staff', 'staff_sessions', 'approvals', 'events' ];

  public static function current(): string {
    $mode = get_option( self::OPTION, self::OFF );
    return in_array( $mode, self::MODES, true ) ? $mode : self::OFF;
  }

  public static function is_ledger(): bool {
    return self::LEDGER === self::current();
  }

  /**
   * Change the mode.
   *
   * @param array $opts force_with_drift (bool), reason (string, 255 characters
   *   kept), audit (callable), actor (int user id; defaults to the current user).
   * @return array mode, previous, changed, forced, engines_ok, violations
   *   (null when no check ran).
   * @throws Xophz_Compass_Bazaar_Core_Http_Error 400 invalid_request for an
   *   unknown mode, 409 ledger_mode_refused when the checks fail.
   */
  public static function set( string $mode, array $opts = [] ): array {
    if ( ! in_array( $mode, self::MODES, true ) ) {
      throw new Xophz_Compass_Bazaar_Core_Http_Error( 'invalid_request', 'mode must be off, shadow or ledger.', 400, [ 'errors' => [ 'mode' => 'must be off, shadow or ledger' ] ] );
    }
    $previous = self::current();
    $reason   = substr( trim( (string) ( $opts['reason'] ?? '' ) ), 0, 255 );
    $result   = [ 'mode' => $mode, 'previous' => $previous, 'changed' => false, 'forced' => false, 'engines_ok' => null, 'violations' => null ];
    if ( $mode === $previous ) {
      return $result;
    }
    if ( self::LEDGER === $mode ) {
      $result['engines_ok'] = self::engines_ok();
      $result['violations'] = self::audit_count( $opts['audit'] ?? null );
      if ( ! $result['engines_ok'] || 0 !== $result['violations'] ) {
        if ( empty( $opts['force_with_drift'] ) || '' === $reason ) {
          throw new Xophz_Compass_Bazaar_Core_Http_Error(
            'ledger_mode_refused',
            $result['engines_ok'] ? 'Ledger mode needs a clean stock audit. Reconcile first, or force with a reason.' : 'Ledger mode needs every Bazaar table and postmeta on InnoDB.',
            409,
            [ 'engines_ok' => $result['engines_ok'], 'violations' => $result['violations'] ]
          );
        }
        $result['forced'] = true;
        error_log( sprintf( 'Bazaar ledger mode forced on with drift by user %d (violations: %d, engines_ok: %s): %s', (int) ( $opts['actor'] ?? get_current_user_id() ), $result['violations'], $result['engines_ok'] ? 'yes' : 'no', $reason ) );
      }
    }
    update_option( self::OPTION, $mode, true );
    self::remember( $previous, $mode, $result, $reason, (int) ( $opts['actor'] ?? get_current_user_id() ) );
    $result['changed'] = true;
    return $result;
  }

  /** @return array[] The recorded changes, oldest first. */
  public static function history(): array {
    $history = get_option( self::HISTORY_OPTION, [] );
    return is_array( $history ) ? array_values( $history ) : [];
  }

  /** Schema_Installer::engines_ok() when it exists, else: the ten Bazaar tables and postmeta all report InnoDB. */
  public static function engines_ok(): bool {
    if ( method_exists( 'Xophz_Compass_Bazaar_Schema_Installer', 'engines_ok' ) ) {
      return (bool) Xophz_Compass_Bazaar_Schema_Installer::engines_ok();
    }
    $engines = Xophz_Compass_Bazaar_Schema_Installer::engines();
    foreach ( array_merge( [ 'postmeta' ], array_map( function ( $name ) {
      return 'bazaar_' . $name;
    }, self::TABLES ) ) as $key ) {
      if ( 'innodb' !== strtolower( (string) ( $engines[ $key ] ?? '' ) ) ) {
        return false;
      }
    }
    return true;
  }

  /**
   * I2 drift: products with balances whose `_stock` (lowest meta_id row)
   * differs from location 0 plus every enabled location by 0.00005 or more.
   * Products whose post no longer exists, that have no `_stock` row, or
   * that no longer manage stock (`_manage_stock` is not 'yes') are skipped:
   * no ledger write reaches them any more, so a difference could never be
   * cleared (finding 17 of #6983). This checks only I2; it is not the full
   * Stock_Audit.
   *
   * @return array[] Each ['stock_product_id', 'wc_stock', 'projectable'].
   */
  public static function drift_violations(): array {
    global $wpdb;
    $rows = Xophz_Compass_Bazaar_Core_Db::get_results(
      'SELECT b.stock_product_id, SUM(CASE WHEN b.location_id = 0 OR b.is_enabled = 1 THEN b.on_hand ELSE 0 END) AS projectable,'
        . " (SELECT pm.meta_value FROM {$wpdb->postmeta} pm WHERE pm.post_id = b.stock_product_id AND pm.meta_key = '_stock' ORDER BY pm.meta_id LIMIT 1) AS wc_stock,"
        . " (SELECT pm.meta_value FROM {$wpdb->postmeta} pm WHERE pm.post_id = b.stock_product_id AND pm.meta_key = '_manage_stock' ORDER BY pm.meta_id LIMIT 1) AS manages"
        . ' FROM ' . Xophz_Compass_Bazaar_Core_Db::table( 'stock_balances' ) . " b JOIN {$wpdb->posts} p ON p.ID = b.stock_product_id"
        . ' GROUP BY b.stock_product_id ORDER BY b.stock_product_id'
    );
    $out = [];
    foreach ( $rows as $row ) {
      if ( null === $row->wc_stock || 'yes' !== (string) $row->manages ) {
        continue;
      }
      $wc          = is_numeric( $row->wc_stock ) ? (float) $row->wc_stock : 0.0;
      $projectable = Xophz_Compass_Bazaar_Stock_Line::qty( (float) $row->projectable );
      if ( Xophz_Compass_Bazaar_Stock_Planner::differs( $wc, $projectable ) ) {
        $out[] = [ 'stock_product_id' => (int) $row->stock_product_id, 'wc_stock' => $wc, 'projectable' => $projectable ];
      }
    }
    return $out;
  }

  /** @param callable|null $audit */
  private static function audit_count( $audit ): int {
    if ( null === $audit ) {
      $audit = method_exists( 'Xophz_Compass_Bazaar_Stock_Audit', 'run' )
        ? [ 'Xophz_Compass_Bazaar_Stock_Audit', 'run' ]
        : [ self::class, 'drift_violations' ];
    }
    if ( ! is_callable( $audit ) ) {
      throw new InvalidArgumentException( 'The stock mode audit must be callable.' );
    }
    $found = call_user_func( $audit );
    if ( is_array( $found ) ) {
      return isset( $found['violations'] ) && is_array( $found['violations'] ) ? count( $found['violations'] ) : count( $found );
    }
    return max( 0, (int) $found );
  }

  private static function remember( string $from, string $to, array $result, string $reason, int $actor ): void {
    $history   = self::history();
    $history[] = [
      'from'       => $from,
      'to'         => $to,
      'at'         => Xophz_Compass_Bazaar_Core_Db::now(),
      'actor'      => max( 0, $actor ),
      'forced'     => $result['forced'],
      'reason'     => $reason,
      'engines_ok' => $result['engines_ok'],
      'violations' => $result['violations'],
    ];
    update_option( self::HISTORY_OPTION, array_slice( $history, -self::HISTORY_MAX ), false );
  }
}
