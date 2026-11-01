<?php
/**
 * Stock_Order_Math: sync deltas, reversals, resales and refund restocks (pure).
 */
require_once dirname( __DIR__ ) . '/support/harness.php';
require_once dirname( __DIR__ ) . '/support/stock-memory-store.php';

$OM = 'Xophz_Compass_Bazaar_Stock_Order_Math';

// Fold deltas into order sums the way Stock_Store::order_sums() reports them.
$fold = function ( array $sums, array $deltas ) {
  foreach ( $deltas as $d ) {
    $item = $d['order_item_id'];
    $loc  = $d['location_id'];
    $row  = $sums['items'][ $item ][ $loc ] ?? [ 'net' => 0.0, 'restocked' => 0.0, 'stock_product_id' => $d['stock_product_id'], 'product_id' => $d['product_id'], 'variation_id' => $d['variation_id'] ];
    $row['net'] = round( $row['net'] + $d['qty_delta'], 4 );
    if ( 'refund_restock' === $d['reason'] ) {
      $row['restocked'] = round( $row['restocked'] + $d['qty_delta'], 4 );
    }
    $sums['items'][ $item ][ $loc ] = $row;
    $sums['last_movement_id']++;
  }
  return $sums;
};
$pick = function ( array $deltas ) {
  return array_map( function ( $d ) { return [ $d['location_id'], $d['qty_delta'], $d['reason'] ]; }, $deltas );
};
$line = function ( int $qty, int $loc = 0, int $item = 11, int $pid = 5 ) {
  return [ 'order_item_id' => $item, 'stock_product_id' => $pid, 'product_id' => $pid, 'variation_id' => 0, 'location_id' => $loc, 'quantity' => $qty ];
};
$empty = [ 'items' => [], 'last_movement_id' => 0 ];

// Sell 3, refund-restock 1, cancel returns exactly 2, re-open sells 2, double cancel is a no-op
$sums = $empty;
$d    = $OM::deltas( [ $line( 3 ) ], $sums, true );
check( 'sell 3', $pick( $d ), [ [ 0, -3.0, 'sale' ] ] );
check( 'sell: batch reason', $OM::batch_reason( $d, true ), 'sale' );
$sums = $fold( $sums, $d );
check( 'committed again is a no-op', $OM::deltas( [ $line( 3 ) ], $sums, true ), [] );
$r = $OM::restock( [ $line( 3 ) ], $sums, [ 11 => 1 ] );
check( 'refund restock 1', $pick( $r ), [ [ 0, 1.0, 'refund_restock' ] ] );
$sums = $fold( $sums, $r );
check( 'after the restock the committed target is quantity - restocked', $OM::deltas( [ $line( 3 ) ], $sums, true ), [] );
$d = $OM::deltas( [ $line( 3 ) ], $sums, false );
check( 'cancel returns exactly 2', $pick( $d ), [ [ 0, 2.0, 'sale_reversal' ] ] );
check( 'cancel: batch reason', $OM::batch_reason( $d, false ), 'sale_reversal' );
$sums = $fold( $sums, $d );
check( 'double cancel is a no-op', $OM::deltas( [ $line( 3 ) ], $sums, false ), [] );
$d = $OM::deltas( [ $line( 3 ) ], $sums, true );
check( 're-open after cancel sells 2', $pick( $d ), [ [ 0, -2.0, 'resale' ] ] );
check( 're-open: batch reason', $OM::batch_reason( $d, true ), 'resale' );
$sums = $fold( $sums, $d );
check( 're-open twice is a no-op', $OM::deltas( [ $line( 3 ) ], $sums, true ), [] );

