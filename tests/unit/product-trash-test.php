<?php
/**
 * Product trash: the policy entries, the refusal wording, the order table
 * choice, and that the open-order query fails closed. Live trash, restore and
 * refusal run against local WordPress, not here.
 */
require_once dirname( __DIR__ ) . '/support/harness.php';
$bazaar_root = dirname( dirname( __DIR__ ) );
require_once $bazaar_root . '/includes/security/class-xophz-compass-bazaar-security-policy.php';
require_once $bazaar_root . '/includes/core/class-xophz-compass-bazaar-core-modules.php';
require_once $bazaar_root . '/admin/class-xophz-compass-bazaar-admin-product-trash.php';

$T  = 'Xophz_Compass_Bazaar_Admin_Product_Trash';
$SP = 'Xophz_Compass_Bazaar_Security_Policy';

check( 'policy: trash needs manage_catalog', $SP::caps_for( 'bazaar_trash_product' ), [ 'bazaar_manage_catalog' ] );
check( 'policy: restore needs manage_catalog', $SP::caps_for( 'bazaar_restore_product' ), [ 'bazaar_manage_catalog' ] );
check( 'policy: list needs manage_catalog only', $SP::caps_for( 'bazaar_list_trashed_products' ), [ 'bazaar_manage_catalog' ] );
check( 'policy: delete forever needs manage_catalog only', $SP::caps_for( 'bazaar_delete_product_permanently' ), [ 'bazaar_manage_catalog' ] );
check( 'manifest: the handler is booted', in_array( 'Admin_Product_Trash', Xophz_Compass_Bazaar_Core_Modules::ADMIN_HANDLERS, true ), true );
check( 'open statuses', $T::OPEN_ORDER_STATUSES, [ 'pending', 'processing', 'on-hold', 'ready-pickup' ] );

check( 'message: one order', $T::open_orders_message( "Elmer's Glue", 1 ), "Elmer's Glue is on an open order, so it can't be deleted yet. Finish or cancel that order first." );
check( 'message: many orders', $T::open_orders_message( 'Tea', 3 ), "Tea is on 3 open orders, so it can't be deleted yet. Finish or cancel those orders first." );

check( 'message: has orders, one', $T::has_orders_message( 'Tea', 1 ), "Tea is on an order, so it can't be deleted forever. Its sales history needs it." );
check( 'message: has orders, many', $T::has_orders_message( 'Tea', 4 ), "Tea is on 4 orders, so it can't be deleted forever. Its sales history needs it." );
check( 'message: forever, photo removed', $T::forever_message( 'Tea', 'removed' ), 'Tea was deleted forever.' );
check( 'message: forever, photo kept', $T::forever_message( 'Tea', 'kept' ), 'Tea was deleted forever. Its photo was kept because it may be used somewhere else.' );
check( 'days_left: counts up', $T::days_left( 1000, 30, 1000 + 86400 * 10 + 5 ), 20 );
check( 'days_left: a fresh one has the full time', $T::days_left( 1000, 30, 1000 ), 30 );
check( 'days_left: never negative', $T::days_left( 1000, 30, 1000 + 86400 * 90 ), 0 );
check( 'source: HPOS reads wc_orders', $T::order_source( true, 'wp_', 'wp_posts' ), [ 'table' => 'wp_wc_orders', 'id' => 'id', 'status' => 'status', 'type' => 'type' ] );
check( 'source: legacy reads posts', $T::order_source( false, 'wp_', 'wp_posts' ), [ 'table' => 'wp_posts', 'id' => 'ID', 'status' => 'post_status', 'type' => 'post_type' ] );

/** A product double: only the two calls ids_on_order_lines makes. */
class Bazaar_Trash_Test_Product {
  public function __construct( private string $type, private array $children ) {}
  public function get_id() { return 5; }
  public function is_type( $type ) { return $type === $this->type; }
  public function get_children() { return $this->children; }
}
check( 'lines: a simple product is itself', $T::ids_on_order_lines( new Bazaar_Trash_Test_Product( 'simple', [] ) ), [ 5 ] );
check( 'lines: a variable product adds its choices', $T::ids_on_order_lines( new Bazaar_Trash_Test_Product( 'variable', [ '6', 7 ] ) ), [ 5, 6, 7 ] );
check( 'lines: a grouped product leaves its members alone', $T::ids_on_order_lines( new Bazaar_Trash_Test_Product( 'grouped', [ 8 ] ) ), [ 5 ] );

/** Records the prepared query and answers what the test set. */
class Bazaar_Trash_Test_Wpdb {
  public $prefix = 'wp_';
  public $posts  = 'wp_posts';
  public $answer = '0';
  public $args   = [];
  public $sql    = '';
  public function prepare( $sql, $args ) { $this->sql = $sql; $this->args = $args; return $sql; }
  public function get_var( $sql ) { return $this->answer; }
}
$GLOBALS['wpdb'] = new Bazaar_Trash_Test_Wpdb();

check( 'count: no ids asks nothing', $T::open_order_count( [ 0 ] ), 0 );
check( 'count: nothing was prepared', $GLOBALS['wpdb']->sql, '' );

$GLOBALS['wpdb']->answer = '2';
check( 'count: the answer is a number', $T::open_order_count( [ 5, 5, 6 ] ), 2 );
check( 'count: ids go as text, statuses carry wc-', $GLOBALS['wpdb']->args, [ '5', '6', 'wc-pending', 'wc-processing', 'wc-on-hold', 'wc-ready-pickup' ] );
check( 'count: refunds are left out', strpos( $GLOBALS['wpdb']->sql, "= 'shop_order'" ) !== false, true );

$GLOBALS['wpdb']->answer = '1';
check( 'any order: no status filter', $T::order_count( [ 5 ], null ), 1 );
check( 'any order: only ids are bound', $GLOBALS['wpdb']->args, [ '5' ] );
check( 'any order: statuses are not in the query', strpos( $GLOBALS['wpdb']->sql, 'post_status IN' ), false );

$GLOBALS['wpdb']->answer = null;
check_throws( 'any order: a failed query is not "no orders"', function () use ( $T ) {
  $T::order_count( [ 5 ], null );
} );
check_throws( 'count: a failed query is not "no orders"', function () use ( $T ) {
  $T::open_order_count( [ 5 ] );
} );
unset( $GLOBALS['wpdb'] );

bazaar_test_standalone( __FILE__ );
