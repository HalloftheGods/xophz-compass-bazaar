<?php
/**
 * A card taken on a separate terminal. Bazaar sees only what the cashier types
 * from the slip, so every capture is unverified.
 *
 * @package Xophz_Compass_Bazaar
 */
class Xophz_Compass_Bazaar_Payments_Provider_External_Terminal implements Xophz_Compass_Bazaar_Payments_Provider {

  public function id(): string {
    return 'external_terminal';
  }

  public function kind(): string {
    return 'external_terminal';
  }

  public function label( string $method_id ): string {
    return 'Card (external terminal)';
  }

  public function requirements(): array {
    return [ 'tendered' => false, 'reference' => Xophz_Compass_Bazaar_Payments_Settings::require_terminal_reference(), 'authorization_token' => false ];
  }

  public function supports( string $feature ): bool {
    return false;
  }

  public function available(): bool {
    return true;
  }

  public function precheck( Xophz_Compass_Bazaar_Payments_Tender $t, Xophz_Compass_Bazaar_Payments_Intent $i ): Xophz_Compass_Bazaar_Payments_Result {
    return $this->reference_problem( $t->reference ) ?? Xophz_Compass_Bazaar_Payments_Result::ok();
  }

  public function capture( Xophz_Compass_Bazaar_Payments_Tender $t, Xophz_Compass_Bazaar_Payments_Intent $i ): Xophz_Compass_Bazaar_Payments_Result {
    $problem = $this->reference_problem( $t->reference );
    if ( null !== $problem ) {
      return $problem;
    }
    $note = 'Card payment taken on an external terminal (not verified by Bazaar).';
    if ( '' !== $t->reference ) {
      $note .= ' Reference: ' . $t->reference . '.';
    }
    return Xophz_Compass_Bazaar_Payments_Result::captured( [
      'verified'   => false,
      'reference'  => $t->reference,
      'card_brand' => $t->card_brand,
      'card_last4' => $t->card_last4,
      'note'       => $note,
    ] );
  }

  public function confirm( array $payment_row, array $input ): Xophz_Compass_Bazaar_Payments_Result {
    return Xophz_Compass_Bazaar_Payments_Result::refused( 'confirm_unsupported', 'External terminal payments are not confirmed later.', 422 );
  }

  /** Bazaar cannot reverse a terminal charge: staff must do it on the terminal. */
  public function void( array $payment_row, string $reason ): Xophz_Compass_Bazaar_Payments_Result {
    return Xophz_Compass_Bazaar_Payments_Result::void_required( [ 'note' => 'Void this card payment on the external terminal; Bazaar cannot reverse it.' ] );
  }

  /** Records a refund the cashier already made on the terminal; needs its slip reference. */
  public function refund( array $payment_row, int $amount_minor, array $input ): Xophz_Compass_Bazaar_Payments_Result {
    if ( $amount_minor <= 0 ) {
      return Xophz_Compass_Bazaar_Payments_Result::refused( 'refund_invalid', 'The refund amount must be above 0.', 422 );
    }
    $reference = trim( (string) ( $input['reference'] ?? '' ) );
    if ( ! Xophz_Compass_Bazaar_Payments_Settings::is_valid_reference( $reference ) ) {
      return Xophz_Compass_Bazaar_Payments_Result::refused( 'reference_required', 'A refund on an external terminal needs the reference from the terminal slip (4 to 64 letters, digits or hyphens).', 422 );
    }
    return Xophz_Compass_Bazaar_Payments_Result::refunded( [
      'reference' => $reference,
      'note'      => 'Card refund recorded from an external terminal (not verified by Bazaar). Reference: ' . $reference . '.',
    ] );
  }

  public function handle_webhook( WP_REST_Request $request ): ?Xophz_Compass_Bazaar_Payments_Result {
    return null;
  }

  /** Null when the reference is acceptable. */
  private function reference_problem( string $reference ): ?Xophz_Compass_Bazaar_Payments_Result {
    if ( Xophz_Compass_Bazaar_Payments_Settings::require_terminal_reference() && ! Xophz_Compass_Bazaar_Payments_Settings::is_valid_reference( $reference ) ) {
      return Xophz_Compass_Bazaar_Payments_Result::refused( 'reference_required', 'Enter the reference from the terminal slip (4 to 64 letters, digits or hyphens).', 422 );
    }
    return null;
  }
}
