<?php
/**
 * Quick Add policy: every admin-ajax action Quick Add (and its Scan a pile
 * module) registers is mapped for
 * the Bazaar Security_Guard (an unmapped action is refused 403 before its
 * handler runs), and Security_Policy and the handler's policy() agree.
 *
 * @package Xophz_Compass_Bazaar
 */

require_once dirname( __DIR__ ) . '/support/harness.php';
$bazaar_root = dirname( dirname( __DIR__ ) );
require_once $bazaar_root . '/includes/security/class-xophz-compass-bazaar-security-capabilities.php';
require_once $bazaar_root . '/includes/security/class-xophz-compass-bazaar-security-policy.php';
require_once $bazaar_root . '/includes/security/class-xophz-compass-bazaar-security-guard.php';
require_once $bazaar_root . '/admin/class-xophz-compass-bazaar-admin-quick-add.php';
require_once $bazaar_root . '/admin/class-xophz-compass-bazaar-admin-quick-pile.php';

$SC        = 'Xophz_Compass_Bazaar_Security_Capabilities';
$SG        = 'Xophz_Compass_Bazaar_Security_Guard';
$SP        = 'Xophz_Compass_Bazaar_Security_Policy';
$QA        = 'Xophz_Compass_Bazaar_Admin_Quick_Add';
$QP        = 'Xophz_Compass_Bazaar_Admin_Quick_Pile';
$qa_policy = $QA::policy();
$qp_policy = $QP::policy();

// A pile scan kept on a phone may be resent days later: it must still count once.
check( 'quick add: pile add remembers a client_uuid for at least 7 days', $QP::IDEMPOTENCY_TTL >= 7 * 86400, true );
// A save with no answer may be settled hours later, after the phone is back online.
check( 'quick add: save remembers a client_uuid for 24 hours', $QA::IDEMPOTENCY_TTL, 86400 );

foreach ( [ $QA, $QP ] as $qa_class ) {
  $qa_module        = new $qa_class( 'xophz-compass-bazaar', 'test' );
  $qa_module_policy = $qa_class::policy();
  check( "quick add: $qa_class is a guard module policy", in_array( $qa_class, $SG::MODULE_POLICIES, true ), true );
  check( "quick add: $qa_class maps exactly its hooks", count( $qa_module_policy ), count( $qa_module->action_hooks ) );
  foreach ( array_keys( $qa_module->action_hooks ) as $hook ) {
    check( "quick add: $hook is a wp_ajax_ hook", 0 === strpos( $hook, 'wp_ajax_' ), true );
    $action = substr( $hook, strlen( 'wp_ajax_' ) );
    $mapped = $SG::caps_for( $action );
    check( "quick add: $action is in Security_Policy", $SP::caps_for( $action ), $qa_module_policy[ $action ] ?? null );
    check( "quick add: $action guard caps equal the handler's", $mapped, $qa_module_policy[ $action ] ?? null );
    foreach ( (array) $mapped as $cap ) {
      check( "quick add: $action cap $cap is a Bazaar cap", in_array( $cap, $SC::ALL, true ), true );
    }
  }
}

foreach ( [ 'bazaar_pile_add', 'bazaar_pile_list', 'bazaar_pile_set_qty', 'bazaar_pile_resolve' ] as $action ) {
  check( "quick add: $action has the lookup caps", $qp_policy[ $action ] ?? null, $qa_policy['bazaar_quick_lookup'] );
}

// Both modules ask for the Bazaar caps alone: a WooCommerce cap would refuse bazaar_stock_clerk.
foreach ( [ 'quick-add', 'quick-pile' ] as $qa_file ) {
  $qa_text = (string) file_get_contents( $bazaar_root . "/admin/class-xophz-compass-bazaar-admin-$qa_file.php" );
  check( "quick add: $qa_file does not ask for a WooCommerce cap", false === strpos( $qa_text, "enforce_spa_ajax( 'manage_woocommerce'" ), true );
}

check( 'quick add: save needs stock or catalog', $qa_policy['bazaar_quick_save'], [ $SC::MANAGE_STOCK, $SC::MANAGE_CATALOG ] );
check( 'quick add: undo needs stock or catalog', $qa_policy['bazaar_quick_undo'], [ $SC::MANAGE_STOCK, $SC::MANAGE_CATALOG ] );
check( 'quick add: lookup allows receive', in_array( $SC::RECEIVE, $qa_policy['bazaar_quick_lookup'], true ), true );
check( 'quick add: image has the lookup caps', $qa_policy['bazaar_quick_image'] ?? null, $qa_policy['bazaar_quick_lookup'] );
check( 'quick add: an unknown action stays unmapped', $SG::caps_for( 'bazaar_quick_nope' ), null );

// The longest client_uuid quickSave accepts, with the longest suffix a stock
// write appends, must still be a valid Stock_Keys uuid (at most 64 characters).
$qa_source = (string) file_get_contents( $bazaar_root . '/admin/class-xophz-compass-bazaar-admin-quick-add.php' );
preg_match( '/\{1,(\d+)\}\$\/\', \$uuid/', $qa_source, $qa_limit );
check( 'quick add: client_uuid plus -a fits a stock key', isset( $qa_limit[1] ) && (int) $qa_limit[1] + 2 <= 64, true );

bazaar_test_standalone( __FILE__ );
