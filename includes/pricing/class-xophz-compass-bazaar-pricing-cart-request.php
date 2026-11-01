<?php
/**
 * The cart a POS client asks to price: shared by the quote and checkout.
 *
 * Spec: docs/plugins/bazaar-core-v2.md section 9.1.
 *
 * from_array() checks the shape and limits and returns a normalized,
 * immutable request. It checks nothing against the catalog: products,
 * coupons and permissions are the pricer's job.
 *
 * Guaranteed:
 * - 1 to 200 lines, integer quantities 1 to 9999, up to 10 coupons and
 *   10 discounts, amounts are non-negative integers up to MAX_AMOUNT.
 * - A line carrying price_override_minor is refused with 422
 *   unsupported_field (reserved for #6805), before any other check.
 * - Any other schema error is refused with 400 invalid_request and
 *   data.errors, a list of {path, message} for every problem found.
 * - Unknown keys are ignored, so checkout can send its own fields alongside.
 *
 * @package Xophz_Compass_Bazaar
 */
final class Xophz_Compass_Bazaar_Pricing_Cart_Request {

  const MAX_LINES     = 200;
  const MAX_QUANTITY  = 9999;
  const MAX_COUPONS   = 10;
  const MAX_DISCOUNTS = 10;
  const MAX_TEXT      = 200;
  /** Largest accepted amount in minor units (1,000,000,000.00 at 2 decimals). */
  const MAX_AMOUNT = 100000000000;

  /** @var int */
  public $location_id = 0;
  /** @var array[] Normalized lines, see normalize_line(). */
  public $lines = [];
  /** @var string[] Coupon codes, trimmed and lowercased, in request order. */
  public $coupons = [];
  /** @var array[] {name, type: percent|fixed, value_bp|amount_minor} */
  public $discounts = [];
  /** @var int */
  public $tip_minor = 0;
  /** @var array{id:int,email:string,phone:string,name:string,create_account:bool,marketing_opt_in:bool,sms_opt_in:bool} */
  public $customer = [];

  /** @var array[] {path, message} collected while parsing. */
  private $errors = [];

  private function __construct() {}

  /**
   * @throws Xophz_Compass_Bazaar_Core_Http_Error 422 unsupported_field or 400 invalid_request.
   */
  public static function from_array( array $data ): self {
    self::refuse_overrides( $data );
    $request = new self();
    $request->parse( $data );
    if ( $request->errors ) {
      throw new Xophz_Compass_Bazaar_Core_Http_Error(
        'invalid_request',
        'The cart request is not valid.',
        400,
        [ 'errors' => $request->errors ]
      );
    }
    return $request;
  }

  /** The normalized request as plain data (stable key order, for hashing). */
  public function to_array(): array {
    return [
      'location_id' => $this->location_id,
      'lines'       => $this->lines,
      'coupons'     => $this->coupons,
      'discounts'   => $this->discounts,
      'tip_minor'   => $this->tip_minor,
      'customer'    => $this->customer,
    ];
  }

  /** @throws Xophz_Compass_Bazaar_Core_Http_Error */
  private static function refuse_overrides( array $data ): void {
    $lines = $data['lines'] ?? null;
    if ( array_key_exists( 'price_override_minor', $data ) ) {
      self::unsupported( 'price_override_minor' );
    }
    if ( ! is_array( $lines ) ) {
      return;
    }
    foreach ( $lines as $i => $line ) {
      if ( is_array( $line ) && array_key_exists( 'price_override_minor', $line ) ) {
        self::unsupported( "lines[$i].price_override_minor" );
      }
    }
  }

  /** @throws Xophz_Compass_Bazaar_Core_Http_Error */
  private static function unsupported( string $path ): void {
    throw new Xophz_Compass_Bazaar_Core_Http_Error(
      'unsupported_field',
      'Price overrides are not supported yet.',
      422,
      [ 'field' => $path ]
    );
  }

  private function parse( array $data ): void {
    $this->location_id = $this->int_field( $data, 'location_id', 'location_id', 0, PHP_INT_MAX, 0 );
    $this->tip_minor   = $this->int_field( $data, 'tip_minor', 'tip_minor', 0, self::MAX_AMOUNT, 0 );
    $this->parse_lines( $data['lines'] ?? null );
    $this->parse_coupons( $data['coupons'] ?? [] );
    $this->parse_discounts( $data['discounts'] ?? [] );
    $this->parse_customer( $data['customer'] ?? [] );
  }

