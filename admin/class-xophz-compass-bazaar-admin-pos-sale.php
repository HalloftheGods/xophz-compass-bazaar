<?php
/**
 * POS sale handlers: create the order and validate a coupon.
 *
 * Split out of Admin_Orders (#6946). The two action names are unchanged.
 *
 * Guaranteed: create_pos_order keeps its permission check and its answer,
 * {success:true, order_id, order_key} or {success:false, message}, sent as
 * before. It reads the request through Core_Request::legacy_args() and routes
 * with route(): Checkout_Legacy_Writer, the old body kept for one release as
 * the rollback, when the option bazaar_checkout_pipeline is "legacy" OR
 * checkout v2 is off (bazaar_pos_checkout_v2=no, which also puts the SPA on
 * this action, spec 17); else Checkout_Legacy_Adapter (also when the
 * pipeline option is unset or holds anything else).
 *
 * Not guaranteed: the v2 route applies checkout v2 rules the old handler did
 * not (see Checkout_Legacy_Adapter). The legacy route is the old behavior: it
 * creates the order before validating, trusts the prices WooCommerce holds and
 * deletes the order when a step fails.
 *
 * @package    Xophz_Compass_Bazaar
 * @subpackage Xophz_Compass_Bazaar/admin
 */
class Xophz_Compass_Bazaar_Admin_Pos_Sale {

  const PIPELINE_OPTION = 'bazaar_checkout_pipeline';

  /** @var string */
  private $plugin_name;

  /** @var string */
  private $version;

  public $action_hooks = [
    'wp_ajax_create_pos_order'    => 'createPosOrder',
    'wp_ajax_validate_pos_coupon' => 'validatePosCoupon',
  ];

  public function __construct( $plugin_name, $version ) {
    $this->plugin_name = $plugin_name;
    $this->version     = $version;
  }

  /** Clean active output buffers and emit the JSON payload. */
  private function send_json_response( $payload ) {
    while ( ob_get_level() > 0 ) {
      @ob_end_clean();
    }
    Xophz_Compass::output_json( $payload );
  }

  public function validatePosCoupon() {
    try {
      if ( ! Xophz_Compass_Bazaar_Security_Guard::can_any( 'bazaar_sell' ) ) {
        throw new \Exception( 'Permission denied.' );
      }
      $args = Xophz_Compass_Bazaar_Core_Request::legacy_args();
      $code = isset( $args->code ) ? sanitize_text_field( $args->code ) : '';

      if ( ! $code ) {
        $this->send_json_response( [ 'success' => false, 'message' => 'Coupon code is required.' ] );
        return;
      }

      $coupon = new WC_Coupon( $code );
      if ( ! $coupon->get_id() || ! $coupon->is_valid() ) {
        $this->send_json_response( [ 'success' => false, 'message' => 'Invalid or expired coupon code.' ] );
        return;
      }

      $this->send_json_response( [
        'success' => true,
        'code'    => $coupon->get_code(),
        'type'    => $coupon->get_discount_type(),
        'amount'  => $coupon->get_amount(),
      ] );
    } catch ( \Throwable $e ) {
      $this->send_json_response( [ 'success' => false, 'message' => $e->getMessage() ] );
    }
  }

  /**
   * The amount of one custom discount. A percent discount is that percent
   * (capped at 100) of the item subtotal; anything else is a money amount.
   * Used by the legacy writer.
   *
   * @param array $discount     One customDiscounts entry.
   * @param float $item_subtotal Sum of the product lines before discounts.
   */
  public static function custom_discount_amount( array $discount, float $item_subtotal ): float {
    $amount = isset( $discount['amount'] ) ? (float) $discount['amount'] : 0.0;
    if ( $amount <= 0 ) {
      return 0.0;
    }
    $type = strtolower( (string) ( $discount['type'] ?? '' ) );
    if ( 'percent' === $type || 'percentage' === $type ) {
      return round( $item_subtotal * min( $amount, 100.0 ) / 100, 2 );
    }
    return $amount;
  }

  /**
   * Pure. "legacy" when the pipeline option says so or checkout v2 is off
   * (either rollback switch brings the old body back); every other case is v2.
   *
   * @param mixed $option The bazaar_checkout_pipeline value.
   */
  public static function route( $option, bool $v2_enabled ): string {
    if ( ! $v2_enabled ) {
      return 'legacy';
    }
    return 'legacy' === ( is_string( $option ) ? strtolower( trim( $option ) ) : '' ) ? 'legacy' : 'v2';
  }

  /** route() for this site's options. */
  public static function pipeline(): string {
    return self::route( get_option( self::PIPELINE_OPTION, 'v2' ), Xophz_Compass_Bazaar_Checkout_Service::enabled() );
  }

  public function createPosOrder() {
    try {
      if ( ! Xophz_Compass_Bazaar_Security_Guard::can_any( 'bazaar_sell' ) ) {
        throw new \Exception( 'Permission denied.' );
      }
      $args = Xophz_Compass_Bazaar_Core_Request::legacy_args();
      $this->send_json_response(
        'legacy' === self::pipeline()
          ? Xophz_Compass_Bazaar_Checkout_Legacy_Writer::run( $args )
          : Xophz_Compass_Bazaar_Checkout_Legacy_Adapter::handle( $args )
      );
    } catch ( \Throwable $e ) {
      $this->send_json_response( [ 'success' => false, 'message' => $e->getMessage() ] );
    }
  }
}
