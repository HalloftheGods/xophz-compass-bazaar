<?php
/**
 * Stock_Planner, Stock_Keys and the stock value objects (pure, no store).
 */
require_once dirname( __DIR__ ) . '/support/harness.php';
require_once dirname( __DIR__ ) . '/support/stock-memory-store.php';

$PL = 'Xophz_Compass_Bazaar_Stock_Planner';
$K  = 'Xophz_Compass_Bazaar_Stock_Keys';
$ln = function ( int $pid, int $loc, float $qty, array $extra = [] ) {
  return new Xophz_Compass_Bazaar_Stock_Line( $pid, $loc, $qty, $extra );
};
$bal = function ( float $on_hand, bool $enabled = true ) {
  return [ 'on_hand' => $on_hand, 'is_enabled' => $enabled, 'last_movement_id' => 0 ];
};

// Running balances, deltas and projection
$plan = $PL::plan( [ $ln( 5, 0, -2 ), $ln( 5, 0, -1 ) ], [ '5:0' => $bal( 4 ) ], [ 5 => 4.0 ], [ 5 => [ 'backorders_allowed' => false ] ] );
check( 'plan: lines on one pair see each other', array_column( $plan['movements'], 'balance_after' ), [ 2.0, 1.0 ] );
check( 'plan: new balance', $plan['balances']['5:0']['on_hand'], 1.0 );
check( 'plan: projectable delta', $plan['deltas'], [ 5 => -3.0 ] );
check( 'plan: projected wc value', $plan['projected'], [ 5 => 1.0 ] );
check( 'plan: no shortfall, nothing oversold', [ $plan['shortfalls'], array_column( $plan['movements'], 'oversold' ) ], [ [], [ false, false ] ] );

$plan = $PL::plan( [ $ln( 5, 0, -4 ) ], [ '5:0' => $bal( 4 ) ], [ 5 => 4.0 ], [] );
check( 'plan: to exactly zero is fine', [ $plan['shortfalls'], $plan['balances']['5:0']['on_hand'] ], [ [], 0.0 ] );

// Shortfalls: every short line, with requested and available
$plan = $PL::plan(
  [ $ln( 5, 0, -3, [ 'product_id' => 5 ] ), $ln( 6, 2, -1, [ 'product_id' => 60, 'variation_id' => 6 ] ), $ln( 7, 0, -1 ) ],
  [ '5:0' => $bal( 2 ), '6:2' => $bal( 0 ), '7:0' => $bal( 5 ) ],
  [ 5 => 2.0, 6 => 0.0, 7 => 5.0 ],
  []
);
check( 'plan: every short line listed', array_column( $plan['shortfalls'], 'line_no' ), [ 1, 2 ] );
check(
  'plan: shortfall shape',
  $plan['shortfalls'][1],
  [ 'line_no' => 2, 'product_id' => 60, 'stock_product_id' => 6, 'variation_id' => 6, 'location_id' => 2, 'requested' => 1.0, 'available' => 0.0 ]
);
check( 'plan: shortfall available is the balance before the line', [ $plan['shortfalls'][0]['requested'], $plan['shortfalls'][0]['available'] ], [ 3.0, 2.0 ] );
check( 'plan: product_id falls back to the stock key', $PL::plan( [ $ln( 9, 0, -1 ) ], [], [], [] )['shortfalls'][0]['product_id'], 9 );
check( 'plan: available never below zero', $PL::plan( [ $ln( 9, 0, -1 ) ], [ '9:0' => $bal( -2 ) ], [], [] )['shortfalls'][0]['available'], 0.0 );
check( 'plan: second line short after the first used the stock', array_column( $PL::plan( [ $ln( 5, 0, -2 ), $ln( 5, 0, -2 ) ], [ '5:0' => $bal( 3 ) ], [], [] )['shortfalls'], 'line_no' ), [ 2 ] );

