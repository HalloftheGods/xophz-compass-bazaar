<?php

/**
 * Web order fulfillment: the "Ready for pickup" status and the Fulfillment board.
 *
 * Lists open WooCommerce web orders (not Bazaar POS sales) split into Local
 * Pickup and Delivery, and moves them through Ready for pickup to Completed.
 * The status is registered both as a post status (legacy order storage) and
 * in wc_order_statuses (HPOS and every order list), so it works either way.
 *
 * @package    Xophz_Compass_Bazaar
 * @subpackage Xophz_Compass_Bazaar/admin
 */
class Xophz_Compass_Bazaar_Admin_Fulfillment {

  const STATUS_READY    = 'ready-pickup';
  const OPEN_STATUSES   = [ 'processing', 'on-hold', self::STATUS_READY ];
  const PICKUP_METHODS  = [ 'local_pickup', 'pickup_location' ];
  const MAX_OPEN_ORDERS = 500;
  // Only paid orders may be handed over: on-hold means payment has not cleared.
  const READY_FROM      = [ 'processing' ];
  const COMPLETE_FROM   = [ 'processing', self::STATUS_READY ];

  private $plugin_name;
  private $version;

  public $action_hooks = [
    'woocommerce_register_shop_order_post_statuses' => 'registerPostStatus',
    'wc_order_statuses'                             => 'addOrderStatus',
    'woocommerce_order_is_paid_statuses'            => 'addPaidStatus',
    'wp_ajax_bazaar_get_fulfillment_orders'         => 'getFulfillmentOrders',
    'wp_ajax_bazaar_mark_ready_for_pickup'          => 'markReadyForPickup',
    'wp_ajax_bazaar_complete_fulfillment'           => 'completeFulfillment',
  ];

  public function __construct( $plugin_name, $version ) {
    $this->plugin_name = $plugin_name;
    $this->version     = $version;
  }

  private function send_json_response( $payload ) {
    while ( ob_get_level() > 0 ) {
      @ob_end_clean();
    }
    Xophz_Compass::output_json( $payload );
  }

  /**
   * Legacy (posts) order storage: register wc-ready-pickup with the rest of
   * WooCommerce's order statuses.
   */
  public function registerPostStatus( $statuses ) {
    $statuses = is_array( $statuses ) ? $statuses : [];
    $statuses[ 'wc-' . self::STATUS_READY ] = [
      'label'                     => _x( 'Ready for pickup', 'Order status', 'xophz-compass-bazaar' ),
      'public'                    => false,
      'exclude_from_search'       => false,
      'show_in_admin_all_list'    => true,
      'show_in_admin_status_list' => true,
      /* translators: %s: number of orders */
      'label_count'               => _n_noop( 'Ready for pickup <span class="count">(%s)</span>', 'Ready for pickup <span class="count">(%s)</span>', 'xophz-compass-bazaar' ),
    ];
    return $statuses;
  }

  /**
   * Every order list and status dropdown (HPOS and legacy) reads this list.
   * Ready for pickup is placed right after Processing.
   */
  public function addOrderStatus( $statuses ) {
    $statuses = is_array( $statuses ) ? $statuses : [];
    $label    = _x( 'Ready for pickup', 'Order status', 'xophz-compass-bazaar' );
    $result   = [];
    foreach ( $statuses as $key => $name ) {
      $result[ $key ] = $name;
      if ( 'wc-processing' === $key ) {
        $result[ 'wc-' . self::STATUS_READY ] = $label;
      }
    }
    if ( ! isset( $result[ 'wc-' . self::STATUS_READY ] ) ) {
      $result[ 'wc-' . self::STATUS_READY ] = $label;
    }
    return $result;
  }

  /** A ready-for-pickup order has been paid, so reports keep counting it. */
  public function addPaidStatus( $statuses ) {
    $statuses = is_array( $statuses ) ? $statuses : [];
    if ( ! in_array( self::STATUS_READY, $statuses, true ) ) {
      $statuses[] = self::STATUS_READY;
    }
    return $statuses;
  }

  /** Whether an order in this status may be marked ready for pickup. */
  public static function can_mark_ready( $status ): bool {
    return in_array( $status, self::READY_FROM, true );
  }

  /** Whether an order in this status may be marked picked up or delivered. */
  public static function can_complete( $status ): bool {
    return in_array( $status, self::COMPLETE_FROM, true );
  }

