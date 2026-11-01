<?php
/**
 * Thin wpdb wrapper: transactions with deadlock retry, typed errors, and
 * after-commit / after-rollback hooks.
 *
 * Every datetime column is UTC. Use now() for values and UTC_TIMESTAMP() in SQL.
 *
 * Guarantees: nested tx() calls join the outer transaction; only the outermost
 * call commits, rolls back and retries. Not guaranteed: partial undo. There are
 * no savepoints, so a nested tx() whose exception is caught by the caller keeps
 * its writes unless the caller lets the exception reach the outermost tx().
 * Retries re-run the whole callable, so it must be safe to run again.
 *
 * After-commit and after-rollback callbacks are best effort: each runs in its
 * own try/catch, a Throwable is logged and the next callback still runs, so a
 * committed transaction never surfaces as an exception from tx() (and a
 * rollback keeps its original exception). callback_errors() lists what the
 * last outermost tx() caught.
 *
 * @package Xophz_Compass_Bazaar
 */
class Xophz_Compass_Bazaar_Core_Db {

  /** @var int Open tx() depth; 0 means no transaction is open. */
  private static $depth = 0;

  /** @var callable[] */
  private static $commit_callbacks = [];

  /** @var callable[] */
  private static $rollback_callbacks = [];

  /** @var string[] Messages of the callbacks that threw in the last outermost tx(). */
  private static $callback_errors = [];

  /**
   * Run $fn in a transaction and return its result. The outermost call retries
   * up to $retries more times, after a 20-80 ms pause, when $fn or its
   * statements raise Core_Deadlock_Exception (MySQL 1213 or 1205).
   *
   * @return mixed
   * @throws Throwable Whatever $fn throws, after the rollback.
   */
  public static function tx( callable $fn, int $retries = 2 ) {
    if ( self::$depth > 0 ) {
      self::$depth++;
      try {
        return $fn();
      } finally {
        self::$depth--;
      }
    }
    $attempt = 0;
    while ( true ) {
      self::raw( 'START TRANSACTION' );
      self::$depth              = 1;
      self::$callback_errors    = [];
      self::$commit_callbacks   = [];
      self::$rollback_callbacks = [];
      try {
        $result = $fn();
        self::raw( 'COMMIT' );
      } catch ( Throwable $e ) {
        self::$depth = 0;
        try {
          self::raw( 'ROLLBACK' );
        } catch ( Throwable $ignored ) {
          // The original exception is the one worth reporting.
          unset( $ignored );
        }
        $rollbacks                = self::$rollback_callbacks;
        self::$commit_callbacks   = [];
        self::$rollback_callbacks = [];
        self::run_all( $rollbacks, 'after-rollback' );
        if ( $e instanceof Xophz_Compass_Bazaar_Core_Deadlock_Exception && $attempt < $retries ) {
          $attempt++;
          usleep( random_int( 20000, 80000 ) );
          continue;
        }
        throw $e;
      }
      self::$depth              = 0;
      $commits                  = self::$commit_callbacks;
      self::$commit_callbacks   = [];
      self::$rollback_callbacks = [];
      self::run_all( $commits, 'after-commit' );
      return $result;
    }
  }

  public static function in_tx(): bool {
    return self::$depth > 0;
  }

  /** @return string[] "<phase>: <class>: <message>" for each callback that threw in the last outermost tx(). */
  public static function callback_errors(): array {
    return self::$callback_errors;
  }

  /** Run $fn after the outermost COMMIT; dropped on rollback; runs at once outside a transaction. */
  public static function after_commit( callable $fn ): void {
    if ( self::$depth > 0 ) {
      self::$commit_callbacks[] = $fn;
      return;
    }
    $fn();
  }

  /** Run $fn after the outermost ROLLBACK (cache clean-up). Dropped on commit and outside a transaction. */
  public static function after_rollback( callable $fn ): void {
    if ( self::$depth > 0 ) {
      self::$rollback_callbacks[] = $fn;
    }
  }

  /**
   * Prepare (when $args is not empty) and run a write. Returns the affected rows.
   *
   * @throws Xophz_Compass_Bazaar_Core_Duplicate_Key_Exception On MySQL 1062.
   * @throws Xophz_Compass_Bazaar_Core_Deadlock_Exception On MySQL 1213 or 1205.
   * @throws RuntimeException On any other database error.
   */
  public static function exec( string $sql, array $args = [] ): int {
    return (int) self::raw( self::prepare( $sql, $args ) );
  }