// Multi-location: reversals return stock to the locations it left; resales go to the current location
$sums = $fold( $empty, $OM::deltas( [ $line( 2, 5 ) ], $empty, true ) );
$d    = $OM::deltas( [ $line( 3, 7 ) ], $sums, true );
check( 'quantity raised after the sale location changed: resale at the current location', $pick( $d ), [ [ 7, -1.0, 'resale' ] ] );
$sums = $fold( $sums, $d );
$d    = $OM::deltas( [ $line( 3, 9 ) ], $sums, false );
check( 'multi-location reversal returns to each source location', $pick( $d ), [ [ 5, 2.0, 'sale_reversal' ], [ 7, 1.0, 'sale_reversal' ] ] );
$sums = $fold( $sums, $d );
check( 'multi-location: nothing left out', $OM::deltas( [ $line( 3, 9 ) ], $sums, false ), [] );
$d = $OM::deltas( [ $line( 3, 9 ) ], $sums, true );
check( 'multi-location: re-open sells at the current location', $pick( $d ), [ [ 9, -3.0, 'resale' ] ] );

// Quantity lowered while committed, an item removed, and a mixed batch
$sums = $fold( $empty, $OM::deltas( [ $line( 4 ), $line( 2, 0, 12, 6 ) ], $empty, true ) );
$d    = $OM::deltas( [ $line( 1 ) ], $sums, true );
check( 'lowered quantity reverses the excess; removed item reverses fully', $pick( $d ), [ [ 0, 3.0, 'sale_reversal' ], [ 0, 2.0, 'sale_reversal' ] ] );
check( 'removed item keeps its stock key', $d[1]['stock_product_id'], 6 );
$d = $OM::deltas( [ $line( 1 ), $line( 5, 0, 12, 6 ) ], $sums, true );
check( 'mixed reversal and resale', $pick( $d ), [ [ 0, 3.0, 'sale_reversal' ], [ 0, -3.0, 'resale' ] ] );
check( 'mixed: committed batch reason is resale', $OM::batch_reason( $d, true ), 'resale' );

// Restock caps
$sums = $fold( $empty, $OM::deltas( [ $line( 2 ) ], $empty, true ) );
check( 'restock capped at net outflow', $pick( $OM::restock( [ $line( 2 ) ], $sums, [ 11 => 5 ] ) ), [ [ 0, 2.0, 'refund_restock' ] ] );
check( 'restock of an unsold item does nothing', $OM::restock( [ $line( 2 ) ], $sums, [ 99 => 1 ] ), [] );
check( 'restock of zero does nothing', $OM::restock( [ $line( 2 ) ], $sums, [ 11 => 0 ] ), [] );
$restocked = $fold( $sums, $OM::restock( [ $line( 2 ) ], $sums, [ 11 => 2 ] ) );
check( 'fully restocked: committed target 0, cancel returns nothing', [ $OM::deltas( [ $line( 2 ) ], $restocked, true ), $OM::deltas( [ $line( 2 ) ], $restocked, false ) ], [ [], [] ] );
$multi = $fold( $fold( $empty, $OM::deltas( [ $line( 1, 3 ) ], $empty, true ) ), [ [ 'order_item_id' => 11, 'stock_product_id' => 5, 'product_id' => 5, 'variation_id' => 0, 'location_id' => 8, 'qty_delta' => -2.0, 'reason' => 'resale' ] ] );
check( 'restock across locations, ascending location', $pick( $OM::restock( [ $line( 3, 8 ) ], $multi, [ 11 => 2 ] ) ), [ [ 3, 1.0, 'refund_restock' ], [ 8, 1.0, 'refund_restock' ] ] );

// Line validation
check_throws( 'order line needs an item id', function () use ( $OM ) { $OM::normalize( [ 'stock_product_id' => 5, 'quantity' => 1 ] ); }, InvalidArgumentException::class );
check_throws( 'order line quantity cannot be negative', function () use ( $OM, $line ) { $OM::normalize( $line( -1 ) ); }, InvalidArgumentException::class );
check( 'order line keeps allow_negative', $OM::deltas( [ $line( 1 ) + [ 'allow_negative' => true ] ], $empty, true )[0]['allow_negative'], true );

bazaar_test_standalone( __FILE__ );
