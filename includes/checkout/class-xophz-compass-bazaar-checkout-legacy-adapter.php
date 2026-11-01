<?php
/**
 * Runs the legacy create_pos_order request through checkout v2.
 *
 * Spec: docs/plugins/bazaar-core-v2.md section 11.5. Admin_Pos_Sale routes here
 * while the option bazaar_checkout_pipeline is "v2" (the default).
 *
 * Guaranteed:
 * - The answer is {success:true, order_id, order_key} or {success:false,
 *   message}, and the caller sends it with HTTP 200 as before.
 * - An empty cart is refused with "Cart is empty." before any checkout row is
 *   claimed. So is a cart whose every line is skipped (quantity 0, an
 *   unusable product id, a custom line without a title or price).
 * - The checkout key is legacy:<uuid4>, new on every call, so a repeated
 *   request is a new sale, as it was. No expected total is sent.
 * - A single tender takes its amount from the priced total. Split legs are
 *   sent as given; when they do not add up to the priced total the answer is
 *   "Split payment sum does not match order total." and nothing is recorded.
 * - A refused digital tender keeps the old text, with the old method name
 *   (the cleaned gateway title, else the id with a capital letter).
 * - The cashier is the logged-in user (or the staff session); the client's
 *   cashierId is not read.
 *
 * Not guaranteed:
 * - The cart is priced twice, once here for the single-tender amount and once
 *   by the checkout. A price that changes between the two is refused by the
 *   tender check, not charged.
 * - v2 rules the old handler lacked now apply: cashier limits and approvals,
 *   the card terminal reference, stock checks and the available tenders.
 *   Their refusal text is the v2 text.
 * - Text longer than 200 characters (custom line and discount names) is cut.
 *
 * @package Xophz_Compass_Bazaar
 */
class Xophz_Compass_Bazaar_Checkout_Legacy_Adapter {

  const MSG_EMPTY = 'Cart is empty.';
  const MSG_SPLIT = 'Split payment sum does not match order total.';

  /**
   * @param object $args The decoded request (Core_Request::legacy_args()).
   * @return array The JSON payload to send.
   */
  public static function handle( object $args ): array {
    $tenders = [];
    try {
      $cart = self::cart( $args );
      if ( empty( $cart['lines'] ) ) {
        return self::failure( self::MSG_EMPTY );
      }
      if ( ! Xophz_Compass_Bazaar_Checkout_Service::enabled() ) {
        return self::failure( 'Checkout v2 is turned off on this site.' );
      }
      $context = Xophz_Compass_Bazaar_Security_Guard::context();
      $priced  = ( new Xophz_Compass_Bazaar_Pricing_Pricer() )->price( Xophz_Compass_Bazaar_Pricing_Cart_Request::from_array( $cart ), $context );
      $mapped = self::tenders( $args, $priced->total_minor() );
      if ( null === $mapped ) {
        return self::failure( self::MSG_SPLIT );
      }
      $tenders = $mapped;
      $request = Xophz_Compass_Bazaar_Checkout_Request::from_array( [
        'client_key' => self::key(),
        'cart'       => $cart,
        'tenders'    => $tenders,
      ] );
      $result = ( new Xophz_Compass_Bazaar_Checkout_Service() )->submit( $request, $context );
      return self::answer( (array) ( $result['body'] ?? [] ) );
    } catch ( Xophz_Compass_Bazaar_Core_Http_Error $e ) {
      return self::failure( self::message( $e, $tenders ) );
    } catch ( \Throwable $e ) {
      return self::failure( $e->getMessage() );
    }
  }

  /** The Checkout_Request cart for the legacy fields. Pure. */
  public static function cart( object $args ): array {
    $R     = 'Xophz_Compass_Bazaar_Core_Request';
    $lines = [];
    foreach ( $R::array_param( $args, 'items' ) as $item ) {
      $line = self::line( is_array( $item ) ? $item : [] );
      if ( null !== $line ) {
        $lines[] = $line;
      }
    }

    $discounts = [];
    $flat      = self::number( $args->discount ?? 0 );
    if ( $flat > 0 ) {
      $discounts[] = [ 'name' => 'POS Discount', 'type' => 'fixed', 'amount_minor' => self::minor( $flat ) ];
    }
    foreach ( $R::array_param( $args, 'customDiscounts' ) as $discount ) {
      $mapped = self::discount( is_array( $discount ) ? $discount : [] );
      if ( null !== $mapped ) {
        $discounts[] = $mapped;
      }
    }

    $coupons = [];
    foreach ( $R::array_param( $args, 'appliedCoupons' ) as $code ) {
      if ( is_string( $code ) && '' !== trim( $code ) ) {
        $coupons[] = trim( $code );
      }
    }

    $email = self::text( $args->customerEmail ?? '' );
    return [
      'lines'     => $lines,
      'coupons'   => array_values( array_unique( $coupons ) ),
      'discounts' => $discounts,
      'tip_minor' => self::minor( max( 0.0, self::number( $args->tipAmount ?? 0 ) ) ),
      'customer'  => [
        'id'               => max( 0, (int) ( $args->customerId ?? 0 ) ),
        'email'            => false === filter_var( $email, FILTER_VALIDATE_EMAIL ) ? '' : $email,
        'phone'            => self::text( $args->customerPhone ?? '' ),
        'name'             => self::text( $args->customerName ?? '' ),
        'create_account'   => $R::bool_param( $args, 'createAccount' ),
        'marketing_opt_in' => $R::bool_param( $args, 'marketingOptIn' ),
        'sms_opt_in'       => $R::bool_param( $args, 'smsOptIn' ),
      ],
    ];
  }

