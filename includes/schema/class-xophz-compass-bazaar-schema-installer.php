<?php
/**
 * Creates and upgrades the Bazaar tables with dbDelta.
 *
 * Tables and ledger history are never dropped by this plugin, including on
 * uninstall. One exception: a multisite site's Scan a pile table goes with
 * the site (Quick_Pile_Store::drop_with_site).
 *
 * Every table name carries $wpdb->prefix and the version is a per-site
 * option, so in multisite each site installs its own tables on its first
 * request after an upgrade.
 *
 * @package Xophz_Compass_Bazaar
 */
class Xophz_Compass_Bazaar_Schema_Installer {

  const DB_VERSION    = '26.11.06.1';
  const OPTION_VERSION = 'xophz_compass_bazaar_db_version';
  const OPTION_ENGINES = 'xophz_compass_bazaar_db_engines';
  const OPTION_LOCK    = 'xophz_compass_bazaar_db_lock';
  const LOCK_SECONDS   = 60;

  /**
   * Install when the stored version differs from DB_VERSION. Returns at once
   * on a match. Otherwise takes a 60 second add_option mutex so two requests
   * do not install together; a request that does not get the mutex returns
   * without installing.
   */
  public static function maybe_install(): void {
    if ( self::DB_VERSION === get_option( self::OPTION_VERSION ) ) {
      return;
    }
    if ( ! self::take_lock() ) {
      return;
    }
    try {
      self::install();
    } finally {
      delete_option( self::OPTION_LOCK );
    }
  }

  /**
   * Run dbDelta over the eleven tables, record storage engines, install roles
   * when the capabilities class exists, and store DB_VERSION.
   *
   * @return bool True when every table exists afterwards and the version was stored.
   *              False leaves the version unset so the next request tries again.
   */
  public static function install(): bool {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    foreach ( Xophz_Compass_Bazaar_Schema_Tables::definitions( $wpdb->prefix, $wpdb->get_charset_collate() ) as $sql ) {
      dbDelta( $sql );
    }

    $engines = self::engines();
    update_option( self::OPTION_ENGINES, $engines, false );

    if ( class_exists( 'Xophz_Compass_Bazaar_Security_Capabilities' ) ) {
      Xophz_Compass_Bazaar_Security_Capabilities::install_roles();
    }

    foreach ( Xophz_Compass_Bazaar_Schema_Tables::NAMES as $name ) {
      if ( empty( $engines[ 'bazaar_' . $name ] ) ) {
        return false;
      }
    }
    update_option( self::OPTION_VERSION, self::DB_VERSION, true );
    return true;
  }

  /**
   * Storage engine per table, from SHOW TABLE STATUS, for the eleven Bazaar
   * tables (keys 'bazaar_<name>') and 'postmeta'. A table that does not exist
   * is left out.
   *
   * @return array<string,string>
   */
  public static function engines(): array {
    global $wpdb;
    $found = [];
    $wanted = [ 'postmeta' => $wpdb->postmeta ];
    foreach ( Xophz_Compass_Bazaar_Schema_Tables::NAMES as $name ) {
      $wanted[ 'bazaar_' . $name ] = Xophz_Compass_Bazaar_Core_Db::table( $name );
    }
    foreach ( $wanted as $key => $table ) {
      $row = Xophz_Compass_Bazaar_Core_Db::get_row( 'SHOW TABLE STATUS LIKE %s', [ $wpdb->esc_like( $table ) ] );
      if ( $row && ! empty( $row->Engine ) ) {
        $found[ $key ] = (string) $row->Engine;
      }
    }
    return $found;
  }

  private static function take_lock(): bool {
    if ( add_option( self::OPTION_LOCK, (string) time(), '', false ) ) {
      return true;
    }
    $taken = (int) get_option( self::OPTION_LOCK, 0 );
    if ( $taken > 0 && time() - $taken > self::LOCK_SECONDS ) {
      delete_option( self::OPTION_LOCK );
      return (bool) add_option( self::OPTION_LOCK, (string) time(), '', false );
    }
    return false;
  }
}