  /**
   * Open web orders for the board.
   *
   * Input: tab ('pickup'|'delivery'), page, limit.
   * Returns the page of orders for the tab, plus counts for both tabs.
   */
  public function getFulfillmentOrders() {
    Xophz_Compass_Security::enforce_spa_ajax( 'manage_woocommerce', 'edit_shop_orders' );

    try {
      if ( ! function_exists( 'wc_get_orders' ) ) {
        $this->send_json_response( [ 'success' => false, 'message' => 'WooCommerce is not active.' ] );
        return;
      }

      $args  = Xophz_Compass::get_input_json();
      $tab   = 'delivery' === ( $args->tab ?? '' ) ? 'delivery' : 'pickup';
      $page  = max( 1, intval( $args->page ?? 1 ) );
      $limit = min( 100, max( 1, intval( $args->limit ?? 25 ) ) );

      $orders = wc_get_orders( [
        'type'    => 'shop_order',
        'status'  => self::OPEN_STATUSES,
        'limit'   => self::MAX_OPEN_ORDERS,
        'orderby' => 'date',
        'order'   => 'ASC',
        'return'  => 'objects',
      ] );

      $by_tab = [ 'pickup' => [], 'delivery' => [] ];
      foreach ( $orders as $order ) {
        if ( ! $order instanceof WC_Order || 'bazaar_pos' === $order->get_created_via() ) {
          continue;
        }
        $fulfillment = $this->describe_fulfillment( $order );
        $by_tab[ $fulfillment['type'] ][] = [ $order, $fulfillment ];
      }

      $rows = [];
      foreach ( array_slice( $by_tab[ $tab ], ( $page - 1 ) * $limit, $limit ) as [ $order, $fulfillment ] ) {
        $data = Xophz_Compass_Bazaar_Admin_Orders::map_order_data( $order );
        if ( $data ) {
          $data['fulfillment'] = $fulfillment;
          $data['is_paid']     = $order->is_paid();
          $rows[] = $data;
        }
      }

      $this->send_json_response( [
        'success'   => true,
        'tab'       => $tab,
        'page'      => $page,
        'limit'     => $limit,
        'total'     => count( $by_tab[ $tab ] ),
        'counts'    => [ 'pickup' => count( $by_tab['pickup'] ), 'delivery' => count( $by_tab['delivery'] ) ],
        'truncated' => count( $orders ) >= self::MAX_OPEN_ORDERS,
        'data'      => $rows,
      ] );
    } catch ( \Throwable $e ) {
      $this->send_json_response( [ 'success' => false, 'message' => $e->getMessage() ] );
    }
  }

  /**
   * Mark a pickup order ready and let the customer know.
   *
   * Input: order_id, notify (default true). The status change stands even
   * when the text or email fails; the result says what was sent.
   */
  public function markReadyForPickup() {
    Xophz_Compass_Security::enforce_spa_ajax( 'manage_woocommerce', 'edit_shop_orders' );

    try {
      $args  = Xophz_Compass::get_input_json();
      $order = $this->find_web_order( $args );
      if ( is_string( $order ) ) {
        $this->send_json_response( [ 'success' => false, 'message' => $order ] );
        return;
      }
      if ( ! self::can_mark_ready( $order->get_status() ) ) {
        $this->send_json_response( [ 'success' => false, 'message' => 'This order is not paid yet, so it cannot be marked ready for pickup.' ] );
        return;
      }

      $order->update_status( self::STATUS_READY, __( 'Marked ready for pickup in COMPASS Bazaar.', 'xophz-compass-bazaar' ) );

      $notify       = ! isset( $args->notify ) || ! in_array( strtolower( (string) $args->notify ), [ 'false', '0', '' ], true );
      $notification = [ 'channel' => 'none', 'sent' => false, 'message' => 'Customer not notified.' ];
      if ( $notify ) {
        try {
          $notification = $this->notify_ready_for_pickup( $order );
        } catch ( \Throwable $e ) {
          $notification = [ 'channel' => 'none', 'sent' => false, 'message' => 'Could not notify the customer: ' . $e->getMessage() ];
        }
      }

      $this->send_json_response( [
        'success'      => true,
        'order_id'     => $order->get_id(),
        'new_status'   => $order->get_status(),
        'notification' => $notification,
      ] );
    } catch ( \Throwable $e ) {
      $this->send_json_response( [ 'success' => false, 'message' => $e->getMessage() ] );
    }
  }

