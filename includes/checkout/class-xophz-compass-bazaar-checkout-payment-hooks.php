<?php
/**
 * Captures a v2 order's pending manual payments when the order is marked paid.
 *
 * register() hooks woocommerce_order_status_changed. When an order that
 * carries _bazaar_checkout_id moves to a status in wc_get_is_paid_statuses()
 * (processing, completed, and whatever filters add, such as ready-pickup),
 * every capture row of that order with kind manual and status pending becomes
 * captured, and one order note per row says so.
 *
 * Ignored: the status change Checkout_Finalizer itself makes (a cod sale is
 * set to processing at checkout, but nothing was paid yet), orders without
 * _bazaar_checkout_id, and payments of other kinds or statuses. It records
 * what the status change claims; it does not check that the money arrived.
 *
 * @package Xophz_Compass_Bazaar
 */
class Xophz_Compass_Bazaar_Checkout_Payment_Hooks {

  public static function register(): void {
    add_action( 'woocommerce_order_status_changed', [ self::class, 'on_status_changed' ], 10, 4 );
  }

  /**
   * @param int    $order_id
   * @param string $from
   * @param string $to
   * @param mixed  $order
   * @return int How many payments were captured.
   */
  public static function on_status_changed( $order_id, $from = '', $to = '', $order = null ): int {
    $order_id = (int) $order_id;
    if ( $order_id <= 0 || Xophz_Compass_Bazaar_Checkout_Finalizer::is_finalizing( $order_id ) ) {
      return 0;
    }
    $paid = function_exists( 'wc_get_is_paid_statuses' ) ? (array) wc_get_is_paid_statuses() : [ 'processing', 'completed' ];
    if ( ! in_array( (string) $to, $paid, true ) ) {
      return 0;
    }
    $order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
    if ( ! $order instanceof WC_Order || ! $order->get_meta( '_bazaar_checkout_id' ) ) {
      return 0;
    }
    $count = 0;
    foreach ( Xophz_Compass_Bazaar_Payments_Repository::for_order( $order_id, 'capture' ) as $payment ) {
      if ( 'manual' !== (string) $payment->kind || 'pending' !== (string) $payment->status ) {
        continue;
      }
      if ( ! Xophz_Compass_Bazaar_Payments_Repository::update_status( (int) $payment->id, 'captured' ) ) {
        continue;
      }
      $count++;
      $order->add_order_note( sprintf(
        'Bazaar payment %s (%s) marked captured because the order status changed to %s. Bazaar did not verify the money arrived.',
        (string) $payment->payment_uuid,
        (string) $payment->method_id,
        (string) $to
      ) );
    }
    return $count;
  }
}
