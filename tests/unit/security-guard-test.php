<?php
/**
 * Security_Guard::decide() matrix, the legacy cap map and its user_has_cap
 * filter, wrap_ajax_actions(), staff-session results and Security_Context.
 * Everything here is pure: no WordPress function is called.
 */
require_once dirname( __DIR__ ) . '/support/harness.php';
$bazaar_root = dirname( dirname( __DIR__ ) );
foreach ( [ 'capabilities', 'policy', 'context', 'guarded-callback', 'guard' ] as $bazaar_part ) {
  require_once $bazaar_root . "/includes/security/class-xophz-compass-bazaar-security-$bazaar_part.php";
}

$SG  = 'Xophz_Compass_Bazaar_Security_Guard';
$SC  = 'Xophz_Compass_Bazaar_Security_Capabilities';
$CTX = 'Xophz_Compass_Bazaar_Security_Context';
$GCB = 'Xophz_Compass_Bazaar_Security_Guarded_Callback';

// decide(): cap held or not x nonce valid/missing/bad x report-only off/on, plus unmapped.
$bazaar_caps = [ 'bazaar_sell', 'bazaar_manage_catalog' ];
foreach ( [ false, true ] as $report_only ) {
  $ro = $report_only ? 'report-only' : 'enforcing';
  foreach ( [ 'valid', 'missing', 'bad' ] as $nonce ) {
    check( "decide: $ro, no cap, nonce $nonce", $SG::decide( $bazaar_caps, [ 'held' => [], 'nonce' => $nonce, 'report_only' => $report_only ] ), 'forbidden' );
    check( "decide: $ro, other cap only, nonce $nonce", $SG::decide( $bazaar_caps, [ 'held' => [ 'bazaar_refund' ], 'nonce' => $nonce, 'report_only' => $report_only ] ), 'forbidden' );
    check( "decide: $ro, unmapped, nonce $nonce", $SG::decide( null, [ 'held' => $bazaar_caps, 'nonce' => $nonce, 'report_only' => $report_only ] ), 'unmapped' );
    $expected = ( 'valid' === $nonce || $report_only ) ? 'allow' : 'bad_nonce';
    check( "decide: $ro, second cap held, nonce $nonce", $SG::decide( $bazaar_caps, [ 'held' => [ 'bazaar_manage_catalog' ], 'nonce' => $nonce, 'report_only' => $report_only ] ), $expected );
  }
}
check( 'decide: empty cap list fails closed', $SG::decide( [], [ 'held' => [ 'bazaar_sell' ], 'nonce' => 'valid' ] ), 'forbidden' );
check( 'decide: empty env fails closed', $SG::decide( $bazaar_caps, [] ), 'forbidden' );
check( 'decide: no nonce key counts as missing', $SG::decide( $bazaar_caps, [ 'held' => [ 'bazaar_sell' ] ] ), 'bad_nonce' );
check( 'decide: report_only must be true, not truthy', $SG::decide( $bazaar_caps, [ 'held' => [ 'bazaar_sell' ], 'nonce' => 'bad', 'report_only' => 1 ] ), 'bad_nonce' );
check( 'decide: held must be an array', $SG::decide( $bazaar_caps, [ 'held' => 'bazaar_sell', 'nonce' => 'valid' ] ), 'forbidden' );

// map_legacy()
$bazaar_orders = [ 'bazaar_sell', 'bazaar_discount', 'bazaar_refund', 'bazaar_approve', 'bazaar_view_customers', 'bazaar_manage_orders', 'bazaar_view_reports' ];
$bazaar_products = [ 'bazaar_manage_catalog', 'bazaar_manage_stock', 'bazaar_receive', 'bazaar_manage_purchasing' ];
check( 'map_legacy: nothing held', $SC::map_legacy( [] ), [] );
check( 'map_legacy: read only', $SC::map_legacy( [ 'read' => true ] ), [] );
check( 'map_legacy: manage_woocommerce gives all 13', $SC::map_legacy( [ 'manage_woocommerce' => true ] ), $SC::ALL );
check( 'map_legacy: a false cap grants nothing', $SC::map_legacy( [ 'manage_woocommerce' => false, 'edit_products' => 0 ] ), [] );
check( 'map_legacy: edit_shop_orders', $SC::map_legacy( [ 'edit_shop_orders' => true ] ), $bazaar_orders );
check( 'map_legacy: edit_products', $SC::map_legacy( [ 'edit_products' => true ] ), $bazaar_products );
$bazaar_both = $SC::map_legacy( [ 'edit_shop_orders' => true, 'edit_products' => true ] );
check( 'map_legacy: orders + products is the union, in ALL order', $bazaar_both, array_values( array_intersect( $SC::ALL, array_merge( $bazaar_orders, $bazaar_products ) ) ) );
check( 'map_legacy: orders + products never gives settings or staff', array_values( array_intersect( $bazaar_both, [ 'bazaar_manage_settings', 'bazaar_manage_staff' ] ) ), [] );
check( 'map_legacy: shop manager caps give all 13 once', $SC::map_legacy( [ 'manage_woocommerce' => true, 'edit_shop_orders' => true, 'edit_products' => true ] ), $SC::ALL );
check( 'map_legacy: bazaar caps are not inputs', $SC::map_legacy( [ 'bazaar_sell' => true, 'manage_options' => true ] ), [] );

