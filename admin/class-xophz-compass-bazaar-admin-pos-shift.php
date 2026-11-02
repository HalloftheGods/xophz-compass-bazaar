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
 * - The staff session is the one named by the X-Bazaar-Staff-Session header
 *   (with X-Bazaar-Device), resolved as rest_permission does: the session's
 *   staff member is the acting user, and the capabilities checked are theirs.
 *   A header naming no live session for this operator and device is refused
 *   ("The staff session is not valid. Enter the PIN again.").
 * - Sales the rollback body (Checkout_Legacy_Writer) recorded have no
 *   checkout or payment rows; outside a staff session they are added from
 *   their WooCommerce orders (Checkout_Legacy_Writer::shift_totals) for the
 *   same window and cashier, and the email says how many there were. A
 *   staff-session scope never adds them (legacy_query), whatever the staff
 *   member's capabilities: they belong to no session.
 * - Recipients are the acting user and the operator. The register's
 *   cashierId is not read.
 *
 * Not guaranteed: it reports what the checkout and payment rows say; it does
 * not reconcile a cash drawer. Coupons and custom discounts are one
 * "Discounts" figure. Rollback sales are attributed to the logged-in user
 * (never a staff session), so a staff-session email does not include them;
 * their refunds are not counted.
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

  /**
   * The context of this request: the staff session the X-Bazaar-Staff-Session
   * header names, else the operator. Null when the header names no live
   * session for this operator and device.
   *
   * @param array         $server  $_SERVER.
   * @param callable|null $resolve fn(token, operator, device): ?array, default Staff_Sessions::resolve.
   * @return array{context:Xophz_Compass_Bazaar_Security_Context,session:?array}|null
   */
  public static function request_context( int $operator, array $server, ?callable $resolve = null ): ?array {
    $G      = 'Xophz_Compass_Bazaar_Security_Guard';
    $device = $G::clean_device( $server['HTTP_X_BAZAAR_DEVICE'] ?? '' );
    if ( ! array_key_exists( 'HTTP_X_BAZAAR_STAFF_SESSION', $server ) ) {
      return [ 'context' => Xophz_Compass_Bazaar_Security_Context::for_operator( $operator, $device ), 'session' => null ];
    }
    $token = is_scalar( $server['HTTP_X_BAZAAR_STAFF_SESSION'] ) ? trim( (string) $server['HTTP_X_BAZAAR_STAFF_SESSION'] ) : '';
    if ( '' === $token || $operator <= 0 ) {
      return null;
    }
    try {
      $session = ( $resolve ?? [ 'Xophz_Compass_Bazaar_Staff_Sessions', 'resolve' ] )( $token, $operator, $device );
    } catch ( \Throwable $e ) {
      error_log( 'bazaar_shift_staff_session_error:' . get_class( $e ) );
      return null;
    }
    $context = $G::context_from_session( $session, $operator, $device );
    return null === $context ? null : [ 'context' => $context, 'session' => (array) $session ];
  }

  /** Pure. The distinct valid addresses, in order. */
  public static function recipients( array $emails ): array {
    $out = [];
    foreach ( $emails as $email ) {
      $email = is_string( $email ) ? trim( $email ) : '';
      if ( '' !== $email && false !== filter_var( $email, FILTER_VALIDATE_EMAIL ) && ! in_array( strtolower( $email ), array_map( 'strtolower', $out ), true ) ) {
        $out[] = $email;
      }
    }
    return $out;
  }

  /**
   * Pure. The summary with the rollback sales added (Checkout_Legacy_Writer::fold
   * shape). $kind maps a method id to its tender kind.
   */
  public static function merge( array $summary, array $legacy, callable $kind ): array {
    $summary['orders']          = (int) ( $summary['orders'] ?? 0 ) + (int) ( $legacy['orders'] ?? 0 );
    $summary['gross_minor']     = (int) ( $summary['gross_minor'] ?? 0 ) + (int) ( $legacy['gross_minor'] ?? 0 );
    $summary['tips_minor']      = (int) ( $summary['tips_minor'] ?? 0 ) + (int) ( $legacy['tips_minor'] ?? 0 );
    $summary['discounts_minor'] = (int) ( $summary['discounts_minor'] ?? 0 ) + (int) ( $legacy['discounts_minor'] ?? 0 );
    $summary['by_kind']         = (array) ( $summary['by_kind'] ?? [] );
    foreach ( (array) ( $legacy['by_method'] ?? [] ) as $method => $m ) {
      $summary['by_kind'][] = [ 'kind' => (string) $kind( (string) $method ), 'method_id' => (string) $method, 'count' => (int) $m['count'], 'amount_minor' => (int) $m['amount_minor'], 'legacy' => true ];
    }
    $summary['legacy_orders'] = (int) ( $legacy['orders'] ?? 0 );
    return $summary;
  }

  /**
   * Pure. The rollback-sale query for this scope: [from, to, cashier_user_id]
   * (cashier 0 = every cashier), or null when none are added. A staff-session
   * scope gets null: rollback sales are never a staff session's, and an
   * unscoped query there would add every register's sales since the session
   * started to a session's email.
   */
  public static function legacy_query( Xophz_Compass_Bazaar_Security_Context $ctx, array $scope ): ?array {
    if ( $ctx->is_staff_session() || isset( $scope['staff_session_id'] ) || ! isset( $scope['from'], $scope['to'] ) ) {
      return null;
    }
    return [ (string) $scope['from'], (string) $scope['to'], (int) ( $scope['cashier_user_id'] ?? 0 ) ];
  }

  private static function holds_any( int $user_id, string ...$caps ): bool {
    foreach ( $caps as $cap ) {
      if ( $user_id > 0 && user_can( $user_id, $cap ) ) {
        return true;
      }
    }
    return false;
  }

  private static function money( int $minor ): string {
    return number_format( (float) Xophz_Compass_Bazaar_Core_Money::from_minor( $minor ), 2 );
  }

  public function emailShiftSummary() {
    try {
      if ( ! Xophz_Compass_Bazaar_Security_Guard::can_any( 'bazaar_sell' ) ) {
        throw new \Exception( 'Permission denied.' );
      }
      $current_user = wp_get_current_user();

      if ( ! $current_user || ! $current_user->exists() ) {
        $this->send_json_response( [ 'success' => false, 'message' => 'Not authenticated.' ] );
        return;
      }

      $caps     = 'Xophz_Compass_Bazaar_Security_Capabilities';
      $resolved = self::request_context( (int) $current_user->ID, $_SERVER );
      if ( null === $resolved ) {
        $this->send_json_response( [ 'success' => false, 'message' => 'The staff session is not valid. Enter the PIN again.', 'code' => 'staff_session_invalid' ] );
        return;
      }
      $ctx = $resolved['context'];
      if ( $ctx->is_staff_session() && ! self::holds_any( $ctx->acting_user_id, $caps::SELL ) ) {
        throw new \Exception( 'Permission denied.' );
      }
      $sees_all = $ctx->is_staff_session()
        ? self::holds_any( $ctx->acting_user_id, $caps::VIEW_REPORTS, $caps::MANAGE_ORDERS )
        : Xophz_Compass_Bazaar_Security_Guard::can_any( $caps::VIEW_REPORTS, $caps::MANAGE_ORDERS );
      $scope    = self::scope( $ctx, $sees_all );
      $legacy_q = self::legacy_query( $ctx, $scope );
      $summary  = self::merge(
        Xophz_Compass_Bazaar_Rest_Checkouts::summary( $scope ),
        null === $legacy_q
          ? Xophz_Compass_Bazaar_Checkout_Legacy_Writer::fold( [] )
          : Xophz_Compass_Bazaar_Checkout_Legacy_Writer::shift_totals( $legacy_q[0], $legacy_q[1], $legacy_q[2] ),
        [ 'Xophz_Compass_Bazaar_Payments_Classifier', 'kind' ]
      );

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
          </div>" . ( $summary['legacy_orders'] > 0 ? "
          <p style='color: #666; font-size: 13px;'>Includes " . (int) $summary['legacy_orders'] . " sale(s) recorded by the rollback checkout, read from their WooCommerce orders. Their refunds are not counted.</p>" : '' ) . "
      </div>
      ";

      $headers = [ 'Content-Type: text/html; charset=UTF-8' ];

      $acting     = $ctx->acting_user_id === (int) $current_user->ID ? $current_user : get_userdata( $ctx->acting_user_id );
      $recipients = self::recipients( [ $acting ? $acting->user_email : '', $current_user->user_email ] );
      if ( ! $recipients ) {
        $this->send_json_response( [ 'success' => false, 'message' => 'No email address to send the summary to.' ] );
        return;
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
