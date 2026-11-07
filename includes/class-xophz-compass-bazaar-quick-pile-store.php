<?php
/**
 * Quick Add "Scan a pile": every query on the per-site pile table.
 *
 * Table {$wpdb->prefix}bazaar_quick_pile ($wpdb->prefix carries the blog id in
 * multisite, so each site has its own pile), created by Schema_Installer from
 * Schema_Tables. One row per code waiting for a price; a finished row keeps
 * its history with status 'done'.
 *
 * Guarantees:
 * - A scan is one INSERT ... ON DUPLICATE KEY UPDATE qty = qty + 1 on the
 *   UNIQUE open_key, so two scans arriving at once both count and never make
 *   two rows for one code.
 * - open_key is the code key while a row is open and NULL once it is done, so
 *   a code can be scanned again (a new row) after its row was finished.
 * - claim() moves a row from open to done in one UPDATE ... WHERE status =
 *   'open', so two saves of one pile row cannot both add stock.
 * - reopen() never breaks the UNIQUE key: when the code was scanned again in
 *   the meantime, the reopened row's count is added to that open row instead.
 * - The key, quantity and item-shaping helpers are pure (no WordPress calls),
 *   so tests/unit/quick-pile-test.php loads this file alone.
 *
 * - open_key is binary (varbinary), so house labels that differ only by
 *   case or accents are different rows.
 * - claim() with the count the screen showed (pile_qty_seen) moves any scans
 *   that landed after that back into an open row, so they are neither lost
 *   nor stocked by this save.
 * - A row taken by a save that never recorded its product is taken again by
 *   a different save only when that first save is provably not running and
 *   did not succeed (see claim()), so stock is never added twice.
 * - Every write that fails throws RuntimeException; callers answer 'failed'.
 *
 * Not guaranteed: set_qty() writes the count it is sent, so a scan that lands
 * between the phone reading the count and sending "−" is overwritten.
 *
 * @package    Xophz_Compass_Bazaar
 * @subpackage Xophz_Compass_Bazaar/includes
 */
class Xophz_Compass_Bazaar_Quick_Pile_Store {

  const TABLE     = 'bazaar_quick_pile';
  const QTY_MAX   = 10000;
  const TITLE_MAX = 200;
  const THUMB_MAX = 1000;
  const LIST_MAX  = 500;
  /** A claim older than this (its request died before finishing) may be taken again. */
  const CLAIM_STALE_AFTER = 120;

  /* ------------------------------------------------------------------ */
  /* Pure helpers                                                        */
  /* ------------------------------------------------------------------ */

  /**
   * The open_key for a normalized code (Xophz_Compass_Bazaar_Barcode::normalize
   * output): 'g:' + normalized when the check digit is valid, else 'r:' + the
   * trimmed raw code. '' when there is no code.
   */
  public static function key_for( array $code ): string {
    if ( ! empty( $code['valid'] ) && isset( $code['normalized'] ) && '' !== (string) $code['normalized'] ) {
      return 'g:' . (string) $code['normalized'];
    }
    $raw = isset( $code['raw'] ) ? trim( (string) $code['raw'] ) : '';
    return '' === $raw ? '' : 'r:' . $raw;
  }

  /** A whole number from 0 to QTY_MAX (int, whole float or digit string), else null. */
  public static function parse_qty( $value ): ?int {
    if ( is_string( $value ) && preg_match( '/^\s*\d{1,6}\s*$/', $value ) ) {
      $value = (int) trim( $value );
    }
    if ( is_float( $value ) && is_finite( $value ) && floor( $value ) === $value ) {
      $value = (int) $value;
    }
    if ( ! is_int( $value ) || $value < 0 || $value > self::QTY_MAX ) {
      return null;
    }
    return $value;
  }

  /** A positive row id, else 0. */
  public static function parse_id( $value ): int {
    if ( is_string( $value ) && preg_match( '/^\d{1,19}$/', $value ) ) {
      $value = (int) $value;
    }
    if ( is_float( $value ) && is_finite( $value ) && floor( $value ) === $value ) {
      $value = (int) $value;
    }
    return is_int( $value ) && $value > 0 ? $value : 0;
  }

