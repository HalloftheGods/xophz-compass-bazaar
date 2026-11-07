<?php
/**
 * Security_Policy coverage: the policy keys equal every quoted 'wp_ajax_…'
 * key in admin/*.php and the orchestrator (66), and every policy cap is a
 * Bazaar cap. Also pins the signed-off defaults (spec section 2 and 6.3).
 */
require_once dirname( __DIR__ ) . '/support/harness.php';
$bazaar_root = dirname( dirname( __DIR__ ) );
require_once $bazaar_root . '/includes/security/class-xophz-compass-bazaar-security-capabilities.php';
require_once $bazaar_root . '/includes/security/class-xophz-compass-bazaar-security-policy.php';

$SC = 'Xophz_Compass_Bazaar_Security_Capabilities';
$SP = 'Xophz_Compass_Bazaar_Security_Policy';

// Scan the handler sources for quoted wp_ajax_ keys.
$bazaar_sources = array_merge(
  glob( $bazaar_root . '/admin/*.php' ) ?: [],
  [ $bazaar_root . '/includes/class-xophz-compass-bazaar.php' ]
);
$bazaar_scanned = [];
foreach ( $bazaar_sources as $file ) {
  if ( preg_match_all( '/[\'"]wp_ajax_([A-Za-z0-9_]+)[\'"]/', (string) file_get_contents( $file ), $m ) ) {
    foreach ( $m[1] as $action ) {
      $bazaar_scanned[ $action ] = true;
    }
  }
}
$bazaar_scanned = array_keys( $bazaar_scanned );
sort( $bazaar_scanned );
$bazaar_policy = array_keys( $SP::AJAX );
sort( $bazaar_policy );

check( 'policy: the scan finds 66 distinct actions', count( $bazaar_scanned ), 66 );
check( 'policy: 66 actions in the policy', count( $SP::AJAX ), 66 );
check( 'policy: registered actions missing from the policy', array_values( array_diff( $bazaar_scanned, $bazaar_policy ) ), [] );
check( 'policy: policy actions no longer registered', array_values( array_diff( $bazaar_policy, $bazaar_scanned ) ), [] );
check( 'policy: keys have no wp_ajax_ prefix', array_values( array_filter( $bazaar_policy, function ( $a ) {
  return 0 === strpos( $a, 'wp_ajax_' );
} ) ), [] );

// Every cap list is a non-empty, duplicate-free list of Bazaar caps.
$bazaar_bad = [];
foreach ( $SP::AJAX as $action => $caps ) {
  if ( ! is_array( $caps ) || [] === $caps || count( $caps ) !== count( array_unique( $caps ) ) ) {
    $bazaar_bad[] = $action . ':shape';
  }
  foreach ( (array) $caps as $cap ) {
    if ( ! in_array( $cap, $SC::ALL, true ) ) {
      $bazaar_bad[] = $action . ':' . $cap;
    }
  }
}
check( 'policy: every cap is in Capabilities::ALL', $bazaar_bad, [] );

// caps_for
check( 'caps_for: mapped action', $SP::caps_for( 'process_pos_refund' ), [ 'bazaar_refund' ] );
check( 'caps_for: any-of list', $SP::caps_for( 'get_orders' ), [ 'bazaar_manage_orders', 'bazaar_view_reports' ] );
check( 'caps_for: unmapped is null', $SP::caps_for( 'not_a_bazaar_action' ), null );
check( 'caps_for: prefixed name is not mapped', $SP::caps_for( 'wp_ajax_get_orders' ), null );

// Capabilities shape
check( 'caps: 13 capabilities', count( $SC::ALL ), 13 );
check( 'caps: all distinct and bazaar_ prefixed', count( array_unique( array_filter( $SC::ALL, function ( $c ) {
  return 0 === strpos( $c, 'bazaar_' );
} ) ) ), 13 );
$bazaar_all = $SC::ALL;
sort( $bazaar_all );
$bazaar_woo = $SC::LEGACY['manage_woocommerce'];
sort( $bazaar_woo );
check( 'legacy: manage_woocommerce covers ALL', $bazaar_woo, $bazaar_all );
check( 'legacy: exactly three WooCommerce caps are mapped', array_keys( $SC::LEGACY ), [ 'manage_woocommerce', 'edit_shop_orders', 'edit_products' ] );
check( 'roles: exactly the three Bazaar roles', array_keys( $SC::ROLES ), [ 'bazaar_cashier', 'bazaar_supervisor', 'bazaar_stock_clerk' ] );
check( 'roles: every role has a label', array_keys( $SC::ROLE_LABELS ), array_keys( $SC::ROLES ) );
$bazaar_bad = [];
foreach ( $SC::ROLES as $role => $caps ) {
  foreach ( $caps as $cap ) {
    if ( 'read' !== $cap && ! in_array( $cap, $SC::ALL, true ) ) {
      $bazaar_bad[] = $role . ':' . $cap;
    }
  }
  if ( ! in_array( 'read', $caps, true ) ) {
    $bazaar_bad[] = $role . ':no-read';
  }
}
check( 'roles: only read and Bazaar caps', $bazaar_bad, [] );
check( 'roles: cashier caps', $SC::ROLES['bazaar_cashier'], [ 'read', 'bazaar_sell', 'bazaar_discount', 'bazaar_refund' ] );

// Signed-off defaults (spec section 2).
$bazaar_holders = function ( string $cap ) use ( $SC ): array {
  $out = [];
  foreach ( $SC::LEGACY as $woo => $caps ) {
    if ( in_array( $cap, $caps, true ) ) {
      $out[] = $woo;
    }
  }
  return $out;
};
check( 'sign-off: settings only through manage_woocommerce', $bazaar_holders( 'bazaar_manage_settings' ), [ 'manage_woocommerce' ] );
foreach ( [ 'bazaar_save_bank_details', 'get_all_payment_gateways', 'toggle_payment_gateway' ] as $action ) {
  check( "sign-off: $action needs manage_settings", $SP::AJAX[ $action ], [ 'bazaar_manage_settings' ] );
}
foreach ( [ 'bazaar_get_coupons', 'bazaar_save_coupon', 'bazaar_delete_coupon' ] as $action ) {
  check( "sign-off: $action needs catalog rights", $SP::AJAX[ $action ], [ 'bazaar_manage_catalog' ] );
}
check( 'sign-off: reading bank details stays open to sell', in_array( 'bazaar_sell', $SP::AJAX['bazaar_get_bank_details'], true ), true );
foreach ( [ 'bazaar_save_inventory_location', 'bazaar_delete_inventory_location', 'bazaar_save_product_inventories', 'bazaar_bulk_update_inventory_stock', 'bazaar_import_inventory_csv' ] as $action ) {
  check( "sign-off: $action needs manage_stock", $SP::AJAX[ $action ], [ 'bazaar_manage_stock' ] );
}
check( 'policy: receive_po needs receive', $SP::AJAX['bazaar_receive_po'], [ 'bazaar_receive' ] );

bazaar_test_standalone( __FILE__ );
