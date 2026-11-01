<?php
/**
 * Stock_Service against the in-memory store and WooCommerce port: replay,
 * conflicts, oversell, counts, transfers, drift, lazy init, lock order, the
 * order lifecycle and a 2000-step seeded property test of I1 and I2.
 */
require_once dirname( __DIR__ ) . '/support/harness.php';
require_once dirname( __DIR__ ) . '/support/stock-memory-wc-port.php';

$fresh = function () {
  $store = new Bazaar_Test_Stock_Memory_Store();
  $wc    = new Bazaar_Test_Stock_Memory_Wc_Port( $store );
  $svc   = new Xophz_Compass_Bazaar_Stock_Service( $store, $wc, function () { return '2026-11-01 12:00:00'; } );
  return [ $store, $wc, $svc ];
};
$meta   = new Xophz_Compass_Bazaar_Stock_Meta( [ 'actor_user_id' => 1 ] );
$ln     = function ( int $pid, int $loc, float $qty, array $extra = [] ) {
  return new Xophz_Compass_Bazaar_Stock_Line( $pid, $loc, $qty, $extra );
};
$code   = function ( callable $fn ) {
  try {
    $fn();
  } catch ( Xophz_Compass_Bazaar_Core_Http_Error $e ) {
    return $e->error_code();
  }
  return 'no error';
};
$clean  = function ( $store, $wc ) {
  return array_merge( $store->i1_violations(), $wc->i2_violations() );
};
$locks_ok = function ( $store ) {
  $phase = [];
  foreach ( $store->lock_log as $entry ) {
    list( $tx, $kind, $items ) = $entry;
    $sorted = $items;
    sort( $sorted );
    if ( $sorted !== $items ) {
      return "tx $tx: $kind not ascending";
    }
    if ( 'products' === $kind && ( $phase[ $tx ] ?? '' ) === 'balances' ) {
      return "tx $tx: products locked after balances";
    }
    $phase[ $tx ] = $kind;
  }
  return 'ok';
};

// Replay: the same key and payload returns the stored batch
list( $store, $wc, $svc ) = $fresh();
$wc->add_product( 5, 10 );
$first  = $svc->adjust( 'adjust:1:a', 5, 0, -2, 'adjust', $meta );
$second = $svc->adjust( 'adjust:1:a', 5, 0, -2, 'adjust', new Xophz_Compass_Bazaar_Stock_Meta( [ 'actor_user_id' => 2 ] ) );
check( 'replay: same batch id', $second->batch_id(), $first->batch_id() );
check( 'replay: flags', [ $first->replayed(), $second->replayed(), $second->noop() ], [ false, true, false ] );
check( 'replay: same lines', $second->lines(), $first->lines() );
check( 'replay: stock moved once', [ $wc->stock[5], $svc->available( 5, 0 ) ], [ 8.0, 8.0 ] );
check( 'replay: after_commit ran once, after commit', $wc->commits, [ [ [ 5 ], 'adjust' ] ] );
check( 'replay: opening then adjust batches', array_column( $store->batches, 'idem_key' ), [ 'opening:v1:5', 'adjust:1:a' ] );
check( 'replay: batch row attribution', [ $store->batches[2]['actor_user_id'], $store->batches[2]['occurred_at'], $store->batches[2]['reason'] ], [ 1, '2026-11-01 12:00:00', 'adjust' ] );

// Same key, other payload
check( 'conflict: same key with a different payload', $code( function () use ( $svc, $meta ) { $svc->adjust( 'adjust:1:a', 5, 0, -3, 'adjust', $meta ); } ), 'idempotency_key_reused' );
check( 'conflict: nothing changed', [ $wc->stock[5], count( $store->batches ) ], [ 8.0, 2 ] );

// Concurrent winner: the key is invisible until the insert hits the unique index
$store->hidden_keys['adjust:1:a'] = 2;
$late = $svc->adjust( 'adjust:1:a', 5, 0, -2, 'adjust', $meta );
check( 'duplicate key: re-read and replay', [ $late->replayed(), $late->batch_id(), $wc->stock[5], count( $store->batches ) ], [ true, $first->batch_id(), 8.0, 2 ] );
check( 'duplicate key: the losing attempt rolled back', $store->rollbacks, 1 );

