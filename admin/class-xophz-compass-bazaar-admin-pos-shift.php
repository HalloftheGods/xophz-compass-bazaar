<?php
/**
 * POS shift summary email handler.
 *
 * Split out of Admin_Orders (#6946). The action name is unchanged.
 *
 * Guaranteed: the figures in the email come from the server
 * (Rest_Checkouts::summary, the same numbers as GET /pos/shift-summary):
 * the current staff session, or, without one, everything since local
 * midnight in the store's time zone. Totals the register sends (cash, card,
 * coupons, customDiscounts, totalTips, totalOrders) are not read. Without
 * bazaar_view_reports or bazaar_manage_orders the figures are the acting
 * cashier's own.
 *
 * Not guaranteed: it reports what the checkout and payment rows say; it does
 * not reconcile a cash drawer. Coupons and custom discounts are one
 * "Discounts" figure. The register's cashierId, if it names another user with
 * an email address, still adds that address to the recipients.
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

  /** The summary scope: the staff session, else today since local midnight. */
  public static function scope( Xophz_Compass_Bazaar_Security_Context $ctx, bool $sees_all ): array {
    if ( $ctx->staff_session_id > 0 ) {
      $scope = [ 'staff_session_id' => $ctx->staff_session_id ];
    } else {
      $zone  = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
      $scope = [
        'from' => ( new DateTimeImmutable( 'today', $zone ) )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ),
        'to'   => gmdate( 'Y-m-d H:i:s', time() + 1 ),
      ];
    }
    if ( ! $sees_all ) {
      $scope['cashier_user_id'] = $ctx->acting_user_id;
    }
    return $scope;
  }

  private static function money( int $minor ): string {
    return number_format( (float) Xophz_Compass_Bazaar_Core_Money::from_minor( $minor ), 2 );
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

      $caps     = 'Xophz_Compass_Bazaar_Security_Capabilities';
      $sees_all = Xophz_Compass_Bazaar_Security_Guard::can_any( $caps::VIEW_REPORTS, $caps::MANAGE_ORDERS );
      $summary  = Xophz_Compass_Bazaar_Rest_Checkouts::summary( self::scope( Xophz_Compass_Bazaar_Security_Guard::context(), $sees_all ) );

      $cash = 0;
      $card = 0;
      foreach ( $summary['by_kind'] as $row ) {
        if ( 'cash' === $row['kind'] ) {
          $cash += (int) $row['amount_minor'];
        } else {
          $card += (int) $row['amount_minor'];
        }
      }
      $totalOrders = (int) $summary['orders'];

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
              <tr><td style='padding: 8px 0; color: #555;'>Cash Tended:</td><td style='text-align: right; font-weight: bold; color: #28a745;'>$" . self::money( $cash ) . "</td></tr>
              <tr><td style='padding: 8px 0; color: #555;'>Card/Electronic:</td><td style='text-align: right; font-weight: bold; color: #007bff;'>$" . self::money( $card ) . "</td></tr>
              <tr><td style='padding: 8px 0; color: #555;'>Tips Collected:</td><td style='text-align: right; font-weight: bold; color: #20c997;'>+$" . self::money( (int) $summary['tips_minor'] ) . "</td></tr>
              <tr><td style='padding: 8px 0; color: #555;'>Discounts (coupons and custom):</td><td style='text-align: right; font-weight: bold; color: #ffc107;'>-$" . self::money( (int) $summary['discounts_minor'] ) . "</td></tr>
              <tr><td style='padding: 8px 0; color: #555;'>Refunds:</td><td style='text-align: right; font-weight: bold; color: #dc3545;'>-$" . self::money( (int) $summary['refunds_minor'] ) . "</td></tr>
          </table>
          <hr style='border: none; border-top: 1px solid #eaeaea; margin: 20px 0;'>
          <div style='display: flex; justify-content: space-between; font-size: 18px; font-weight: bold;'>
              <span>Total Shift Sales:</span>
              <span>$" . self::money( (int) $summary['gross_minor'] ) . "</span>
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
