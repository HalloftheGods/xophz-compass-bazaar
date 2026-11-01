<?php
/**
 * WooCommerce coupon management handlers for the Bazaar coupons screen.
 *
 * Split out of Admin_Orders (#6946). The three action names are unchanged.
 * Per docs/plugins/bazaar-core-v2.md section 6.3 they need bazaar_manage_catalog.
 *
 * @package    Xophz_Compass_Bazaar
 * @subpackage Xophz_Compass_Bazaar/admin
 */
class Xophz_Compass_Bazaar_Admin_Coupons {

  /** @var string */
  private $plugin_name;

  /** @var string */
  private $version;

  public $action_hooks = [
    'wp_ajax_bazaar_get_coupons'   => 'getCoupons',
    'wp_ajax_bazaar_save_coupon'   => 'saveCoupon',
    'wp_ajax_bazaar_delete_coupon' => 'deleteCoupon',
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

  /** @throws \Exception 'Permission denied.' when the user holds none of the caps. */
  private function require_any( string ...$caps ): void {
    if ( ! Xophz_Compass_Bazaar_Security_Guard::can_any( ...$caps ) ) {
      throw new \Exception( 'Permission denied.' );
    }
  }

  public function getCoupons() {
    try {
      $this->require_any( 'bazaar_manage_catalog' );

      if ( ! class_exists( 'WooCommerce' ) ) {
        $this->send_json_response( [ 'success' => false, 'message' => 'WooCommerce is not active.', 'coupons' => [] ] );
        return;
      }

      $args   = Xophz_Compass_Bazaar_Core_Request::legacy_args();
      $search = isset( $args->search ) ? sanitize_text_field( $args->search ) : '';

      $query_args = [
        'post_type'      => 'shop_coupon',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'orderby'        => 'date',
        'order'          => 'DESC',
      ];

      if ( ! empty( $search ) ) {
        $query_args['s'] = $search;
      }

      $coupons = [];

      foreach ( get_posts( $query_args ) as $post ) {
        try {
          $coupon = new WC_Coupon( $post->ID );
          if ( ! $coupon || ! $coupon->get_id() ) {
            continue;
          }

          $expiry    = $coupon->get_date_expires();
          $coupons[] = [
            'id'                     => $coupon->get_id(),
            'code'                   => $coupon->get_code(),
            'description'            => $coupon->get_description(),
            'discount_type'          => $coupon->get_discount_type(),
            'amount'                 => floatval( $coupon->get_amount() ),
            'date_expires'           => $expiry ? $expiry->date( 'Y-m-d' ) : null,
            'usage_count'            => (int) $coupon->get_usage_count(),
            'usage_limit'            => (int) $coupon->get_usage_limit(),
            'usage_limit_per_user'   => (int) $coupon->get_usage_limit_per_user(),
            'limit_usage_to_x_items' => (int) $coupon->get_limit_usage_to_x_items(),
            'free_shipping'          => (bool) $coupon->get_free_shipping(),
            'minimum_amount'         => floatval( $coupon->get_minimum_amount() ),
            'maximum_amount'         => floatval( $coupon->get_maximum_amount() ),
            'individual_use'         => (bool) $coupon->get_individual_use(),
            'exclude_sale_items'     => (bool) $coupon->get_exclude_sale_items(),
          ];
        } catch ( \Throwable $ex ) {
          continue;
        }
      }

      $this->send_json_response( [ 'success' => true, 'coupons' => $coupons ] );
    } catch ( \Throwable $e ) {
      $this->send_json_response( [ 'success' => false, 'message' => $e->getMessage(), 'coupons' => [] ] );
    }
  }

  public function saveCoupon() {
    try {
      $this->require_any( 'bazaar_manage_catalog' );

      if ( ! class_exists( 'WooCommerce' ) ) {
        $this->send_json_response( [ 'success' => false, 'message' => 'WooCommerce is not active.' ] );
        return;
      }

      $args = Xophz_Compass_Bazaar_Core_Request::legacy_args();
      $id   = isset( $args->id ) ? intval( $args->id ) : 0;
      $code = isset( $args->code ) ? sanitize_text_field( $args->code ) : '';

      if ( empty( $code ) ) {
        $this->send_json_response( [ 'success' => false, 'message' => 'Coupon code is required.' ] );
        return;
      }

      $coupon = $id ? new WC_Coupon( $id ) : new WC_Coupon();

      $coupon->set_code( $code );
      if ( isset( $args->description ) ) {
        $coupon->set_description( sanitize_textarea_field( $args->description ) );
      }
      if ( isset( $args->discount_type ) ) {
        $coupon->set_discount_type( sanitize_text_field( $args->discount_type ) );
      }
      if ( isset( $args->amount ) ) {
        $coupon->set_amount( floatval( $args->amount ) );
      }
      if ( ! empty( $args->date_expires ) ) {
        $coupon->set_date_expires( sanitize_text_field( $args->date_expires ) );
      } else {
        $coupon->set_date_expires( null );
      }
      if ( isset( $args->usage_limit ) ) {
        $coupon->set_usage_limit( intval( $args->usage_limit ) );
      }
      if ( isset( $args->usage_limit_per_user ) ) {
        $coupon->set_usage_limit_per_user( intval( $args->usage_limit_per_user ) );
      }
      if ( isset( $args->free_shipping ) ) {
        $coupon->set_free_shipping( filter_var( $args->free_shipping, FILTER_VALIDATE_BOOLEAN ) );
      }
      if ( isset( $args->minimum_amount ) ) {
        $coupon->set_minimum_amount( floatval( $args->minimum_amount ) );
      }
      if ( isset( $args->maximum_amount ) ) {
        $coupon->set_maximum_amount( floatval( $args->maximum_amount ) );
      }
      if ( isset( $args->individual_use ) ) {
        $coupon->set_individual_use( filter_var( $args->individual_use, FILTER_VALIDATE_BOOLEAN ) );
      }
      if ( isset( $args->exclude_sale_items ) ) {
        $coupon->set_exclude_sale_items( filter_var( $args->exclude_sale_items, FILTER_VALIDATE_BOOLEAN ) );
      }

      $saved_id = $coupon->save();

      if ( is_wp_error( $saved_id ) ) {
        throw new \Exception( $saved_id->get_error_message() );
      }

      if ( $saved_id ) {
        $this->send_json_response( [
          'success' => true,
          'id'      => $saved_id,
          'message' => 'Coupon saved successfully.',
        ] );
      } else {
        $this->send_json_response( [ 'success' => false, 'message' => 'Failed to save coupon.' ] );
      }
    } catch ( \Throwable $e ) {
      $this->send_json_response( [ 'success' => false, 'message' => $e->getMessage() ] );
    }
  }

  public function deleteCoupon() {
    try {
      $this->require_any( 'bazaar_manage_catalog' );

      if ( ! class_exists( 'WooCommerce' ) ) {
        $this->send_json_response( [ 'success' => false, 'message' => 'WooCommerce is not active.' ] );
        return;
      }

      $args = Xophz_Compass_Bazaar_Core_Request::legacy_args();
      $id   = isset( $args->id ) ? intval( $args->id ) : 0;

      if ( ! $id ) {
        $this->send_json_response( [ 'success' => false, 'message' => 'Coupon ID is required.' ] );
        return;
      }

      $coupon = new WC_Coupon( $id );
      if ( ! $coupon->get_id() ) {
        $this->send_json_response( [ 'success' => false, 'message' => 'Coupon not found.' ] );
        return;
      }

      $coupon->delete( true );

      $this->send_json_response( [ 'success' => true, 'message' => 'Coupon deleted successfully.' ] );
    } catch ( \Throwable $e ) {
      $this->send_json_response( [ 'success' => false, 'message' => $e->getMessage() ] );
    }
  }
}
