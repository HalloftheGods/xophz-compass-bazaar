<?php
/**
 * Admin_Orders split (#6946): the nine handler classes together register the
 * original 21 actions once each, every file stays under 500 lines, and the
 * pure helpers behave.
 */
require_once dirname( __DIR__ ) . '/support/harness.php';
$bazaar_root = dirname( dirname( __DIR__ ) );

$bazaar_files = [
  'Admin_Orders'           => 'orders',
  'Admin_Pos_Sale'         => 'pos-sale',
  'Admin_Pos_Customers'    => 'pos-customers',
  'Admin_Pos_Shift'        => 'pos-shift',
  'Admin_Pos_Refunds'      => 'pos-refunds',
  'Admin_Coupons'          => 'coupons',
  'Admin_Payment_Settings' => 'payment-settings',
  'Admin_Test_Portal'      => 'test-portal',
  'Admin_Categories'       => 'categories',
];

$bazaar_original = [
  'get_orders', 'get_categories', 'create_pos_order', 'get_payment_gateways', 'get_all_payment_gateways',
  'toggle_payment_gateway', 'update_order_status', 'validate_pos_coupon', 'send_pos_receipt',
  'get_pos_customers', 'email_shift_summary', 'get_pos_order_for_refund', 'process_pos_refund',
  'bazaar_get_coupons', 'bazaar_save_coupon', 'bazaar_delete_coupon', 'bazaar_create_category',
  'bazaar_get_bank_details', 'bazaar_save_bank_details', 'bazaar_pos_gateway_portal',
  'bazaar_pos_authorize_test_payment',
];

$bazaar_hooks = [];
$bazaar_long  = [];
$bazaar_miss  = [];
foreach ( $bazaar_files as $short => $slug ) {
  $path = $bazaar_root . '/admin/class-xophz-compass-bazaar-admin-' . $slug . '.php';
  if ( ! is_file( $path ) ) {
    $bazaar_miss[] = $slug;
    continue;
  }
  $source = (string) file_get_contents( $path );
  if ( substr_count( $source, "\n" ) + 1 >= 500 ) {
    $bazaar_long[] = $slug;
  }
  if ( preg_match_all( '/[\'"]wp_ajax_([A-Za-z0-9_]+)[\'"]\s*=>\s*[\'"]([A-Za-z0-9_]+)[\'"]/', $source, $m ) ) {
    foreach ( $m[1] as $i => $action ) {
      $bazaar_hooks[] = $action;
      // Every hooked method exists in the same file.
      check( "split: $slug defines {$m[2][$i]}", false !== strpos( $source, 'function ' . $m[2][ $i ] . '(' ), true );
    }
  }
}
check( 'split: all nine handler files exist', $bazaar_miss, [] );
check( 'split: every handler file is under 500 lines', $bazaar_long, [] );
check( 'split: no action is registered twice', count( $bazaar_hooks ), count( array_unique( $bazaar_hooks ) ) );
$bazaar_sorted = $bazaar_hooks;
sort( $bazaar_sorted );
$bazaar_expected = $bazaar_original;
sort( $bazaar_expected );
check( 'split: the nine classes register exactly the original 21 actions', $bazaar_sorted, $bazaar_expected );
check( 'split: 21 actions', count( $bazaar_hooks ), 21 );

// Module manifest lists the eight new handlers (Admin_Orders is booted by the orchestrator).
require_once $bazaar_root . '/includes/core/class-xophz-compass-bazaar-core-modules.php';
$bazaar_listed = array_diff( array_values( $bazaar_files ), [ 'orders' ] );
$bazaar_names  = array_map( function ( $s ) {
  return implode( '_', array_map( 'ucfirst', explode( '-', 'admin-' . $s ) ) );
}, $bazaar_listed );
check( 'manifest: lists the eight new handlers', array_values( array_diff( $bazaar_names, Xophz_Compass_Bazaar_Core_Modules::ADMIN_HANDLERS ) ), [] );

// Pure helpers
require_once $bazaar_root . '/admin/class-xophz-compass-bazaar-admin-orders.php';
require_once $bazaar_root . '/admin/class-xophz-compass-bazaar-admin-pos-sale.php';
require_once $bazaar_root . '/admin/class-xophz-compass-bazaar-admin-pos-customers.php';
require_once $bazaar_root . '/admin/class-xophz-compass-bazaar-admin-payment-settings.php';

$O = 'Xophz_Compass_Bazaar_Admin_Orders';
check( 'status: wc- prefix dropped, draft and refunded removed', $O::allowed_statuses( [
  'wc-pending' => 'Pending', 'wc-completed' => 'Completed', 'wc-refunded' => 'Refunded',
  'wc-checkout-draft' => 'Draft', 'wc-ready-pickup' => 'Ready',
] ), [ 'pending', 'completed', 'ready-pickup' ] );
check( 'status: empty list', $O::allowed_statuses( [] ), [] );

$S = 'Xophz_Compass_Bazaar_Admin_Pos_Sale';
check( 'discount: 10 percent of 50.00', $S::custom_discount_amount( [ 'type' => 'percent', 'amount' => '10' ], 50.0 ), 5.0 );
check( 'discount: percent rounds to cents', $S::custom_discount_amount( [ 'type' => 'percent', 'amount' => 15 ], 9.99 ), 1.5 );
check( 'discount: percent above 100 is capped at 100', $S::custom_discount_amount( [ 'type' => 'percent', 'amount' => 250 ], 20.0 ), 20.0 );
check( 'discount: fixed amount is money', $S::custom_discount_amount( [ 'type' => 'fixed', 'amount' => '4.5' ], 50.0 ), 4.5 );
check( 'discount: missing type is money', $S::custom_discount_amount( [ 'amount' => 3 ], 50.0 ), 3.0 );
check( 'discount: zero and negative give nothing', [ $S::custom_discount_amount( [ 'amount' => 0 ], 50.0 ), $S::custom_discount_amount( [ 'type' => 'percent', 'amount' => -5 ], 50.0 ) ], [ 0.0, 0.0 ] );

$C = 'Xophz_Compass_Bazaar_Admin_Pos_Customers';
check( 'mask: email', $C::mask_email( 'jane.doe@example.com' ), 'j***@e***.com' );
check( 'mask: not an email', $C::mask_email( 'nope' ), '' );
check( 'mask: phone keeps the last four digits', $C::mask_phone( '+1 (555) 010-1234' ), '***-1234' );
check( 'mask: empty phone', $C::mask_phone( '' ), '' );

$P = 'Xophz_Compass_Bazaar_Admin_Payment_Settings';
check( 'title: WooPayments card', $P::clean_payment_title( 'WooPayments (Card)' ), 'Credit Card' );
check( 'title: WooPayments wallet', $P::clean_payment_title( 'WooPayments (Apple Pay)' ), 'Apple Pay' );
check( 'title: plain WooPayments', $P::clean_payment_title( 'WooPayments' ), 'Credit Card' );
check( 'title: other gateway is trimmed only', $P::clean_payment_title( '  Cash on delivery ' ), 'Cash on delivery' );

bazaar_test_standalone( __FILE__ );
