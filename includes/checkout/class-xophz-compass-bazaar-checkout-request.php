<?php
/**
 * One validated POST /bazaar/v1/pos/checkouts body. Built from untrusted input.
 *
 * Spec: docs/plugins/bazaar-core-v2.md section 11.1.
 *
 * Fields:
 * - client_key: a UUID v4, optionally prefixed "legacy:" (the legacy adapter's
 *   keys). Lowercased. The Idempotency-Key header is an alias: it is used when
 *   the body has no client_key, and a body key that differs from it is refused.
 * - client_created_at: optional ISO 8601 time, stored as UTC 'Y-m-d H:i:s'.
 * - expected_total_minor: optional; null when absent.
 * - quote_hash: optional; '' or 64 hex characters (advisory, never enforced).
 * - cart: the spec 9.1 cart, through Pricing_Cart_Request::from_array.
 * - tenders: 0 to 10, each through Payments_Tender::from_array. A tender of
 *   amount_minor 0 is allowed only as the only tender (the no-charge tender
 *   of a zero-total sale); the service checks the sum against the total.
 * - approval_tokens: up to 10 strings.
 *
 * Guaranteed: every schema problem found is reported at once as 400
 * invalid_request with data.errors [{path, message}], except a price
 * override, which the cart refuses first with 422 unsupported_field.
 * Unknown keys are ignored. Not checked here: products, coupons, providers,
 * totals and approvals (the service does that).
 *
 * request_hash() is Core_Canonical::hash of the normalized body without
 * authorization_token, approval_tokens and client_created_at, so a retry that
 * only adds an approval or a digital token keeps its hash.
 *
 * @package Xophz_Compass_Bazaar
 */
final class Xophz_Compass_Bazaar_Checkout_Request {

  const MAX_TENDERS   = 10;
  const MAX_APPROVALS = 10;
  const MAX_TOKEN     = 200;
  const KEY_PATTERN   = '/^(?:legacy:)?[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';
  const HASH_EXCLUDED = [ 'authorization_token', 'approval_tokens', 'client_created_at' ];

  /** @var string */
  public $client_key = '';
  /** @var string|null UTC 'Y-m-d H:i:s'. */
  public $client_created_at = null;
  /** @var int|null */
  public $expected_total_minor = null;
  /** @var string */
  public $quote_hash = '';
  /** @var Xophz_Compass_Bazaar_Pricing_Cart_Request */
  public $cart;
  /** @var Xophz_Compass_Bazaar_Payments_Tender[] */
  public $tenders = [];
  /** @var string[] */
  public $approval_tokens = [];

  /** @var array[] {path, message} */
  private $errors = [];

  private function __construct() {}

  /**
   * @param string $header_key The Idempotency-Key header value, '' when absent.
   * @throws Xophz_Compass_Bazaar_Core_Http_Error 400 invalid_request or 422 unsupported_field.
   */
  public static function from_array( array $data, string $header_key = '' ): self {
    $r = new self();
    $r->parse_key( $data, strtolower( trim( $header_key ) ) );
    $r->parse_created_at( $data['client_created_at'] ?? null );
    $r->parse_expected_total( $data );
    $r->parse_quote_hash( $data['quote_hash'] ?? '' );
    $r->parse_cart( $data['cart'] ?? null );
    $r->parse_tenders( $data['tenders'] ?? null );
    $r->parse_approvals( $data['approval_tokens'] ?? [] );
    if ( $r->errors ) {
      throw new Xophz_Compass_Bazaar_Core_Http_Error( 'invalid_request', 'The checkout request is not valid.', 400, [ 'errors' => $r->errors ] );
    }
    return $r;
  }

  /** The normalized body, including the fields the hash leaves out. */
  public function to_array(): array {
    return [
      'client_key'           => $this->client_key,
      'client_created_at'    => $this->client_created_at,
      'expected_total_minor' => $this->expected_total_minor,
      'quote_hash'           => $this->quote_hash,
      'cart'                 => $this->cart->to_array(),
      'tenders'              => array_map( [ self::class, 'tender_array' ], $this->tenders ),
      'approval_tokens'      => $this->approval_tokens,
    ];
  }

  /** sha256 hex over to_array() minus HASH_EXCLUDED, at every depth. */
  public function request_hash(): string {
    return Xophz_Compass_Bazaar_Core_Canonical::hash( $this->to_array(), self::HASH_EXCLUDED );
  }

  /** The sum of the tender amounts. */
  public function tender_total_minor(): int {
    $sum = 0;
    foreach ( $this->tenders as $tender ) {
      $sum += $tender->amount_minor;
    }
    return $sum;
  }

  public static function tender_array( Xophz_Compass_Bazaar_Payments_Tender $t ): array {
    return [
      'method_id'           => $t->method_id,
      'amount_minor'        => $t->amount_minor,
      'tendered_minor'      => $t->tendered_minor,
      'reference'           => $t->reference,
      'card_brand'          => $t->card_brand,
      'card_last4'          => $t->card_last4,
      'payment_uuid'        => $t->payment_uuid,
      'authorization_token' => $t->authorization_token,
    ];
  }

