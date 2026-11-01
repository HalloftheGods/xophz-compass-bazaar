<?php
/**
 * Payments_Tender, Payments_Result and the four Phase 1 providers.
 * Run alone: php wp-content/plugins/xophz-compass-bazaar/tests/unit/payments-providers-test.php
 */
require_once dirname( __DIR__ ) . '/support/harness.php';
$root = dirname( dirname( __DIR__ ) );
require_once $root . '/includes/core/class-xophz-compass-bazaar-core-autoloader.php';
Xophz_Compass_Bazaar_Core_Autoloader::register( $root );
require_once $root . '/includes/class-xophz-compass-bazaar-pos-authorization.php';

if ( ! function_exists( 'get_option' ) ) {
  function get_option( $name, $default = false ) { return array_key_exists( $name, $GLOBALS['test_options'] ) ? $GLOBALS['test_options'][ $name ] : $default; }
}
if ( ! class_exists( 'WP_REST_Request' ) ) {
  class WP_REST_Request {}
}
$GLOBALS['test_options'] = [];
$prev_environment        = $GLOBALS['test_environment'];
$prev_filters            = $GLOBALS['test_filters'];
$GLOBALS['test_environment'] = 'production';
$GLOBALS['test_filters']     = [];

$T = 'Xophz_Compass_Bazaar_Payments_Tender';
$I = 'Xophz_Compass_Bazaar_Payments_Intent';
$A = 'Xophz_Compass_Bazaar_Pos_Authorization';
$E = 'Xophz_Compass_Bazaar_Core_Http_Error';

$tender = function ( array $over = [] ) use ( $T ) {
  return $T::from_array( array_merge( [ 'method_id' => 'cash', 'amount_minor' => 1000 ], $over ) );
};
$intent = function ( array $over = [] ) use ( $I ) {
  return new $I( array_merge( [ 'checkout_id' => 1, 'seq' => 1, 'amount_minor' => 1000, 'currency' => 'USD', 'operator_user_id' => 7, 'cashier_user_id' => 7, 'method_title' => 'PayPal' ], $over ) );
};
$field = function ( callable $fn ) {
  try {
    $fn();
  } catch ( Xophz_Compass_Bazaar_Core_Http_Error $e ) {
    return $e->error_code() . ':' . $e->status() . ':' . ( $e->data()['field'] ?? '' );
  }
  return 'no error';
};

// Tender::from_array.
$t = $T::from_array( [ 'method_id' => ' Cash ', 'amount_minor' => '1250', 'tendered_minor' => 2000, 'reference' => '  ab-12  ', 'card_brand' => 'VISA', 'card_last4' => '4242', 'authorization_token' => ' tok ', 'payment_uuid' => '123e4567-e89b-12d3-a456-426614174000' ] );
check( 'tender: method id lowercased and trimmed', $t->method_id, 'cash' );
check( 'tender: digit string amount accepted', $t->amount_minor, 1250 );
check( 'tender: tendered', $t->tendered_minor, 2000 );
check( 'tender: reference trimmed', $t->reference, 'ab-12' );
check( 'tender: brand lowercased', $t->card_brand, 'visa' );
check( 'tender: last4', $t->card_last4, '4242' );
check( 'tender: token trimmed', $t->authorization_token, 'tok' );
check( 'tender: uuid kept', $t->payment_uuid, '123e4567-e89b-12d3-a456-426614174000' );
check( 'tender: absent tendered is null', $tender()->tendered_minor, null );
check( 'tender: absent optional fields are empty', [ $tender()->reference, $tender()->card_brand, $tender()->card_last4, $tender()->authorization_token, $tender()->payment_uuid ], [ '', '', '', '', '' ] );
check( 'tender: tendered 0 is allowed', $tender( [ 'tendered_minor' => 0 ] )->tendered_minor, 0 );
check( 'tender: 64-char reference ok', strlen( $tender( [ 'reference' => str_repeat( 'a', 64 ) ] )->reference ), 64 );
check( 'tender: empty brand ok', $tender( [ 'card_brand' => 'other' ] )->card_brand, 'other' );

