<?php

/**
 * Order list and order status handlers for the Bazaar orders screen.
 *
 * The POS, coupon, category, payment and test portal handlers that used to
 * live here moved to Admin_Pos_Sale, Admin_Pos_Customers, Admin_Pos_Shift,
 * Admin_Pos_Refunds, Admin_Coupons, Admin_Payment_Settings, Admin_Test_Portal
 * and Admin_Categories (#6946). They keep their action names.
 *
 * This class keeps three unhooked delegates (createPosOrder, renderGatewayPortal
 * and authorizeTestPayment) because tests/integration/pos-authorization.sh and
 * older callers invoke them here.
 *
 * @package    Xophz_Compass_Bazaar
 * @subpackage Xophz_Compass_Bazaar/admin
 */
class Xophz_Compass_Bazaar_Admin_Orders {

  /** Order statuses updateOrderStatus never sets (without the wc- prefix). */
  const BLOCKED_STATUSES = [ 'checkout-draft', 'refunded' ];

  /** @var string */
  private $plugin_name;

  /** @var string */
  private $version;

  public $action_hooks = [
    'wp_ajax_get_orders'          => 'getOrders',
    'wp_ajax_update_order_status' => 'updateOrderStatus',
  ];

  public function __construct( $plugin_name, $version ) {
    $this->plugin_name = $plugin_name;
    $this->version     = $version;
  }

  /**
   * Clean active output buffer and emit structured JSON response.
   *
   * @param array $payload Response data payload.
   * @return void
   */
  private function send_json_response( $payload ) {
    while ( ob_get_level() > 0 ) {
      @ob_end_clean();
    }
    Xophz_Compass::output_json( $payload );
  }

  /**
   * Shape one order the way the Bazaar SPA reads it (order-details-dialog,
   * orders table, fulfillment board).
   *
   * @param int $id Order id.
   * @return array|null
   */
  public static function map_order_data( $id ) {
    $order = wc_get_order( $id );
    if ( ! $order || is_wp_error( $order ) ) {
      return null;
    }
    $data                    = $order->get_data();
    $data['order']           = $order->get_order_number();
    $data['currency_symbol'] = html_entity_decode( get_woocommerce_currency_symbol( $order->get_currency() ), ENT_QUOTES, 'UTF-8' );

    if ( isset( $data['date_created'] ) && is_a( $data['date_created'], 'WC_DateTime' ) ) {
      $data['date_created'] = $data['date_created']->date( 'Y-m-d H:i:s' );
    }

    $cashier_id = $order->get_meta( '_pos_cashier_id' );
    if ( $cashier_id ) {
      $cashier = get_userdata( $cashier_id );
      if ( $cashier ) {
        $data['cashier_name'] = $cashier->display_name;
      }
    }
    if ( empty( $data['cashier_name'] ) ) {
      $data['cashier_name'] = 'System / Online Store';
    }

    $formatted_items = [];
    foreach ( $order->get_items() as $item_id => $item ) {
      $product = $item->get_product();
      $thumb   = '';
      $sku     = '';
      if ( $product ) {
        $sku      = $product->get_sku();
        $image_id = $product->get_image_id();
        if ( $image_id ) {
          $thumb_src = wp_get_attachment_image_src( $image_id, 'thumbnail' );
          if ( $thumb_src ) {
            $thumb = $thumb_src[0];
          }
        }
      }

      $formatted_items[] = [
        'id'           => $item_id,
        'name'         => $item->get_name(),
        'product_id'   => $item->get_product_id(),
        'variation_id' => $item->get_variation_id(),
        'quantity'     => $item->get_quantity(),
        'subtotal'     => floatval( $item->get_subtotal() ),
        'total'        => floatval( $item->get_total() ),
        'sku'          => $sku,
        'thumb'        => $thumb,
      ];
    }
    $data['line_items'] = $formatted_items;

    return $data;
  }

  public function getOrders() {
    try {
      if ( ! Xophz_Compass_Bazaar_Security_Guard::can_any( 'bazaar_manage_orders', 'bazaar_view_reports' ) ) {
        throw new \Exception( 'Permission denied.' );
      }
      $args         = Xophz_Compass_Bazaar_Core_Request::legacy_args();
      $args->return = 'objects';

      $orderIds = self::getOrderIds( $args );
      if ( is_wp_error( $orderIds ) ) {
        throw new \Exception( $orderIds->get_error_message() );
      }

      $raw_orders  = isset( $orderIds->orders ) && is_array( $orderIds->orders ) ? $orderIds->orders : [];
      $orders_data = array_values( array_filter( array_map( [ self::class, 'map_order_data' ], $raw_orders ) ) );

      $this->send_json_response( [
        'success'     => true,
        'total_count' => isset( $orderIds->total ) ? (int) $orderIds->total : 0,
        'data'        => $orders_data,
      ] );
    } catch ( \Throwable $e ) {
      $this->send_json_response( [ 'success' => false, 'message' => $e->getMessage() ] );
    }
  }

