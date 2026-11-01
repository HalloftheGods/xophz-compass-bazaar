<?php
/**
 * Staff_Throttle, the claim-before-check order in Staff_Pins::verify() and
 * Staff_Password_Proof (review fix #6986), against an in-memory stand-in for
 * wpdb that understands only the statements these classes send.
 *
 * Parallel requests are simulated by the wp_check_password() stub: while one
 * "slow hash" runs it lets the queued burst of other requests run to the end
 * first, which is the interleaving that let every guess in a burst be checked
 * before the fifth failure set the lock. The real MySQL path, with separate
 * processes, is covered by tests/integration/staff.sh.
 *
 * Run alone: php wp-content/plugins/xophz-compass-bazaar/tests/unit/staff-throttle-test.php
 */
require_once dirname( __DIR__ ) . '/support/harness.php';
$root = dirname( dirname( __DIR__ ) );
require_once $root . '/includes/core/class-xophz-compass-bazaar-core-autoloader.php';
Xophz_Compass_Bazaar_Core_Autoloader::register( $root );

if ( ! class_exists( 'WP_Error' ) ) {
  class WP_Error {
    public $code;
    public function __construct( $code = '', $message = '', $data = '' ) { $this->code = $code; }
  }
}
if ( ! function_exists( 'get_current_user_id' ) ) {
  function get_current_user_id() { return (int) ( $GLOBALS['bzt_current_user'] ?? 0 ); }
}
if ( ! function_exists( 'get_userdata' ) ) {
  function get_userdata( $id ) { return $GLOBALS['bzt_users'][ (int) $id ] ?? false; }
}
if ( ! function_exists( 'do_action' ) ) {
  function do_action( $hook, ...$args ) { $GLOBALS['bzt_actions'][] = [ $hook, $args[0] ?? null ]; }
}
if ( ! function_exists( 'wp_check_password' ) ) {
  /** A "slow hash": the queued burst runs to the end while this check is in progress. */
  function wp_check_password( $password, $hash, $user_id = '' ) {
    $GLOBALS['bzt_hash_checks']++;
    $GLOBALS['wpdb']->log[] = 'hash-check';
    $burst                  = $GLOBALS['bzt_burst'];
    $GLOBALS['bzt_burst']   = [];
    foreach ( $burst as $request ) {
      $request();
    }
    return 'hash:' . $password === $hash;
  }
}

/** Only the statements Staff_Throttle and Staff_Pins send; anything else throws. */
class Bazaar_Test_Staff_Wpdb {
  public $prefix     = 'wp_';
  public $options    = 'wp_options';
  public $last_error = '';
  public $insert_id  = 0;
  public $opts       = [];
  public $staff      = [];
  public $events     = [];
  public $log        = [];
  private $last_id   = 0;

  public function suppress_errors( $on = true ) { return false; }
  public function esc_like( $text ) { return addcslashes( $text, '_%\\' ); }

  public function prepare( $sql, ...$args ) {
    $i = 0;
    return preg_replace_callback( '/%[sd]/', function ( $m ) use ( &$i, $args ) {
      $v = $args[ $i++ ];
      return '%d' === $m[0] ? (string) (int) $v : "'" . addslashes( (string) $v ) . "'";
    }, $sql );
  }