// Oversell is refused with every short line and nothing persisted
list( $store, $wc, $svc ) = $fresh();
$wc->add_product( 5, 2 );
$wc->add_product( 6, 0 );
$wc->add_product( 7, 9 );
$batch = new Xophz_Compass_Bazaar_Stock_Batch( 'po:1:receive', 'adjust', [ $ln( 5, 0, -3 ), $ln( 7, 0, -1 ), $ln( 6, 0, -1 ) ], $meta );
$error = null;
try {
  $svc->apply( $batch );
} catch ( Xophz_Compass_Bazaar_Stock_Insufficient_Exception $e ) {
  $error = $e;
}
check( 'oversell: refused', $error instanceof Xophz_Compass_Bazaar_Stock_Insufficient_Exception, true );
check( 'oversell: every short line', $error ? array_map( function ( $l ) { return [ $l['product_id'], $l['location_id'], $l['requested'], $l['available'] ]; }, $error->lines() ) : null, [ [ 5, 0, 3.0, 2.0 ], [ 6, 0, 1.0, 0.0 ] ] );
check( 'oversell: nothing persisted', [ $store->batches, $store->movements, $store->rows, $wc->deltas, $wc->commits ], [ [], [], [], [], [] ] );
check( 'oversell: WooCommerce untouched', $wc->stock, [ 5 => 2.0, 6 => 0.0, 7 => 9.0 ] );

// Backorders allow a negative balance, marked oversold
$wc->add_product( 8, 0, [ 'backorders_allowed' => true ] );
$r = $svc->adjust( 'adjust:1:b', 8, 0, -1, 'sale', $meta );
check( 'backorders: negative balance with oversold 1', [ $r->lines()[0]['balance_after'], $r->lines()[0]['oversold'], $store->movements[ max( array_keys( $store->movements ) ) ]['oversold'], $wc->stock[8] ], [ -1.0, true, 1, -1.0 ] );
check( 'approval: refused without one', $code( function () use ( $svc, $meta ) { $svc->adjust( 'adjust:1:c', 6, 0, -2, 'sale', $meta ); } ), 'insufficient_stock' );
check( 'approval: an allow_negative line oversells', $svc->apply( new Xophz_Compass_Bazaar_Stock_Batch( 'adjust:1:d', 'sale', [ $ln( 6, 0, -2, [ 'allow_negative' => true ] ) ], $meta ) )->oversold(), true );
check( 'not managing stock: no movement', $svc->adjust( 'adjust:1:e', 404, 0, -1, 'adjust', $meta )->noop(), true );

// set_count: noop, stale expected, applied, replay
list( $store, $wc, $svc ) = $fresh();
$wc->add_product( 5, 4 );
$r = $svc->set_count( 'adjust:1:n', 5, 0, 4, 4, 'count', $meta );
check( 'set_count: delta 0 is a noop', [ $r->noop(), $r->batch_id(), array_column( $store->batches, 'reason' ) ], [ true, 0, [ 'opening' ] ] );
$stale = null;
try {
  $svc->set_count( 'adjust:1:s', 5, 0, 9, 3, 'count', $meta );
} catch ( Xophz_Compass_Bazaar_Stock_Conflict_Exception $e ) {
  $stale = $e;
}
check( 'set_count: stale expected is stock_changed with current', $stale ? [ $stale->error_code(), $stale->status(), $stale->data()['current'] ] : null, [ 'stock_changed', 409, 4.0 ] );
$r = $svc->set_count( 'adjust:1:t', 5, 0, 9, 4, 'count', $meta );
check( 'set_count: applies the difference', [ $r->lines()[0]['qty_delta'], $r->lines()[0]['balance_after'], $wc->stock[5] ], [ 5.0, 9.0, 9.0 ] );
$again = $svc->set_count( 'adjust:1:t', 5, 0, 9, 4, 'count', $meta );
check( 'set_count: a retry replays even though the delta would now be 0', [ $again->replayed(), $again->batch_id() ], [ true, $r->batch_id() ] );
check( 'set_count: same key, other count conflicts', $code( function () use ( $svc, $meta ) { $svc->set_count( 'adjust:1:t', 5, 0, 8, 4, 'count', $meta ); } ), 'idempotency_key_reused' );
check_throws( 'set_count: negative count refused', function () use ( $svc, $meta ) { $svc->set_count( 'adjust:1:u', 5, 0, -1, null, 'count', $meta ); }, InvalidArgumentException::class );