  private function parse_key( array $data, string $header ): void {
    $raw = $data['client_key'] ?? '';
    if ( ! is_string( $raw ) ) {
      $this->error( 'client_key', 'Must be text.' );
      return;
    }
    $key = strtolower( trim( $raw ) );
    if ( '' !== $header && '' !== $key && $header !== $key ) {
      $this->error( 'client_key', 'Differs from the Idempotency-Key header.' );
      return;
    }
    $key = '' !== $key ? $key : $header;
    if ( ! preg_match( self::KEY_PATTERN, $key ) ) {
      $this->error( 'client_key', 'Must be a UUID v4, optionally prefixed "legacy:".' );
      return;
    }
    $this->client_key = $key;
  }

  private function parse_created_at( $value ): void {
    if ( null === $value || '' === $value ) {
      return;
    }
    if ( ! is_string( $value ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:\d{2})$/', $value ) ) {
      $this->error( 'client_created_at', 'Must be an ISO 8601 time with a zone, such as 2026-11-01T17:59:58Z.' );
      return;
    }
    try {
      $time = new DateTimeImmutable( $value );
    } catch ( Exception $e ) {
      $this->error( 'client_created_at', 'Must be a real time.' );
      return;
    }
    $this->client_created_at = $time->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
  }

  private function parse_expected_total( array $data ): void {
    if ( ! array_key_exists( 'expected_total_minor', $data ) || null === $data['expected_total_minor'] ) {
      return;
    }
    $v = $data['expected_total_minor'];
    if ( ! is_int( $v ) || $v < 0 || $v > Xophz_Compass_Bazaar_Pricing_Cart_Request::MAX_AMOUNT ) {
      $this->error( 'expected_total_minor', 'Must be a whole number of 0 or more.' );
      return;
    }
    $this->expected_total_minor = $v;
  }

  private function parse_quote_hash( $value ): void {
    if ( null === $value || '' === $value ) {
      return;
    }
    if ( ! is_string( $value ) || ! preg_match( '/^[0-9a-fA-F]{64}$/', $value ) ) {
      $this->error( 'quote_hash', 'Must be 64 hex characters or empty.' );
      return;
    }
    $this->quote_hash = strtolower( $value );
  }

  /** @throws Xophz_Compass_Bazaar_Core_Http_Error 422 unsupported_field passes through. */
  private function parse_cart( $cart ): void {
    if ( ! is_array( $cart ) ) {
      $this->error( 'cart', 'Must be an object.' );
      return;
    }
    try {
      $this->cart = Xophz_Compass_Bazaar_Pricing_Cart_Request::from_array( $cart );
    } catch ( Xophz_Compass_Bazaar_Core_Http_Error $e ) {
      if ( 'invalid_request' !== $e->error_code() ) {
        throw $e;
      }
      foreach ( (array) ( $e->data()['errors'] ?? [] ) as $error ) {
        $this->error( 'cart.' . (string) ( $error['path'] ?? '' ), (string) ( $error['message'] ?? 'Not valid.' ) );
      }
    }
  }

  private function parse_tenders( $tenders ): void {
    if ( ! is_array( $tenders ) || ( $tenders && array_keys( $tenders ) !== range( 0, count( $tenders ) - 1 ) ) ) {
      $this->error( 'tenders', 'Must be a list of 0 to ' . self::MAX_TENDERS . ' tenders.' );
      return;
    }
    if ( count( $tenders ) > self::MAX_TENDERS ) {
      $this->error( 'tenders', 'At most ' . self::MAX_TENDERS . ' tenders.' );
      return;
    }
    foreach ( $tenders as $i => $tender ) {
      if ( ! is_array( $tender ) ) {
        $this->error( "tenders[$i]", 'Must be an object.' );
        continue;
      }
      try {
        $this->tenders[] = Xophz_Compass_Bazaar_Payments_Tender::from_array( $tender, 1 === count( $tenders ) );
      } catch ( Xophz_Compass_Bazaar_Core_Http_Error $e ) {
        $this->error( "tenders[$i]." . (string) ( $e->data()['field'] ?? '' ), $e->getMessage() );
      }
    }
  }

  private function parse_approvals( $tokens ): void {
    if ( null === $tokens ) {
      return;
    }
    if ( ! is_array( $tokens ) || ( $tokens && array_keys( $tokens ) !== range( 0, count( $tokens ) - 1 ) ) || count( $tokens ) > self::MAX_APPROVALS ) {
      $this->error( 'approval_tokens', 'Must be a list of at most ' . self::MAX_APPROVALS . ' tokens.' );
      return;
    }
    foreach ( $tokens as $i => $token ) {
      if ( ! is_string( $token ) || '' === trim( $token ) || strlen( $token ) > self::MAX_TOKEN ) {
        $this->error( "approval_tokens[$i]", 'Must be non-empty text of at most ' . self::MAX_TOKEN . ' characters.' );
        continue;
      }
      $this->approval_tokens[] = trim( $token );
    }
  }

  private function error( string $path, string $message ): void {
    $this->errors[] = [ 'path' => $path, 'message' => $message ];
  }
}
