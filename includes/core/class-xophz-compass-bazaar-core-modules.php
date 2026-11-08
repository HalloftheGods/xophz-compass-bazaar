<?php
/**
 * Module manifest: every Phase 1 module listed up front.
 *
 * A listed class that does not exist is skipped, so packages can land in any
 * order. The three lists pass through the filter xophz_compass_bazaar_modules
 * (an array with the keys admin_handlers, rest_controllers, hook_modules; each
 * a list of short class names without the Xophz_Compass_Bazaar_ prefix).
 *
 * @package Xophz_Compass_Bazaar
 */
class Xophz_Compass_Bazaar_Core_Modules {

  const PREFIX = 'Xophz_Compass_Bazaar_';

  /** Booted with $plugin->define_class_hooks( $class ). */
  const ADMIN_HANDLERS = [
    'Admin_Pos_Sale', 'Admin_Pos_Customers', 'Admin_Pos_Shift', 'Admin_Pos_Refunds',
    'Admin_Coupons', 'Admin_Payment_Settings', 'Admin_Test_Portal', 'Admin_Categories', 'Admin_Pos_Lookup',
    'Admin_Product_Trash', 'Admin_Product_Details',
  ];

  /** Booted with (new $class())->register_routes() on rest_api_init. */
  const REST_CONTROLLERS = [
    'Rest_Pos_Config', 'Rest_Pos_Quote', 'Rest_Checkouts', 'Rest_Refunds', 'Rest_Staff', 'Rest_Stock',
  ];

  /** Booted with $class::register(), which adds WordPress hooks directly. */
  const HOOK_MODULES = [
    'Security_Capabilities', 'Pricing_Tax_Location', 'Stock_Observer', 'Stock_Order_Lifecycle',
    'Stock_Backfill', 'Checkout_Sweeper', 'Checkout_Payment_Hooks', 'Cli_Commands',
  ];

  /**
   * The three lists after the filter, as full class names.
   *
   * @return array{admin_handlers:string[],rest_controllers:string[],hook_modules:string[]}
   */
  public static function lists(): array {
    $lists = apply_filters( 'xophz_compass_bazaar_modules', [
      'admin_handlers'   => self::ADMIN_HANDLERS,
      'rest_controllers' => self::REST_CONTROLLERS,
      'hook_modules'     => self::HOOK_MODULES,
    ] );
    $out = [];
    foreach ( [ 'admin_handlers', 'rest_controllers', 'hook_modules' ] as $key ) {
      $out[ $key ] = [];
      foreach ( (array) ( is_array( $lists ) ? ( $lists[ $key ] ?? [] ) : [] ) as $short ) {
        if ( is_string( $short ) && '' !== $short ) {
          $out[ $key ][] = 0 === strpos( $short, self::PREFIX ) ? $short : self::PREFIX . $short;
        }
      }
    }
    return $out;
  }

  /** Keep the names whose class exists (the autoloader is tried). */
  public static function existing( array $classes ): array {
    return array_values( array_filter( $classes, function ( $class ) {
      return class_exists( $class );
    } ) );
  }

  /**
   * Boot admin handlers and hook modules, and queue REST registration.
   * Called at the end of the plugin constructor.
   *
   * @param object $plugin Needs define_class_hooks() and register_rest_routes().
   */
  public static function boot( $plugin ): void {
    $lists = self::lists();
    foreach ( self::existing( $lists['admin_handlers'] ) as $class ) {
      $plugin->define_class_hooks( $class );
    }
    foreach ( self::existing( $lists['hook_modules'] ) as $class ) {
      if ( method_exists( $class, 'register' ) ) {
        $class::register();
      }
    }
    add_action( 'rest_api_init', [ $plugin, 'register_rest_routes' ] );
  }

  /** Register every existing REST controller's routes. Call on rest_api_init. */
  public static function register_rest(): void {
    foreach ( self::existing( self::lists()['rest_controllers'] ) as $class ) {
      ( new $class() )->register_routes();
    }
  }
}