  /** @param mixed $lines */
  private function parse_lines( $lines ): void {
    if ( ! self::is_list( $lines ) ) {
      $this->error( 'lines', 'Must be a list of lines.' );
      return;
    }
    $count = count( $lines );
    if ( $count < 1 || $count > self::MAX_LINES ) {
      $this->error( 'lines', 'Must hold 1 to ' . self::MAX_LINES . ' lines.' );
      return;
    }
    foreach ( $lines as $i => $line ) {
      $normalized = $this->normalize_line( $line, "lines[$i]" );
      if ( null !== $normalized ) {
        $this->lines[] = $normalized;
      }
    }
  }

  /** @param mixed $line */
  private function normalize_line( $line, string $path ): ?array {
    if ( ! is_array( $line ) || self::is_list( $line ) ) {
      $this->error( $path, 'Must be an object.' );
      return null;
    }
    $type     = $line['type'] ?? 'product';
    $quantity = $this->int_field( $line, 'quantity', "$path.quantity", 1, self::MAX_QUANTITY, null );
    if ( 'product' === $type ) {
      return [
        'type'         => 'product',
        'product_id'   => $this->int_field( $line, 'product_id', "$path.product_id", 1, PHP_INT_MAX, null ),
        'variation_id' => $this->int_field( $line, 'variation_id', "$path.variation_id", 0, PHP_INT_MAX, 0 ),
        'quantity'     => $quantity,
      ];
    }
    if ( 'custom' === $type ) {
      return [
        'type'             => 'custom',
        'name'             => $this->text_field( $line, 'name', "$path.name", true ),
        'unit_price_minor' => $this->int_field( $line, 'unit_price_minor', "$path.unit_price_minor", 0, self::MAX_AMOUNT, null ),
        'quantity'         => $quantity,
        'taxable'          => $this->bool_field( $line, 'taxable', "$path.taxable", true ),
      ];
    }
    $this->error( "$path.type", 'Must be "product" or "custom".' );
    return null;
  }

  /** @param mixed $coupons */
  private function parse_coupons( $coupons ): void {
    if ( ! self::is_list( $coupons ) ) {
      $this->error( 'coupons', 'Must be a list of coupon codes.' );
      return;
    }
    if ( count( $coupons ) > self::MAX_COUPONS ) {
      $this->error( 'coupons', 'Must hold at most ' . self::MAX_COUPONS . ' coupons.' );
      return;
    }
    foreach ( $coupons as $i => $code ) {
      $clean = is_string( $code ) ? self::coupon_code( $code ) : '';
      if ( '' === $clean || strlen( $clean ) > self::MAX_TEXT ) {
        $this->error( "coupons[$i]", 'Must be a coupon code of 1 to ' . self::MAX_TEXT . ' characters.' );
      } elseif ( in_array( $clean, $this->coupons, true ) ) {
        $this->error( "coupons[$i]", 'This coupon is already in the request.' );
      } else {
        $this->coupons[] = $clean;
      }
    }
  }

  /** @param mixed $discounts */
  private function parse_discounts( $discounts ): void {
    if ( ! self::is_list( $discounts ) ) {
      $this->error( 'discounts', 'Must be a list of discounts.' );
      return;
    }
    if ( count( $discounts ) > self::MAX_DISCOUNTS ) {
      $this->error( 'discounts', 'Must hold at most ' . self::MAX_DISCOUNTS . ' discounts.' );
      return;
    }
    foreach ( $discounts as $i => $discount ) {
      $path = "discounts[$i]";
      if ( ! is_array( $discount ) || self::is_list( $discount ) ) {
        $this->error( $path, 'Must be an object.' );
        continue;
      }
      $name = $this->text_field( $discount, 'name', "$path.name", false );
      $type = $discount['type'] ?? null;
      if ( 'percent' === $type ) {
        $this->discounts[] = [ 'name' => $name, 'type' => 'percent', 'value_bp' => $this->int_field( $discount, 'value_bp', "$path.value_bp", 1, 10000, null ) ];
      } elseif ( 'fixed' === $type ) {
        $this->discounts[] = [ 'name' => $name, 'type' => 'fixed', 'amount_minor' => $this->int_field( $discount, 'amount_minor', "$path.amount_minor", 1, self::MAX_AMOUNT, null ) ];
      } else {
        $this->error( "$path.type", 'Must be "percent" or "fixed".' );
      }
    }
  }

