<?php
/**
 * Refunds_Allocation (review fix of #6995): without tenders, captures whose
 * refund needs no slip reference go first and external terminal captures
 * last, newest first within each group; the refusal for an unavoidable
 * terminal leg names the payment, the amount and the tender to send.
 */
require_once dirname( __DIR__ ) . '/support/harness.php';
$plugin = dirname( dirname( __DIR__ ) );
require_once $plugin . '/includes/core/class-xophz-compass-bazaar-core-http-error.php';
require_once $plugin . '/includes/core/class-xophz-compass-bazaar-core-money.php';
require_once $plugin . '/includes/refunds/class-xophz-compass-bazaar-refunds-allocation.php';

$AL  = 'Xophz_Compass_Bazaar_Refunds_Allocation';
$cap = function ( int $id, string $kind, string $provider ) {
  return (object) [ 'id' => $id, 'kind' => $kind, 'provider' => $provider, 'payment_uuid' => 'p' . $id, 'method_id' => $kind, 'card_last4' => '' ];
};
$ids = function ( array $rows ) {
  return array_map( function ( $r ) { return $r->id; }, $rows );
};

// Newest first in: card seq 2 (id 9), cash seq 1 (id 8).
check( 'default order: a split sale refunds the cash leg before the card leg', $ids( $AL::default_order( [ $cap( 9, 'external_terminal', 'external_terminal' ), $cap( 8, 'cash', 'cash' ) ] ) ), [ 8, 9 ] );
check( 'default order: newest first kept within each group', $ids( $AL::default_order( [
  $cap( 7, 'external_terminal', 'external_terminal' ), $cap( 6, 'manual', 'manual' ), $cap( 5, 'external_terminal', 'external_terminal' ), $cap( 4, 'cash', 'cash' ), $cap( 3, 'digital', 'test_portal' ),
] ) ), [ 6, 4, 3, 7, 5 ] );
check( 'default order: only terminal captures stay as they are', $ids( $AL::default_order( [ $cap( 2, 'external_terminal', 'external_terminal' ), $cap( 1, 'external_terminal', 'external_terminal' ) ] ) ), [ 2, 1 ] );
check( 'default order: no captures', $AL::default_order( [] ), [] );

$card             = $cap( 9, 'external_terminal', 'external_terminal' );
$card->method_id  = 'bazaar_card_terminal';
$card->card_last4 = '1881';
$e                = $AL::reference_needed( $card, 1250 );
check( 'reference_needed: code and status', [ $e->error_code(), $e->status() ], [ 'reference_required', 422 ] );
check( 'reference_needed: the message names the amount and the card', $e->getMessage(), '12.50 of this refund goes back to the card terminal payment (card ending 1881). Enter the reference from the terminal refund slip.' );
check( 'reference_needed: data names the payment and the tender to send', array_intersect_key( $e->data(), array_flip( [ 'payment_uuid', 'method_id', 'kind', 'amount_minor', 'card_last4', 'tender' ] ) ), [
  'payment_uuid' => 'p9', 'method_id' => 'bazaar_card_terminal', 'kind' => 'external_terminal', 'amount_minor' => 1250, 'card_last4' => '1881',
  'tender'       => [ 'payment_uuid' => 'p9', 'amount_minor' => 1250, 'reference' => '' ],
] );

bazaar_test_standalone( __FILE__ );
