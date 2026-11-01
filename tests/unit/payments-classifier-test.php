<?php
/**
 * Payments_Classifier, Payments_Settings and Payments_Registry::pos_tenders.
 * Run alone: php wp-content/plugins/xophz-compass-bazaar/tests/unit/payments-classifier-test.php
 */
require_once dirname( __DIR__ ) . '/support/harness.php';
$root = dirname( dirname( __DIR__ ) );
require_once $root . '/includes/core/class-xophz-compass-bazaar-core-autoloader.php';
Xophz_Compass_Bazaar_Core_Autoloader::register( $root );
require_once $root . '/includes/class-xophz-compass-bazaar-pos-authorization.php';

if ( ! function_exists( 'get_option' ) ) {
  function get_option( $name, $default = false ) { return array_key_exists( $name, $GLOBALS['test_options'] ) ? $GLOBALS['test_options'][ $name ] : $default; }
}
$GLOBALS['test_options']     = [];
$prev_environment            = $GLOBALS['test_environment'];
$prev_filters                = $GLOBALS['test_filters'];
$GLOBALS['test_environment'] = 'production';
$GLOBALS['test_filters']     = [];

$C = 'Xophz_Compass_Bazaar_Payments_Classifier';
$A = 'Xophz_Compass_Bazaar_Pos_Authorization';

// Table: method id, title, expected kind.
$table = [
  [ 'cash', '', 'cash' ],
  [ 'CASH', '', 'cash' ],
  [ 'cod', '', 'manual' ],
  [ 'bacs', '', 'manual' ],
  [ 'cheque', '', 'manual' ],
  [ 'bazaar_card_terminal', '', 'external_terminal' ],
  [ 'bazaar_split', '', 'split' ],
  [ 'stripe', '', 'external_terminal' ],
  [ 'woocommerce_payments', 'Credit Card (WooPayments)', 'external_terminal' ],
  [ 'square_credit_card', '', 'external_terminal' ],
  [ 'authorize_net', '', 'external_terminal' ],
  [ 'direct_bank_wire', 'Wire', 'manual' ],
  [ 'check_payment', '', 'manual' ],
  [ 'ppcp-gateway', 'PayPal', 'digital' ],
  [ 'affirm', '', 'digital' ],
  [ '', '', 'manual' ],
];
foreach ( $table as [ $id, $title, $kind ] ) {
  check( "kind: '$id' '$title'", $C::kind( $id, $title ), $kind );
  check( "kind agrees with requires_authorization: '$id' '$title'", 'digital' === $C::kind( $id, $title ), $A::requires_authorization( $id, $title ) );
}

// Filter: may change the kind, but only to a known kind.
$GLOBALS['test_filters']['xophz_compass_bazaar_tender_kind'] = 'manual';
check( 'filter: override accepted', $C::kind( 'affirm' ), 'manual' );
$GLOBALS['test_filters']['xophz_compass_bazaar_tender_kind'] = 'bogus';
check( 'filter: unknown kind ignored', $C::kind( 'affirm' ), 'digital' );
$GLOBALS['test_filters']['xophz_compass_bazaar_tender_kind'] = [ 'x' ];
check( 'filter: non-string ignored', $C::kind( 'cash' ), 'cash' );
unset( $GLOBALS['test_filters']['xophz_compass_bazaar_tender_kind'] );

// Settings.
$S = 'Xophz_Compass_Bazaar_Payments_Settings';
check( 'reference required: default on', $S::require_terminal_reference(), true );
foreach ( [ '0', 0, false, 'no', 'false', 'off', 'OFF' ] as $off ) {
  $GLOBALS['test_options']['bazaar_require_terminal_reference'] = $off;
  check( 'reference required: off for ' . var_export( $off, true ), $S::require_terminal_reference(), false );
}
foreach ( [ '1', 1, true, 'yes' ] as $on ) {
  $GLOBALS['test_options']['bazaar_require_terminal_reference'] = $on;
  check( 'reference required: on for ' . var_export( $on, true ), $S::require_terminal_reference(), true );
}
$GLOBALS['test_filters']['xophz_compass_bazaar_require_terminal_reference'] = false;
check( 'reference required: filter wins', $S::require_terminal_reference(), false );
$GLOBALS['test_filters'] = [];
$GLOBALS['test_options'] = [];
check( 'reference format: 4 chars ok', $S::is_valid_reference( 'AB-1' ), true );
check( 'reference format: 64 chars ok', $S::is_valid_reference( str_repeat( 'a', 64 ) ), true );
check( 'reference format: 3 chars refused', $S::is_valid_reference( 'AB1' ), false );
check( 'reference format: 65 chars refused', $S::is_valid_reference( str_repeat( 'a', 65 ) ), false );
check( 'reference format: space refused', $S::is_valid_reference( 'AB 12' ), false );
check( 'reference format: trailing newline refused', $S::is_valid_reference( "AB12\n" ), false );