  /** A client_uuid (1 to 64 letters, digits, _ or -), else ''. */
  public static function parse_uuid( $value ): string {
    return is_string( $value ) && preg_match( '/^[A-Za-z0-9_-]{1,64}$/', $value ) ? $value : '';
  }

  /**
   * How many scans to put back in the pile when a save claims a row: the
   * row's count beyond what the screen showed (scans that landed while it
   * was being priced). 0 when the screen's count is unknown.
   */
  public static function extra_after_claim( int $row_qty, ?int $seen_qty ): int {
    if ( null === $seen_qty || $seen_qty < 1 ) {
      return 0;
    }
    return max( 0, $row_qty - $seen_qty );
  }

  /** The title cut to TITLE_MAX characters (the caller sanitizes first). */
  public static function clip_title( string $title ): string {
    $title = trim( $title );
    return function_exists( 'mb_substr' ) ? mb_substr( $title, 0, self::TITLE_MAX ) : substr( $title, 0, self::TITLE_MAX );
  }

  /** The URL when it is an https URL without user, password or port and at most THUMB_MAX long, else ''. */
  public static function clean_thumb( $url ): string {
    if ( ! is_string( $url ) ) {
      return '';
    }
    $url = trim( $url );
    if ( '' === $url || strlen( $url ) > self::THUMB_MAX || preg_match( '/[\s"\'<>\\\\]/', $url ) ) {
      return '';
    }
    $parts = parse_url( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- pure helper, no WordPress loaded in tests.
    if ( ! is_array( $parts ) || 'https' !== strtolower( (string) ( $parts['scheme'] ?? '' ) ) || '' === (string) ( $parts['host'] ?? '' )
      || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['port'] ) ) {
      return '';
    }
    return $url;
  }

  /** A UTC 'Y-m-d H:i:s' column value as ISO 8601 ('' when empty). */
  public static function iso( $mysql_utc ): string {
    if ( ! is_string( $mysql_utc ) || '' === $mysql_utc || 0 === strpos( $mysql_utc, '0000-00-00' ) ) {
      return '';
    }
    $time = strtotime( $mysql_utc . ' UTC' );
    return false === $time ? '' : gmdate( 'c', $time );
  }

  /**
   * One row as the API's PileItem.
   *
   * @param array|object         $row   A table row.
   * @param array<int,string>    $names created_by user id => display name.
   * @return array{pile_id:int,barcode:string,normalized:string,valid:bool,qty:int,title:string,thumb:string,product_id:int|null,status:string,scanned_by:string,created_at:string,updated_at:string}
   */
  public static function shape( $row, array $names = [] ): array {
    $row        = is_object( $row ) ? get_object_vars( $row ) : (array) $row;
    $product_id = (int) ( $row['product_id'] ?? 0 );
    $by         = (int) ( $row['created_by'] ?? 0 );
    return [
      'pile_id'    => (int) ( $row['id'] ?? 0 ),
      'barcode'    => (string) ( $row['barcode'] ?? '' ),
      'normalized' => (string) ( $row['normalized'] ?? '' ),
      'valid'      => ! empty( $row['valid'] ),
      'qty'        => max( 0, (int) ( $row['qty'] ?? 0 ) ),
      'title'      => (string) ( $row['title'] ?? '' ),
      'thumb'      => (string) ( $row['thumb'] ?? '' ),
      'product_id' => $product_id > 0 ? $product_id : null,
      'status'     => 'done' === ( $row['status'] ?? '' ) ? 'done' : 'open',
      'scanned_by' => (string) ( $names[ $by ] ?? '' ),
      'created_at' => self::iso( $row['created_at'] ?? '' ),
      'updated_at' => self::iso( $row['updated_at'] ?? '' ),
    ];
  }

  /* ------------------------------------------------------------------ */
  /* Schema                                                              */
  /* ------------------------------------------------------------------ */

  public static function table(): string {
    global $wpdb;
    return $wpdb->prefix . self::TABLE;
  }

  /**
   * wpmu_drop_tables: drop this site's pile table when the site is deleted.
   *
   * @param string[] $tables
   * @param int      $site_id
   * @return string[]
   */
  public static function drop_with_site( $tables, $site_id = 0 ) {
    global $wpdb;
    $tables   = is_array( $tables ) ? $tables : [];
    $tables[] = $wpdb->get_blog_prefix( (int) $site_id ) . self::TABLE;
    return array_values( array_unique( $tables ) );
  }

