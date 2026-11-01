<?php
/**
 * Cash in the drawer. Not verified: staff count it.
 *
 * @package Xophz_Compass_Bazaar
 */
class Xophz_Compass_Bazaar_Payments_Provider_Cash implements Xophz_Compass_Bazaar_Payments_Provider {

  public function id(): string {
    return 'cash';
  }

  public function kind(): string {
    return 'cash';
  }

  public function label( string $method_id ): string {
    return 'Cash';
  }

  public function requirements(): array {
    return [ 'tendered' => true, 'reference' => false, 'authorization_token' => false ];
  }

  public function supports( string $feature ): bool {
    return false;
  }

  public function available(): bool {
    return true;
  }

  public function precheck( Xophz_Compass_Bazaar_Payments_Tender $t, Xophz_Compass_Bazaar_Payments_Intent $i ): Xophz_Compass_Bazaar_Payments_Result {
    if ( null === $t->tendered_minor || $t->tendered_minor < $t->amount_minor ) {
      return Xophz_Compass_Bazaar_Payments_Result::refused( 'tender_insufficient', 'The cash handed over is less than the amount due.', 422 );
    }
    return Xophz_Compass_Bazaar_Payments_Result::ok( [ 'payload' => $this->amounts( $t ) ] );
  }

  public function capture( Xophz_Compass_Bazaar_Payments_Tender $t, Xophz_Compass_Bazaar_Payments_Intent $i ): Xophz_Compass_Bazaar_Payments_Result {
    $check = $this->precheck( $t, $i );
    if ( $check->is_refused() ) {
      return $check;
    }
    return Xophz_Compass_Bazaar_Payments_Result::captured( [
      'verified' => false,
      'note'     => 'Cash payment taken (counted by staff, not verified by Bazaar).',
      'payload'  => $this->amounts( $t ),
    ] );
  }

  public function confirm( array $payment_row, array $input ): Xophz_Compass_Bazaar_Payments_Result {
    return Xophz_Compass_Bazaar_Payments_Result::refused( 'confirm_unsupported', 'Cash payments are not confirmed later.', 422 );
  }

  public function void( array $payment_row, string $reason ): Xophz_Compass_Bazaar_Payments_Result {
    return Xophz_Compass_Bazaar_Payments_Result::voided( [ 'note' => 'Cash payment voided.' ] );
  }

  public function refund( array $payment_row, int $amount_minor, array $input ): Xophz_Compass_Bazaar_Payments_Result {
    if ( $amount_minor <= 0 ) {
      return Xophz_Compass_Bazaar_Payments_Result::refused( 'refund_invalid', 'The refund amount must be above 0.', 422 );
    }
    return Xophz_Compass_Bazaar_Payments_Result::refunded( [ 'note' => 'Cash refunded from the drawer.' ] );
  }

  public function handle_webhook( WP_REST_Request $request ): ?Xophz_Compass_Bazaar_Payments_Result {
    return null;
  }

  private function amounts( Xophz_Compass_Bazaar_Payments_Tender $t ): array {
    return [
      'tendered_minor' => (int) $t->tendered_minor,
      'change_minor'   => (int) $t->tendered_minor - $t->amount_minor,
    ];
  }
}
