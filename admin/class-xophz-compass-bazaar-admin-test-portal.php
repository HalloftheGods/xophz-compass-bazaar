<?php
/**
 * POS test payment portal: the approval popup and its one-time token endpoint.
 *
 * Split out of Admin_Orders (#6946). The two action names are unchanged.
 * No payment processor is connected; both handlers refuse unless the test
 * portal is enabled, and both keep Xophz_Compass_Security::enforce_spa_ajax
 * (nonce and capability) as their first step.
 *
 * @package    Xophz_Compass_Bazaar
 * @subpackage Xophz_Compass_Bazaar/admin
 */
class Xophz_Compass_Bazaar_Admin_Test_Portal {

  /** @var string */
  private $plugin_name;

  /** @var string */
  private $version;

  public $action_hooks = [
    'wp_ajax_bazaar_pos_gateway_portal'          => 'renderGatewayPortal',
    'wp_ajax_bazaar_pos_authorize_test_payment' => 'authorizeTestPayment',
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

  /**
   * Issue a one-time POS authorization token from the test portal.
   *
   * Input: gateway, amount. Only while the test portal is enabled; the token
   * is bound to the current cashier, gateway and amount, and createPosOrder
   * spends it.
   */
  public function authorizeTestPayment() {
    Xophz_Compass_Security::enforce_spa_ajax( 'bazaar_sell' );

    if ( ! Xophz_Compass_Bazaar_Pos_Authorization::is_test_portal_enabled() ) {
      wp_send_json( [ 'success' => false, 'code' => 'test_portal_disabled', 'message' => 'The POS test payment portal is turned off on this site.' ], 403 );
    }

    $args    = Xophz_Compass_Bazaar_Core_Request::legacy_args();
    $gateway = sanitize_key( (string) ( $args->gateway ?? '' ) );
    $amount  = (float) ( $args->amount ?? 0 );
    if ( '' === $gateway || $amount <= 0 ) {
      $this->send_json_response( [ 'success' => false, 'message' => 'A payment method and an amount above zero are required.' ] );
      return;
    }

    $this->send_json_response( [
      'success'            => true,
      'gatewayId'          => $gateway,
      'authorizationToken' => Xophz_Compass_Bazaar_Pos_Authorization::issue( get_current_user_id(), $gateway, $amount ),
    ] );
  }

  /**
   * Test-only approval page the digital payment dialog opens in a popup.
   *
   * No processor is connected, so it never takes card details: it asks the
   * server for a one-time authorization token and hands it to the register
   * window, addressed to this site's origin only.
   */
  public function renderGatewayPortal() {
    Xophz_Compass_Security::enforce_spa_ajax( 'bazaar_sell' );

    if ( ! Xophz_Compass_Bazaar_Pos_Authorization::is_test_portal_enabled() ) {
      wp_die( esc_html__( 'The POS test payment portal is turned off on this site. Take this payment with cash, a card terminal, or a bank transfer.', 'xophz-compass-bazaar' ), 403 );
    }

    $gateway_id = sanitize_key( (string) ( $_GET['gateway'] ?? '' ) );
    $amount     = isset( $_GET['amount'] ) ? (float) $_GET['amount'] : 0.0;

    $gateway_title = 'Digital Payment';
    if ( function_exists( 'WC' ) && WC()->payment_gateways() ) {
      $all = WC()->payment_gateways()->payment_gateways();
      if ( isset( $all[ $gateway_id ] ) ) {
        $gw            = $all[ $gateway_id ];
        $gateway_title = Xophz_Compass_Bazaar_Admin_Payment_Settings::clean_payment_title( $gw->title ?: $gw->get_method_title(), $gateway_id );
      }
    }

    $admin_parts = wp_parse_url( admin_url() );
    $origin      = $admin_parts['scheme'] . '://' . $admin_parts['host'] . ( isset( $admin_parts['port'] ) ? ':' . $admin_parts['port'] : '' );

    header( 'Content-Type: text/html; charset=UTF-8' );
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
      <meta charset="UTF-8">
      <meta name="viewport" content="width=device-width, initial-scale=1.0">
      <title><?php echo esc_html( $gateway_title ); ?> - Test Payment Portal</title>
      <style>
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
        body { background: #081224; color: #fff; display: flex; align-items: center; justify-content: center; min-height: 100vh; padding: 20px; }
        .portal-card { background: rgba(15, 25, 45, 0.95); border: 1px solid rgba(255,255,255,0.18); border-radius: 20px; width: 100%; max-width: 440px; padding: 28px; }
        .test-badge { background: rgba(255, 159, 67, 0.15); border: 1px solid #ff9f43; color: #ff9f43; border-radius: 10px; padding: 10px 12px; font-size: 13px; font-weight: 700; margin-bottom: 18px; }
        .amount-val { font-size: 34px; font-weight: 800; color: #28c76f; text-align: center; margin: 18px 0 22px; }
        .btn-submit { width: 100%; background: #28c76f; color: #fff; border: none; padding: 14px; border-radius: 12px; font-size: 16px; font-weight: 700; cursor: pointer; }
        .btn-submit:disabled { opacity: 0.6; cursor: wait; }
        .status { font-size: 13px; color: #cbd5e1; margin-top: 14px; text-align: center; min-height: 18px; }
      </style>
    </head>
    <body>
      <div class="portal-card">
        <div class="test-badge">TEST MODE: no payment processor is connected and no money moves.</div>
        <h2 style="font-size: 20px; font-weight: 700;"><?php echo esc_html( $gateway_title ); ?></h2>
        <div class="amount-val">$<?php echo esc_html( number_format( $amount, 2 ) ); ?></div>
        <button type="button" class="btn-submit" id="approve">Approve test payment</button>
        <div class="status" id="status"></div>
      </div>
      <script>
        (function () {
          var ORIGIN = <?php echo wp_json_encode( $origin ); ?>;
          var GATEWAY = <?php echo wp_json_encode( $gateway_id ); ?>;
          var AMOUNT = <?php echo wp_json_encode( $amount ); ?>;
          var AJAX = <?php echo wp_json_encode( admin_url( 'admin-ajax.php?action=bazaar_pos_authorize_test_payment' ) ); ?>;
          var NONCE = <?php echo wp_json_encode( wp_create_nonce( 'wp_rest' ) ); ?>;
          var button = document.getElementById('approve');
          var status = document.getElementById('status');

          button.addEventListener('click', function () {
            button.disabled = true;
            status.textContent = 'Requesting authorization...';
            var body = new URLSearchParams({ gateway: GATEWAY, amount: String(AMOUNT) });
            fetch(AJAX, { method: 'POST', credentials: 'same-origin', headers: { 'X-WP-Nonce': NONCE }, body: body })
              .then(function (response) { return response.json(); })
              .then(function (reply) {
                if (!reply || reply.success !== true || !reply.authorizationToken) {
                  throw new Error((reply && reply.message) || 'Authorization was refused.');
                }
                if (!window.opener) {
                  throw new Error('The register window is gone. Start the payment again.');
                }
                window.opener.postMessage({ status: 'approved', gatewayId: GATEWAY, authorizationToken: reply.authorizationToken }, ORIGIN);
                status.textContent = 'Approved. You can close this window.';
                setTimeout(function () { window.close(); }, 800);
              })
              .catch(function (error) {
                button.disabled = false;
                status.textContent = error.message;
              });
          });
        })();
      </script>
    </body>
    </html>
    <?php
    exit;
  }
}