  public function query( $q ) {
    $q = preg_replace( '/\s+/', ' ', trim( $q ) );
    if ( preg_match( "/^UPDATE wp_options SET option_value = LAST_INSERT_ID.* WHERE option_name = '([^']+)'$/", $q, $m ) ) {
      if ( ! isset( $this->opts[ $m[1] ] ) ) {
        return 0;
      }
      $this->log[] = 'throttle-claim';
      $this->last_id = ++$this->opts[ $m[1] ]['value'];
      return 1;
    }
    if ( preg_match( "/^INSERT IGNORE INTO wp_options \(option_name, option_value, autoload\) VALUES \('([^']+)', '0', '(\w+)'\)$/", $q, $m ) ) {
      if ( isset( $this->opts[ $m[1] ] ) ) {
        return 0;
      }
      $this->opts[ $m[1] ] = [ 'value' => 0, 'autoload' => $m[2] ];
      return 1;
    }
    if ( preg_match( "/^UPDATE wp_options SET option_value = GREATEST.* WHERE option_name = '([^']+)'$/", $q, $m ) ) {
      $this->log[] = 'throttle-release';
      if ( ! isset( $this->opts[ $m[1] ] ) ) {
        return 0;
      }
      $this->opts[ $m[1] ]['value'] = max( 0, $this->opts[ $m[1] ]['value'] - 1 );
      return 1;
    }
    if ( preg_match( "/^DELETE FROM wp_options WHERE option_name LIKE '([^']+)' AND option_name < '([^']+)'$/", $q, $m ) ) {
      $gone = 0;
      foreach ( array_keys( $this->opts ) as $name ) {
        // The pattern arrives SQL-quoted and LIKE-escaped: unquote, then unescape.
        if ( 0 === strpos( $name, stripslashes( stripslashes( rtrim( $m[1], '%' ) ) ) ) && strcmp( $name, $m[2] ) < 0 ) {
          unset( $this->opts[ $name ] );
          $gone++;
        }
      }
      return $gone;
    }
    if ( preg_match( '/^UPDATE wp_bazaar_staff SET locked_until = IF\(failed_attempts \+ 1 >= (\d+), .* WHERE user_id = (\d+) AND active = 1 AND pin_hash <> \'\' AND \(locked_until IS NULL OR locked_until <= UTC_TIMESTAMP\(\)\)$/', $q, $m ) ) {
      $this->log[] = 'attempt-claim';
      $row = &$this->staff[ (int) $m[2] ];
      if ( ! $row || 1 !== $row['active'] || '' === $row['pin_hash'] || $this->locked( $row ) ) {
        return 0;
      }
      $n = $row['failed_attempts'] + 1;
      if ( $n >= (int) $m[1] ) {
        $row['locked_until'] = gmdate( 'Y-m-d H:i:s', time() + 900 );
      }
      $row['failed_attempts'] = $n >= (int) $m[1] ? 0 : $n;
      $this->last_id          = $n;
      return 1;
    }
    if ( preg_match( '/^UPDATE wp_bazaar_staff SET failed_attempts = 0, locked_until = NULL, updated_at = \'[^\']+\' WHERE user_id = (\d+)( AND .*)?$/', $q, $m ) ) {
      $this->log[] = 'attempt-clear';
      $row = &$this->staff[ (int) $m[1] ];
      if ( ! empty( $m[2] ) && $this->locked( $row ) ) {
        return 0;
      }
      $row['failed_attempts'] = 0;
      $row['locked_until']    = null;
      return 1;
    }
    throw new RuntimeException( "fake wpdb: unexpected query: $q" );
  }

  public function get_var( $q ) {
    if ( 'SELECT LAST_INSERT_ID()' === $q ) {
      return (string) $this->last_id;
    }
    if ( preg_match( '/^SELECT locked_until FROM wp_bazaar_staff WHERE user_id = (\d+)$/', $q, $m ) ) {
      return $this->staff[ (int) $m[1] ]['locked_until'] ?? null;
    }
    throw new RuntimeException( "fake wpdb: unexpected get_var: $q" );
  }

  public function get_row( $q ) {
    if ( preg_match( '/^SELECT pin_hash, locked_until, active FROM wp_bazaar_staff WHERE user_id = (\d+)$/', $q, $m ) ) {
      $this->log[] = 'attempt-read';
      return isset( $this->staff[ (int) $m[1] ] ) ? (object) $this->staff[ (int) $m[1] ] : null;
    }
    throw new RuntimeException( "fake wpdb: unexpected get_row: $q" );
  }

  public function insert( $table, $row ) {
    if ( 'wp_bazaar_events' !== $table ) {
      throw new RuntimeException( "fake wpdb: unexpected insert into $table" );
    }
    $this->events[]  = $row;
    $this->insert_id = count( $this->events );
    return 1;
  }

