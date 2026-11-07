<?php
/**
 * Barcode memory: what any store on this install has learned a code to be,
 * so the next store's scan names it without asking a provider.
 *
 * One table for the whole install, {$wpdb->base_prefix}bazaar_barcode_memory.
 * Schema_Installer is per site, so this class installs its own table, keyed
 * by an option on the main network.
 *
 * Guarantees:
 * - Only public identity is stored: a name, a brand and the web address of a
 *   picture of the product, which may be on any host but never a store's own
 *   uploads (a picture there would say who sells it). Never a price, cost or
 *   stock figure, and nothing says which site sells the item. title_owner is
 *   a one-way marker of the site that named a code; a read never returns it.
 * - Money amounts are taken out of titles (strip_money).
 * - Only a code with a valid check digit outside the GS1 restricted and
 *   in-store ranges is read or written (Barcode::is_shared_gtin).
 * - A write is one INSERT ... ON DUPLICATE KEY UPDATE. A provider write never
 *   overwrites a store's title; a store write overwrites a store's title only
 *   when the same site wrote it. Both only fill an empty brand or picture.
 * - A read never writes. find() caches rows and misses for an hour in a
 *   global object-cache group; every write drops that code from it.
 * - With the memory off (main-network option bazaar_barcode_memory_enabled set
 *   to 0, no, off or false, or the filter of the same name) nothing is read,
 *   written or installed. forget() does nothing either.
 * - The string helpers, the owner marker and the SQL text are pure, so
 *   tests/unit/barcode-memory-test.php loads this file without WordPress.
 *
 * Not guaranteed: strip_money finds amounts by their currency mark, or as a
 * bare N.NN at the very end of a title; a price written any other way stays.
 * The table outlives the plugin and any one site (see Schema_Installer).
 *
 * @package Xophz_Compass_Bazaar
 */
class Xophz_Compass_Bazaar_Barcode_Memory {

  const TABLE          = 'bazaar_barcode_memory';
  const DB_VERSION     = '26.11.07.1';
  const OPTION_VERSION = 'bazaar_barcode_memory_db_version';
  const OPTION_LOCK    = 'bazaar_barcode_memory_db_lock';
  const OPTION_ENABLED = 'bazaar_barcode_memory_enabled';
  const ROW_FILTER     = 'bazaar_barcode_memory_row';
  const CACHE_GROUP    = 'bazaar_barcode_memory';
  const CACHE_TTL      = 3600;
  const LOCK_SECONDS   = 60;
  const TITLE_MAX      = 200;
  const BRAND_MAX      = 100;
  const IMAGE_MAX      = 500;
  const OWNER_LENGTH   = 16;
  const OFF_VALUES     = [ '0', 'no', 'off', 'false' ];

  /* ------------------------------------------------------------------ */
  /* Pure helpers                                                        */
  /* ------------------------------------------------------------------ */

  /** The row key for Barcode::normalize output; '' when the code may not be shared. */
  public static function key_for( array $code ): string {
    $normalized = (string) ( $code['normalized'] ?? '' );
    if ( empty( $code['valid'] ) || ! Xophz_Compass_Bazaar_Barcode::is_shared_gtin( $normalized ) ) {
      return '';
    }
    return $normalized;
  }

  /** True for a stored value that turns the memory off. */
  public static function is_off_value( $value ): bool {
    return is_scalar( $value ) && in_array( strtolower( trim( (string) $value ) ), self::OFF_VALUES, true );
  }

  /** The marker of the site that named a code. One-way: it cannot be read back to a blog id. */
  public static function owner_marker( int $blog_id, string $salt ): string {
    return substr( hash_hmac( 'sha256', 'bazaar_barcode_memory|' . $blog_id, $salt ), 0, self::OWNER_LENGTH );
  }