  /**
   * Complete an order once it is picked up or delivered.
   *
   * Input: order_id.
   */
  public function completeFulfillment() {
    Xophz_Compass_Security::enforce_spa_ajax( 'manage_woocommerce', 'edit_shop_orders' );

    try {
      $args  = Xophz_Compass::get_input_json();
      $order = $this->find_web_order( $args );
      if ( is_string( $order ) ) {
        $this->send_json_response( [ 'success' => false, 'message' => $order ] );
        return;
      }
      if ( ! self::can_complete( $order->get_status() ) ) {
        $this->send_json_response( [ 'success' => false, 'message' => 'This order is not paid yet, or is not waiting for fulfillment.' ] );
        return;
      }

      $note = 'pickup' === $this->describe_fulfillment( $order )['type']
        ? __( 'Picked up by the customer (COMPASS Bazaar).', 'xophz-compass-bazaar' )
        : __( 'Delivered (COMPASS Bazaar).', 'xophz-compass-bazaar' );
      $order->update_status( 'completed', $note );

      $this->send_json_response( [
        'success'    => true,
        'order_id'   => $order->get_id(),
        'new_status' => $order->get_status(),
      ] );
    } catch ( \Throwable $e ) {
      $this->send_json_response( [ 'success' => false, 'message' => $e->getMessage() ] );
    }
  }

  /**
   * @return WC_Order|string The order, or an error message.
   */
  private function find_web_order( $args ) {
    $order_id = intval( $args->order_id ?? 0 );
    $order    = $order_id > 0 ? wc_get_order( $order_id ) : false;
    if ( ! $order instanceof WC_Order ) {
      return 'Order not found.';
    }
    if ( 'bazaar_pos' === $order->get_created_via() ) {
      return 'Point of sale orders are not fulfilled here.';
    }
    return $order;
  }

  /**
   * Pickup or delivery, from the order's shipping lines.
   *
   * @return array{type: string, shipping_method: string, pickup_location: string}
   */
  private function describe_fulfillment( WC_Order $order ): array {
    $titles   = [];
    $location = '';
    $type     = 'delivery';

    foreach ( $order->get_shipping_methods() as $item ) {
      $titles[] = $item->get_method_title();
      if ( in_array( $item->get_method_id(), self::PICKUP_METHODS, true ) ) {
        $type     = 'pickup';
        $location = (string) ( $item->get_meta( 'pickup_location' ) ?: $item->get_meta( 'pickup_address' ) );
      }
    }

    return [
      'type'            => $type,
      'shipping_method' => implode( ', ', array_filter( $titles ) ),
      'pickup_location' => $location,
    ];
  }

  /**
   * Text the customer when Twilio is set up and the order has a phone;
   * otherwise (or if the text fails) add a customer note, which WooCommerce
   * emails to the customer as its "Customer note" email.
   */
  private function notify_ready_for_pickup( WC_Order $order ): array {
    $phone     = $order->get_billing_phone() ?: $order->get_shipping_phone();
    $sms_error = '';

    if ( $phone && Xophz_Compass_Bazaar_Twilio::is_configured() ) {
      $result = Xophz_Compass_Bazaar_Twilio::send_pickup_ready_sms( $phone, $order );
      if ( true === $result ) {
        $order->add_order_note( sprintf( __( 'Ready-for-pickup text sent to %s.', 'xophz-compass-bazaar' ), $phone ) );
        return [ 'channel' => 'sms', 'sent' => true, 'message' => 'Customer texted at ' . $phone . '.' ];
      }
      $sms_error = is_wp_error( $result ) ? $result->get_error_message() : 'Unknown Twilio error';
      $order->add_order_note( sprintf( __( 'Ready-for-pickup text failed: %s', 'xophz-compass-bazaar' ), $sms_error ) );
    }

    $order->add_order_note( Xophz_Compass_Bazaar_Twilio::pickup_ready_message( $order ), 1 );
    $email = $order->get_billing_email();

    return [
      'channel'   => 'email',
      'sent'      => '' !== (string) $email,
      'message'   => $email
        ? 'Customer note added; WooCommerce emails it to ' . $email . '.'
        : 'Customer note added; the order has no email address.',
      'sms_error' => $sms_error,
    ];
  }
}