// Registry: pos_tenders.
$R        = 'Xophz_Compass_Bazaar_Payments_Registry';
$gateways = [
  [ 'id' => 'bacs', 'title' => 'Direct bank transfer' ],
  [ 'id' => 'cod', 'title' => 'Cash on delivery' ],
  [ 'id' => 'stripe', 'title' => 'Stripe' ],
  [ 'id' => 'woocommerce_payments', 'title' => 'Credit Card (WooPayments)' ],
  [ 'id' => 'ppcp-gateway', 'title' => 'PayPal' ],
  [ 'id' => 'cash', 'title' => 'Cash again' ],
  [ 'id' => '', 'title' => 'No id' ],
];
$ids = function ( array $tenders ) { return array_column( $tenders, 'method_id' ); };

check( 'tenders: portal off, no digital', $ids( $R::pos_tenders( $gateways ) ), [ 'cash', 'bazaar_card_terminal', 'bacs', 'cod' ] );
$GLOBALS['test_environment'] = 'local';
check( 'tenders: portal on adds digital', $ids( $R::pos_tenders( $gateways ) ), [ 'cash', 'bazaar_card_terminal', 'bacs', 'cod', 'ppcp-gateway' ] );
$GLOBALS['test_environment'] = 'production';
check( 'tenders: no gateways source gives built-ins', $ids( $R::pos_tenders( [] ) ), [ 'cash', 'bazaar_card_terminal' ] );
check( 'tenders: card-pattern gateways never listed', in_array( 'stripe', $ids( $R::pos_tenders( $gateways ) ), true ), false );

$tenders = $R::pos_tenders( $gateways );
check( 'tenders: cash shape', $tenders[0], [
  'method_id' => 'cash', 'label' => 'Cash', 'kind' => 'cash', 'provider' => 'cash',
  'requires'  => [ 'tendered' => true, 'reference' => false, 'authorization_token' => false ],
] );
check( 'tenders: card terminal needs reference by default', $tenders[1]['requires']['reference'], true );
check( 'tenders: card terminal provider', $tenders[1]['provider'], 'external_terminal' );
check( 'tenders: gateway label is its title', $tenders[2]['label'], 'Direct bank transfer' );
check( 'tenders: gateway provider', $tenders[2]['provider'], 'manual' );

// Registry: for_method and the provider filter.
check( 'for_method: cash', $R::for_method( 'cash' )->id(), 'cash' );
check( 'for_method: legacy card pattern', $R::for_method( 'stripe' )->id(), 'external_terminal' );
check( 'for_method: split has none', $R::for_method( 'bazaar_split' ), null );
check( 'for_method: digital has none while portal off', $R::for_method( 'affirm' ), null );
$GLOBALS['test_environment'] = 'local';
check( 'for_method: digital uses the portal while it is on', $R::for_method( 'affirm' )->id(), 'test_portal' );
$GLOBALS['test_environment'] = 'production';
check( 'providers: four built-ins', array_keys( $R::providers() ), [ 'cash', 'external_terminal', 'manual', 'test_portal' ] );
$GLOBALS['test_filters']['xophz_compass_bazaar_payment_providers'] = [ new Xophz_Compass_Bazaar_Payments_Provider_Cash(), 'junk', new stdClass() ];
check( 'providers: filter result is cleaned', array_keys( $R::providers() ), [ 'cash' ] );
$GLOBALS['test_environment'] = $prev_environment;
$GLOBALS['test_filters']     = $prev_filters;

bazaar_test_standalone( __FILE__ );
