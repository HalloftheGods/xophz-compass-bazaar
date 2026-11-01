<?php
/**
 * The shared Stock_Service on the wpdb store and the WooCommerce port.
 *
 * Guarantees: service() returns the same instance for the whole request, so
 * the service's own transaction depth and after-commit queue are shared by
 * every caller. reset() exists for tests.
 *
 * @package Xophz_Compass_Bazaar
 */
final class Xophz_Compass_Bazaar_Stock_Factory {

  /** @var Xophz_Compass_Bazaar_Stock_Service|null */
  private static $service = null;

  /** @var Xophz_Compass_Bazaar_Stock_Wc_Port_Wpdb|null */
  private static $port = null;

  public static function service(): Xophz_Compass_Bazaar_Stock_Service {
    if ( null === self::$service ) {
      self::$service = new Xophz_Compass_Bazaar_Stock_Service(
        new Xophz_Compass_Bazaar_Stock_Store_Wpdb(),
        self::port(),
        [ 'Xophz_Compass_Bazaar_Core_Db', 'now' ]
      );
    }
    return self::$service;
  }

  /** The port the shared service uses (for ensure_stock_rows and product_info before a call). */
  public static function port(): Xophz_Compass_Bazaar_Stock_Wc_Port_Wpdb {
    if ( null === self::$port ) {
      self::$port = new Xophz_Compass_Bazaar_Stock_Wc_Port_Wpdb();
    }
    return self::$port;
  }

  public static function reset(): void {
    self::$service = null;
    self::$port    = null;
  }
}
