<?php
/**
 * Fixture steps for payments-repository.sh, run inside WordPress with
 * `wp eval-file`. The step comes from the MODE env var; each prints one line.
 *
 * Everything it creates uses order_id TEST_ORDER and refund created_by
 * TEST_USER, and `cleanup` deletes exactly those rows.
 *
 * Env: MODE, PAYMENT_ID (alloc, release), AMOUNT (alloc, release), KEY and HASH (claim).
 */
const BZ_TEST_ORDER = 990000061;
const BZ_TEST_USER  = 990000062;

$P    = 'Xophz_Compass_Bazaar';
$mode = (string) getenv( 'MODE' );
$Pay  = "{$P}_Payments_Repository";
$Ref  = "{$P}_Refunds_Repository";
$Db   = "{$P}_Core_Db";

$capture = function ( array $over = [] ) {
  return array_merge( [
    'order_id' => BZ_TEST_ORDER, 'provider' => 'cash', 'method_id' => 'cash', 'kind' => 'cash',
    'status' => 'captured', 'amount_minor' => 1000, 'currency' => 'USD', 'cashier_user_id' => BZ_TEST_USER,
  ], $over );
};

switch ( $mode ) {
  case 'cleanup':
    $Db::exec( 'DELETE FROM ' . $Db::table( 'payments' ) . ' WHERE order_id = %d', [ BZ_TEST_ORDER ] );
    $Db::exec( 'DELETE FROM ' . $Db::table( 'refunds' ) . ' WHERE created_by = %d', [ BZ_TEST_USER ] );
    echo "cleaned\n";
    break;

  case 'new_capture':
    echo $Pay::insert_capture( $capture( [ 'amount_minor' => (int) ( getenv( 'AMOUNT' ) ?: 1000 ) ] ) ) . "\n";
    break;

  case 'alloc':
    echo $Pay::allocate_refund( (int) getenv( 'PAYMENT_ID' ), (int) getenv( 'AMOUNT' ) ) ? "won\n" : "lost\n";
    break;

  case 'refunded':
    echo (int) $Pay::find( (int) getenv( 'PAYMENT_ID' ) )->refunded_minor . "\n";
    break;

  case 'basics':
    $out = [];
    $id  = $Pay::insert_capture( $capture( [ 'payment_uuid' => wp_generate_uuid4(), 'amount_minor' => 500, 'staff_session_id' => 990000063, 'tendered_minor' => 1000, 'change_minor' => 500, 'provider_payload' => [ 'change_minor' => 500 ] ] ) );
    $row = $Pay::find( $id );
    $out[] = 'insert=' . ( $row && 'capture' === $row->direction && 500 === (int) $row->amount_minor && 0 === (int) $row->refunded_minor ? 'ok' : 'bad' );
    $out[] = 'by_uuid=' . ( $Pay::find_by_uuid( $row->payment_uuid )->id == $id ? 'ok' : 'bad' );
    try { $Pay::insert_capture( $capture( [ 'payment_uuid' => $row->payment_uuid ] ) ); $out[] = 'dup_uuid=none'; } catch ( Xophz_Compass_Bazaar_Core_Duplicate_Key_Exception $e ) { $out[] = 'dup_uuid=typed'; }
    try { $Pay::insert_capture( [ 'provider' => 'cash' ] ); $out[] = 'invalid=none'; } catch ( InvalidArgumentException $e ) { $out[] = 'invalid=refused'; }
    try { $Pay::insert_refund_row( $capture() ); $out[] = 'refund_no_parent=none'; } catch ( InvalidArgumentException $e ) { $out[] = 'refund_no_parent=refused'; }
    $out[] = 'zero=' . var_export( $Pay::allocate_refund( $id, 0 ), true );
    $out[] = 'over=' . var_export( $Pay::allocate_refund( $id, 501 ), true );
    $out[] = 'part=' . var_export( $Pay::allocate_refund( $id, 200 ), true );
    $out[] = 'rest=' . var_export( $Pay::allocate_refund( $id, 300 ), true );
    $out[] = 'full=' . var_export( $Pay::allocate_refund( $id, 1 ), true );
    $out[] = 'release_too_much=' . var_export( $Pay::release_refund( $id, 501 ), true );
    $out[] = 'release=' . var_export( $Pay::release_refund( $id, 300 ), true );
    $out[] = 'after_release=' . (int) $Pay::find( $id )->refunded_minor;
    $Pay::update_status( $id, 'void_required' );
    $out[] = 'not_captured=' . var_export( $Pay::allocate_refund( $id, 1 ), true );
    echo implode( ',', $out ) . "\n";
    break;

  case 'rollback':
    $id = $Pay::insert_capture( $capture( [ 'amount_minor' => 700 ] ) );
    try {
      $Db::tx( function () use ( $Pay, $id ) {
        $Pay::allocate_refund( $id, 700 );
        throw new RuntimeException( 'boom' );
      } );
    } catch ( RuntimeException $e ) {
      // expected
    }
    echo 'after_rollback=' . (int) $Pay::find( $id )->refunded_minor . ',then=' . var_export( $Pay::allocate_refund( $id, 700 ), true ) . "\n";
    break;

  case 'claim':
    $key   = (string) getenv( 'KEY' );
    $first = $Ref::claim( BZ_TEST_USER, $key, [ 'order_id' => BZ_TEST_ORDER, 'request_hash' => str_repeat( 'a', 64 ), 'amount_minor' => 250, 'reason' => 'test' ] );
    $again = $Ref::claim( BZ_TEST_USER, $key, [ 'order_id' => BZ_TEST_ORDER, 'request_hash' => str_repeat( 'a', 64 ) ] );
    $diff  = $Ref::claim( BZ_TEST_USER, $key, [ 'order_id' => BZ_TEST_ORDER, 'request_hash' => str_repeat( 'b', 64 ) ] );
    $other = $Ref::claim( BZ_TEST_USER + 1, $key, [ 'order_id' => BZ_TEST_ORDER, 'request_hash' => str_repeat( 'a', 64 ) ] );
    $id    = (int) $first['row']->id;
    $out   = [];
    $out[] = 'first=' . ( $first['replayed'] || $first['conflict'] ? 'bad' : $first['row']->state );
    $out[] = 'replay=' . ( $again['replayed'] && ! $again['conflict'] && (int) $again['row']->id === $id ? 'same-row' : 'bad' );
    $out[] = 'conflict=' . ( $diff['replayed'] && $diff['conflict'] ? 'flagged' : 'bad' );
    $out[] = 'other_user=' . ( ! $other['replayed'] && (int) $other['row']->id !== $id ? 'own-row' : 'bad' );
    $out[] = 'amounts=' . var_export( $Ref::set_amounts( $id, 300, 20 ), true );
    $out[] = 'complete=' . var_export( $Ref::complete( $id, 990000064, [ 'refund_id' => 990000064 ] ), true );
    $out[] = 'complete_twice=' . var_export( $Ref::complete( $id, 990000065, [] ), true );
    $out[] = 'fail_after_complete=' . var_export( $Ref::fail( $id, 'late' ), true );
    $out[] = 'amounts_after=' . var_export( $Ref::set_amounts( $id, 1, 1 ), true );
    $done  = $Ref::find( $id );
    $out[] = 'state=' . $done->state . ',wc=' . (int) $done->wc_refund_id . ',amount=' . (int) $done->amount_minor . ',tax=' . (int) $done->tax_minor;
    $out[] = 'response=' . ( $Ref::stored_response( $done )['refund_id'] ?? 'none' );
    $out[] = 'for_order=' . count( array_filter( $Ref::for_order( BZ_TEST_ORDER ), function ( $r ) { return (int) $r->created_by === BZ_TEST_USER; } ) );
    $f = $Ref::claim( BZ_TEST_USER, $key . '-f', [ 'order_id' => BZ_TEST_ORDER, 'request_hash' => str_repeat( 'c', 64 ) ] );
    $out[] = 'fail=' . var_export( $Ref::fail( (int) $f['row']->id, 'order_busy', [ 'code' => 'order_busy' ] ), true ) . ',' . $Ref::find( (int) $f['row']->id )->state;
    try { $Ref::claim( 0, $key, [ 'order_id' => 1, 'request_hash' => 'x' ] ); $out[] = 'invalid=none'; } catch ( InvalidArgumentException $e ) { $out[] = 'invalid=refused'; }
    $Db::exec( 'DELETE FROM ' . $Db::table( 'refunds' ) . ' WHERE created_by = %d', [ BZ_TEST_USER + 1 ] );
    echo implode( ',', $out ) . "\n";
    break;

  case 'shift':
    $session = 990000070;
    $mk      = function ( array $over ) use ( $Pay, $capture, $session ) {
      return $Pay::insert_capture( $capture( array_merge( [ 'staff_session_id' => $session ], $over ) ) );
    };
    $mk( [ 'amount_minor' => 1000, 'change_minor' => 0 ] );
    $mk( [ 'amount_minor' => 400, 'change_minor' => 100 ] );
    $card = $mk( [ 'provider' => 'external_terminal', 'method_id' => 'bazaar_card_terminal', 'kind' => 'card_external', 'amount_minor' => 2500 ] );
    $mk( [ 'method_id' => 'bazaar_card_terminal', 'kind' => 'card_external', 'amount_minor' => 300, 'status' => 'pending' ] );
    $mk( [ 'method_id' => 'bazaar_card_terminal', 'kind' => 'card_external', 'amount_minor' => 900, 'status' => 'void_required', 'reference' => 'VOID-1' ] );
    $mk( [ 'amount_minor' => 50, 'staff_session_id' => $session + 1 ] );
    $Pay::insert_refund_row( $capture( [ 'staff_session_id' => $session, 'refund_id' => 990000071, 'parent_payment_id' => $card, 'direction' => 'refund', 'status' => 'refunded', 'method_id' => 'bazaar_card_terminal', 'kind' => 'card_external', 'amount_minor' => 600 ] ) );
    $s = $Ref ? Xophz_Compass_Bazaar_Payments_Shift_Summary::summarize( [ 'staff_session_id' => $session ] ) : [];
    $t = $s['totals'];
    $c = $s['methods']['cash'];
    $k = $s['methods']['bazaar_card_terminal'];
    echo 'totals=' . $t['captured_minor'] . '/' . $t['pending_minor'] . '/' . $t['refunded_minor'] . '/' . $t['net_minor'] . '/' . $t['count']
      . ',cash=' . $c['captured_minor'] . '/' . $c['change_minor'] . '/' . $c['count']
      . ',card=' . $k['captured_minor'] . '/' . $k['refunded_minor'] . '/' . $k['net_minor']
      . ',void=' . count( $s['void_required'] ) . ':' . ( $s['void_required'][0]['reference'] ?? '' ) . ':' . ( $s['void_required'][0]['amount_minor'] ?? '' );
    $w = Xophz_Compass_Bazaar_Payments_Shift_Summary::summarize( [ 'from' => gmdate( 'Y-m-d H:i:s', time() - 3600 ), 'to' => gmdate( 'Y-m-d H:i:s', time() + 3600 ), 'cashier_user_id' => BZ_TEST_USER ] );
    echo ',window_has_session_rows=' . ( $w['totals']['captured_minor'] >= 3950 ? 'yes' : 'no' );
    $none = Xophz_Compass_Bazaar_Payments_Shift_Summary::summarize( [ 'staff_session_id' => 990000079 ] );
    echo ',empty=' . $none['totals']['count'] . '/' . count( $none['void_required'] );
    try { Xophz_Compass_Bazaar_Payments_Shift_Summary::summarize( [] ); echo ',noscope=none'; } catch ( InvalidArgumentException $e ) { echo ',noscope=refused'; }
    echo "\n";
    break;

  default:
    echo "unknown MODE\n";
    exit( 1 );
}
