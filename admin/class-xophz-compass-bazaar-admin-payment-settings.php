<?php
/**
 * Payment gateway and bank detail handlers for the Bazaar register and settings.
 *
 * Split out of Admin_Orders (#6946). The five action names are unchanged.
 * Caps follow docs/plugins/bazaar-core-v2.md section 6.3; the central guard
 * checks them first and these in-handler checks repeat them.
 *
 * @package    Xophz_Compass_Bazaar
 * @subpackage Xophz_Compass_Bazaar/admin
 */
class Xophz_Compass_Bazaar_Admin_Payment_Settings {

  /** @var string */
  private $plugin_name;

  /** @var string */
  private $version;

  public $action_hooks = [
    'wp_ajax_get_payment_gateways'     => 'getPaymentGateways',
    'wp_ajax_get_all_payment_gateways' => 'getAllPaymentGateways',
    'wp_ajax_toggle_payment_gateway'   => 'togglePaymentGateway',
    'wp_ajax_bazaar_get_bank_details'  => 'getBankDetails',
    'wp_ajax_bazaar_save_bank_details' => 'saveBankDetails',
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

  /**
   * Shorten a WooPayments title for the register.
   *
   * @param string $raw_title  Gateway title.
   * @param string $gateway_id Gateway id (unused, kept for callers).
   * @return string
   */
  public static function clean_payment_title( $raw_title, $gateway_id = '' ) {
    $title = trim( (string) $raw_title );

    if ( preg_match( '/WooPayments\s*\(([^)]+)\)/i', $title, $matches ) ) {
      $inner = trim( $matches[1] );
      if ( strcasecmp( $inner, 'Card' ) === 0 ) {
        return 'Credit Card';
      }
      return $inner;
    }

    if ( strcasecmp( $title, 'WooPayments' ) === 0 ) {
      return 'Credit Card';
    }

    return $title;
  }

  /** @return object[]|WP_Error The WooCommerce gateway objects keyed by id. */
  private function gateways() {
    $gateways_obj = WC()->payment_gateways();
    if ( ! $gateways_obj ) {
      throw new \Exception( 'Payment gateways unavailable.' );
    }
    return $gateways_obj->payment_gateways();
  }

  public function getPaymentGateways() {
    try {
      $this->require_any( 'bazaar_sell' );

      if ( ! function_exists( 'WC' ) ) {
        $this->send_json_response( [ 'success' => true, 'gateways' => [] ] );
        return;
      }

      $gateways = $this->gateways();
      if ( is_wp_error( $gateways ) ) {
        throw new \Exception( $gateways->get_error_message() );
      }
      $data = [];

      foreach ( $gateways as $gateway ) {
        if ( $gateway->enabled === 'yes' ) {
          $raw_title = $gateway->title ?: $gateway->get_method_title();
          $data[]    = [
            'id'           => $gateway->id,
            'title'        => html_entity_decode( self::clean_payment_title( $raw_title, $gateway->id ), ENT_QUOTES, 'UTF-8' ),
            'method_title' => html_entity_decode( $gateway->get_method_title(), ENT_QUOTES, 'UTF-8' ),
          ];
        }
      }

      $this->send_json_response( [ 'success' => true, 'gateways' => array_values( $data ) ] );
    } catch ( \Throwable $e ) {
      $this->send_json_response( [ 'success' => false, 'message' => $e->getMessage(), 'gateways' => [] ] );
    }
  }

  public function getAllPaymentGateways() {
    try {
      $this->require_any( 'bazaar_manage_settings' );

      if ( ! function_exists( 'WC' ) ) {
        $this->send_json_response( [ 'success' => false, 'gateways' => [] ] );
        return;
      }

      $gateways = $this->gateways();
      if ( is_wp_error( $gateways ) ) {
        throw new \Exception( $gateways->get_error_message() );
      }
      $data = [];

      foreach ( $gateways as $gateway ) {
        $is_enabled  = ( $gateway->enabled === 'yes' );
        $needs_setup = false;

        // Detect credential requirements (e.g. Stripe)
        if ( $gateway->id === 'stripe' || strpos( $gateway->id, 'stripe' ) !== false ) {
          $secret_key = $gateway->get_option( 'secret_key' ) ?: $gateway->get_option( 'test_secret_key' );
          if ( empty( $secret_key ) ) {
            $needs_setup = true;
          }
        }

        $raw_title = $gateway->title ?: $gateway->get_method_title();

        $data[] = [
          'id'           => $gateway->id,
          'title'        => self::clean_payment_title( $raw_title, $gateway->id ),
          'method_title' => $gateway->get_method_title(),
          'description'  => $gateway->description ?: '',
          'enabled'      => $is_enabled,
          'needs_setup'  => $needs_setup,
        ];
      }

      $this->send_json_response( [ 'success' => true, 'gateways' => array_values( $data ) ] );
    } catch ( \Throwable $e ) {
      $this->send_json_response( [ 'success' => false, 'message' => $e->getMessage(), 'gateways' => [] ] );
    }
  }

  public function togglePaymentGateway() {
    try {
      $this->require_any( 'bazaar_manage_settings' );

      if ( ! function_exists( 'WC' ) ) {
        $this->send_json_response( [ 'success' => false, 'message' => 'WooCommerce is not active.' ] );
        return;
      }

      $args       = Xophz_Compass_Bazaar_Core_Request::legacy_args();
      $gateway_id = isset( $args->gateway_id ) ? sanitize_text_field( $args->gateway_id ) : '';
      $enabled    = Xophz_Compass_Bazaar_Core_Request::bool_param( $args, 'enabled' );

      if ( ! $gateway_id ) {
        $this->send_json_response( [ 'success' => false, 'message' => 'Gateway ID is required.' ] );
        return;
      }

      $gateways_obj = WC()->payment_gateways();
      if ( ! $gateways_obj ) {
        throw new \Exception( 'Payment gateways unavailable.' );
      }
      $gateways = $gateways_obj->payment_gateways();
      if ( ! isset( $gateways[ $gateway_id ] ) ) {
        $this->send_json_response( [ 'success' => false, 'message' => 'Payment gateway not found.' ] );
        return;
      }

      $gateways[ $gateway_id ]->update_option( 'enabled', $enabled ? 'yes' : 'no' );

      // Re-initialize payment gateways
      $gateways_obj->init();

      $this->send_json_response( [
        'success'    => true,
        'gateway_id' => $gateway_id,
        'enabled'    => $enabled,
      ] );
    } catch ( \Throwable $e ) {
      $this->send_json_response( [ 'success' => false, 'message' => $e->getMessage() ] );
    }
  }

  public function getBankDetails() {
    try {
      $this->require_any( 'bazaar_sell', 'bazaar_manage_settings' );

      $accounts = get_option( 'woocommerce_bacs_accounts', [] );
      $account  = ! empty( $accounts[0] ) ? $accounts[0] : [];

      $bank_name   = ! empty( $account['bank_name'] ) ? $account['bank_name'] : get_option( 'bazaar_bank_name', '' );
      $routing     = ! empty( $account['sort_code'] ) ? $account['sort_code'] : get_option( 'bazaar_bank_routing', '' );
      $account_num = ! empty( $account['account_number'] ) ? $account['account_number'] : get_option( 'bazaar_bank_account', '' );
      $iban        = ! empty( $account['iban'] ) ? $account['iban'] : get_option( 'bazaar_bank_iban', '' );

      $this->send_json_response( [
        'success'   => true,
        'bank_name' => $bank_name,
        'routing'   => $routing,
        'account'   => $account_num,
        'iban'      => $iban,
      ] );
    } catch ( \Throwable $e ) {
      $this->send_json_response( [ 'success' => false, 'message' => $e->getMessage() ] );
    }
  }

  public function saveBankDetails() {
    try {
      $this->require_any( 'bazaar_manage_settings' );

      $args      = Xophz_Compass_Bazaar_Core_Request::legacy_args();
      $bank_name = isset( $args->bank_name ) ? sanitize_text_field( $args->bank_name ) : '';
      $routing   = isset( $args->routing ) ? sanitize_text_field( $args->routing ) : '';
      $account   = isset( $args->account ) ? sanitize_text_field( $args->account ) : '';
      $iban      = isset( $args->iban ) ? sanitize_text_field( $args->iban ) : '';

      $bacs_account = [
        'account_name'   => get_bloginfo( 'name' ),
        'account_number' => $account,
        'bank_name'      => $bank_name,
        'sort_code'      => $routing,
        'iban'           => $iban,
        'bic'            => '',
      ];

      update_option( 'woocommerce_bacs_accounts', [ $bacs_account ] );
      update_option( 'bazaar_bank_name', $bank_name );
      update_option( 'bazaar_bank_routing', $routing );
      update_option( 'bazaar_bank_account', $account );
      update_option( 'bazaar_bank_iban', $iban );

      $this->send_json_response( [
        'success'   => true,
        'message'   => 'Bank details saved successfully.',
        'bank_name' => $bank_name,
        'routing'   => $routing,
        'account'   => $account,
        'iban'      => $iban,
      ] );
    } catch ( \Throwable $e ) {
      $this->send_json_response( [ 'success' => false, 'message' => $e->getMessage() ] );
    }
  }
}
