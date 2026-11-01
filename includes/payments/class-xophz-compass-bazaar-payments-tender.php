<?php
/**
 * One validated tender line of a sale. Built from untrusted input.
 *
 * @package Xophz_Compass_Bazaar
 */
final class Xophz_Compass_Bazaar_Payments_Tender {

  const CARD_BRANDS = [ 'visa', 'mastercard', 'amex', 'discover', 'other' ];

  /** @var string */
  public $method_id;
  /** @var int */
  public $amount_minor;
  /** @var int|null Null when the client sent none. */
  public $tendered_minor;
  /** @var string */
  public $reference;
  /** @var string */
  public $card_brand;
  /** @var string */
  public $card_last4;
  /** @var string */
  public $authorization_token;
  /** @var string */
  public $payment_uuid;

  private function __construct() {}

  /**
   * Validate and build. Throws Core_Http_Error (422 tender_invalid, with
   * data.field) on the first invalid field. Checks shape only; whether a
   * provider accepts the tender is the provider's precheck. With $allow_zero
   * amount_minor may be 0: the no-charge tender of a zero-total sale, which
   * Checkout_Request allows only as the only tender.
   */
  public static function from_array( array $in, bool $allow_zero = false ): self {
    $t            = new self();
    $t->method_id = strtolower( trim( self::text( $in, 'method_id' ) ) );
    if ( '' === $t->method_id || strlen( $t->method_id ) > 100 || ! preg_match( '/^[a-z0-9_\-]+$/', $t->method_id ) ) {
      throw self::invalid( 'method_id', 'method_id is required and may hold only lowercase letters, digits, underscores and hyphens.' );
    }

    $amount = self::int( $in['amount_minor'] ?? null );
    if ( null === $amount || $amount < 0 || ( 0 === $amount && ! $allow_zero ) ) {
      throw self::invalid( 'amount_minor', $allow_zero ? 'amount_minor must be a whole number of 0 or more.' : 'amount_minor must be a whole number above 0.' );
    }
    $t->amount_minor = $amount;

    $t->tendered_minor = null;
    if ( array_key_exists( 'tendered_minor', $in ) && null !== $in['tendered_minor'] && '' !== $in['tendered_minor'] ) {
      $tendered = self::int( $in['tendered_minor'] );
      if ( null === $tendered || $tendered < 0 ) {
        throw self::invalid( 'tendered_minor', 'tendered_minor must be a whole number of 0 or more.' );
      }
      $t->tendered_minor = $tendered;
    }

    $t->reference = trim( self::text( $in, 'reference' ) );
    if ( strlen( $t->reference ) > 64 ) {
      throw self::invalid( 'reference', 'reference may be at most 64 characters.' );
    }

    $t->card_brand = strtolower( trim( self::text( $in, 'card_brand' ) ) );
    if ( '' !== $t->card_brand && ! in_array( $t->card_brand, self::CARD_BRANDS, true ) ) {
      throw self::invalid( 'card_brand', 'card_brand must be visa, mastercard, amex, discover, other or empty.' );
    }

    $t->card_last4 = trim( self::text( $in, 'card_last4' ) );
    if ( '' !== $t->card_last4 && ! preg_match( '/^[0-9]{4}$/', $t->card_last4 ) ) {
      throw self::invalid( 'card_last4', 'card_last4 must be exactly four digits or empty.' );
    }

    $t->authorization_token = trim( self::text( $in, 'authorization_token' ) );
    if ( strlen( $t->authorization_token ) > 200 ) {
      throw self::invalid( 'authorization_token', 'authorization_token is too long.' );
    }

    $t->payment_uuid = trim( self::text( $in, 'payment_uuid' ) );
    if ( '' !== $t->payment_uuid && ! preg_match( '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $t->payment_uuid ) ) {
      throw self::invalid( 'payment_uuid', 'payment_uuid must be a UUID or empty.' );
    }
    return $t;
  }

  /** A string field; a non-scalar value counts as invalid by becoming a value no rule accepts. */
  private static function text( array $in, string $key ): string {
    $v = $in[ $key ] ?? '';
    if ( is_int( $v ) || is_string( $v ) ) {
      return (string) $v;
    }
    if ( null === $v ) {
      return '';
    }
    throw self::invalid( $key, $key . ' must be text.' );
  }

  /** An int, or a string of digits. Floats and everything else give null. */
  private static function int( $v ): ?int {
    if ( is_int( $v ) ) {
      return $v;
    }
    if ( is_string( $v ) && preg_match( '/^-?[0-9]{1,15}$/', trim( $v ) ) ) {
      return (int) trim( $v );
    }
    return null;
  }

  private static function invalid( string $field, string $message ): Xophz_Compass_Bazaar_Core_Http_Error {
    return new Xophz_Compass_Bazaar_Core_Http_Error( 'tender_invalid', $message, 422, [ 'field' => $field ] );
  }
}