// Transfers
list( $store, $wc, $svc ) = $fresh();
$wc->add_product( 5, 5, [], [ 1 => [ 5, true ], 2 => [ 0, true ], 3 => [ 0, false ] ] );
$r = $svc->transfer( 'transfer:t1', 5, 1, 2, 2, $meta );
check( 'transfer: nets zero in WooCommerce', [ $wc->stock[5], end( $wc->deltas )[1] ], [ 5.0, 0.0 ] );
check( 'transfer: both locations move', [ $svc->available( 5, 1 ), $svc->available( 5, 2 ), $store->batches[ $r->batch_id() ]['reason'] ], [ 3.0, 2.0, 'transfer_out' ] );
check( 'transfer: multi-inventory mirrored', [ $wc->multi[5][1]['qty'], $wc->multi[5][2]['qty'] ], [ 3.0, 2.0 ] );
$svc->transfer( 'transfer:t2', 5, 2, 3, 1, $meta );
check( 'transfer: into a disabled location leaves the projection', [ $wc->stock[5], $svc->available( 5, 3 ) ], [ 4.0, 1.0 ] );
check( 'transfer: short source refused', $code( function () use ( $svc, $meta ) { $svc->transfer( 'transfer:t3', 5, 2, 1, 5, $meta ); } ), 'insufficient_stock' );
check_throws( 'transfer: same location refused', function () use ( $svc, $meta ) { $svc->transfer( 'transfer:t4', 5, 1, 1, 1, $meta ); }, InvalidArgumentException::class );
check( 'transfer: invariants', $clean( $store, $wc ), [] );

// set_enabled moves the projection, not the balance
$r = $svc->set_enabled( 5, 3, true, $meta );
check( 'set_enabled: enabling adds the balance to _stock', [ $r->noop(), $r->batch_id(), $wc->stock[5], $svc->balances( 5 )[3]['is_enabled'] ], [ false, 0, 5.0, true ] );
check( 'set_enabled: same state is a noop', $svc->set_enabled( 5, 3, true, $meta )->noop(), true );
$svc->set_enabled( 5, 1, false, $meta );
check( 'set_enabled: disabling removes it', [ $wc->stock[5], $svc->available( 5, 1 ) ], [ 2.0, 3.0 ] );
check_throws( 'set_enabled: location 0 cannot be disabled', function () use ( $svc, $meta ) { $svc->set_enabled( 5, 0, false, $meta ); }, InvalidArgumentException::class );
check( 'set_enabled: invariants', $clean( $store, $wc ), [] );

// External changes are recorded as reconcile before the batch
list( $store, $wc, $svc ) = $fresh();
$wc->add_product( 5, 10 );
$svc->adjust( 'adjust:1:x1', 5, 0, -2, 'adjust', $meta );
$wc->external_write( 5, 5 );
$r = $svc->adjust( 'adjust:1:x2', 5, 0, -1, 'adjust', $meta );
check( 'drift: reconcile recorded first', array_map( function ( $l ) { return [ $l['qty_delta'], $l['balance_after'], $l['reason'], $l['location_id'] ]; }, $r->reconciled() ), [ [ -3.0, 5.0, 'reconcile', 0 ] ] );
check( 'drift: reconcile batch precedes the adjust', $r->reconciled()[0]['batch_id'] < $r->batch_id(), true );
check( 'drift: reconcile key', $store->batches[ $r->reconciled()[0]['batch_id'] ]['idem_key'], 'reconcile:5:2:5' );
check( 'drift: then the adjust', [ $r->lines()[0]['balance_after'], $wc->stock[5] ], [ 4.0, 4.0 ] );
$wc->external_write( 5, 7 );
$r = $svc->reconcile_from_wc( 5, 'external', $meta, [ 'type' => 'order', 'id' => 42 ] );
check( 'observer: records the observed difference', [ $r->lines()[0]['qty_delta'], $store->batches[ $r->batch_id() ]['reason'], $store->batches[ $r->batch_id() ]['source_id'], $wc->stock[5] ], [ 3.0, 'external', 42, 7.0 ] );
check( 'observer: a duplicate hook records nothing', $svc->reconcile_from_wc( 5, 'external', $meta ), null );
$wc->on_lock = function ( $pids, $port ) {
  $port->external_write( 5, 1 );
  $port->on_lock = null;
};
$r = $svc->adjust( 'adjust:1:x3', 5, 0, -1, 'adjust', $meta );
check( 'drift: a write landing just before the lock is reconciled', [ $r->reconciled()[0]['qty_delta'], $r->lines()[0]['balance_after'], $wc->stock[5] ], [ -6.0, 0.0, 0.0 ] );
check( 'drift: invariants', $clean( $store, $wc ), [] );

