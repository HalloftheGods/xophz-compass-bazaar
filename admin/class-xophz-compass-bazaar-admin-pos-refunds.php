<?php
/**
 * POS refund handlers: look up an order's refundable lines and refund them.
 *
 * Split out of Admin_Orders (#6946). The two action names are unchanged.
 *
 * processPosRefund is the legacy adapter of Refunds v2 (spec section 12): it
 * maps {order_id, items, reason} onto a Refunds_Request with a server-made
 * key and answers {success, message, refund_id, amount} as before. Extra
 * optional arguments: approval_token and tenders (the refund request's own
 * shapes). Every refund goes through Refunds_Service, so a user whose refund
 * limit is below the amount (a cashier by default) gets success false with
 * code approval_required and the data needed to ask for an approval.
 *
 * Guaranteed: a refund through this action is idempotent per request only in
 * the sense that each call gets a new key; two identical calls are two
 * refunds if the quantities allow. Not guaranteed: quantities above what is
 * refundable are refused (invalid_line), no longer cut down silently; a
 * refund that lands on a card terminal payment needs tenders with the slip
 * reference, which the legacy POS screen does not send.
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
            'taxes'         => $this->item_taxes( $order, $item_id, $item ),
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
          'currency'       => $order->get_currency(),
          'items'          => $items_data,
          'payments'       => $this->capture_payments( (int) $order->get_id() ),
        ],
      ] );
    } catch ( \Throwable $e ) {
      $this->send_json_response( [ 'success' => false, 'message' => $e->getMessage() ] );
    }
  }

  /**
   * Legacy adapter of Refunds v2. Answers {success, message, refund_id,
   * amount}; refund_id is the WooCommerce refund id and amount is in the
   * store currency. A refusal answers success false with message, code and
   * data (for approval_required, the scope, amount and subject_hash).
   */
  public function processPosRefund() {
    try {
      $this->require_any( 'bazaar_refund' );

      $args     = Xophz_Compass_Bazaar_Core_Request::legacy_args();
      $order_id = isset( $args->order_id ) ? intval( $args->order_id ) : 0;
      $reason   = isset( $args->reason ) ? sanitize_text_field( $args->reason ) : 'POS Return';

      $lines = [];
      foreach ( Xophz_Compass_Bazaar_Core_Request::array_param( $args, 'items' ) as $r_item ) {
        $r_item  = is_array( $r_item ) ? $r_item : [];
        $item_id = isset( $r_item['item_id'] ) ? intval( $r_item['item_id'] ) : 0;
        $qty     = isset( $r_item['qty'] ) ? intval( $r_item['qty'] ) : 0;
        if ( $item_id > 0 && $qty > 0 ) {
          $lines[ $item_id ] = ( $lines[ $item_id ] ?? 0 ) + $qty;
        }
      }
      if ( ! $order_id || ! $lines ) {
        $this->send_json_response( [ 'success' => false, 'message' => 'Invalid refund parameters.' ] );
        return;
      }

      $body = [
        'client_key'     => 'legacy:' . wp_generate_uuid4(),
        'lines'          => array_map( function ( $id, $qty ) {
          return [ 'order_item_id' => $id, 'qty' => $qty ];
        }, array_keys( $lines ), array_values( $lines ) ),
        'reason'         => $reason,
        'restock'        => true,
        'tenders'        => Xophz_Compass_Bazaar_Core_Request::array_param( $args, 'tenders' ),
        'approval_token' => isset( $args->approval_token ) && is_string( $args->approval_token ) ? $args->approval_token : '',
      ];
      $result = ( new Xophz_Compass_Bazaar_Refunds_Service() )->refund(
        Xophz_Compass_Bazaar_Refunds_Request::from_array( $body, $order_id ),
        Xophz_Compass_Bazaar_Security_Guard::context()
      );

      $this->send_json_response( [
        'success'   => true,
        'message'   => 'Refund processed successfully.',
        'refund_id' => (int) $result['body']['wc_refund_id'],
        'amount'    => (float) Xophz_Compass_Bazaar_Core_Money::from_minor( (int) $result['body']['amount_minor'] ),
      ] );
    } catch ( Xophz_Compass_Bazaar_Core_Http_Error $e ) {
      $this->send_json_response( [ 'success' => false, 'message' => $e->getMessage(), 'code' => $e->error_code(), 'data' => $e->data() ] );
    } catch ( \Throwable $e ) {
      $this->send_json_response( [ 'success' => false, 'message' => $e->getMessage() ] );
    }
  }

  /**
   * An item's tax by rate: [{rate_id, label, amount, amount_minor, refunded_minor}].
   * amount is the line tax for that rate as WooCommerce stores it.
   */
  private function item_taxes( WC_Order $order, $item_id, WC_Order_Item $item ): array {
    $out = [];
    foreach ( (array) ( $item->get_taxes()['total'] ?? [] ) as $rate_id => $amount ) {
      $amount = '' === (string) $amount ? '0' : (string) $amount;
      $out[]  = [
        'rate_id'        => (int) $rate_id,
        'label'          => class_exists( 'WC_Tax' ) ? (string) WC_Tax::get_rate_label( (int) $rate_id ) : '',
        'amount'         => $amount,
        'amount_minor'   => Xophz_Compass_Bazaar_Core_Money::to_minor( $amount ),
        'refunded_minor' => Xophz_Compass_Bazaar_Core_Money::to_minor( abs( (float) $order->get_tax_refunded_for_item( $item_id, $rate_id ) ) ),
      ];
    }
    return $out;
  }

  /** The order's capture payments, newest first, with the room left to refund. */
  private function capture_payments( int $order_id ): array {
    $out = [];
    foreach ( Xophz_Compass_Bazaar_Payments_Repository::for_order( $order_id, 'capture' ) as $payment ) {
      $out[] = [
        'payment_uuid'    => (string) $payment->payment_uuid,
        'method_id'       => (string) $payment->method_id,
        'kind'            => (string) $payment->kind,
        'provider'        => (string) $payment->provider,
        'status'          => (string) $payment->status,
        'amount_minor'    => (int) $payment->amount_minor,
        'refunded_minor'  => (int) $payment->refunded_minor,
        'remaining_minor' => 'captured' === $payment->status ? max( 0, (int) $payment->amount_minor - (int) $payment->refunded_minor ) : 0,
        'reference'       => (string) $payment->reference,
        'card_brand'      => (string) $payment->card_brand,
        'card_last4'      => (string) $payment->card_last4,
      ];
    }
    return $out;
  }
}
