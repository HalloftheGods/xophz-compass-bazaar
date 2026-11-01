<?php
/**
 * Bank transfer, cheque and cash on delivery: nothing is paid yet. Capture
 * records a pending payment; marking the order paid later is another module's job.
 *
 * @package Xophz_Compass_Bazaar
 */
class Xophz_Compass_Bazaar_Payments_Provider_Manual implements Xophz_Compass_Bazaar_Payments_Provider {

  public function id(): string {
    return 'manual';
  }

  public function kind(): string {
    return 'manual';
  }

  public function label( string $method_id ): string {
    return 'Pay later (' . $method_id . ')';
  }

  public function requirements(): array {
    return [ 'tendered' => false, 'reference' => false, 'authorization_token' => false ];
  }

  public function supports( string $feature ): bool {
    return false;
  }

  public function available(): bool {
    return true;
  }

  public function precheck( Xophz_Compass_Bazaar_Payments_Tender $t, Xophz_Compass_Bazaar_Payments_Intent $i ): Xophz_Compass_Bazaar_Payments_Result {
    return Xophz_Compass_Bazaar_Payments_Result::ok();
  }

  /** payload.order_status is a hint: cod gives processing, everything else on-hold. */
  public function capture( Xophz_Compass_Bazaar_Payments_Tender $t, Xophz_Compass_Bazaar_Payments_Intent $i ): Xophz_Compass_Bazaar_Payments_Result {
    return Xophz_Compass_Bazaar_Payments_Result::pending( [
      'verified'  => false,
      'reference' => $t->reference,
      'note'      => 'Payment pending (' . $t->method_id . '); not yet received.' . ( '' !== $t->reference ? ' Reference: ' . $t->reference . '.' : '' ),
      'payload'   => [ 'order_status' => 'cod' === $t->method_id ? 'processing' : 'on-hold' ],
    ] );
  }

  public function confirm( array $payment_row, array $input ): Xophz_Compass_Bazaar_Payments_Result {
    return Xophz_Compass_Bazaar_Payments_Result::refused( 'confirm_unsupported', 'Pending payments are captured when the order is marked paid, not through confirm.', 422 );
  }

  public function void( array $payment_row, string $reason ): Xophz_Compass_Bazaar_Payments_Result {
    return Xophz_Compass_Bazaar_Payments_Result::voided( [ 'note' => 'Pending payment voided.' ] );
  }

  public function refund( array $payment_row, int $amount_minor, array $input ): Xophz_Compass_Bazaar_Payments_Result {
    if ( $amount_minor <= 0 ) {
      return Xophz_Compass_Bazaar_Payments_Result::refused( 'refund_invalid', 'The refund amount must be above 0.', 422 );
    }
    return Xophz_Compass_Bazaar_Payments_Result::refunded( [
      'reference' => trim( (string) ( $input['reference'] ?? '' ) ),
      'note'      => 'Refund recorded; the money must be returned outside Bazaar.',
    ] );
  }

  public function handle_webhook( WP_REST_Request $request ): ?Xophz_Compass_Bazaar_Payments_Result {
    return null;
  }
}
