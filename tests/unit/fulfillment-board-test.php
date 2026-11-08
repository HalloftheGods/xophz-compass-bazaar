<?php
/**
 * Fulfillment board: which tab an order lands on, and when "emailed" is true.
 *
 * @package Xophz_Compass_Bazaar
 */

require_once dirname( __DIR__ ) . '/support/harness.php';
require_once dirname( __DIR__, 2 ) . '/admin/class-xophz-compass-bazaar-admin-fulfillment.php';

$F = 'Xophz_Compass_Bazaar_Admin_Fulfillment';

check( 'tab: pickup method', $F::classify( [ [ 'id' => 'local_pickup', 'title' => 'Local pickup' ] ] ), 'pickup' );
check( 'tab: pickup wins over delivery', $F::classify( [ [ 'id' => 'local_delivery', 'title' => 'x' ], [ 'id' => 'local_pickup', 'title' => 'y' ] ] ), 'pickup' );
check( 'tab: local_delivery id', $F::classify( [ [ 'id' => 'local_delivery', 'title' => 'Drop off' ] ] ), 'delivery' );
check( 'tab: flat rate titled delivery', $F::classify( [ [ 'id' => 'flat_rate', 'title' => 'Local Delivery' ] ] ), 'delivery' );
check( 'tab: carrier is shipping', $F::classify( [ [ 'id' => 'flat_rate', 'title' => 'Flat rate' ] ] ), 'shipping' );
check( 'tab: no method is shipping', $F::classify( [] ), 'shipping' );
check( 'tab: read known', $F::read_tab( 'shipping' ), 'shipping' );
check( 'tab: read unknown falls to pickup', $F::read_tab( 'drone' ), 'pickup' );

check( 'email: enabled', $F::customer_note_email_enabled( [ 'enabled' => 'yes' ] ), true );
check( 'email: disabled', $F::customer_note_email_enabled( [ 'enabled' => 'no' ] ), false );
check( 'email: never saved keeps WooCommerce default (on)', $F::customer_note_email_enabled( false ), true );

bazaar_test_standalone( __FILE__ );