// Lazy init
list( $store, $wc, $svc ) = $fresh();
$wc->add_product( 20, 7 );
$wc->add_product( 21, 10, [], [ 1 => [ 4, true ], 2 => [ 3, false ] ] );
$wc->add_product( 22, 0 );
check( 'init: from the WooCommerce value', [ $svc->balances( 20 ), $store->batches[1]['idem_key'] ], [ [ 0 => [ 'on_hand' => 7.0, 'is_enabled' => true ] ], 'opening:v1:20' ] );
check( 'init: from multi-inventory, disabled kept, residual on location 0', $svc->balances( 21 ), [ 0 => [ 'on_hand' => 6.0, 'is_enabled' => true ], 1 => [ 'on_hand' => 4.0, 'is_enabled' => true ], 2 => [ 'on_hand' => 3.0, 'is_enabled' => false ] ] );
check( 'init: opening writes no WooCommerce stock', [ $wc->stock[21], $wc->deltas, $wc->commits ], [ 10.0, [], [] ] );
$svc->ensure_initialized( 21 );
$svc->ensure_initialized( 22 );
check( 'init: idempotent; a zero product writes no batch', [ count( $store->batches ), $store->has_balances( 22 ) ], [ 2, true ] );
check( 'init: invariants', $clean( $store, $wc ), [] );

// Stock key: a variation whose parent manages stock
$wc->add_product( 30, 6 );
$wc->products[31] = [ 'stock_product_id' => 30, 'product_id' => 30, 'variation_id' => 31, 'manages_stock' => true, 'backorders_allowed' => false, 'location_tracked' => false, 'name' => '' ];
$r = $svc->adjust( 'adjust:1:v', 31, 0, -1, 'adjust', $meta );
check( 'stock key: variation moves the parent ledger', [ $r->lines()[0]['stock_product_id'], $r->lines()[0]['product_id'], $r->lines()[0]['variation_id'], $wc->stock[30], $svc->available( 31, 0 ) ], [ 30, 30, 31, 5.0, 5.0 ] );

// Lock order
list( $store, $wc, $svc ) = $fresh();
foreach ( [ 9, 3, 7 ] as $pid ) {
  $wc->add_product( $pid, 5, [], 7 === $pid ? [ 4 => [ 1, true ] ] : [] );
}
$svc->apply( new Xophz_Compass_Bazaar_Stock_Batch( 'po:2:receive', 'receive', [ $ln( 9, 0, 1 ), $ln( 3, 0, 1 ), $ln( 7, 4, 1 ), $ln( 7, 0, 1 ) ], $meta ) );
check( 'locks: products ascending, then balances ascending', array_slice( $store->lock_log, 0, 2 ), [ [ 1, 'products', [ 3, 7, 9 ] ], [ 1, 'balances', [ [ 3, 0 ], [ 7, 0 ], [ 7, 4 ], [ 9, 0 ] ] ] ] );
check( 'locks: one transaction, one call each', count( $store->lock_log ), 2 );