  /**
   * The tender list for the legacy payment fields. A single tender gets
   * $total_minor. Null when split legs do not add up to it. Pure.
   *
   * @return array[]|null
   */
  public static function tenders( object $args, int $total_minor ): ?array {
    $R      = 'Xophz_Compass_Bazaar_Core_Request';
    $method = self::method( $args->paymentMethod ?? 'cash' );
    $token  = self::text( $args->authorizationToken ?? '' );
    if ( 'bazaar_split' !== $method ) {
      return [ self::tender( $method, $total_minor, [
        'reference'     => $args->terminalReference ?? '',
        'cardBrand'     => $args->cardBrand ?? '',
        'cardLast4'     => $args->cardLast4 ?? '',
        'authorization' => $token,
      ] ) ];
    }

    $legs = [];
    $sum  = 0;
    foreach ( $R::array_param( $args, 'splitPayments' ) as $leg ) {
      $leg    = is_array( $leg ) ? $leg : [];
      $amount = self::minor( self::number( $leg['amount'] ?? 0 ) );
      if ( 0 === $amount ) {
        continue;
      }
      $sum   += $amount;
      $legs[] = self::tender( self::method( $leg['method'] ?? '' ), $amount, [
        'reference'     => $leg['reference'] ?? '',
        'cardBrand'     => $leg['cardBrand'] ?? '',
        'cardLast4'     => $leg['cardLast4'] ?? '',
        'authorization' => self::text( $leg['authorizationToken'] ?? '' ) ?: $token,
      ] );
    }
    return $sum === $total_minor ? $legs : null;
  }

  /** legacy:<uuid4>, new on every call. */
  public static function key(): string {
    $uuid = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : self::uuid4();
    return 'legacy:' . strtolower( $uuid );
  }

  /** @return array|null A cart line, or null for an entry the old handler also skipped. */
  private static function line( array $item ): ?array {
    $product_id = (int) ( $item['product_id'] ?? 0 );
    $quantity   = (int) ( $item['quantity'] ?? 0 );
    if ( $quantity <= 0 || 0 === $product_id ) {
      return null;
    }
    if ( $product_id > 0 ) {
      return [ 'type' => 'product', 'product_id' => $product_id, 'variation_id' => max( 0, (int) ( $item['variation_id'] ?? 0 ) ), 'quantity' => $quantity ];
    }
    $name  = self::text( $item['title'] ?? ( $item['name'] ?? '' ) );
    $price = $item['price'] ?? ( $item['unit_price'] ?? ( $item['unitPrice'] ?? null ) );
    if ( '' === $name || ! is_numeric( $price ) || (float) $price < 0 ) {
      return null;
    }
    return [
      'type'             => 'custom',
      'name'             => $name,
      'unit_price_minor' => self::minor( (float) $price ),
      'quantity'         => $quantity,
      'taxable'          => ! array_key_exists( 'taxable', $item ) || filter_var( $item['taxable'], FILTER_VALIDATE_BOOLEAN ),
    ];
  }

  /** percent becomes value_bp = amount x 100 (at most 100%); anything else is a fixed amount. */
  private static function discount( array $discount ): ?array {
    $amount = self::number( $discount['amount'] ?? 0 );
    if ( $amount <= 0 ) {
      return null;
    }
    $name = self::text( $discount['name'] ?? '' );
    $name = '' !== $name ? $name : 'Custom Discount';
    $type = strtolower( (string) ( $discount['type'] ?? '' ) );
    if ( 'percent' === $type || 'percentage' === $type ) {
      $bp = (int) round( min( $amount, 100.0 ) * 100 );
      return $bp > 0 ? [ 'name' => $name, 'type' => 'percent', 'value_bp' => $bp ] : null;
    }
    $minor = self::minor( $amount );
    return $minor > 0 ? [ 'name' => $name, 'type' => 'fixed', 'amount_minor' => $minor ] : null;
  }