check( 'tender: missing method', $field( function () use ( $T ) { $T::from_array( [ 'amount_minor' => 5 ] ); } ), 'tender_invalid:422:method_id' );
check( 'tender: bad method chars', $field( function () use ( $tender ) { $tender( [ 'method_id' => 'a b' ] ); } ), 'tender_invalid:422:method_id' );
check( 'tender: zero amount', $field( function () use ( $tender ) { $tender( [ 'amount_minor' => 0 ] ); } ), 'tender_invalid:422:amount_minor' );
check( 'tender: negative amount', $field( function () use ( $tender ) { $tender( [ 'amount_minor' => -5 ] ); } ), 'tender_invalid:422:amount_minor' );
check( 'tender: float amount', $field( function () use ( $tender ) { $tender( [ 'amount_minor' => 10.5 ] ); } ), 'tender_invalid:422:amount_minor' );
check( 'tender: text amount', $field( function () use ( $tender ) { $tender( [ 'amount_minor' => '10.5' ] ); } ), 'tender_invalid:422:amount_minor' );
check( 'tender: negative tendered', $field( function () use ( $tender ) { $tender( [ 'tendered_minor' => -1 ] ); } ), 'tender_invalid:422:tendered_minor' );
check( 'tender: reference too long', $field( function () use ( $tender ) { $tender( [ 'reference' => str_repeat( 'a', 65 ) ] ); } ), 'tender_invalid:422:reference' );
check( 'tender: array reference', $field( function () use ( $tender ) { $tender( [ 'reference' => [ 'x' ] ] ); } ), 'tender_invalid:422:reference' );
check( 'tender: unknown brand', $field( function () use ( $tender ) { $tender( [ 'card_brand' => 'diners' ] ); } ), 'tender_invalid:422:card_brand' );
check( 'tender: short last4', $field( function () use ( $tender ) { $tender( [ 'card_last4' => '424' ] ); } ), 'tender_invalid:422:card_last4' );
check( 'tender: letters in last4', $field( function () use ( $tender ) { $tender( [ 'card_last4' => '42a2' ] ); } ), 'tender_invalid:422:card_last4' );
check( 'tender: bad uuid', $field( function () use ( $tender ) { $tender( [ 'payment_uuid' => 'nope' ] ); } ), 'tender_invalid:422:payment_uuid' );

// Result.
$Res = 'Xophz_Compass_Bazaar_Payments_Result';
$r   = $Res::refused( 'x', 'msg', 402 );
check( 'result: refused fields', [ $r->status, $r->code, $r->message, $r->http_status, $r->is_refused(), $r->verified ], [ 'refused', 'x', 'msg', 402, true, false ] );
check( 'result: captured is not refused', $Res::captured()->is_refused(), false );
check( 'result: default http status', $Res::ok()->http_status, 200 );
check( 'result: status cannot be overridden by fields', $Res::captured( [ 'status' => 'refused' ] )->status, 'captured' );
check_throws( 'result: unknown status throws', function () use ( $Res ) { $Res::make( 'paid' ); }, InvalidArgumentException::class );

