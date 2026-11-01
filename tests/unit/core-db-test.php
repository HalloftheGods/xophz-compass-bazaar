<?php
/**
 * Core_Db::tx() callbacks are best effort (#6985): an after-commit callback
 * that throws never turns a committed transaction into an exception, and the
 * callbacks after it still run. An after-rollback callback that throws keeps
 * the original exception.
 */
require_once dirname( __DIR__ ) . '/support/harness.php';
require_once dirname( dirname( __DIR__ ) ) . '/includes/core/class-xophz-compass-bazaar-core-db.php';

if ( ! class_exists( 'Bazaar_Test_Core_Db_Wpdb' ) ) {
  /** The few wpdb members Core_Db::tx() touches; records every query. */
  class Bazaar_Test_Core_Db_Wpdb {
    public $last_error = '';
    public $queries    = [];
    public function query( $q ) { $this->queries[] = $q; return 0; }
    public function suppress_errors( $on ) { return false; }
  }
}

$previous_wpdb   = $GLOBALS['wpdb'] ?? null;
$GLOBALS['wpdb'] = new Bazaar_Test_Core_Db_Wpdb();
$previous_log    = ini_get( 'error_log' );
$log             = tempnam( sys_get_temp_dir(), 'bzdb' );
ini_set( 'error_log', $log );
$D = 'Xophz_Compass_Bazaar_Core_Db';

// A throwing after-commit callback: tx() returns the result, later callbacks run.
$ran    = [];
$result = null;
$thrown = null;
try {
  $result = $D::tx( function () use ( $D, &$ran ) {
    $D::after_commit( function () use ( &$ran ) { $ran[] = 'first'; throw new RuntimeException( 'listener failed' ); } );
    $D::after_commit( function () use ( &$ran ) { $ran[] = 'second'; throw new Error( 'fatal in listener' ); } );
    $D::after_commit( function () use ( &$ran ) { $ran[] = 'third'; } );
    return 'committed-row';
  } );
} catch ( Throwable $e ) {
  $thrown = $e;
}
check( 'core-db: after-commit throw does not escape tx()', null === $thrown ? 'none' : get_class( $thrown ) . ': ' . $thrown->getMessage(), 'none' );
check( 'core-db: tx() still returns the committed result', $result, 'committed-row' );
check( 'core-db: every after-commit callback ran', $ran, [ 'first', 'second', 'third' ] );
check( 'core-db: COMMIT was sent, no ROLLBACK', $GLOBALS['wpdb']->queries, [ 'START TRANSACTION', 'COMMIT' ] );
check( 'core-db: both failures are recorded', $D::callback_errors(), [ 'after-commit: RuntimeException: listener failed', 'after-commit: Error: fatal in listener' ] );
check( 'core-db: failures are logged', substr_count( (string) file_get_contents( $log ), 'Bazaar after-commit:' ), 2 );
check( 'core-db: depth is back to 0', $D::in_tx(), false );

// The next tx() starts with no recorded errors.
$D::tx( function () { return 1; } );
check( 'core-db: callback_errors resets per outermost tx()', $D::callback_errors(), [] );

// A throwing after-rollback callback keeps the original exception.
$GLOBALS['wpdb']->queries = [];
$caught = null;
try {
  $D::tx( function () use ( $D ) {
    $D::after_rollback( function () { throw new RuntimeException( 'cache cleanup failed' ); } );
    $D::after_commit( function () { throw new LogicException( 'must not run on rollback' ); } );
    throw new DomainException( 'original' );
  } );
} catch ( Throwable $e ) {
  $caught = $e;
}
check( 'core-db: rollback keeps the original exception', $caught ? get_class( $caught ) . ': ' . $caught->getMessage() : 'none', 'DomainException: original' );
check( 'core-db: ROLLBACK was sent', $GLOBALS['wpdb']->queries, [ 'START TRANSACTION', 'ROLLBACK' ] );
check( 'core-db: rollback callback failure recorded, commit callback dropped', $D::callback_errors(), [ 'after-rollback: RuntimeException: cache cleanup failed' ] );

ini_set( 'error_log', (string) $previous_log );
@unlink( $log );
$GLOBALS['wpdb'] = $previous_wpdb;

bazaar_test_standalone( __FILE__ );