// filter_user_has_cap()
$bazaar_f = function ( array $allcaps, array $caps ) use ( $SC ) {
  return $SC::filter_user_has_cap( $allcaps, $caps, [], null );
};
check( 'filter: grants a requested mapped cap', $bazaar_f( [ 'edit_shop_orders' => true ], [ 'bazaar_sell' ] ), [ 'edit_shop_orders' => true, 'bazaar_sell' => true ] );
check( 'filter: does not grant an unmapped bazaar cap', $bazaar_f( [ 'edit_shop_orders' => true ], [ 'bazaar_manage_settings' ] ), [ 'edit_shop_orders' => true ] );
check( 'filter: only the requested cap is added', $bazaar_f( [ 'manage_woocommerce' => true ], [ 'bazaar_refund' ] ), [ 'manage_woocommerce' => true, 'bazaar_refund' => true ] );
check( 'filter: never touches a non-bazaar cap', $bazaar_f( [ 'manage_woocommerce' => true ], [ 'manage_options', 'edit_users' ] ), [ 'manage_woocommerce' => true ] );
check( 'filter: unknown bazaar_ cap is not granted', $bazaar_f( [ 'manage_woocommerce' => true ], [ 'bazaar_superpower' ] ), [ 'manage_woocommerce' => true ] );
check( 'filter: keeps an explicit deny', $bazaar_f( [ 'manage_woocommerce' => true, 'bazaar_refund' => false ], [ 'bazaar_refund' ] ), [ 'manage_woocommerce' => true, 'bazaar_refund' => false ] );
check( 'filter: a role cap stays as it is', $bazaar_f( [ 'bazaar_sell' => true ], [ 'bazaar_sell' ] ), [ 'bazaar_sell' => true ] );
check( 'filter: no WooCommerce cap, nothing granted', $bazaar_f( [ 'read' => true ], [ 'bazaar_sell' ] ), [ 'read' => true ] );
check( 'filter: non-array input returned unchanged', $SC::filter_user_has_cap( 'x', [ 'bazaar_sell' ] ), 'x' );

// wrap_ajax_actions()
$bazaar_original = function () {
  return 'ran';
};
$bazaar_object = new ArrayObject();
$bazaar_queue  = [
  [ 'hook' => 'admin_menu', 'callback' => [ $bazaar_object, 'count' ], 'priority' => 10, 'accepted_args' => 1 ],
  [ 'hook' => 'wp_ajax_get_orders', 'callback' => [ $bazaar_object, 'count' ], 'priority' => 7, 'accepted_args' => 3 ],
  [ 'hook' => 'init', 'callback' => $bazaar_original, 'priority' => 1, 'accepted_args' => 0 ],
  [ 'hook' => 'wp_ajax_nopriv_bazaar_pos_gateway_portal', 'callback' => $bazaar_original, 'priority' => 10, 'accepted_args' => 1 ],
  [ 'hook' => 'xophz_wp_ajax_lookalike', 'callback' => $bazaar_original, 'priority' => 10, 'accepted_args' => 1 ],
  'not-an-entry',
];
$bazaar_wrapped = $SG::wrap_ajax_actions( $bazaar_queue );
check( 'wrap: same number of entries', count( $bazaar_wrapped ), count( $bazaar_queue ) );
check( 'wrap: non-ajax hook untouched', $bazaar_wrapped[0], $bazaar_queue[0] );
check( 'wrap: init untouched', $bazaar_wrapped[2], $bazaar_queue[2] );
check( 'wrap: lookalike hook untouched', $bazaar_wrapped[4], $bazaar_queue[4] );
check( 'wrap: non-array entry untouched', $bazaar_wrapped[5], 'not-an-entry' );
check( 'wrap: wp_ajax_ callback is guarded', $bazaar_wrapped[1]['callback'] instanceof $GCB, true );
check( 'wrap: action name drops the prefix', $bazaar_wrapped[1]['callback']->action(), 'get_orders' );
check( 'wrap: original callback kept', $bazaar_wrapped[1]['callback']->original(), [ $bazaar_object, 'count' ] );
check( 'wrap: priority kept', $bazaar_wrapped[1]['priority'], 7 );
check( 'wrap: accepted_args kept', $bazaar_wrapped[1]['accepted_args'], 3 );
check( 'wrap: hook name kept', $bazaar_wrapped[1]['hook'], 'wp_ajax_get_orders' );
check( 'wrap: nopriv hooks are guarded too (unmapped, so refused)', $bazaar_wrapped[3]['callback'] instanceof $GCB && 'nopriv_bazaar_pos_gateway_portal' === $bazaar_wrapped[3]['callback']->action(), true );
$bazaar_twice = $SG::wrap_ajax_actions( $bazaar_wrapped );
check( 'wrap: a guarded callback is not wrapped again', $bazaar_twice[1]['callback'] === $bazaar_wrapped[1]['callback'], true );
check( 'wrap: empty queue', $SG::wrap_ajax_actions( [] ), [] );

