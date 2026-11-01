<?php
/**
 * POS shift summary email handler.
 *
 * Split out of Admin_Orders (#6946). The action name is unchanged. The figures
 * in the email are the ones the register sends; the server does not recompute
 * them (the server-side shift summary arrives with the payments repository).
 *
 * @package    Xophz_Compass_Bazaar
 * @subpackage Xophz_Compass_Bazaar/admin
 */
class Xophz_Compass_Bazaar_Admin_Pos_Shift {

  /** @var string */
  private $plugin_name;

  /** @var string */
  private $version;

  public $action_hooks = [
    'wp_ajax_email_shift_summary' => 'emailShiftSummary',
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

  public function emailShiftSummary() {
    try {
      if ( ! Xophz_Compass_Bazaar_Security_Guard::can_any( 'bazaar_sell' ) ) {
        throw new \Exception( 'Permission denied.' );
      }
      $args         = Xophz_Compass_Bazaar_Core_Request::legacy_args();
      $current_user = wp_get_current_user();

      if ( ! $current_user || ! $current_user->exists() ) {
        $this->send_json_response( [ 'success' => false, 'message' => 'Not authenticated.' ] );
        return;
      }

      $cash            = isset( $args->cash ) ? floatval( $args->cash ) : 0;
      $card            = isset( $args->card ) ? floatval( $args->card ) : 0;
      $coupons         = isset( $args->coupons ) ? floatval( $args->coupons ) : 0;
      $customDiscounts = isset( $args->customDiscounts ) ? floatval( $args->customDiscounts ) : 0;
      $totalTips       = isset( $args->totalTips ) ? floatval( $args->totalTips ) : 0;
      $totalOrders     = isset( $args->totalOrders ) ? intval( $args->totalOrders ) : 0;
      $totalSales      = $cash + $card;

      $site_name = get_bloginfo( 'name' );
      $site_html = esc_html( $site_name );
      $date_str  = current_time( 'M d, Y, h:i A' );

      $html = "
      <div style='font-family: sans-serif; max-width: 500px; margin: 0 auto; padding: 20px; border: 1px solid #eaeaea; border-radius: 8px;'>
          <h2 style='text-align: center; color: #333;'>$site_html - POS Shift Summary</h2>
          <p style='text-align: center; color: #666; font-size: 14px;'>Report generated: $date_str</p>
          <hr style='border: none; border-top: 1px solid #eaeaea; margin: 20px 0;'>
          <table style='width: 100%; border-collapse: collapse;'>
              <tr><td style='padding: 8px 0; color: #555;'>Total Orders:</td><td style='text-align: right; font-weight: bold;'>$totalOrders</td></tr>
              <tr><td style='padding: 8px 0; color: #555;'>Cash Tended:</td><td style='text-align: right; font-weight: bold; color: #28a745;'>$" . number_format( $cash, 2 ) . "</td></tr>
              <tr><td style='padding: 8px 0; color: #555;'>Card/Electronic:</td><td style='text-align: right; font-weight: bold; color: #007bff;'>$" . number_format( $card, 2 ) . "</td></tr>
              <tr><td style='padding: 8px 0; color: #555;'>Tips Collected:</td><td style='text-align: right; font-weight: bold; color: #20c997;'>+$" . number_format( $totalTips, 2 ) . "</td></tr>
              <tr><td style='padding: 8px 0; color: #555;'>Coupons Applied:</td><td style='text-align: right; font-weight: bold; color: #ffc107;'>-$" . number_format( $coupons, 2 ) . "</td></tr>
              <tr><td style='padding: 8px 0; color: #555;'>Custom Discounts:</td><td style='text-align: right; font-weight: bold; color: #ffc107;'>-$" . number_format( $customDiscounts, 2 ) . "</td></tr>
          </table>
          <hr style='border: none; border-top: 1px solid #eaeaea; margin: 20px 0;'>
          <div style='display: flex; justify-content: space-between; font-size: 18px; font-weight: bold;'>
              <span>Total Shift Sales:</span>
              <span>$" . number_format( $totalSales, 2 ) . "</span>
          </div>
      </div>
      ";

      $headers = [ 'Content-Type: text/html; charset=UTF-8' ];

      $recipients = [ $current_user->user_email ];
      $cashierId  = isset( $args->cashierId ) ? intval( $args->cashierId ) : 0;
      if ( $cashierId && $cashierId !== $current_user->ID ) {
        $cashier_user = get_userdata( $cashierId );
        if ( $cashier_user && ! empty( $cashier_user->user_email ) ) {
          $recipients[] = $cashier_user->user_email;
        }
      }

      $sent = wp_mail( $recipients, "POS Shift Summary - $site_name", $html, $headers );

      $this->send_json_response( [
        'success' => (bool) $sent,
        'message' => $sent ? 'Shift summary emailed to ' . implode( ', ', $recipients ) : 'Failed to send email.',
      ] );
    } catch ( \Throwable $e ) {
      $this->send_json_response( [ 'success' => false, 'message' => $e->getMessage() ] );
    }
  }
}