  private function locked( $row ): bool {
    return null !== $row['locked_until'] && strtotime( $row['locked_until'] . ' UTC' ) > time();
  }
}

$bzt_saved_wpdb = $GLOBALS['wpdb'] ?? null;
/** Fresh fake database and stub state. */
function bzt_reset( array $staff = [] ): Bazaar_Test_Staff_Wpdb {
  $db = new Bazaar_Test_Staff_Wpdb();
  foreach ( $staff as $id => $pin ) {
    $db->staff[ $id ] = [ 'pin_hash' => 'hash:' . $pin, 'failed_attempts' => 0, 'locked_until' => null, 'active' => 1 ];
  }
  $GLOBALS['wpdb']             = $db;
  $GLOBALS['bzt_hash_checks']  = 0;
  $GLOBALS['bzt_burst']        = [];
  $GLOBALS['bzt_actions']      = [];
  $GLOBALS['bzt_current_user'] = 0;
  return $db;
}
/** Run $fn; return 'ok' or the Core_Http_Error code. */
function bzt_try( callable $fn ): string {
  try {
    $fn();
    return 'ok';
  } catch ( Xophz_Compass_Bazaar_Core_Http_Error $e ) {
    return $e->error_code();
  }
}

$T   = 'Xophz_Compass_Bazaar_Staff_Throttle';
$P   = 'Xophz_Compass_Bazaar_Staff_Pins';
$W   = 'Xophz_Compass_Bazaar_Staff_Password_Proof';
$now = strtotime( '2026-11-01 12:07:30 UTC' );

// pure parts
check( 'window: 15-minute fixed windows', [ $T::WINDOW, $T::window( $now ), $T::window( $now - 450 ), $T::window( $now - 451 ) ], [ 900, intdiv( $now, 900 ), intdiv( $now, 900 ), intdiv( $now, 900 ) - 1 ] );
check( 'seconds_left: to the end of the window', [ $T::seconds_left( $now ), $T::seconds_left( intdiv( $now, 900 ) * 900 ) ], [ 450, 900 ] );
check( 'option_name: prefix, 10-digit window, md5 of the bucket', $T::option_name( 'pin:device:x', 1966 ), '_bazaar_thr_0000001966_' . md5( 'pin:device:x' ) );
check( 'option_name: fits wp_options.option_name (191)', strlen( $T::option_name( str_repeat( 'x', 500 ), PHP_INT_MAX ) ) <= 191, true );
check( 'option_name: an earlier window sorts first', strcmp( $T::option_name( 'b', 999 ), $T::option_name( 'a', 1000 ) ) < 0, true );
check( 'within: all at or under their maximum', $T::within( [ 'a' => 20, 'b' => 1 ], [ 'a' => 20, 'b' => 60 ] ), true );
check( 'within: one over is refused', $T::within( [ 'a' => 21, 'b' => 1 ], [ 'a' => 20, 'b' => 60 ] ), false );
check( 'within: a missing count is refused', $T::within( [ 'a' => 1 ], [ 'a' => 20, 'b' => 60 ] ), false );
check( 'ip_key: IPv4 as is', $T::ip_key( '203.0.113.9' ), '203.0.113.9' );
check( 'ip_key: IPv4-mapped IPv6 is the IPv4 address', $T::ip_key( '::ffff:203.0.113.9' ), '203.0.113.9' );
check( 'ip_key: IPv6 by /64', [ $T::ip_key( '2001:db8:1:2::1' ), $T::ip_key( '2001:db8:1:2:ffff:ffff:ffff:ffff' ) ], [ '20010db800010002::/64', '20010db800010002::/64' ] );
check( 'ip_key: not an address', [ $T::ip_key( '' ), $T::ip_key( 'x' ), $T::ip_key( '1.2.3.4, 5.6.7.8' ) ], [ '', '', '' ] );
check( 'password proof: five per user', $W::buckets( 12 ), [ 'password:user:12' => 5 ] );