  /**
   * The title with any money amount taken out, along with the words that go
   * with it ("was $3.99", "2 for $5", "$1 off"), and a bare N.NN at its end.
   */
  public static function strip_money( string $title ): string {
    $amount = '(?:\d{1,3}(?:,\d{3})+(?:\.\d+)?|\d+(?:[.,]\d+)?|\.\d+)';
    $codes  = 'USD|EUR|GBP|CAD|AUD';
    $money  = '(?:(?:US|CA|AU|C|A)?[$€£¥]\s?' . $amount
      . '|(?<![\p{L}\p{N}])(?:' . $codes . ')\s?' . $amount
      . '|(?<![\p{L}\p{N}.,])' . $amount . '\s?(?:¢|€|£|(?:' . $codes . '|dollars?|cents?|bucks?)(?![\p{L}\p{N}])))';
    $before = '(?:(?<![\p{L}\p{N}])(?:only|now|was|save|sale|price|our price|msrp|rrp|retail|from|just)\s*:?\s*)?(?:\d+\s*(?:for|/)\s*)?';
    $after  = '(?:\s*(?:off|each|ea)(?![\p{L}\p{N}]))?';
    $text   = (string) preg_replace( '~' . $before . $money . $after . '~iu', ' ', $title );
    $text   = (string) preg_replace( '/(?<=\s)\d*\.\d{2}\s*$/u', '', $text );
    if ( $text === $title ) {
      return $title;
    }
    $text = (string) preg_replace( '/\(\s*\)|\[\s*\]/u', ' ', $text );
    $text = (string) preg_replace( '/\s+/u', ' ', $text );
    return (string) preg_replace( '~^[\s\-–—:;,|/]+|[\s\-–—:;,|/]+$~u', '', $text );
  }

  /** The title as it may be stored ('' when nothing is left). */
  public static function clean_title( $value ): string {
    return is_scalar( $value ) ? self::cut( self::strip_money( trim( sanitize_text_field( (string) $value ) ) ), self::TITLE_MAX ) : '';
  }

  /** The brand as it may be stored. */
  public static function clean_brand( $value ): string {
    return is_scalar( $value ) ? self::cut( self::strip_money( trim( sanitize_text_field( (string) $value ) ) ), self::BRAND_MAX ) : '';
  }

  /**
   * The URL when it is an acceptable picture address that fits the column and
   * is not a store's own upload, else ''. Any WordPress uploads path counts,
   * so another site on this install is caught under its own domain too.
   *
   * @param mixed    $value
   * @param string[] $own_hosts This install's hosts.
   */
  public static function clean_image( $value, array $own_hosts = [] ): string {
    $url = Xophz_Compass_Bazaar_Barcode_Image::accept_url( $value );
    if ( '' === $url || strlen( $url ) > self::IMAGE_MAX || false !== stripos( $url, '/wp-content/uploads/' ) ) {
      return '';
    }
    $host = strtolower( (string) parse_url( $url, PHP_URL_HOST ) );
    return in_array( $host, array_map( 'strtolower', $own_hosts ), true ) ? '' : $url;
  }

  /** What a read may show of a stored row; null when there is no row. */
  public static function view( ?array $row ): ?array {
    if ( null === $row ) {
      return null;
    }
    return [
      'title'     => (string) ( $row['title'] ?? '' ),
      'brand'     => (string) ( $row['brand'] ?? '' ),
      'image_url' => (string) ( $row['image_url'] ?? '' ),
      'source'    => (string) ( $row['source'] ?? '' ),
    ];
  }