// Ledger-owned order lifecycle
list( $store, $wc, $svc ) = $fresh();
$wc->add_product( 5, 10 );
$order = [ [ 'order_item_id' => 11, 'product_id' => 5, 'quantity' => 3, 'location_id' => 0 ] ];
$r     = $svc->sync_order( 100, true, $order, $meta );
check( 'order: sell 3', [ $r->lines()[0]['qty_delta'], $r->lines()[0]['order_id'], $r->lines()[0]['order_item_id'], $store->batches[ $r->batch_id() ]['idem_key'], $wc->stock[5] ], [ -3.0, 100, 11, 'order:100:sync:0:c', 7.0 ] );
check( 'order: repeated commit is a noop', $svc->sync_order( 100, true, $order, $meta )->noop(), true );
$refund = $svc->restock_refund( 500, 100, [ 11 => 1 ], $order, $meta );
check( 'order: refund restock 1', [ $refund->lines()[0]['qty_delta'], $store->batches[ $refund->batch_id() ]['idem_key'], $wc->stock[5] ], [ 1.0, 'refund:500:restock', 8.0 ] );
check( 'order: refund replay', $svc->restock_refund( 500, 100, [ 11 => 1 ], $order, $meta )->replayed(), true );
check( 'order: refund key reuse conflicts', $code( function () use ( $svc, $order, $meta ) { $svc->restock_refund( 500, 100, [ 11 => 2 ], $order, $meta ); } ), 'idempotency_key_reused' );
$r = $svc->sync_order( 100, false, $order, $meta );
check( 'order: cancel returns exactly 2', [ $r->lines()[0]['qty_delta'], $store->batches[ $r->batch_id() ]['reason'], $wc->stock[5] ], [ 2.0, 'sale_reversal', 10.0 ] );
check( 'order: double cancel is a noop', [ $svc->sync_order( 100, false, $order, $meta )->noop(), $wc->stock[5] ], [ true, 10.0 ] );
$r = $svc->sync_order( 100, true, $order, $meta );
check( 'order: re-open sells 2', [ $r->lines()[0]['qty_delta'], $store->batches[ $r->batch_id() ]['reason'], $wc->stock[5] ], [ -2.0, 'resale', 8.0 ] );
check( 'order: oversell refused', $code( function () use ( $svc, $meta ) { $svc->sync_order( 101, true, [ [ 'order_item_id' => 12, 'product_id' => 5, 'quantity' => 9, 'location_id' => 0 ] ], $meta ); } ), 'insufficient_stock' );
check( 'order: invariants', $clean( $store, $wc ), [] );
check( 'after_commit: once per committed batch, never for refused ones', array_column( $wc->commits, 1 ), [ 'sale', 'refund_restock', 'sale_reversal', 'resale' ] );

