<?php
/**
 * Maps Bazaar class names to files by convention.
 *
 *   Xophz_Compass_Bazaar_<Group>_<Name…>
 *     -> <dir(Group)>/<kind>-xophz-compass-bazaar-<group>-<name…>.php
 *
 * The kinds class, interface, trait and abstract are tried in that order.
 * A class that maps to no existing file is ignored, never fatal.
 *
 * @package Xophz_Compass_Bazaar
 */
class Xophz_Compass_Bazaar_Core_Autoloader {

  const PREFIX = 'Xophz_Compass_Bazaar_';

  const KINDS = [ 'class', 'interface', 'trait', 'abstract' ];

  const GROUPS = [
    'Core'     => 'includes/core',
    'Schema'   => 'includes/schema',
    'Events'   => 'includes/events',
    'Security' => 'includes/security',
    'Stock'    => 'includes/stock',
    'Payments' => 'includes/payments',
    'Pricing'  => 'includes/pricing',
    'Checkout' => 'includes/checkout',
    'Refunds'  => 'includes/refunds',
    'Staff'    => 'includes/staff',
    'Rest'     => 'includes/rest',
    'Cli'      => 'includes/cli',
    'Admin'    => 'admin',
  ];

  /** @var bool */
  private static $registered = false;

  /** Register the loader once per process. Returns true when it registered now. */
  public static function register( string $plugin_dir ): bool {
    if ( self::$registered ) {
      return false;
    }
    $dir = rtrim( $plugin_dir, '/\\' );
    spl_autoload_register( function ( $class ) use ( $dir ) {
      foreach ( self::candidates( (string) $class, $dir ) as $path ) {
        if ( is_file( $path ) ) {
          require_once $path;
          return;
        }
      }
    } );
    self::$registered = true;
    return true;
  }

  /**
   * Candidate file paths for a class, in the order they are tried. Empty when
   * the name does not follow the convention. Touches no file system.
   *
   * @return string[]
   */
  public static function candidates( string $class, string $plugin_dir ): array {
    if ( 0 !== strpos( $class, self::PREFIX ) ) {
      return [];
    }
    $rest = substr( $class, strlen( self::PREFIX ) );
    if ( ! preg_match( '/^[A-Za-z][A-Za-z0-9]*(_[A-Za-z0-9]+)+$/', $rest ) ) {
      return [];
    }
    $parts = explode( '_', $rest );
    $group = array_shift( $parts );
    if ( ! isset( self::GROUPS[ $group ] ) ) {
      return [];
    }
    $slug  = strtolower( $group . '-' . implode( '-', $parts ) );
    $paths = [];
    foreach ( self::KINDS as $kind ) {
      $paths[] = rtrim( $plugin_dir, '/\\' ) . '/' . self::GROUPS[ $group ] . '/' . $kind . '-xophz-compass-bazaar-' . $slug . '.php';
    }
    return $paths;
  }
}
