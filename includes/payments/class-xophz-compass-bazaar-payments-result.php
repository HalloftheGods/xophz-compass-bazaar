<?php
/**
 * The outcome of one provider call.
 *
 * @package Xophz_Compass_Bazaar
 */
final class Xophz_Compass_Bazaar_Payments_Result {

  const STATUSES = [ 'ok', 'captured', 'pending', 'voided', 'void_required', 'refunded', 'refused' ];

  /** @var string One of STATUSES. */
  public $status = 'ok';
  /** @var bool True only when funds were confirmed by a processor or token, not by a person's word. */
  public $verified = false;
  /** @var string */
  public $reference = '';
  /** @var string */
  public $provider_ref = '';
  /** @var string */
  public $card_brand = '';
  /** @var string */
  public $card_last4 = '';
  /** @var string Phase 2: what the client must do next. Empty in Phase 1. */
  public $next_action = '';
  /** @var string Error code on a refusal. */
  public $code = '';
  /** @var string */
  public $message = '';
  /** @var int HTTP status for a refusal; 200 otherwise. */
  public $http_status = 200;
  /** @var string Order note to add. */
  public $note = '';
  /** @var array Provider data to store, such as change_minor. */
  public $payload = [];

  private function __construct() {}

  /** @param array $fields Any public property except status. */
  public static function make( string $status, array $fields = [] ): self {
    if ( ! in_array( $status, self::STATUSES, true ) ) {
      throw new InvalidArgumentException( 'Unknown payment result status: ' . $status );
    }
    $r         = new self();
    $r->status = $status;
    foreach ( $fields as $key => $value ) {
      if ( 'status' !== $key && property_exists( $r, $key ) ) {
        $r->$key = $value;
      }
    }
    return $r;
  }

  public static function ok( array $fields = [] ): self {
    return self::make( 'ok', $fields );
  }

  public static function captured( array $fields = [] ): self {
    return self::make( 'captured', $fields );
  }

  public static function pending( array $fields = [] ): self {
    return self::make( 'pending', $fields );
  }

  public static function voided( array $fields = [] ): self {
    return self::make( 'voided', $fields );
  }

  public static function void_required( array $fields = [] ): self {
    return self::make( 'void_required', $fields );
  }

  public static function refunded( array $fields = [] ): self {
    return self::make( 'refunded', $fields );
  }

  public static function refused( string $code, string $message, int $http_status = 422, array $fields = [] ): self {
    return self::make( 'refused', array_merge( $fields, [ 'code' => $code, 'message' => $message, 'http_status' => $http_status ] ) );
  }

  public function is_refused(): bool {
    return 'refused' === $this->status;
  }

  public function to_array(): array {
    return get_object_vars( $this );
  }
}