// Allowed oversell
$plan = $PL::plan( [ $ln( 5, 0, -3, [ 'allow_negative' => true ] ) ], [ '5:0' => $bal( 1 ) ], [ 5 => 1.0 ], [] );
check( 'plan: approved line may go negative', [ $plan['shortfalls'], $plan['movements'][0]['balance_after'], $plan['movements'][0]['oversold'] ], [ [], -2.0, true ] );
$plan = $PL::plan( [ $ln( 5, 0, -3 ) ], [ '5:0' => $bal( 1 ) ], [ 5 => 1.0 ], [ 5 => [ 'backorders_allowed' => true ] ] );
check( 'plan: backorders allow negative, marked oversold', [ $plan['shortfalls'], $plan['movements'][0]['oversold'] ], [ [], true ] );

// Increments never fail
$plan = $PL::plan( [ $ln( 5, 0, 1 ) ], [ '5:0' => $bal( -5 ) ], [ 5 => -5.0 ], [] );
check( 'plan: increment on a negative balance', [ $plan['shortfalls'], $plan['movements'][0]['balance_after'], $plan['movements'][0]['oversold'] ], [ [], -4.0, false ] );

// Projection: location 0 always, other locations while enabled
$plan = $PL::plan( [ $ln( 5, 3, 2 ), $ln( 5, 4, 5 ), $ln( 5, 0, 1 ) ], [ '5:3' => $bal( 0, false ), '5:4' => $bal( 0 ), '5:0' => $bal( 0, false ) ], [ 5 => 0.0 ], [] );
check( 'plan: disabled location is not projectable', $plan['deltas'][5], 6.0 );
check( 'plan: missing pair starts at 0 enabled', $PL::plan( [ $ln( 5, 8, 2 ) ], [], [ 5 => 1.0 ], [] )['balances']['5:8'], [ 'on_hand' => 2.0, 'is_enabled' => true, 'last_movement_id' => 0 ] );
check( 'plan: transfer between enabled locations nets zero', $PL::plan( [ $ln( 5, 1, -2 ), $ln( 5, 2, 2 ) ], [ '5:1' => $bal( 2 ), '5:2' => $bal( 0 ) ], [ 5 => 2.0 ], [] )['deltas'], [ 5 => 0.0 ] );
check( 'plan: projectable_sum', $PL::projectable_sum( 5, [ '5:0' => $bal( 1 ), '5:1' => $bal( 2 ), '5:2' => $bal( 4, false ), '6:0' => $bal( 9 ) ] ), 3.0 );

// Opening entries, lock order and helpers
check(
  'opening: residual on location 0, disabled kept, repeats summed',
  $PL::opening_entries( [ [ 2, 3, false ], [ 1, 4, true ], [ 1, 1, true ], [ -1, 9, true ] ], 10 ),
  [ 0 => [ 'qty' => 5.0, 'enabled' => true ], 1 => [ 'qty' => 5.0, 'enabled' => true ], 2 => [ 'qty' => 3.0, 'enabled' => false ] ]
);
check( 'opening: location 0 given by the port is kept and topped up', $PL::opening_entries( [ [ 0, 2, false ] ], 7 ), [ 0 => [ 'qty' => 7.0, 'enabled' => true ] ] );
check( 'opening: negative wc value lands on location 0', $PL::opening_entries( [ [ 1, 2, true ] ], -1 )[0]['qty'], -3.0 );
check( 'sorted pairs: unique, by pid then loc', $PL::sorted_pairs( [ [ 7, 4 ], [ 3, 0 ], [ 7, 0 ], [ 3, 0 ], [ 10, 0 ] ] ), [ [ 3, 0 ], [ 7, 0 ], [ 7, 4 ], [ 10, 0 ] ] );
check( 'pids: unique ascending', $PL::pids( [ $ln( 9, 0, 1 ), $ln( 3, 1, 1 ), $ln( 9, 2, 1 ) ] ), [ 3, 9 ] );
check( 'locations: one product, ascending', $PL::locations( 5, [ '5:2' => $bal( 1 ), '5:0' => $bal( 4 ), '6:0' => $bal( 8 ) ] ), [ 0 => 4.0, 2 => 1.0 ] );