// context_from_session()
$bazaar_ok = $SG::context_from_session( [ 'id' => 9, 'staff_user_id' => 12, 'operator_user_id' => 3, 'device_id' => 'dev-1' ], 3, 'dev-1' );
check( 'session: valid array gives a pin context', $bazaar_ok ? $bazaar_ok->to_array() : null, [ 'operator_user_id' => 3, 'acting_user_id' => 12, 'staff_session_id' => 9, 'device_id' => 'dev-1', 'via' => 'pin' ] );
$bazaar_obj = $SG::context_from_session( (object) [ 'staff_session_id' => 4, 'staff_user_id' => 5 ], 3, '' );
check( 'session: object with staff_session_id', $bazaar_obj ? [ $bazaar_obj->acting_user_id, $bazaar_obj->staff_session_id, $bazaar_obj->via ] : null, [ 5, 4, 'pin' ] );
check( 'session: other operator refused', $SG::context_from_session( [ 'id' => 9, 'staff_user_id' => 12, 'operator_user_id' => 4 ], 3, '' ), null );
check( 'session: other device refused', $SG::context_from_session( [ 'id' => 9, 'staff_user_id' => 12, 'device_id' => 'dev-2' ], 3, 'dev-1' ), null );
check( 'session: no staff user refused', $SG::context_from_session( [ 'id' => 9 ], 3, '' ), null );
check( 'session: no session id refused', $SG::context_from_session( [ 'staff_user_id' => 12 ], 3, '' ), null );
check( 'session: logged-out operator refused', $SG::context_from_session( [ 'id' => 9, 'staff_user_id' => 12 ], 0, '' ), null );
foreach ( [ 'null' => null, 'false' => false, 'true' => true, 'string' => 'ok', 'int' => 12 ] as $label => $value ) {
  check( "session: $label result refused", $SG::context_from_session( $value, 3, '' ), null );
}

// clean_device()
check( 'device: keeps a uuid', $SG::clean_device( '3f2a9c1e-7b4d-4e8a-9f00-1234567890ab' ), '3f2a9c1e-7b4d-4e8a-9f00-1234567890ab' );
check( 'device: strips other characters', $SG::clean_device( "a b<c>\"d'\n" ), 'abcd' );
check( 'device: at most 64 characters', strlen( $SG::clean_device( str_repeat( 'a', 100 ) ) ), 64 );
check( 'device: non-scalar is empty', $SG::clean_device( [ 'x' ] ), '' );

// Security_Context
$bazaar_op = $CTX::for_operator( 7, 'dev' );
check( 'context: operator acts for themselves', $bazaar_op->to_array(), [ 'operator_user_id' => 7, 'acting_user_id' => 7, 'staff_session_id' => 0, 'device_id' => 'dev', 'via' => 'login' ] );
check( 'context: operator context is not a staff session', $bazaar_op->is_staff_session(), false );
check( 'context: unknown via becomes login', ( new $CTX( 1, 1, 0, '', 'magic' ) )->via, 'login' );
check( 'context: negative ids become 0', ( new $CTX( -1, -2, -3 ) )->to_array()['acting_user_id'], 0 );
check( 'context: staff context', $CTX::for_staff( 1, 2, 3, 'd' )->is_staff_session(), true );

bazaar_test_standalone( __FILE__ );