  /* ------------------------------------------------------------------ */
  /* Queries                                                             */
  /* ------------------------------------------------------------------ */

  private static function now(): string {
    return gmdate( 'Y-m-d H:i:s' );
  }

  /**
   * Count one scan: a new open row with qty 1, or qty + 1 on the open row of
   * the same code, in one statement.
   *
   * @return array{id:int,created:bool}|null null when the write failed.
   */
  public static function add_scan( array $code, string $key, int $user_id ): ?array {
    global $wpdb;
    $table = self::table();
    $now   = self::now();
    $sql   = $wpdb->prepare(
      "INSERT INTO {$table} (open_key, barcode, normalized, valid, qty, status, created_by, created_at, updated_at)
       VALUES (%s, %s, %s, %d, 1, 'open', %d, %s, %s)
       ON DUPLICATE KEY UPDATE qty = LEAST(qty + 1, %d), updated_at = %s, id = LAST_INSERT_ID(id)",
      $key,
      substr( (string) $code['raw'], 0, 64 ),
      substr( (string) ( $code['valid'] ? $code['normalized'] : '' ), 0, 20 ),
      $code['valid'] ? 1 : 0,
      $user_id,
      $now,
      $now,
      self::QTY_MAX,
      $now
    );
    $affected = $wpdb->query( $sql );
    $id       = (int) $wpdb->insert_id;
    if ( false === $affected || $id <= 0 ) {
      return null;
    }
    // MySQL reports 1 for a new row and 2 (or 0 when nothing changed) for an update.
    return [ 'id' => $id, 'created' => 1 === (int) $affected ];
  }

  /** Run one write; throws when the database refused it. @return int Rows affected. */
  private static function write( string $sql ): int {
    global $wpdb;
    $result = $wpdb->query( $sql );
    if ( false === $result ) {
      throw new RuntimeException( 'bazaar_quick_pile write failed: ' . (string) $wpdb->last_error );
    }
    return (int) $result;
  }

  /** @return array|null The row as an associative array. */
  public static function get( int $id ): ?array {
    global $wpdb;
    $table = self::table();
    $row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
    return is_array( $row ) ? $row : null;
  }

  /** The PileItem for a row id, null when it is gone. */
  public static function item( int $id ): ?array {
    $row = self::get( $id );
    return $row ? self::shape( $row, self::names( [ $row ] ) ) : null;
  }

  /** @return array{items:array[],open_count:int} Open rows, oldest first. */
  public static function open_items(): array {
    global $wpdb;
    $table = self::table();
    $rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE status = %s ORDER BY created_at ASC, id ASC LIMIT %d", 'open', self::LIST_MAX ), ARRAY_A );
    $rows  = is_array( $rows ) ? $rows : [];
    $count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s", 'open' ) );
    $names = self::names( $rows );
    return [
      'items'      => array_map( function ( $row ) use ( $names ) {
        return self::shape( $row, $names );
      }, $rows ),
      'open_count' => max( $count, count( $rows ) ),
    ];
  }

  /** Display names of the rows' scanners, user id => name. */
  private static function names( array $rows ): array {
    $ids = array_values( array_unique( array_filter( array_map( function ( $row ) {
      return (int) ( $row['created_by'] ?? 0 );
    }, $rows ) ) ) );
    $out = [];
    foreach ( $ids as $id ) {
      $user       = get_userdata( $id );
      $out[ $id ] = $user ? (string) $user->display_name : '';
    }
    return $out;
  }

  /**
   * Set the count of an open row; 0 deletes it.
   *
   * @return string 'ok', 'removed', 'done' (the row is finished) or 'missing'.
   */
  public static function set_qty( int $id, int $qty ): string {
    global $wpdb;
    $table = self::table();
    if ( 0 === $qty ) {
      $deleted = self::write( $wpdb->prepare( "DELETE FROM {$table} WHERE id = %d AND status = %s", $id, 'open' ) );
      if ( $deleted > 0 ) {
        return 'removed';
      }
    } else {
      self::write( $wpdb->prepare( "UPDATE {$table} SET qty = %d, updated_at = %s WHERE id = %d AND status = %s", $qty, self::now(), $id, 'open' ) );
    }
    $row = self::get( $id );
    if ( ! $row ) {
      return 0 === $qty ? 'removed' : 'missing';
    }
    return 'done' === $row['status'] ? 'done' : 'ok';
  }