// Property test: 2000 seeded random operations keep I1 (balance = sum of
// movements, running balance_after) and I2 (_stock = projectable sum) after
// every step, refused operations included.
list( $store, $wc, $svc ) = $fresh();
mt_srand( 6941 );
$wc->add_product( 1, 20 );
$wc->add_product( 2, 12, [], [ 1 => [ 5, true ], 2 => [ 3, false ] ] );
$wc->add_product( 3, 0, [ 'backorders_allowed' => true ] );
$wc->add_product( 4, 6 );
$wc->products[41] = [ 'stock_product_id' => 4, 'product_id' => 4, 'variation_id' => 41, 'manages_stock' => true, 'backorders_allowed' => false, 'location_tracked' => false, 'name' => '' ];
$locs_of = function ( int $pid ) { return 2 === $pid ? [ 0, 1, 2 ] : [ 0 ]; };
$any     = function ( array $list ) { return $list[ mt_rand( 0, count( $list ) - 1 ) ]; };
$qty     = function () { return mt_rand( 1, 6 ) / 2; };
$orders  = [];
for ( $o = 1; $o <= 5; $o++ ) {
  $orders[ $o ] = [ [ 'order_item_id' => $o * 10 + 1, 'product_id' => 1, 'quantity' => mt_rand( 1, 3 ) ], [ 'order_item_id' => $o * 10 + 2, 'product_id' => 2, 'quantity' => mt_rand( 1, 2 ) ], [ 'order_item_id' => $o * 10 + 3, 'variation_id' => 41, 'product_id' => 4, 'quantity' => 1 ] ];
}
$order_lines = function ( int $o ) use ( $orders, $any ) {
  return array_map( function ( $l ) use ( $any ) { return $l + [ 'location_id' => 2 === $l['product_id'] ? $any( [ 0, 1, 2 ] ) : 0 ]; }, $orders[ $o ] );
};
$violations = [];
$done       = [];
$adjusts    = [];
for ( $step = 1; $step <= 2000; $step++ ) {
  $op  = mt_rand( 0, 9 );
  $pid = $any( [ 1, 2, 3, 4, 41 ] );
  $loc = $any( $locs_of( 4 === $pid || 41 === $pid ? 4 : $pid ) );
  try {
    switch ( $op ) {
      case 0:
        $args      = [ "adjust:1:p$step", $pid, $loc, $any( [ -1, 1 ] ) * $qty(), $any( [ 'adjust', 'receive', 'sale' ] ) ];
        $adjusts[] = $args;
        $svc->adjust( $args[0], $args[1], $args[2], $args[3], $args[4], $meta );
        break;
      case 1:
        $current = $svc->available( $pid, $loc );
        $svc->set_count( "adjust:1:c$step", $pid, $loc, (float) mt_rand( 0, 10 ), $any( [ null, $current, $current + 1 ] ), 'count', $meta );
        break;
      case 2:
        $from = $any( [ 0, 1, 2 ] );
        $svc->transfer( "transfer:p$step", 2, $from, ( $from + mt_rand( 1, 2 ) ) % 3, $qty(), $meta );
        break;
      case 3:
      case 4:
        $o = mt_rand( 1, 5 );
        $svc->sync_order( $o, 3 === $op, $order_lines( $o ), $meta );
        break;
      case 5:
        $o = mt_rand( 1, 5 );
        $svc->restock_refund( $step, $o, [ $o * 10 + mt_rand( 1, 3 ) => mt_rand( 1, 2 ) ], $order_lines( $o ), $meta );
        break;
      case 6:
        $wc->external_write( 4 === $pid || 41 === $pid ? 4 : $pid, (float) mt_rand( -2, 15 ) );
        $svc->reconcile_from_wc( $pid, 'external', $meta );
        break;
      case 7:
        $svc->set_enabled( 2, mt_rand( 1, 2 ), (bool) mt_rand( 0, 1 ), $meta );
        break;
      case 8:
        if ( $adjusts ) {
          $args = $any( $adjusts );
          $svc->adjust( $args[0], $args[1], $args[2], $args[3], $args[4], $meta );
        }
        break;
      default:
        $lines = [];
        for ( $i = mt_rand( 1, 4 ); $i > 0; $i-- ) {
          $p       = $any( [ 1, 2, 3, 4 ] );
          $lines[] = $ln( $p, $any( $locs_of( $p ) ), $any( [ -1, 1 ] ) * $qty(), [ 'allow_negative' => 0 === mt_rand( 0, 4 ) ] );
        }
        $svc->apply( new Xophz_Compass_Bazaar_Stock_Batch( "import:" . hash( 'sha256', 'p' ) . ":$step", 'import', $lines, $meta ) );
    }
    $done[ $op ] = ( $done[ $op ] ?? 0 ) + 1;
  } catch ( Xophz_Compass_Bazaar_Stock_Insufficient_Exception $e ) {
    $done['refused'] = ( $done['refused'] ?? 0 ) + 1;
  } catch ( Xophz_Compass_Bazaar_Stock_Conflict_Exception $e ) {
    $done['conflict'] = ( $done['conflict'] ?? 0 ) + 1;
  } catch ( Throwable $e ) {
    $violations[] = "step $step op $op: " . get_class( $e ) . ': ' . $e->getMessage();
  }
  foreach ( $clean( $store, $wc ) as $bad ) {
    $violations[] = "step $step op $op: $bad";
  }
  for ( $o = 1; $o <= 5; $o++ ) {
    foreach ( $store->order_sums( $o )['items'] as $item => $by_loc ) {
      $out = -array_sum( array_column( $by_loc, 'net' ) );
      if ( $out < -0.00001 || $out > 3.00001 ) {
        $violations[] = "step $step: order $o item $item net outflow $out out of range";
      }
    }
  }
  if ( count( $violations ) > 5 ) {
    break;
  }
}
check( 'property: 2000 steps ran', $step, 2001 );
check( 'property: I1, I2 and the order outflow range hold at every step', $violations, [] );
check( 'property: every operation kind succeeded at least once', count( array_intersect_key( $done, array_flip( range( 0, 9 ) ) ) ), 10 );
check( 'property: refusals and conflicts were exercised', [ ( $done['refused'] ?? 0 ) > 0, ( $done['conflict'] ?? 0 ) > 0 ], [ true, true ] );
check( 'property: locks always ascending, products before balances', $locks_ok( $store ), 'ok' );
check( 'property: movements recorded', count( $store->movements ) > 1000, true );

bazaar_test_standalone( __FILE__ );