  /**
   * The statuses updateOrderStatus accepts, without the wc- prefix: every key
   * of wc_get_order_statuses() except checkout-draft and refunded.
   *
   * @param array $statuses wc_get_order_statuses() result, keyed wc-<status>.
   * @return string[]
   */
  public static function allowed_statuses( array $statuses ): array {
    $allowed = [];
    foreach ( array_keys( $statuses ) as $key ) {
      $slug = 0 === strpos( (string) $key, 'wc-' ) ? substr( (string) $key, 3 ) : (string) $key;
      if ( '' !== $slug && ! in_array( $slug, self::BLOCKED_STATUSES, true ) ) {
        $allowed[] = $slug;
      }
    }
    return $allowed;
  }

  public function updateOrderStatus() {
    try {
      if ( ! Xophz_Compass_Bazaar_Security_Guard::can_any( 'bazaar_manage_orders' ) ) {
        throw new \Exception( 'Permission denied.' );
      }
      $args     = Xophz_Compass_Bazaar_Core_Request::legacy_args();
      $order_id = isset( $args->order_id ) ? intval( $args->order_id ) : 0;
      $status   = isset( $args->status ) ? sanitize_text_field( $args->status ) : '';
      $status   = 0 === strpos( $status, 'wc-' ) ? substr( $status, 3 ) : $status;

      if ( ! $order_id || ! $status ) {
        $this->send_json_response( [ 'success' => false, 'message' => 'Invalid order ID or status' ] );
        return;
      }

      if ( ! in_array( $status, self::allowed_statuses( wc_get_order_statuses() ), true ) ) {
        $this->send_json_response( [ 'success' => false, 'message' => 'Invalid order status.' ] );
        return;
      }

      $order = wc_get_order( $order_id );
      if ( ! $order || is_wp_error( $order ) ) {
        $this->send_json_response( [ 'success' => false, 'message' => 'Order not found' ] );
        return;
      }

      $res = $order->update_status( $status, 'Order status updated via COMPASS Bazaar UI.' );
      if ( is_wp_error( $res ) ) {
        throw new \Exception( $res->get_error_message() );
      }

      $this->send_json_response( [
        'success'    => true,
        'order_id'   => $order_id,
        'new_status' => $order->get_status(),
      ] );
    } catch ( \Throwable $e ) {
      $this->send_json_response( [ 'success' => false, 'message' => $e->getMessage() ] );
    }
  }

  public static function getOrderIds( $args ) {
    $query_args = (array) $args;

    $default = [
      'return'   => 'ids',
      'paginate' => true,
      'status'   => 'any',
      'type'     => 'shop_order',
    ];

    if ( ! empty( $query_args['limit'] ) ) {
      $default['limit'] = intval( $query_args['limit'] );
    }
    if ( ! empty( $query_args['page'] ) ) {
      $default['page'] = intval( $query_args['page'] );
    }

    if ( ! function_exists( 'wc_get_orders' ) ) {
      return (object) [ 'orders' => [], 'total' => 0, 'max_num_pages' => 0 ];
    }

    $final_args = array_merge( $default, $query_args );
    return wc_get_orders( $final_args );
  }

  /** Delegate to Admin_Pos_Sale::createPosOrder(). Not hooked. */
  public function createPosOrder() {
    ( new Xophz_Compass_Bazaar_Admin_Pos_Sale( $this->plugin_name, $this->version ) )->createPosOrder();
  }

  /** Delegate to Admin_Test_Portal::renderGatewayPortal(). Not hooked. */
  public function renderGatewayPortal() {
    ( new Xophz_Compass_Bazaar_Admin_Test_Portal( $this->plugin_name, $this->version ) )->renderGatewayPortal();
  }

  /** Delegate to Admin_Test_Portal::authorizeTestPayment(). Not hooked. */
  public function authorizeTestPayment() {
    ( new Xophz_Compass_Bazaar_Admin_Test_Portal( $this->plugin_name, $this->version ) )->authorizeTestPayment();
  }
}
