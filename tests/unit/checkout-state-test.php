<?php
/**
 * Checkout_State: the spec 11.2 transition table, exactly, and the restart rule.
 */
require_once dirname( __DIR__ ) . '/support/harness.php';
require_once dirname( dirname( __DIR__ ) ) . '/includes/checkout/class-xophz-compass-bazaar-checkout-state.php';

$S = 'Xophz_Compass_Bazaar_Checkout_State';

$spec = [
  'received'         => [ 'priced', 'failed' ],
  'priced'           => [ 'order_created', 'failed' ],
  'order_created'    => [ 'committed', 'cancelled' ],
  'committed'        => [ 'completed', 'cancelled' ],
  'completed'        => [],
  'failed'           => [ 'received' ],
  'cancelled'        => [],
  'awaiting_payment' => [ 'committed', 'cancelled' ],
];
check( 'state: the eight spec states, in order', $S::all(), array_keys( $spec ) );

// Every ordered pair of states (and two unknown names): allowed exactly when the spec lists it.
$names = array_merge( array_keys( $spec ), [ 'bogus', '' ] );
$wrong = [];
$thrown = 0;
foreach ( $names as $from ) {
  foreach ( $names as $to ) {
    $allowed = isset( $spec[ $from ] ) && in_array( $to, $spec[ $from ], true );
    if ( $S::can( $from, $to ) !== $allowed ) {
      $wrong[] = "$from->$to";
    }
    try {
      $S::assert( $from, $to );
      if ( ! $allowed ) {
        $wrong[] = "assert $from->$to did not throw";
      }
    } catch ( LogicException $e ) {
      $thrown++;
      if ( $allowed ) {
        $wrong[] = "assert $from->$to threw";
      }
    }
  }
}
check( 'state: can() and assert() match the table for every pair', $wrong, [] );
check( 'state: assert() throws for every pair outside the table (100 pairs, 11 edges)', $thrown, 100 - 11 );
check_throws( 'state: a self-transition throws', function () use ( $S ) { $S::assert( 'received', 'received' ); }, LogicException::class );
check_throws( 'state: completed is final', function () use ( $S ) { $S::assert( 'completed', 'cancelled' ); }, LogicException::class );
check_throws( 'state: cancelled never comes back', function () use ( $S ) { $S::assert( 'cancelled', 'received' ); }, LogicException::class );
check_throws( 'state: no skipping the order', function () use ( $S ) { $S::assert( 'priced', 'committed' ); }, LogicException::class );
check_throws( 'state: a post-order failure is cancelled, not failed', function () use ( $S ) { $S::assert( 'order_created', 'failed' ); }, LogicException::class );

check( 'terminal: completed and cancelled only', array_values( array_filter( $S::all(), [ $S, 'is_terminal' ] ) ), [ 'completed', 'cancelled' ] );
check( 'in flight: the sweeper states', $S::IN_FLIGHT, [ 'received', 'priced', 'order_created', 'committed' ] );

foreach ( [ 'received', 'priced', 'failed' ] as $from ) {
  check( "restart: from $from goes to received", $S::restart_from( $from ), 'received' );
}
foreach ( [ 'order_created', 'committed', 'completed', 'cancelled', 'awaiting_payment', 'bogus' ] as $from ) {
  check_throws( "restart: refused from $from", function () use ( $S, $from ) { $S::restart_from( $from ); }, LogicException::class );
}

bazaar_test_standalone( __FILE__ );
