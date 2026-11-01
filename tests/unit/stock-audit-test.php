<?php
/**
 * Stock_Audit's pure rules: the order stock target per status (one rule with
 * Stock_Order_Lifecycle::action_for) and the type of a negative balance.
 */
require_once dirname( __DIR__ ) . '/support/harness.php';
require_once dirname( __DIR__ ) . '/support/stock-memory-store.php';
require_once dirname( dirname( __DIR__ ) ) . '/includes/stock/class-xophz-compass-bazaar-stock-order-lifecycle.php';
require_once dirname( dirname( __DIR__ ) ) . '/includes/stock/class-xophz-compass-bazaar-stock-audit.php';

$AU        = 'Xophz_Compass_Bazaar_Stock_Audit';
$LC        = 'Xophz_Compass_Bazaar_Stock_Order_Lifecycle';
$committed = [ 'processing', 'completed', 'ready-pickup', 'on-hold' ];

// Order targets
check( 'target: a paid status is committed', $AU::target( 'completed', $committed, '' ), true );
check( 'target: cancelled is released', $AU::target( 'cancelled', $committed, '' ), false );
check( 'target: pending follows a committed checkout row', [ $AU::target( 'pending', $committed, 'committed' ), $AU::target( 'pending', $committed, 'order_created' ) ], [ true, false ] );
check( 'target: an unknown status has none', $AU::target( 'checkout-draft', $committed, '' ), null );
// Finding 16 of #6983: the lifecycle never syncs on refunded, so the target is the last sync's
check( 'refunded: the lifecycle does nothing on completed to refunded', $LC::action_for( 'completed', 'refunded', $committed ), '' );
check( 'refunded: nor on cancelled to refunded', $LC::action_for( 'cancelled', 'refunded', $committed ), '' );
check( 'refunded after a commit keeps the committed target', $AU::target( 'refunded', $committed, 'committed', true ), true );
check( 'refunded after a cancel keeps the released target', $AU::target( 'refunded', $committed, 'committed', false ), false );
check( 'refunded with no sync batch is released (nothing moved)', $AU::target( 'refunded', $committed, '', null ), false );

// Finding 17 of #6983: a negative balance is classified by the product as it is now
check( 'negative: managed, no backorders is a violation type', $AU::negative_type( [ 'manages_stock' => true, 'backorders_allowed' => false ] ), 'negative_balance' );
check( 'negative: backorders allowed is the backorder notice', $AU::negative_type( [ 'manages_stock' => true, 'backorders_allowed' => true ] ), 'negative_balance_backorder' );
check( 'negative: a deleted product is the untracked notice', $AU::negative_type( null ), 'negative_balance_untracked' );
check( 'negative: a product that stopped managing stock is the untracked notice', $AU::negative_type( [ 'manages_stock' => false, 'backorders_allowed' => false ] ), 'negative_balance_untracked' );

bazaar_test_standalone( __FILE__ );