// Cash.
$cash = new Xophz_Compass_Bazaar_Payments_Provider_Cash();
check( 'cash: below the amount refused', $cash->precheck( $tender( [ 'tendered_minor' => 999 ] ), $intent() )->code, 'tender_insufficient' );
check( 'cash: no tendered refused', $cash->precheck( $tender(), $intent() )->code, 'tender_insufficient' );
check( 'cash: exact is ok', $cash->precheck( $tender( [ 'tendered_minor' => 1000 ] ), $intent() )->status, 'ok' );
$c = $cash->capture( $tender( [ 'tendered_minor' => 2000 ] ), $intent() );
check( 'cash: captured', $c->status, 'captured' );
check( 'cash: change computed', $c->payload, [ 'tendered_minor' => 2000, 'change_minor' => 1000 ] );
check( 'cash: not verified', $c->verified, false );
check( 'cash: capture also refuses short cash', $cash->capture( $tender( [ 'tendered_minor' => 5 ] ), $intent() )->status, 'refused' );
check( 'cash: void', $cash->void( [], 'x' )->status, 'voided' );
check( 'cash: refund', $cash->refund( [], 500, [] )->status, 'refunded' );
check( 'cash: refund of 0 refused', $cash->refund( [], 0, [] )->status, 'refused' );
check( 'cash: no webhook in Phase 1', $cash->handle_webhook( new WP_REST_Request() ), null );

// External terminal.
$ext = new Xophz_Compass_Bazaar_Payments_Provider_External_Terminal();
$ext_tender = function ( array $over = [] ) use ( $tender ) { return $tender( array_merge( [ 'method_id' => 'bazaar_card_terminal' ], $over ) ); };
check( 'terminal: missing reference refused', $ext->precheck( $ext_tender(), $intent() )->code, 'reference_required' );
check( 'terminal: short reference refused', $ext->precheck( $ext_tender( [ 'reference' => 'ab1' ] ), $intent() )->code, 'reference_required' );
check( 'terminal: malformed reference refused', $ext->precheck( $ext_tender( [ 'reference' => 'ab 12!' ] ), $intent() )->code, 'reference_required' );
check( 'terminal: capture refuses too', $ext->capture( $ext_tender(), $intent() )->status, 'refused' );
check( 'terminal: valid reference ok', $ext->precheck( $ext_tender( [ 'reference' => 'A1B2-C3' ] ), $intent() )->status, 'ok' );
$c = $ext->capture( $ext_tender( [ 'reference' => 'A1B2-C3', 'card_brand' => 'visa', 'card_last4' => '4242' ] ), $intent() );
check( 'terminal: captured', $c->status, 'captured' );
check( 'terminal: verified false', $c->verified, false );
check( 'terminal: reference, brand and last4 copied', [ $c->reference, $c->card_brand, $c->card_last4 ], [ 'A1B2-C3', 'visa', '4242' ] );
check( 'terminal: note', $c->note, 'Card payment taken on an external terminal (not verified by Bazaar). Reference: A1B2-C3.' );
$GLOBALS['test_options']['bazaar_require_terminal_reference'] = '0';
check( 'terminal: reference optional when switched off', $ext->precheck( $ext_tender(), $intent() )->status, 'ok' );
$c = $ext->capture( $ext_tender(), $intent() );
check( 'terminal: captured without reference, still unverified', [ $c->status, $c->verified, $c->reference ], [ 'captured', false, '' ] );
check( 'terminal: note without reference', $c->note, 'Card payment taken on an external terminal (not verified by Bazaar).' );
check( 'terminal: requirements follow the setting', $ext->requirements()['reference'], false );
$GLOBALS['test_options'] = [];
check( 'terminal: requirements default', $ext->requirements()['reference'], true );
check( 'terminal: void needs the terminal', $ext->void( [], 'x' )->status, 'void_required' );
check( 'terminal: refund needs a reference', $ext->refund( [], 500, [] )->code, 'reference_required' );
check( 'terminal: refund with reference', $ext->refund( [], 500, [ 'reference' => 'RF-0001' ] )->status, 'refunded' );