// Observed batches record what happened
$plan = $PL::plan( [ $ln( 5, 0, -7 ) ], [ '5:0' => $bal( 1 ) ], [], [], true );
check( 'plan: observed mode has no shortfall and no oversold', [ $plan['shortfalls'], $plan['movements'][0]['oversold'], $plan['movements'][0]['balance_after'] ], [ [], false, -6.0 ] );
check( 'plan: precision is 4 decimals', $PL::plan( [ $ln( 5, 0, 0.1 ), $ln( 5, 0, 0.2 ) ], [], [], [] )['balances']['5:0']['on_hand'], 0.3 );
check( 'plan: differs at ledger precision', [ $PL::differs( 1.0, 1.00001 ), $PL::differs( 1.0, 1.0001 ) ], [ false, true ] );

// Keys (spec 8.10)
check( 'keys: opening', $K::opening( 12 ), 'opening:v1:12' );
check( 'keys: reconcile', [ $K::reconcile( 12, 0, 5.0 ), $K::reconcile( 12, 40, 2.5 ), $K::reconcile( 12, 40, -3.0 ), $K::reconcile( 12, 1, -0.0 ) ], [ 'reconcile:12:0:5', 'reconcile:12:40:2.5', 'reconcile:12:40:-3', 'reconcile:12:1:0' ] );
check( 'keys: order sync', [ $K::order_sync( 99, 0, true ), $K::order_sync( 99, 7, false ) ], [ 'order:99:sync:0:c', 'order:99:sync:7:r' ] );
check( 'keys: refund, po, adjust, transfer', [ $K::refund_restock( 3 ), $K::po_receive( 4 ), $K::adjust( 5, 'ab-12_C' ), $K::transfer( 'xyz' ) ], [ 'refund:3:restock', 'po:4:receive', 'adjust:5:ab-12_C', 'transfer:xyz' ] );
$sha = hash( 'sha256', 'file' );
check( 'keys: import', $K::import( $sha, 3 ), "import:$sha:3" );
$big     = PHP_INT_MAX;
$uuid64  = str_repeat( 'a', 64 );
$longest = [
  $K::opening( $big ),
  $K::reconcile( $big, $big, -999999999999999.9999 ),
  $K::order_sync( $big, $big, false ),
  $K::refund_restock( $big ),
  $K::po_receive( $big ),
  $K::adjust( $big, $uuid64 ),
  $K::import( $sha, $big ),
  $K::transfer( $uuid64 ),
];
check( 'keys: every worst-case key is under 191 characters', max( array_map( 'strlen', $longest ) ) < 191, true );
check_throws( 'keys: zero id refused', function () use ( $K ) { $K::opening( 0 ); }, InvalidArgumentException::class );
check_throws( 'keys: bad uuid refused', function () use ( $K ) { $K::adjust( 1, 'has space' ); }, InvalidArgumentException::class );
check_throws( 'keys: long uuid refused', function () use ( $K ) { $K::transfer( str_repeat( 'a', 65 ) ); }, InvalidArgumentException::class );
check_throws( 'keys: bad sha refused', function () use ( $K ) { $K::import( 'ABC', 1 ); }, InvalidArgumentException::class );
check_throws( 'keys: absurd wc value cannot overflow the column', function () use ( $K ) { $K::reconcile( 1, 1, 1e200 ); }, InvalidArgumentException::class );

