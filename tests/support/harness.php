<?php
/**
 * Shared plain-PHP test harness for Bazaar. No WordPress is loaded.
 *
 * Provides: the minimal WordPress function stubs the unit tests need,
 * check(), check_throws(), and a summary helper. Loading it twice is safe.
 *
 * Every tests/unit/*-test.php file requires this file, so each one also runs
 * on its own: php wp-content/plugins/xophz-compass-bazaar/tests/unit/<file>
 */

if ( defined( 'BAZAAR_TEST_HARNESS' ) ) {
  return;
}
define( 'BAZAAR_TEST_HARNESS', true );

$GLOBALS['failures']         = $GLOBALS['failures'] ?? 0;
$GLOBALS['passes']           = $GLOBALS['passes'] ?? 0;
$GLOBALS['test_transients']  = [];
$GLOBALS['test_environment'] = 'production';
$GLOBALS['test_filters']     = [];

if ( ! function_exists( '_x' ) ) {
  function _x( $text ) { return $text; }
}
if ( ! function_exists( '_n_noop' ) ) {
  function _n_noop( $single, $plural ) { return [ 0 => $single, 1 => $plural, 'singular' => $single, 'plural' => $plural ]; }
}
if ( ! function_exists( 'set_transient' ) ) {
  function set_transient( $key, $value, $ttl ) { $GLOBALS['test_transients'][ $key ] = $value; return true; }
  function get_transient( $key ) { return $GLOBALS['test_transients'][ $key ] ?? false; }
  function delete_transient( $key ) { unset( $GLOBALS['test_transients'][ $key ] ); return true; }
}
if ( ! function_exists( 'wp_generate_password' ) ) {
  function wp_generate_password( $length ) { return bin2hex( random_bytes( (int) ( $length / 2 ) ) ); }
}
if ( ! function_exists( 'wp_get_environment_type' ) ) {
  function wp_get_environment_type() { return $GLOBALS['test_environment']; }
}
if ( ! function_exists( 'apply_filters' ) ) {
  function apply_filters( $hook, $value ) { return array_key_exists( $hook, $GLOBALS['test_filters'] ) ? $GLOBALS['test_filters'][ $hook ] : $value; }
}
if ( ! function_exists( 'wp_unslash' ) ) {
  /** Same behaviour as WordPress: strips one level of slashes from strings, recursively. */
  function wp_unslash( $value ) {
    if ( is_array( $value ) ) {
      return array_map( 'wp_unslash', $value );
    }
    return is_string( $value ) ? stripslashes( $value ) : $value;
  }
}

/** Record one equality check. Strict (===). */
function check( string $name, $actual, $expected ) {
  global $failures, $passes;
  if ( $actual === $expected ) {
    $passes++;
    return;
  }
  $failures++;
  fwrite( STDERR, "FAIL $name\n  expected: " . var_export( $expected, true ) . "\n  actual:   " . var_export( $actual, true ) . "\n" );
}

/** Record that $fn throws an instance of $class (default Throwable). */
function check_throws( string $name, callable $fn, string $class = Throwable::class ) {
  try {
    $fn();
  } catch ( Throwable $e ) {
    check( $name, $e instanceof $class, true );
    return;
  }
  check( $name, 'no exception', $class );
}

/** Print the summary and return the process exit code. */
function bazaar_test_summary(): int {
  global $failures, $passes;
  echo "$passes passed, $failures failed\n";
  return $failures > 0 ? 1 : 0;
}

/**
 * Call at the bottom of a unit file. When that file was started directly
 * (not through run-tests.php), prints the summary and exits.
 */
function bazaar_test_standalone( string $file ): void {
  $entry = $_SERVER['argv'][0] ?? '';
  if ( '' !== $entry && realpath( $entry ) === realpath( $file ) ) {
    exit( bazaar_test_summary() );
  }
}