  /** @param mixed $customer */
  private function parse_customer( $customer ): void {
    if ( ! is_array( $customer ) || ( $customer && self::is_list( $customer ) ) ) {
      $this->error( 'customer', 'Must be an object.' );
      $customer = [];
    }
    $email = $this->text_field( $customer, 'email', 'customer.email', false );
    if ( '' !== $email && false === filter_var( $email, FILTER_VALIDATE_EMAIL ) ) {
      $this->error( 'customer.email', 'Must be an email address.' );
    }
    $this->customer = [
      'id'               => $this->int_field( $customer, 'id', 'customer.id', 0, PHP_INT_MAX, 0 ),
      'email'            => $email,
      'phone'            => $this->text_field( $customer, 'phone', 'customer.phone', false ),
      'name'             => $this->text_field( $customer, 'name', 'customer.name', false ),
      'create_account'   => $this->bool_field( $customer, 'create_account', 'customer.create_account', false ),
      'marketing_opt_in' => $this->bool_field( $customer, 'marketing_opt_in', 'customer.marketing_opt_in', false ),
      'sms_opt_in'       => $this->bool_field( $customer, 'sms_opt_in', 'customer.sms_opt_in', false ),
    ];
  }

  /**
   * A JSON integer in [$min, $max]. A missing key gives $default, or an
   * error when $default is null. Strings and floats are refused.
   */
  private function int_field( array $data, string $key, string $path, int $min, int $max, ?int $default ): int {
    if ( ! array_key_exists( $key, $data ) || null === $data[ $key ] ) {
      if ( null === $default ) {
        $this->error( $path, 'Is required.' );
        return 0;
      }
      return $default;
    }
    $value = $data[ $key ];
    if ( ! is_int( $value ) || $value < $min || $value > $max ) {
      $this->error( $path, "Must be an integer from $min to $max." );
      return 0;
    }
    return $value;
  }

  private function text_field( array $data, string $key, string $path, bool $required ): string {
    $value = $data[ $key ] ?? '';
    if ( ! is_string( $value ) ) {
      $this->error( $path, 'Must be text.' );
      return '';
    }
    $value = trim( $value );
    if ( $required && '' === $value ) {
      $this->error( $path, 'Is required.' );
    } elseif ( self::length( $value ) > self::MAX_TEXT ) {
      $this->error( $path, 'Must be at most ' . self::MAX_TEXT . ' characters.' );
    }
    return $value;
  }

  private function bool_field( array $data, string $key, string $path, bool $default ): bool {
    if ( ! array_key_exists( $key, $data ) || null === $data[ $key ] ) {
      return $default;
    }
    if ( ! is_bool( $data[ $key ] ) ) {
      $this->error( $path, 'Must be true or false.' );
      return $default;
    }
    return $data[ $key ];
  }

  private function error( string $path, string $message ): void {
    $this->errors[] = [ 'path' => $path, 'message' => $message ];
  }

  /** WooCommerce's coupon code format when loaded, else trimmed lowercase. */
  private static function coupon_code( string $code ): string {
    $code = trim( $code );
    if ( function_exists( 'wc_format_coupon_code' ) ) {
      $code = wc_format_coupon_code( $code );
    }
    return function_exists( 'wc_strtolower' ) ? wc_strtolower( $code ) : strtolower( $code );
  }

  private static function length( string $text ): int {
    return function_exists( 'mb_strlen' ) ? mb_strlen( $text ) : strlen( $text );
  }

  /** @param mixed $value */
  private static function is_list( $value ): bool {
    if ( ! is_array( $value ) ) {
      return false;
    }
    $i = 0;
    foreach ( $value as $key => $unused ) {
      if ( $key !== $i++ ) {
        return false;
      }
    }
    return true;
  }
}