  /** Store a lookup result on a row (display only). False when the row is gone. */
  public static function resolve( int $id, ?int $product_id, string $title, string $thumb ): bool {
    global $wpdb;
    $table = self::table();
    if ( null === $product_id ) {
      self::write( $wpdb->prepare( "UPDATE {$table} SET product_id = NULL, title = %s, thumb = %s WHERE id = %d", $title, $thumb, $id ) );
    } else {
      self::write( $wpdb->prepare( "UPDATE {$table} SET product_id = %d, title = %s, thumb = %s WHERE id = %d", $product_id, $title, $thumb, $id ) );
    }
    return null !== self::get( $id );
  }

  /**
   * Take a row for one save, before any stock is written.
   *
   * A row is taken when it is open, or when this same save (claim_key, the
   * v1 hash of user id and client_uuid) took it earlier without finishing.
   * A row another save took and never recorded a product on (done,
   * saved_product_id NULL, older than CLAIM_STALE_AFTER) is taken only when
   * that save is provably not running (no v1 lock row) and did not succeed
   * (no stored success; a stored success is recorded on the row instead).
   * Anything else is 'done': never add stock twice.
   *
   * With $seen_qty (the count the screen showed), any scans beyond it go
   * back into an open row for the same code (see extra_after_claim).
   *
   * @return string 'claimed', 'done' (finished already) or 'missing'.
   */
  public static function claim( int $id, string $claim_key, ?int $seen_qty = null ): string {
    global $wpdb;
    $table = self::table();
    $now   = self::now();
    $took  = self::write( $wpdb->prepare(
      "UPDATE {$table} SET status = 'done', open_key = NULL, claim_key = %s, saved_product_id = NULL, done_at = %s, updated_at = %s
       WHERE id = %d AND ( status = 'open' OR ( status = 'done' AND saved_product_id IS NULL AND claim_key = %s ) )",
      $claim_key,
      $now,
      $now,
      $id,
      $claim_key
    ) );
    if ( $took <= 0 ) {
      $row = self::get( $id );
      if ( ! $row ) {
        return 'missing';
      }
      $took = self::take_stale( $row, $claim_key );
      if ( $took <= 0 ) {
        return 'done';
      }
    }
    if ( null !== $seen_qty ) {
      self::put_back_extra( $id, $seen_qty );
    }
    return 'claimed';
  }

  /** Take a row another save left unfinished, only when that save is provably dead and failed. @return int Rows taken. */
  private static function take_stale( array $row, string $claim_key ): int {
    global $wpdb;
    $table = self::table();
    $other = (string) ( $row['claim_key'] ?? '' );
    $done  = (string) ( $row['done_at'] ?? '' );
    if ( 'done' !== $row['status'] || ! empty( $row['saved_product_id'] ) || '' === $other || $other === $claim_key || '' === $done ) {
      return 0;
    }
    $stale = gmdate( 'Y-m-d H:i:s', time() - self::CLAIM_STALE_AFTER );
    if ( strcmp( $done, $stale ) >= 0 ) {
      return 0;
    }
    $first = Xophz_Compass_Bazaar_Admin_Quick_Add::save_state( $other );
    if ( $first['product_id'] > 0 ) {
      // The first save went through but its row was never recorded: record it now.
      self::finish( (int) $row['id'], $first['product_id'] );
      return 0;
    }
    if ( $first['running'] ) {
      return 0;
    }
    $now = self::now();
    return self::write( $wpdb->prepare(
      "UPDATE {$table} SET claim_key = %s, saved_product_id = NULL, done_at = %s, updated_at = %s
       WHERE id = %d AND status = 'done' AND saved_product_id IS NULL AND claim_key = %s AND done_at < %s",
      $claim_key,
      $now,
      $now,
      (int) $row['id'],
      $other,
      $stale
    ) );
  }

