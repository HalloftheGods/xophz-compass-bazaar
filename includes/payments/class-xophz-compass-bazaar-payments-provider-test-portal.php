<?php
/**
 * The development test portal: a one-time token stands in for a processor.
 * Offered only while Pos_Authorization::is_test_portal_enabled(). No money moves.
 *
 * The token is issued to the logged-in user (intent.operator_user_id) for the
 * payment method id and the amount of this tender.
 *
 * @package Xophz_Compass_Bazaar
 */
class Xophz_Compass_Bazaar_Payments_Provider_Test_Portal implements Xophz_Compass_Bazaar_Payments_Provider {

  public function id(): string {
    return 'test_portal';
  }

  public function kind(): string {
    return 'digital';
  }

  public function label( string $method_id ): string {
    return $method_id;
  }

  public function requirements(): array {
    return [ 'tendered' => false, 'reference' => false, 'authorization_token' => true ];
  }

  public function supports( string $feature ): bool {
    return false;
  }

  public function available(): bool {
    return Xophz_Compass_Bazaar_Pos_Authorization::is_test_portal_enabled();
  }

  /** Reads the token without spending it. */
  public function precheck( Xophz_Compass_Bazaar_Payments_Tender $t, Xophz_Compass_Bazaar_Payments_Intent $i ): Xophz_Compass_Bazaar_Payments_Result {
    if ( ! $this->available() ) {
      return $this->unavailable();
    }
    $reason = Xophz_Compass_Bazaar_Pos_Authorization::peek( $t->authorization_token, $i->operator_user_id, $t->method_id, Xophz_Compass_Bazaar_Core_Money::from_minor( $t->amount_minor ) );
    return '' === $reason ? Xophz_Compass_Bazaar_Payments_Result::ok() : $this->not_authorized( $reason, $i );
  }

  /** Spends the token. It is spent whether or not it matched, so call it once per tender. */
  public function capture( Xophz_Compass_Bazaar_Payments_Tender $t, Xophz_Compass_Bazaar_Payments_Intent $i ): Xophz_Compass_Bazaar_Payments_Result {
    if ( ! $this->available() ) {
      return $this->unavailable();
    }
    $reason = Xophz_Compass_Bazaar_Pos_Authorization::consume( $t->authorization_token, $i->operator_user_id, $t->method_id, Xophz_Compass_Bazaar_Core_Money::from_minor( $t->amount_minor ) );
    if ( '' !== $reason ) {
      return $this->not_authorized( $reason, $i );
    }
    return Xophz_Compass_Bazaar_Payments_Result::captured( [
      'verified' => true,
      'note'     => 'Payment authorized through the Bazaar POS test portal (no processor connected).',
    ] );
  }

  public function confirm( array $payment_row, array $input ): Xophz_Compass_Bazaar_Payments_Result {
    return Xophz_Compass_Bazaar_Payments_Result::refused( 'confirm_unsupported', 'Test portal payments are not confirmed later.', 422 );
  }

  /** No processor: nothing to reverse, so staff must handle any real money elsewhere. */
  public function void( array $payment_row, string $reason ): Xophz_Compass_Bazaar_Payments_Result {
    return Xophz_Compass_Bazaar_Payments_Result::void_required( [ 'note' => 'Test portal payment: no processor is connected, nothing was reversed.' ] );
  }

  public function refund( array $payment_row, int $amount_minor, array $input ): Xophz_Compass_Bazaar_Payments_Result {
    if ( $amount_minor <= 0 ) {
      return Xophz_Compass_Bazaar_Payments_Result::refused( 'refund_invalid', 'The refund amount must be above 0.', 422 );
    }
    return Xophz_Compass_Bazaar_Payments_Result::refunded( [ 'note' => 'Test portal refund recorded (no processor connected).' ] );
  }

  public function handle_webhook( WP_REST_Request $request ): ?Xophz_Compass_Bazaar_Payments_Result {
    return null;
  }

  private function not_authorized( string $reason, Xophz_Compass_Bazaar_Payments_Intent $i ): Xophz_Compass_Bazaar_Payments_Result {
    return Xophz_Compass_Bazaar_Payments_Result::refused(
      'payment_not_authorized',
      sprintf( 'Payment not authorized (%s). %s was not charged and the sale was not recorded.', $reason, $i->method_title ),
      402,
      [ 'payload' => [ 'reason' => $reason ] ]
    );
  }

  private function unavailable(): Xophz_Compass_Bazaar_Payments_Result {
    return Xophz_Compass_Bazaar_Payments_Result::refused( 'tender_unsupported', 'This payment method is not available.', 422 );
  }
}