// claim / release
$db  = bzt_reset();
$win = $T::window( $now );
$b   = [ 'test:a' => 3 ];
check( 'claim: three pass, the fourth is refused', [ $T::claim( $b, $win ), $T::claim( $b, $win ), $T::claim( $b, $win ), $T::claim( $b, $win ) ], [ true, true, true, false ] );
check( 'claim: a refused claim gave its slot back', $db->opts[ $T::option_name( 'test:a', $win ) ]['value'], 3 );
check( 'claim: the row is not autoloaded', $db->opts[ $T::option_name( 'test:a', $win ) ]['autoload'], 'no' );
$T::release( $b, $win );
check( 'release: gives one back', [ $db->opts[ $T::option_name( 'test:a', $win ) ]['value'], $T::claim( $b, $win ) ], [ 2, true ] );
check( 'claim: a refusal in one bucket gives back the others', [ $T::claim( [ 'test:a' => 3, 'test:b' => 9 ], $win ), $db->opts[ $T::option_name( 'test:b', $win ) ]['value'] ], [ false, 0 ] );
check( 'claim: a new window starts at zero', $T::claim( $b, $win + 1 ), true );
check( 'claim: the first claim of a new window deletes earlier windows', isset( $db->opts[ $T::option_name( 'test:a', $win ) ] ), false );

// verify(): the attempt is claimed before the hash check
$db = bzt_reset( [ 42 => '3958' ] );
check( 'verify: a right PIN works', bzt_try( function () use ( $P ) { $P::verify( 42, '3958', 'till-1' ); } ), 'ok' );
check( 'verify: order is read, claim, hash check, clear; the throttle slot is given back', $db->log, [ 'throttle-claim', 'attempt-read', 'attempt-claim', 'hash-check', 'attempt-clear', 'throttle-release' ] );

// The race: eleven more wrong PINs arrive while the first one's hash check runs.
$db      = bzt_reset( [ 42 => '3958' ] );
$results = [];
for ( $i = 1; $i <= 11; $i++ ) {
  $GLOBALS['bzt_burst'][] = function () use ( $P, $i, &$results ) {
    $results[] = bzt_try( function () use ( $P, $i ) { $P::verify( 42, (string) ( 6000 + $i ), 'till-' . $i ); } );
  };
}
$results[] = bzt_try( function () use ( $P ) { $P::verify( 42, '6000', 'till-0' ); } );
$tally     = array_count_values( $results );
check( 'race: twelve parallel wrong PINs run only five hash checks', $GLOBALS['bzt_hash_checks'], 5 );
check( 'race: four pin_invalid, eight pin_locked', [ $tally['pin_invalid'] ?? 0, $tally['pin_locked'] ?? 0 ], [ 4, 8 ] );
check( 'race: the PIN is locked with the count restarted', [ $P::is_locked( $db->staff[42]['locked_until'], time() ), $db->staff[42]['failed_attempts'] ], [ true, 0 ] );
check( 'race: one staff.pin.locked event', array_column( $db->events, 'type' ), [ 'staff.pin.locked' ] );
check( 'race: the right PIN is refused while locked, without a hash check', [ bzt_try( function () use ( $P ) { $P::verify( 42, '3958', 'till-x' ); } ), $GLOBALS['bzt_hash_checks'] ], [ 'pin_locked', 5 ] );
check( 'race: only the five checked wrong PINs count against the device throttle', $db->opts[ $T::option_name( 'pin:device:till-0', $T::window( time() ) ) ]['value'], 1 );