  /** After a claim: scans beyond the count the screen showed go back into an open row. */
  private static function put_back_extra( int $id, int $seen_qty ): void {
    global $wpdb;
    $table = self::table();
    $row   = self::get( $id );
    if ( ! $row ) {
      return;
    }
    $row_qty = max( 0, (int) $row['qty'] );
    $extra   = self::extra_after_claim( $row_qty, $seen_qty );
    if ( $extra <= 0 ) {
      return;
    }
    $key = self::key_for( Xophz_Compass_Bazaar_Barcode::normalize( (string) $row['barcode'] ) );
    if ( '' === $key ) {
      return;
    }
    // Only this claim holds the row (open_key is NULL, so no scan can raise it now).
    if ( self::write( $wpdb->prepare( "UPDATE {$table} SET qty = %d WHERE id = %d AND qty = %d", $seen_qty, $id, $row_qty ) ) <= 0 ) {
      return;
    }
    $now = self::now();
    self::write( $wpdb->prepare(
      "INSERT INTO {$table} (open_key, barcode, normalized, valid, qty, title, thumb, product_id, status, created_by, created_at, updated_at)
       VALUES (%s, %s, %s, %d, %d, %s, %s, NULLIF(%d, 0), 'open', %d, %s, %s)
       ON DUPLICATE KEY UPDATE qty = LEAST(qty + %d, %d), updated_at = %s",
      $key,
      (string) $row['barcode'],
      (string) $row['normalized'],
      (int) $row['valid'],
      $extra,
      (string) $row['title'],
      (string) $row['thumb'],
      (int) ( $row['product_id'] ?? 0 ),
      (int) $row['created_by'],
      $now,
      $now,
      $extra,
      self::QTY_MAX,
      $now
    ) );
  }

  /** Record the saved product on a claimed row. False (and logged) when the row was not written. */
  public static function finish( int $id, int $product_id ): bool {
    global $wpdb;
    $table  = self::table();
    $now    = self::now();
    $result = $wpdb->query( $wpdb->prepare(
      "UPDATE {$table} SET status = 'done', open_key = NULL, saved_product_id = %d, done_at = %s, updated_at = %s WHERE id = %d",
      $product_id,
      $now,
      $now,
      $id
    ) );
    if ( false === $result || (int) $result <= 0 ) {
      error_log( 'bazaar_quick_pile: row ' . $id . ' not marked saved (product ' . $product_id . '): ' . ( false === $result ? (string) $wpdb->last_error : 'no row' ) );
      return false;
    }
    return true;
  }

  /**
   * Put a done row back in the pile (a failed save, or an undo). When the
   * same code has an open row again, that row gets this row's count and this
   * row is deleted, so the UNIQUE key holds.
   *
   * @return int The open row id now holding the count, 0 when the row is gone.
   */
  public static function reopen( int $id ): int {
    global $wpdb;
    $table = self::table();
    $row   = self::get( $id );
    if ( ! $row ) {
      return 0;
    }
    if ( 'open' === $row['status'] ) {
      return $id;
    }
    $code = Xophz_Compass_Bazaar_Barcode::normalize( (string) $row['barcode'] );
    $key  = self::key_for( $code );
    if ( '' === $key ) {
      return 0;
    }
    $now = self::now();
    // Two tries: the open row a duplicate key points at may be removed in between.
    for ( $try = 0; $try < 2; $try++ ) {
      $suppressed = $wpdb->suppress_errors( true );
      $moved      = $wpdb->query( $wpdb->prepare(
        "UPDATE {$table} SET status = 'open', open_key = %s, claim_key = NULL, saved_product_id = NULL, done_at = NULL, updated_at = %s WHERE id = %d AND status = 'done'",
        $key,
        $now,
        $id
      ) );
      $wpdb->suppress_errors( $suppressed );
      if ( false !== $moved ) {
        return $id;
      }
      // A duplicate open_key: the code was scanned again since. Merge into that row.
      $other = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE open_key = %s", $key ) );
      if ( $other <= 0 ) {
        continue;
      }
      $added = $wpdb->query( $wpdb->prepare(
        "UPDATE {$table} SET qty = LEAST(qty + %d, %d), updated_at = %s WHERE id = %d AND status = 'open'",
        max( 0, (int) $row['qty'] ),
        self::QTY_MAX,
        $now,
        $other
      ) );
      if ( (int) $added > 0 ) {
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id = %d AND status = 'done'", $id ) );
        return $other;
      }
    }
    error_log( 'bazaar_quick_pile: could not put row ' . $id . ' back in the pile' );
    return 0;
  }
}