// Manual.
$man = new Xophz_Compass_Bazaar_Payments_Provider_Manual();
check( 'manual: precheck ok', $man->precheck( $tender( [ 'method_id' => 'bacs' ] ), $intent() )->status, 'ok' );
$c = $man->capture( $tender( [ 'method_id' => 'bacs', 'reference' => 'inv-1' ] ), $intent() );
check( 'manual: pending, not verified', [ $c->status, $c->verified, $c->reference ], [ 'pending', false, 'inv-1' ] );
check( 'manual: bacs goes on-hold', $c->payload['order_status'], 'on-hold' );
check( 'manual: cheque goes on-hold', $man->capture( $tender( [ 'method_id' => 'cheque' ] ), $intent() )->payload['order_status'], 'on-hold' );
check( 'manual: cod goes processing', $man->capture( $tender( [ 'method_id' => 'cod' ] ), $intent() )->payload['order_status'], 'processing' );
check( 'manual: void', $man->void( [], 'x' )->status, 'voided' );

// Test portal.
$portal = new Xophz_Compass_Bazaar_Payments_Provider_Test_Portal();
$pt     = function ( string $token ) use ( $tender ) { return $tender( [ 'method_id' => 'paypal', 'authorization_token' => $token ] ); };
check( 'portal: unavailable in production', $portal->precheck( $pt( 'x' ), $intent() )->code, 'tender_unsupported' );
check( 'portal: not available', $portal->available(), false );
$GLOBALS['test_environment'] = 'local';
check( 'portal: available locally', $portal->available(), true );
$text = 'Payment not authorized (%s). PayPal was not charged and the sale was not recorded.';
$r    = $portal->precheck( $pt( '' ), $intent() );
check( 'portal: missing token', [ $r->code, $r->http_status, $r->message, $r->payload ], [ 'payment_not_authorized', 402, sprintf( $text, 'missing' ), [ 'reason' => 'missing' ] ] );
check( 'portal: unknown token', $portal->precheck( $pt( 'forged-token-1234567890' ), $intent() )->message, sprintf( $text, 'unknown or already used' ) );
$token = $A::issue( 7, 'paypal', '10.00' );
check( 'portal: different total message', $portal->precheck( $tender( [ 'method_id' => 'paypal', 'authorization_token' => $token, 'amount_minor' => 1001 ] ), $intent() )->message, sprintf( $text, 'issued for a different total' ) );
check( 'portal: other cashier', $portal->precheck( $pt( $token ), $intent( [ 'operator_user_id' => 8 ] ) )->payload['reason'], 'issued to another cashier' );
check( 'portal: other method', $portal->precheck( $tender( [ 'method_id' => 'affirm', 'authorization_token' => $token ] ), $intent() )->payload['reason'], 'issued for another payment method' );
check( 'portal: precheck passes', $portal->precheck( $pt( $token ), $intent() )->status, 'ok' );
check( 'portal: precheck does not spend the token', $portal->precheck( $pt( $token ), $intent() )->status, 'ok' );
$c = $portal->capture( $pt( $token ), $intent() );
check( 'portal: captured and verified', [ $c->status, $c->verified ], [ 'captured', true ] );
check( 'portal: note', $c->note, 'Payment authorized through the Bazaar POS test portal (no processor connected).' );
check( 'portal: second capture refused', $portal->capture( $pt( $token ), $intent() )->payload['reason'], 'unknown or already used' );
check( 'portal: precheck after capture refused', $portal->precheck( $pt( $token ), $intent() )->payload['reason'], 'unknown or already used' );
$bad = $A::issue( 7, 'paypal', '10.00' );
$portal->capture( $pt( $bad ), $intent( [ 'operator_user_id' => 8 ] ) );
check( 'portal: a refused capture still spends the token', $portal->precheck( $pt( $bad ), $intent() )->payload['reason'], 'unknown or already used' );
check( 'portal: void needs staff', $portal->void( [], 'x' )->status, 'void_required' );
check( 'portal: requirements', $portal->requirements(), [ 'tendered' => false, 'reference' => false, 'authorization_token' => true ] );
$GLOBALS['test_environment'] = $prev_environment;
$GLOBALS['test_filters']     = $prev_filters;

bazaar_test_standalone( __FILE__ );
