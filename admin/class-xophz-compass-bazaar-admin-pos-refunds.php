<?php
/**
 * POS refund handlers: look up an order's refundable lines and refund them.
 *
 * Split out of Admin_Orders (#6946). The two action names are unchanged.
 * Not guaranteed here: manager approval for refunds and idempotency arrive
 * with the Checkout v2 refund service (docs/plugins/bazaar-core-v2.md).
 *
 * @package    Xophz_Compass_Bazaar
 * @subpackage Xophz_Compass_Bazaar/admin
 */
class Xophz_Compass_Bazaar_Admin_Pos_Refunds {

  /** @var string */
  private $plugin_name;

  /** @var string */
  private $version;

  public $action_hooks = [
    'wp_ajax_get_pos_order_for_refund' => 'getPosOrderForRefund',
    'wp_ajax_process_pos_refund'       => 'processPosRefund',
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

  public function getPosOrderForRefund() {
    try {
      $this->require_any( 'bazaar_refund', 'bazaar_manage_orders' );

      $args     = Xophz_Compass_Bazaar_Core_Request::legacy_args();
      $order_id = isset( $args->order_id ) ? intval( $args->order_id ) : 0;

      if ( ! $order_id ) {
        $this->send_json_response( [ 'success' => false, 'message' => 'Order ID is required.' ] );
        return;
      }

      $order = wc_get_order( $order_id );
      if ( ! $order || is_wp_error( $order ) ) {
        $this->send_json_response( [ 'success' => false, 'message' => 'Order not found.' ] );
        return;
      }

      $items_data = [];
      foreach ( $order->get_items() as $item_id => $item ) {
        $product       = $item->get_product();
        $refunded_qty  = absint( $order->get_qty_refunded_for_item( $item_id ) );
        $qty_available = $item->get_quantity() - $refunded_qty;

        if ( $qty_available > 0 ) {
          $items_data[] = [
            'item_id'       => $item_id,
            'product_id'    => $item->get_product_id(),
            'name'          => $item->get_name(),
            'qty_available' => $qty_available,
            'price'         => $order->get_item_total( $item, false, false ),
            'total'         => $order->get_line_total( $item, false, false ),
            'tax'           => $order->get_line_tax( $item ),
            'thumb'         => ( $product && ! is_wp_error( $product ) ) ? wp_get_attachment_image_url( $product->get_image_id(), 'thumbnail' ) : '',
          ];
        }
      }

      $this->send_json_response( [
        'success' => true,
        'order'   => [
          'id'             => $order->get_id(),
          'status'         => $order->get_status(),
          'total'          => $order->get_total(),
          'total_refunded' => $order->get_total_refunded(),
          'items'          => $items_data,
        ],
      ] );
    } catch ( \Throwable $e ) {
      $this->send_json_response( [ 'success' => false, 'message' => $e->getMessage() ] );
    }
  }

  public function processPosRefund() {
    try {
      $this->require_any( 'bazaar_refund' );

      $args         = Xophz_Compass_Bazaar_Core_Request::legacy_args();
      $order_id     = isset( $args->order_id ) ? intval( $args->order_id ) : 0;
      $refund_items = Xophz_Compass_Bazaar_Core_Request::array_param( $args, 'items' );
      $reason       = isset( $args->reason ) ? sanitize_text_field( $args->reason ) : 'POS Return';

      if ( ! $order_id || empty( $refund_items ) ) {
        $this->send_json_response( [ 'success' => false, 'message' => 'Invalid refund parameters.' ] );
        return;
      }

      $order = wc_get_order( $order_id );
      if ( ! $order || is_wp_error( $order ) ) {
        $this->send_json_response( [ 'success' => false, 'message' => 'Order not found.' ] );
        return;
      }

      $line_items    = [];
      $refund_amount = 0;

      foreach ( $refund_items as $r_item ) {
        $r_item  = is_array( $r_item ) ? $r_item : [];
        $item_id = isset( $r_item['item_id'] ) ? intval( $r_item['item_id'] ) : 0;
        $qty     = isset( $r_item['qty'] ) ? intval( $r_item['qty'] ) : 0;
        if ( $qty <= 0 ) {
          continue;
        }

        $order_item = $order->get_item( $item_id );
        if ( ! $order_item || is_wp_error( $order_item ) ) {
          continue;
        }

        $item_qty      = $order_item->get_quantity();
        $refunded_qty  = absint( $order->get_qty_refunded_for_item( $item_id ) );
        $available_qty = $item_qty - $refunded_qty;
        if ( $available_qty <= 0 ) {
          continue;
        }

        if ( $qty > $available_qty ) {
          $qty = $available_qty;
        }

        $unit_total = $order->get_item_total( $order_item, false, false );
        $unit_tax   = $item_qty > 0 ? ( $order->get_line_tax( $order_item ) / $item_qty ) : 0;

        $line_total = $unit_total * $qty;
        $line_tax   = $unit_tax * $qty;

        $refund_amount += ( $line_total + $line_tax );

        $item_taxes = $order_item->get_taxes();
        $refund_tax = [];
        if ( $line_tax > 0 && ! empty( $item_taxes['total'] ) ) {
          $tax_rate_id = key( $item_taxes['total'] );
          if ( $tax_rate_id ) {
            $refund_tax[ $tax_rate_id ] = $line_tax;
          }
        }

        $line_items[ $item_id ] = [
          'qty'          => $qty,
          'refund_total' => $line_total,
          'refund_tax'   => $refund_tax,
        ];
      }

      $refund = wc_create_refund( [
        'amount'         => $refund_amount,
        'reason'         => $reason,
        'order_id'       => $order_id,
        'line_items'     => $line_items,
        'refund_payment' => false, // Do not auto-refund the gateway for POS; handled manually.
        'restock_items'  => true,
      ] );

      if ( is_wp_error( $refund ) ) {
        throw new \Exception( $refund->get_error_message() );
      }

      $this->send_json_response( [
        'success'   => true,
        'message'   => 'Refund processed successfully.',
        'refund_id' => $refund->get_id(),
        'amount'    => $refund_amount,
      ] );
    } catch ( \Throwable $e ) {
      $this->send_json_response( [ 'success' => false, 'message' => $e->getMessage() ] );
    }
  }
}