// Value objects
$B = 'Xophz_Compass_Bazaar_Stock_Batch';
check( 'line: 4-decimal rounding and no -0', [ $ln( 1, 0, 1.23456 )->qty_delta(), Xophz_Compass_Bazaar_Stock_Line::qty( -0.00001 ) ], [ 1.2346, 0.0 ] );
check_throws( 'line: unknown field refused', function () use ( $ln ) { $ln( 1, 0, 1, [ 'qty' => 2 ] ); }, InvalidArgumentException::class );
check_throws( 'line: stock product id required', function () use ( $ln ) { $ln( 0, 0, 1 ); }, InvalidArgumentException::class );
check_throws( 'line: lower-case currency refused', function () use ( $ln ) { $ln( 1, 0, 1, [ 'cost_currency' => 'usd' ] ); }, InvalidArgumentException::class );
check( 'line: for_stock_key keeps given product ids', [ $ln( 6, 0, 1, [ 'product_id' => 2 ] )->for_stock_key( 4, 9, 6 )->product_id(), $ln( 6, 0, 1 )->for_stock_key( 4, 9, 6 )->to_array()['variation_id'] ], [ 2, 6 ] );
$h1 = ( new $B( 'k', 'adjust', [ $ln( 1, 0, 2 ) ], new Xophz_Compass_Bazaar_Stock_Meta( [ 'actor_user_id' => 1 ] ) ) )->request_hash();
$h2 = ( new $B( 'k2', 'adjust', [ $ln( 1, 0, 2.0 ) ], new Xophz_Compass_Bazaar_Stock_Meta( [ 'actor_user_id' => 2 ] ), [ 'note' => 'x' ] ) )->request_hash();
check( 'batch: key, meta and note are not hashed', $h1, $h2 );
check( 'batch: qty changes the hash', $h1 === ( new $B( 'k', 'adjust', [ $ln( 1, 0, 3 ) ] ) )->request_hash(), false );
check( 'batch: reason changes the hash', $h1 === ( new $B( 'k', 'receive', [ $ln( 1, 0, 2 ) ] ) )->request_hash(), false );
check( 'batch: source changes the hash', $h1 === ( new $B( 'k', 'adjust', [ $ln( 1, 0, 2 ) ], null, [ 'source_type' => 'po', 'source_id' => 1 ] ) )->request_hash(), false );
check( 'batch: hash basis replaces the lines', ( new $B( 'k', 'count', [ $ln( 1, 0, 2 ) ], null, [ 'hash_basis' => [ 'c' => 1 ] ] ) )->request_hash(), ( new $B( 'k', 'count', [ $ln( 1, 0, 5 ) ], null, [ 'hash_basis' => [ 'c' => 1 ] ] ) )->request_hash() );
check_throws( 'batch: unknown reason refused', function () use ( $B ) { new $B( 'k', 'theft', [] ); }, InvalidArgumentException::class );
check_throws( 'batch: key over 191 refused', function () use ( $B ) { new $B( str_repeat( 'k', 192 ), 'adjust', [] ); }, InvalidArgumentException::class );
check_throws( 'batch: non-line refused', function () use ( $B ) { new $B( 'k', 'adjust', [ [ 1, 0, 2 ] ] ); }, InvalidArgumentException::class );
$row = ( new $B( 'k', 'adjust', [], new Xophz_Compass_Bazaar_Stock_Meta( [ 'occurred_at' => '2026-11-01 10:00:00', 'register_id' => 4 ] ) ) )->to_row( 'h', '2026-11-01 10:05:00' );
check( 'batch: row keeps occurred_at from meta', [ $row['occurred_at'], $row['created_at'], $row['register_id'], $row['staff_session_id'] ], [ '2026-11-01 10:00:00', '2026-11-01 10:05:00', 4, null ] );
check( 'batch: row occurred_at defaults to now', ( new $B( 'k', 'adjust', [] ) )->to_row( 'h', '2026-11-01 10:05:00' )['occurred_at'], '2026-11-01 10:05:00' );
check_throws( 'meta: occurred_at must be UTC datetime', function () { new Xophz_Compass_Bazaar_Stock_Meta( [ 'occurred_at' => '2026-11-01T10:00:00Z' ] ); }, InvalidArgumentException::class );
check_throws( 'meta: unknown field refused', function () { new Xophz_Compass_Bazaar_Stock_Meta( [ 'user' => 1 ] ); }, InvalidArgumentException::class );

// Exceptions map to the REST error contract
$short = new Xophz_Compass_Bazaar_Stock_Insufficient_Exception( [ [ 'line_no' => 1 ], [ 'line_no' => 2 ] ] );
check( 'insufficient: code, status, lines', [ $short->error_code(), $short->status(), count( $short->lines() ), $short->data()['lines'][1]['line_no'] ], [ 'insufficient_stock', 409, 2, 2 ] );
$reused = Xophz_Compass_Bazaar_Stock_Conflict_Exception::key_reused();
$stale  = Xophz_Compass_Bazaar_Stock_Conflict_Exception::stock_changed( 3.5 );
check( 'conflict: codes and statuses', [ $reused->error_code(), $reused->status(), $stale->error_code(), $stale->status(), $stale->data()['current'], $stale->current(), $reused->current() ], [ 'idempotency_key_reused', 422, 'stock_changed', 409, 3.5, 3.5, null ] );

bazaar_test_standalone( __FILE__ );