// A right PIN whose claim set the lock clears it; a right PIN does not clear a lock a parallel guess set.
$db = bzt_reset( [ 42 => '3958' ] );
$db->staff[42]['failed_attempts'] = 4;
check( 'right fifth PIN: works and clears its own lock', [ bzt_try( function () use ( $P ) { $P::verify( 42, '3958', 'till-1' ); } ), $db->staff[42]['locked_until'], $db->staff[42]['failed_attempts'] ], [ 'ok', null, 0 ] );
$db = bzt_reset( [ 42 => '3958' ] );
$db->staff[42]['failed_attempts'] = 3;
$GLOBALS['bzt_burst'][] = function () use ( $P ) { bzt_try( function () use ( $P ) { $P::verify( 42, '1357', 'till-2' ); } ); };
check( 'right fourth PIN: still works, but the lock the parallel fifth guess set stays', [ bzt_try( function () use ( $P ) { $P::verify( 42, '3958', 'till-1' ); } ), $P::is_locked( $db->staff[42]['locked_until'], time() ) ], [ 'ok', true ] );

// Throttle: rotating X-Bazaar-Device values does not escape the operator cap.
$db                          = bzt_reset( [ 101 => '3958', 102 => '3958', 103 => '3958', 104 => '3958', 105 => '3958', 106 => '3958' ] );
$GLOBALS['bzt_current_user'] = 9;
$codes                       = [];
for ( $i = 0; $i < 20; $i++ ) {
  $codes[] = bzt_try( function () use ( $P, $i ) { $P::verify( 101 + intdiv( $i, 4 ), '1357', 'random-' . $i ); } );
}
check( 'operator cap: twenty wrong PINs from twenty device ids are all checked', array_count_values( $codes ), [ 'pin_invalid' => 20 ] );
check( 'operator cap: the 21st is refused even with the right PIN and a new device id', [ bzt_try( function () use ( $P ) { $P::verify( 106, '3958', 'random-new' ); } ), $GLOBALS['bzt_hash_checks'] ], [ 'pin_throttled', 20 ] );
$GLOBALS['bzt_current_user'] = 10;
check( 'operator cap: another operator is not blocked', bzt_try( function () use ( $P ) { $P::verify( 106, '3958', 'random-new' ); } ), 'ok' );

// Password proof: counted, locked (403, never 401), reported to login limiters.
$db                  = bzt_reset();
$GLOBALS['bzt_users'] = [ 7 => (object) [ 'ID' => 7, 'user_login' => 'till_op', 'user_pass' => 'hash:right-pass' ] ];
check( 'password: the right one works and gives its slot back', [ bzt_try( function () use ( $W ) { $W::verify( 7, 'right-pass' ); } ), $db->opts[ $T::option_name( 'password:user:7', $T::window( time() ) ) ]['value'] ], [ 'ok', 0 ] );
$results                    = [];
$GLOBALS['bzt_hash_checks'] = 0;
for ( $i = 1; $i <= 11; $i++ ) {
  $GLOBALS['bzt_burst'][] = function () use ( $W, $i, &$results ) {
    $results[] = bzt_try( function () use ( $W, $i ) { $W::verify( 7, 'guess-' . $i ); } );
  };
}
$results[] = bzt_try( function () use ( $W ) { $W::verify( 7, 'guess-0' ); } );
$tally = array_count_values( $results );
check( 'password race: twelve parallel guesses run only five password checks', $GLOBALS['bzt_hash_checks'], 5 );
check( 'password race: five proof_invalid, seven proof_locked', [ $tally['proof_invalid'] ?? 0, $tally['proof_locked'] ?? 0 ], [ 5, 7 ] );
check( 'password: each checked wrong password fires wp_login_failed with the login', array_values( array_filter( $GLOBALS['bzt_actions'], function ( $a ) { return 'wp_login_failed' === $a[0]; } ) ), array_fill( 0, 5, [ 'wp_login_failed', 'till_op' ] ) );
try {
  $W::verify( 7, 'right-pass' );
  check( 'password: locked even for the right one', 'ok', 'proof_locked' );
} catch ( Xophz_Compass_Bazaar_Core_Http_Error $e ) {
  check( 'password: locked even for the right one, 403 with retry_after', [ $e->error_code(), $e->status(), $e->data()['retry_after'] > 0 && $e->data()['retry_after'] <= 900 ], [ 'proof_locked', 403, true ] );
}

$GLOBALS['wpdb'] = $bzt_saved_wpdb;
bazaar_test_standalone( __FILE__ );
