<?php
/**
 * Core_Autoloader name mapping, Core_Modules manifest and Core_Http_Error.
 */
require_once dirname( __DIR__ ) . '/support/harness.php';
$root = dirname( dirname( __DIR__ ) );
require_once $root . '/includes/core/class-xophz-compass-bazaar-core-autoloader.php';
require_once $root . '/includes/core/class-xophz-compass-bazaar-core-modules.php';
require_once $root . '/includes/core/class-xophz-compass-bazaar-core-http-error.php';

$A = 'Xophz_Compass_Bazaar_Core_Autoloader';

$first = function ( string $class ) use ( $A, $root ) {
  $paths = $A::candidates( $class, $root );
  return $paths ? substr( $paths[0], strlen( $root ) + 1 ) : null;
};

check( 'autoload: stock service', $first( 'Xophz_Compass_Bazaar_Stock_Service' ), 'includes/stock/class-xophz-compass-bazaar-stock-service.php' );
check( 'autoload: admin handler keeps the admin naming', $first( 'Xophz_Compass_Bazaar_Admin_Pos_Sale' ), 'admin/class-xophz-compass-bazaar-admin-pos-sale.php' );
check( 'autoload: core money', $first( 'Xophz_Compass_Bazaar_Core_Money' ), 'includes/core/class-xophz-compass-bazaar-core-money.php' );
check( 'autoload: underscores become hyphens', $first( 'Xophz_Compass_Bazaar_Core_Duplicate_Key_Exception' ), 'includes/core/class-xophz-compass-bazaar-core-duplicate-key-exception.php' );
check( 'autoload: rest', $first( 'Xophz_Compass_Bazaar_Rest_Pos_Quote' ), 'includes/rest/class-xophz-compass-bazaar-rest-pos-quote.php' );
check( 'autoload: cli', $first( 'Xophz_Compass_Bazaar_Cli_Commands' ), 'includes/cli/class-xophz-compass-bazaar-cli-commands.php' );
check( 'autoload: kinds tried in order', array_map( function ( $p ) { return explode( '-', basename( $p ), 2 )[0]; }, $A::candidates( 'Xophz_Compass_Bazaar_Payments_Provider', $root ) ), [ 'class', 'interface', 'trait', 'abstract' ] );
foreach ( [ 'Schema', 'Events', 'Security', 'Stock', 'Payments', 'Pricing', 'Checkout', 'Refunds', 'Staff', 'Rest', 'Cli', 'Admin', 'Core' ] as $group ) {
  check( "autoload: group $group is mapped", count( $A::candidates( "Xophz_Compass_Bazaar_{$group}_Thing", $root ) ), 4 );
}
check( 'autoload: unknown group ignored', $A::candidates( 'Xophz_Compass_Bazaar_Pos_Authorization', $root ), [] );
check( 'autoload: group alone ignored', $A::candidates( 'Xophz_Compass_Bazaar_Admin', $root ), [] );
check( 'autoload: other prefix ignored', $A::candidates( 'Some_Other_Class', $root ), [] );
check( 'autoload: path tricks ignored', $A::candidates( 'Xophz_Compass_Bazaar_Core_../x', $root ), [] );
check( 'autoload: trailing slash on the plugin dir is fine', $A::candidates( 'Xophz_Compass_Bazaar_Core_Money', $root . '/' )[0], $root . '/includes/core/class-xophz-compass-bazaar-core-money.php' );

check( 'autoload: register() installs once', [ $A::register( $root ), $A::register( $root ) ], [ true, false ] );
check( 'autoload: a real class loads through spl', class_exists( 'Xophz_Compass_Bazaar_Core_Money' ), true );
check( 'autoload: a missing class is not fatal', class_exists( 'Xophz_Compass_Bazaar_Stock_Does_Not_Exist' ), false );

// Manifest
$M     = 'Xophz_Compass_Bazaar_Core_Modules';
$lists = $M::lists();
check( 'modules: three lists', array_keys( $lists ), [ 'admin_handlers', 'rest_controllers', 'hook_modules' ] );
check( 'modules: counts', [ count( $lists['admin_handlers'] ), count( $lists['rest_controllers'] ), count( $lists['hook_modules'] ) ], [ 8, 6, 8 ] );
check( 'modules: full class names', $lists['rest_controllers'][2], 'Xophz_Compass_Bazaar_Rest_Checkouts' );
check( 'modules: classes that do not exist yet are skipped', $M::existing( $lists['hook_modules'] ), [] );
$GLOBALS['test_filters']['xophz_compass_bazaar_modules'] = [ 'hook_modules' => [ 'Core_Money', 'Xophz_Compass_Bazaar_Core_Canonical', '' ] ];
$filtered = $M::lists();
check( 'modules: filter replaces a list and accepts both name forms', $filtered['hook_modules'], [ 'Xophz_Compass_Bazaar_Core_Money', 'Xophz_Compass_Bazaar_Core_Canonical' ] );
check( 'modules: a list the filter omits becomes empty', $filtered['admin_handlers'], [] );
check( 'modules: existing() keeps loadable classes', $M::existing( $filtered['hook_modules'] ), $filtered['hook_modules'] );
unset( $GLOBALS['test_filters']['xophz_compass_bazaar_modules'] );

// Http_Error (to_wp_error needs WP_Error, so a tiny stand-in is declared)
if ( ! class_exists( 'WP_Error' ) ) {
  class WP_Error {
    public $code;
    public $message;
    public $data;
    public function __construct( $code, $message, $data ) { $this->code = $code; $this->message = $message; $this->data = $data; }
  }
}
$e = new Xophz_Compass_Bazaar_Core_Http_Error( 'conflict', 'Already used.', 409, [ 'id' => 5 ] );
check( 'http error: accessors', [ $e->error_code(), $e->getMessage(), $e->status() ], [ 'conflict', 'Already used.', 409 ] );
check( 'http error: data carries the status', $e->data(), [ 'id' => 5, 'status' => 409 ] );
$w = $e->to_wp_error();
check( 'http error: to_wp_error', [ $w->code, $w->message, $w->data ], [ 'conflict', 'Already used.', [ 'id' => 5, 'status' => 409 ] ] );
check( 'http error: default status is 400', ( new Xophz_Compass_Bazaar_Core_Http_Error( 'x', 'y' ) )->status(), 400 );

bazaar_test_standalone( __FILE__ );