  /** CREATE TABLE for dbDelta (see Schema_Tables for the rules it follows). */
  public static function definition( string $table, string $collate ): string {
    return "CREATE TABLE {$table} (
  code varchar(14) NOT NULL,
  title varchar(200) NOT NULL DEFAULT '',
  brand varchar(100) NOT NULL DEFAULT '',
  image_url varchar(500) NOT NULL DEFAULT '',
  source varchar(10) NOT NULL DEFAULT 'provider',
  title_owner char(16) NOT NULL DEFAULT '',
  hits int(10) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (code)
) ENGINE=InnoDB {$collate};";
  }

  /**
   * The one write. MySQL applies these assignments left to right, each seeing
   * the ones before it, so title and title_owner must come before source and
   * title before title_owner: both ask who held the row before this write.
   * A provider write has an empty title_owner, so it differs from any store's.
   */
  public static function upsert_sql( string $table ): string {
    $held = "source = 'store' AND title_owner <> VALUES(title_owner)";
    return "INSERT INTO {$table} (code, title, brand, image_url, source, title_owner, hits, created_at, updated_at)"
      . ' VALUES (%s, %s, %s, %s, %s, %s, 1, %s, %s)'
      . ' ON DUPLICATE KEY UPDATE'
      . " title = IF({$held}, title, VALUES(title)),"
      . " brand = IF(brand = '', VALUES(brand), brand),"
      . " image_url = IF(image_url = '', VALUES(image_url), image_url),"
      . " title_owner = IF({$held}, title_owner, VALUES(title_owner)),"
      . ' hits = hits + 1,'
      . ' updated_at = VALUES(updated_at),'
      . " source = IF(source = 'store', 'store', VALUES(source))";
  }

  private static function cut( string $text, int $max ): string {
    return trim( function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $max ) : substr( $text, 0, $max ) );
  }

  /* ------------------------------------------------------------------ */
  /* Switch and install                                                  */
  /* ------------------------------------------------------------------ */

  public static function enabled(): bool {
    $on = ! self::is_off_value( get_network_option( get_main_network_id(), self::OPTION_ENABLED, '1' ) );
    return (bool) apply_filters( self::OPTION_ENABLED, $on );
  }

  public static function table(): string {
    global $wpdb;
    return $wpdb->base_prefix . self::TABLE;
  }

  /** Install when the stored version differs, under a 60 second mutex, as Schema_Installer does per site. */
  public static function maybe_install(): void {
    if ( ! self::enabled() || self::installed() ) {
      return;
    }
    $network = get_main_network_id();
    if ( ! add_network_option( $network, self::OPTION_LOCK, (string) time() ) ) {
      $since = (int) get_network_option( $network, self::OPTION_LOCK, 0 );
      if ( time() - $since < self::LOCK_SECONDS ) {
        return;
      }
      update_network_option( $network, self::OPTION_LOCK, (string) time() );
    }
    try {
      self::install();
    } finally {
      delete_network_option( $network, self::OPTION_LOCK );
    }
  }

  /** @return bool True when the table exists afterwards and the version was stored. */
  private static function install(): bool {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $table = self::table();
    dbDelta( self::definition( $table, $wpdb->get_charset_collate() ) );
    if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) {
      return false;
    }
    update_network_option( get_main_network_id(), self::OPTION_VERSION, self::DB_VERSION );
    return true;
  }

  private static function installed(): bool {
    return self::DB_VERSION === get_network_option( get_main_network_id(), self::OPTION_VERSION );
  }

  private static function ready(): bool {
    return self::enabled() && self::installed();
  }

  /* ------------------------------------------------------------------ */
  /* Reads                                                               */
  /* ------------------------------------------------------------------ */

  /**
   * What the memory knows about a code: title, brand, image_url and source
   * ('provider' or 'store'), else null. The filter bazaar_barcode_memory_row
   * (row, code) may change the row, or return null to hide it.
   */
  public static function find( string $barcode ): ?array {
    $key = self::key( $barcode );
    if ( '' === $key || ! self::ready() ) {
      return null;
    }
    self::share_cache_group();
    $found  = false;
    $cached = wp_cache_get( $key, self::CACHE_GROUP, false, $found );
    if ( $found && is_array( $cached ) ) {
      $row = $cached['row'] ?? null;
    } else {
      global $wpdb;
      $row = self::view( self::row( $key ) );
      if ( '' === $wpdb->last_error ) {
        wp_cache_set( $key, [ 'row' => $row ], self::CACHE_GROUP, self::CACHE_TTL );
      }
    }
    if ( null === $row ) {
      return null;
    }
    $row = apply_filters( self::ROW_FILTER, $row, $key );
    return is_array( $row ) && '' !== (string) ( $row['title'] ?? '' ) ? $row : null;
  }

  /** The whole stored row, past the cache, else null. */
  private static function row( string $key ): ?array {
    global $wpdb;
    $table = self::table();
    $row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE code = %s", $key ), ARRAY_A );
    return is_array( $row ) ? $row : null;
  }

  private static function key( string $barcode ): string {
    return self::key_for( Xophz_Compass_Bazaar_Barcode::normalize( $barcode ) );
  }

  /** The hosts of this site and its uploads. @return string[] */
  private static function own_hosts(): array {
    $uploads = wp_get_upload_dir();
    $urls    = [ home_url(), site_url(), (string) ( $uploads['baseurl'] ?? '' ) ];
    return array_values( array_filter( array_map( function ( $url ) {
      return (string) parse_url( $url, PHP_URL_HOST );
    }, $urls ) ) );
  }

  private static function owner(): string {
    return self::owner_marker( (int) get_current_blog_id(), wp_salt( 'auth' ) );
  }

  /** The group must be global, so every site reads one copy. Safe to repeat. */
  private static function share_cache_group(): void {
    if ( function_exists( 'wp_cache_add_global_groups' ) ) {
      wp_cache_add_global_groups( [ self::CACHE_GROUP ] );
    }
  }

  /* ------------------------------------------------------------------ */
  /* Writes                                                              */
  /* ------------------------------------------------------------------ */

  /**
   * Remember a provider's answer.
   *
   * @param array $product title, brand, image_url
   */
  public static function remember_provider( string $barcode, array $product ): void {
    $key   = self::key( $barcode );
    $title = self::clean_title( $product['title'] ?? '' );
    if ( '' === $key || '' === $title || ! self::ready() ) {
      return;
    }
    self::upsert( $key, $title, self::clean_brand( $product['brand'] ?? '' ), self::clean_image( $product['image_url'] ?? '', self::own_hosts() ), 'provider', '' );
  }

  /**
   * Remember the name a store gave an item it just saved.
   *
   * @return array{code:string,before:?array}|null What restore() needs to take
   *   the write back. Null when nothing was written.
   */
  public static function remember_store( string $barcode, string $title, string $image_url ): ?array {
    $key   = self::key( $barcode );
    $title = self::clean_title( $title );
    if ( '' === $key || '' === $title || ! self::ready() ) {
      return null;
    }
    $before = self::row( $key );
    if ( ! self::upsert( $key, $title, '', self::clean_image( $image_url, self::own_hosts() ), 'store', self::owner() ) ) {
      return null;
    }
    return [ 'code' => $key, 'before' => $before ];
  }

  /**
   * Put a row back as it was before remember_store: delete it if it was new,
   * else restore the snapshot. Touches the row only while it holds this
   * site's store title, so another site's name for the code is left alone.
   *
   * @param array{code:string,before:?array} $undo
   */
  public static function restore( array $undo ): void {
    global $wpdb;
    $key = (string) ( $undo['code'] ?? '' );
    if ( '' === $key || ! self::ready() ) {
      return;
    }
    $table  = self::table();
    $before = isset( $undo['before'] ) && is_array( $undo['before'] ) ? $undo['before'] : null;
    if ( null === $before ) {
      $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE code = %s AND source = 'store' AND title_owner = %s", $key, self::owner() ) );
    } else {
      $wpdb->query( $wpdb->prepare(
        "UPDATE {$table} SET title = %s, brand = %s, image_url = %s, source = %s, title_owner = %s, hits = %d, updated_at = %s WHERE code = %s AND source = 'store' AND title_owner = %s",
        (string) $before['title'],
        (string) $before['brand'],
        (string) $before['image_url'],
        (string) $before['source'],
        (string) $before['title_owner'],
        (int) $before['hits'],
        (string) $before['updated_at'],
        $key,
        self::owner()
      ) );
    }
    self::drop_cached( $key );
  }

  /** Delete a code from the memory. Network admins and WP-CLI only. Returns true when a row went. */
  public static function forget( string $barcode ): bool {
    global $wpdb;
    if ( ! ( defined( 'WP_CLI' ) && WP_CLI ) && ! current_user_can( 'manage_network_options' ) ) {
      return false;
    }
    $key = self::key( $barcode );
    if ( '' === $key || ! self::ready() ) {
      return false;
    }
    $table   = self::table();
    $removed = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE code = %s", $key ) );
    self::drop_cached( $key );
    return $removed > 0;
  }

  private static function upsert( string $key, string $title, string $brand, string $image_url, string $source, string $owner ): bool {
    global $wpdb;
    $now  = gmdate( 'Y-m-d H:i:s' );
    $done = $wpdb->query( $wpdb->prepare( self::upsert_sql( self::table() ), $key, $title, $brand, $image_url, $source, $owner, $now, $now ) );
    self::drop_cached( $key );
    return false !== $done;
  }

  private static function drop_cached( string $key ): void {
    self::share_cache_group();
    wp_cache_delete( $key, self::CACHE_GROUP );
  }
}