  /** @param array{reference:mixed,cardBrand:mixed,cardLast4:mixed,authorization:string} $extra */
  private static function tender( string $method, int $amount_minor, array $extra ): array {
    $brand = strtolower( self::text( $extra['cardBrand'] ) );
    $last4 = self::text( $extra['cardLast4'] );
    return [
      'method_id'           => $method,
      'amount_minor'        => $amount_minor,
      'tendered_minor'      => 'cash' === $method ? $amount_minor : null,
      'reference'           => self::text( $extra['reference'] ),
      'card_brand'          => '' === $brand ? '' : ( in_array( $brand, Xophz_Compass_Bazaar_Payments_Tender::CARD_BRANDS, true ) ? $brand : 'other' ),
      'card_last4'          => 1 === preg_match( '/^[0-9]{4}$/', $last4 ) ? $last4 : '',
      'authorization_token' => $extra['authorization'],
    ];
  }

  private static function answer( array $body ): array {
    $order_id = (int) ( $body['order']['id'] ?? 0 );
    if ( $order_id > 0 ) {
      return [ 'success' => true, 'order_id' => $order_id, 'order_key' => (string) ( $body['order']['key'] ?? '' ) ];
    }
    $message = (string) ( $body['message'] ?? ( $body['error']['message'] ?? '' ) );
    return self::failure( '' !== $message ? $message : 'The sale was not completed.' );
  }

  /** @param array[] $tenders The tenders that were sent, to name the one a refusal is about. */
  private static function message( Xophz_Compass_Bazaar_Core_Http_Error $e, array $tenders ): string {
    if ( 'tender_total_mismatch' === $e->error_code() ) {
      return self::MSG_SPLIT;
    }
    if ( 'payment_not_authorized' === $e->error_code() && isset( $e->data()['reason'] ) ) {
      $method = (string) ( $tenders[ (int) ( $e->data()['tender'] ?? 0 ) ]['method_id'] ?? '' );
      return sprintf( 'Payment not authorized (%s). %s was not charged and the sale was not recorded.', (string) $e->data()['reason'], self::title( $method ) );
    }
    if ( 'invalid_request' === $e->error_code() ) {
      $first = (array) ( $e->data()['errors'][0] ?? [] );
      return isset( $first['path'] ) ? sprintf( 'The sale is not valid: %s %s', $first['path'], (string) ( $first['message'] ?? '' ) ) : $e->getMessage();
    }
    return $e->getMessage();
  }

  /** The method name the old handler put in its refusal: the cleaned gateway title, else the id with a capital. */
  private static function title( string $method ): string {
    if ( function_exists( 'WC' ) && WC() && WC()->payment_gateways() ) {
      $gateways = WC()->payment_gateways()->payment_gateways();
      if ( isset( $gateways[ $method ] ) ) {
        $gateway = $gateways[ $method ];
        return Xophz_Compass_Bazaar_Admin_Payment_Settings::clean_payment_title( $gateway->title ?: $gateway->get_method_title(), $method );
      }
    }
    return ucfirst( $method );
  }

  private static function failure( string $message ): array {
    return [ 'success' => false, 'message' => $message ];
  }

  /** A lowercase payment method id; empty stays empty. */
  private static function method( $value ): string {
    return strtolower( self::text( $value ) );
  }

  /** A trimmed single-line string of at most 200 characters; non-scalars give ''. */
  private static function text( $value ): string {
    if ( ! is_scalar( $value ) ) {
      return '';
    }
    $text = trim( function_exists( 'sanitize_text_field' ) ? sanitize_text_field( (string) $value ) : trim( strip_tags( (string) $value ) ) );
    return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, 200 ) : substr( $text, 0, 200 );
  }

  /** A finite float; anything else is 0. */
  private static function number( $value ): float {
    return is_numeric( $value ) && is_finite( (float) $value ) ? (float) $value : 0.0;
  }

  private static function minor( float $amount ): int {
    try {
      return Xophz_Compass_Bazaar_Core_Money::to_minor( $amount );
    } catch ( \InvalidArgumentException $e ) {
      return 0;
    }
  }

  private static function uuid4(): string {
    $b    = random_bytes( 16 );
    $b[6] = chr( ( ord( $b[6] ) & 0x0f ) | 0x40 );
    $b[8] = chr( ( ord( $b[8] ) & 0x3f ) | 0x80 );
    return vsprintf( '%s%s-%s-%s-%s-%s%s%s', str_split( bin2hex( $b ), 4 ) );
  }
}