  /**
   * Insert one row into a full table name (see table()) and return the insert id.
   * Same exceptions as exec().
   */
  public static function insert( string $table, array $row ): int {
    global $wpdb;
    self::quiet( function () use ( $wpdb, $table, $row ) {
      $wpdb->insert( $table, $row );
    } );
    self::throw_last_error();
    return (int) $wpdb->insert_id;
  }

  /** @return object|null */
  public static function get_row( string $sql, array $args = [] ) {
    global $wpdb;
    $query  = self::prepare( $sql, $args );
    $result = null;
    self::quiet( function () use ( $wpdb, $query, &$result ) {
      $result = $wpdb->get_row( $query );
    } );
    self::throw_last_error();
    return $result;
  }

  /** @return object[] */
  public static function get_results( string $sql, array $args = [] ): array {
    global $wpdb;
    $query  = self::prepare( $sql, $args );
    $result = [];
    self::quiet( function () use ( $wpdb, $query, &$result ) {
      $result = $wpdb->get_results( $query );
    } );
    self::throw_last_error();
    return is_array( $result ) ? $result : [];
  }

  /** @return string|null */
  public static function get_var( string $sql, array $args = [] ) {
    global $wpdb;
    $query  = self::prepare( $sql, $args );
    $result = null;
    self::quiet( function () use ( $wpdb, $query, &$result ) {
      $result = $wpdb->get_var( $query );
    } );
    self::throw_last_error();
    return $result;
  }

  /** Full table name for a Bazaar table: $wpdb->prefix . 'bazaar_' . $name. */
  public static function table( string $name ): string {
    global $wpdb;
    return $wpdb->prefix . 'bazaar_' . $name;
  }

  /** Current UTC time as 'Y-m-d H:i:s'. */
  public static function now(): string {
    return gmdate( 'Y-m-d H:i:s' );
  }

  /** @return string The prepared query, or $sql unchanged when there are no arguments. */
  private static function prepare( string $sql, array $args ): string {
    global $wpdb;
    if ( ! $args ) {
      return $sql;
    }
    $prepared = $wpdb->prepare( $sql, ...array_values( $args ) );
    if ( ! is_string( $prepared ) ) {
      throw new RuntimeException( 'Bazaar database query could not be prepared.' );
    }
    return $prepared;
  }

  /** @return int|bool wpdb::query() result, after errors were turned into exceptions. */
  private static function raw( string $query ) {
    global $wpdb;
    $result = null;
    self::quiet( function () use ( $wpdb, $query, &$result ) {
      $result = $wpdb->query( $query );
    } );
    self::throw_last_error();
    return $result;
  }

  /** Run $fn with wpdb error printing off, restoring the previous setting. */
  private static function quiet( callable $fn ): void {
    global $wpdb;
    $was = $wpdb->suppress_errors( true );
    try {
      $fn();
    } finally {
      $wpdb->suppress_errors( $was );
    }
  }

  private static function throw_last_error(): void {
    global $wpdb;
    $message = (string) $wpdb->last_error;
    if ( '' === $message ) {
      return;
    }
    $errno = ( isset( $wpdb->dbh ) && $wpdb->dbh instanceof mysqli ) ? (int) $wpdb->dbh->errno : 0;
    if ( 1062 === $errno || false !== stripos( $message, 'Duplicate entry' ) ) {
      throw new Xophz_Compass_Bazaar_Core_Duplicate_Key_Exception( $message, 1062 );
    }
    if ( 1213 === $errno || 1205 === $errno || false !== stripos( $message, 'Deadlock found' ) || false !== stripos( $message, 'Lock wait timeout' ) ) {
      throw new Xophz_Compass_Bazaar_Core_Deadlock_Exception( $message, $errno ?: 1213 );
    }
    throw new RuntimeException( 'Bazaar database error: ' . $message );
  }

  /**
   * Run every callback; one that throws is logged and recorded, never rethrown.
   *
   * @param callable[] $callbacks
   */
  private static function run_all( array $callbacks, string $phase ): void {
    foreach ( $callbacks as $callback ) {
      try {
        $callback();
      } catch ( Throwable $e ) {
        $message                 = $phase . ': ' . get_class( $e ) . ': ' . $e->getMessage();
        self::$callback_errors[] = $message;
        error_log( 'Bazaar ' . $message );
      }
    }
  }
}
