<?php
/**
 * Writes the priced cart to a pending WooCommerce order (spec 9.4, 11.3 step 6),
 * and runs the stock precheck of step 4 (precheck_stock).
 *
 * Two steps, so the checkout row can record the order id before anything
 * else can fail:
 * - open(): a saved, empty pending order with created_via bazaar_pos, the
 *   Bazaar meta and the customer's billing fields. It never creates a user.
 * - fill(): line items with explicit subtotal, total and taxes by rate
 *   (custom lines have product id 0), coupon items with discount,
 *   discount_tax and coupon_info meta, the tip as an untaxed fee, then
 *   update_taxes() and calculate_totals(false). It then checks that the
 *   order's total and tax, in minor units, equal the priced cart, and
 *   otherwise logs both and throws 422 pricing_drift.
 *
 * Not guaranteed: coupon usage counts. WooCommerce updates them on status
 * hooks, after the finalizer sets the status.
 *
 * @package Xophz_Compass_Bazaar
 */
class Xophz_Compass_Bazaar_Checkout_Order_Writer {

  const CREATED_VIA = 'bazaar_pos';

  /**
   * Create and save the pending order shell.
   *
   * @param array $row The checkout row (id, stock_owner, staff_session_id).
   */
  public function open( Xophz_Compass_Bazaar_Pricing_Priced_Cart $priced, array $row, Xophz_Compass_Bazaar_Security_Context $context ): WC_Order {
    $request  = $priced->request();
    $totals   = $priced->totals();
    $decimals = function_exists( 'wc_get_price_decimals' ) ? (int) wc_get_price_decimals() : 2;
    $order    = new WC_Order();
    $order->set_status( 'pending' );
    $order->set_created_via( self::CREATED_VIA );
    $order->set_currency( (string) $row['currency'] );
    $order->set_prices_include_tax( 'yes' === get_option( 'woocommerce_prices_include_tax' ) );
    $this->attach_customer( $order, $request->customer );
    $order->update_meta_data( '_bazaar_checkout_id', (int) $row['id'] );
    $order->update_meta_data( '_bazaar_location_id', (int) $request->location_id );
    $order->update_meta_data( '_bazaar_stock_owner', 'ledger' === $row['stock_owner'] ? 'ledger' : 'wc' );
    $order->update_meta_data( '_pos_cashier_id', $context->acting_user_id );
    $order->update_meta_data( '_pos_tip_amount', Xophz_Compass_Bazaar_Core_Money::from_minor( (int) $totals['tip_minor'], $decimals ) );
    $order->update_meta_data( '_pos_tip_cashier_id', $context->acting_user_id );
    if ( $context->staff_session_id > 0 ) {
      $order->update_meta_data( '_bazaar_staff_session_id', $context->staff_session_id );
    }
    // Read by the finalizer's post-complete step, which may run in a later request.
    $order->update_meta_data( '_bazaar_customer_flags', wp_json_encode( [
      'create_account'   => ! empty( $request->customer['create_account'] ),
      'marketing_opt_in' => ! empty( $request->customer['marketing_opt_in'] ),
      'sms_opt_in'       => ! empty( $request->customer['sms_opt_in'] ),
    ] ) );
    $order->save();
    if ( 'ledger' === $row['stock_owner'] ) {
      // The ledger already moved the stock: WooCommerce must not reduce it again.
      $order->get_data_store()->set_stock_reduced( $order->get_id(), true );
    }
    return $order;
  }

  /** Restart a failed, received or priced row, then cancel any order the old attempt saved but never recorded. */
  public static function restart_clean( array $row ): ?array {
    $fresh = Xophz_Compass_Bazaar_Checkout_Repository::restart( $row );
    if ( $fresh ) {
      self::cancel_unrecorded( $fresh, 'checkout_interrupted', 'restarted' );
    }
    return $fresh;
  }

  /**
   * Cancel (never delete) orders that carry this row's checkout id but that the row never recorded.
   * Covers an order saved by open() before the row moved to order_created. Call only while holding the row's lease.
   * Best effort: a failure is logged, not thrown.
   */
  public static function cancel_unrecorded( array $row, string $code, string $reason ): int {
    $cancelled = 0;
    try {
      $orders = wc_get_orders( [ 'limit' => -1, 'status' => 'any', 'meta_key' => '_bazaar_checkout_id', 'meta_value' => (int) $row['id'] ] );
      foreach ( $orders as $order ) {
        if ( ! $order instanceof WC_Order || (int) $order->get_id() === (int) ( $row['order_id'] ?? 0 ) || in_array( $order->get_status(), [ 'cancelled', 'completed', 'refunded' ], true ) ) {
          continue;
        }
        $order->update_meta_data( '_bazaar_failure_code', $code );
        $order->update_status( 'cancelled', 'Bazaar POS checkout ended before the row recorded this order (' . $reason . '); the order was cancelled, not deleted.' );
        $cancelled++;
      }
    } catch ( Throwable $e ) {
      error_log( 'Bazaar checkout ' . (int) $row['id'] . ' unrecorded order cancel failed: ' . $e->getMessage() );
    }
    return $cancelled;
  }

  /**
   * Add the priced items, compute WooCommerce's totals and assert parity.
   *
   * @throws Xophz_Compass_Bazaar_Core_Http_Error 422 pricing_drift.
   */
  public function fill( WC_Order $order, Xophz_Compass_Bazaar_Pricing_Priced_Cart $priced ): void {
    $requested = (int) $priced->request()->location_id;
    foreach ( $priced->order_items() as $line ) {
      $order->add_item( $this->line_item( $line, $requested ) );
    }
    foreach ( $priced->coupon_items() as $coupon ) {
      $order->add_item( $this->coupon_item( $coupon ) );
    }
    foreach ( $priced->fee_items() as $fee ) {
      $item = new WC_Order_Item_Fee();
      $item->set_name( (string) $fee['name'] );
      $item->set_tax_status( 'none' );
      $item->set_tax_class( '' );
      $item->set_amount( (string) $fee['total'] );
      $item->set_total( (string) $fee['total'] );
      $item->set_total_tax( 0 );
      $item->set_taxes( [ 'total' => [] ] );
      $order->add_item( $item );
    }
    $order->save();
    $order->update_taxes();
    $order->calculate_totals( false );
    $this->assert_parity( $order, $priced );
  }

  /**
   * Spec 11.3 step 4, before any order exists. Ledger stock owner: each
   * stock key's on_hand at its sale location (Stock_Service::available).
   * Otherwise: WooCommerce stock. Products that do not manage stock, and
   * products that allow backorders, are skipped. Advisory in the wc mode:
   * WooCommerce reduces stock later without refusing.
   *
   * @throws Xophz_Compass_Bazaar_Core_Http_Error 409 insufficient_stock {lines}
   */
  public function precheck_stock( Xophz_Compass_Bazaar_Pricing_Priced_Cart $priced, string $owner ): void {
    $requested = (int) $priced->request()->location_id;
    $need      = [];
    foreach ( $priced->order_items() as $i => $line ) {
      if ( 'product' !== $line['type'] ) {
        continue;
      }
      $id      = (int) $line['variation_id'] > 0 ? (int) $line['variation_id'] : (int) $line['product_id'];
      $product = wc_get_product( $id );
      $key     = $product ? (int) $product->get_stock_managed_by_id() : 0;
      $owner_p = $key > 0 ? wc_get_product( $key ) : null;
      if ( ! $owner_p || ! $owner_p->managing_stock() || $owner_p->backorders_allowed() ) {
        continue;
      }
      $loc  = 'ledger' === $owner ? Xophz_Compass_Bazaar_Stock_Locations::sale_location( $id, $requested > 0 ? $requested : null ) : 0;
      $slot = $key . ':' . $loc;
      if ( ! isset( $need[ $slot ] ) ) {
        $need[ $slot ] = [ 'line' => $i, 'product_id' => $id, 'stock_product_id' => $key, 'location_id' => $loc, 'requested' => 0.0 ];
      }
      $need[ $slot ]['requested'] += (float) $line['quantity'];
    }
    $short = [];
    foreach ( $need as $n ) {
      $available = 'ledger' === $owner
        ? Xophz_Compass_Bazaar_Stock_Factory::service()->available( $n['stock_product_id'], $n['location_id'] )
        : (float) wc_get_product( $n['stock_product_id'] )->get_stock_quantity();
      if ( $available < $n['requested'] ) {
        $short[] = array_merge( $n, [ 'available' => $available ] );
      }
    }
    if ( $short ) {
      throw new Xophz_Compass_Bazaar_Core_Http_Error( 'insufficient_stock', 1 === count( $short ) ? 'Not enough stock for 1 line.' : sprintf( 'Not enough stock for %d lines.', count( $short ) ), 409, [ 'lines' => $short ] );
    }
  }

  /**
   * Stock lines for Stock_Service::sync_order: one per catalog line item.
   * location_id is the item's _bazaar_location_id, else the order's, else 0.
   *
   * @return array[] {order_item_id, product_id, variation_id, quantity, location_id}
   */
  public static function stock_lines( WC_Order $order ): array {
    $fallback = (int) $order->get_meta( '_bazaar_location_id' );
    $lines    = [];
    foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
      if ( ! $item instanceof WC_Order_Item_Product || (int) $item->get_product_id() <= 0 ) {
        continue;
      }
      $loc     = $item->get_meta( '_bazaar_location_id', true );
      $lines[] = [
        'order_item_id' => (int) $item_id,
        'product_id'    => (int) $item->get_product_id(),
        'variation_id'  => (int) $item->get_variation_id(),
        'quantity'      => (float) $item->get_quantity(),
        'location_id'   => '' !== (string) $loc ? (int) $loc : $fallback,
      ];
    }
    return $lines;
  }

  private function line_item( array $line, int $requested ): WC_Order_Item_Product {
    $item = new WC_Order_Item_Product();
    if ( 'product' === $line['type'] ) {
      $id      = (int) $line['variation_id'] > 0 ? (int) $line['variation_id'] : (int) $line['product_id'];
      $product = wc_get_product( $id );
      if ( $product ) {
        $item->set_product( $product );
      }
      $item->add_meta_data( '_bazaar_location_id', Xophz_Compass_Bazaar_Stock_Locations::sale_location( $id, $requested > 0 ? $requested : null ), true );
    } else {
      $item->set_name( (string) $line['name'] );
      $item->set_product_id( 0 );
      $item->set_variation_id( 0 );
      $item->set_tax_class( '' );
      $item->add_meta_data( '_bazaar_custom_line', 'yes', true );
    }
    $order = $line['order'];
    $item->set_quantity( (int) $line['quantity'] );
    $item->set_subtotal( (string) $order['subtotal'] );
    $item->set_total( (string) $order['total'] );
    $item->set_subtotal_tax( (string) $order['subtotal_tax'] );
    $item->set_total_tax( (string) $order['total_tax'] );
    if ( ! empty( $order['taxes']['total'] ) || ! empty( $order['taxes']['subtotal'] ) ) {
      $item->set_taxes( [ 'subtotal' => (array) $order['taxes']['subtotal'], 'total' => (array) $order['taxes']['total'] ] );
    }
    $item->add_meta_data( '_bazaar_line_key', (string) $line['key'], true );
    return $item;
  }

  private function coupon_item( array $coupon ): WC_Order_Item_Coupon {
    $item = new WC_Order_Item_Coupon();
    $item->set_code( (string) $coupon['code'] );
    $item->set_discount( (string) $coupon['discount'] );
    $item->set_discount_tax( (string) $coupon['discount_tax'] );
    $wc_coupon = new WC_Coupon( (string) $coupon['code'] );
    if ( $wc_coupon->get_id() && method_exists( $wc_coupon, 'get_short_info' ) ) {
      $item->add_meta_data( 'coupon_info', $wc_coupon->get_short_info(), true );
    }
    return $item;
  }

  /** @throws Xophz_Compass_Bazaar_Core_Http_Error 422 pricing_drift */
  private function assert_parity( WC_Order $order, Xophz_Compass_Bazaar_Pricing_Priced_Cart $priced ): void {
    $decimals = function_exists( 'wc_get_price_decimals' ) ? (int) wc_get_price_decimals() : 2;
    $actual   = [
      'total_minor' => Xophz_Compass_Bazaar_Core_Money::to_minor( wc_format_decimal( $order->get_total(), $decimals ), $decimals ),
      'tax_minor'   => Xophz_Compass_Bazaar_Core_Money::to_minor( wc_format_decimal( $order->get_total_tax(), $decimals ), $decimals ),
    ];
    $expected = [ 'total_minor' => $priced->total_minor(), 'tax_minor' => $priced->tax_minor() ];
    if ( $actual === $expected ) {
      return;
    }
    error_log( 'Bazaar pricing_drift on order ' . $order->get_id() . ': priced ' . wp_json_encode( $priced->totals() ) . ' order ' . wp_json_encode( $actual ) );
    throw new Xophz_Compass_Bazaar_Core_Http_Error( 'pricing_drift', 'The order total does not match the priced cart. Nothing was charged.', 422, [ 'expected' => $expected, 'actual' => $actual ] );
  }

  /**
   * True for a user who is a store customer (role customer, or the
   * paying_customer meta), the same rule get_pos_customers uses. Staff
   * accounts are never attached to a sale as its customer.
   */
  public static function is_customer( int $user_id ): bool {
    $user = $user_id > 0 ? get_user_by( 'id', $user_id ) : null;
    return $user && ( in_array( 'customer', (array) $user->roles, true ) || get_user_meta( $user_id, 'paying_customer', true ) );
  }

  /** An existing customer's billing fields (customers only, see is_customer), or the typed guest fields. */
  private function attach_customer( WC_Order $order, array $customer ): void {
    $id    = (int) ( $customer['id'] ?? 0 );
    $email = (string) ( $customer['email'] ?? '' );
    $phone = (string) ( $customer['phone'] ?? '' );
    $name  = (string) ( $customer['name'] ?? '' );
    if ( self::is_customer( $id ) ) {
      $wc = new WC_Customer( $id );
      $order->set_customer_id( $id );
      $order->set_billing_first_name( $wc->get_billing_first_name() ?: ( $wc->get_first_name() ?: $name ) );
      $order->set_billing_last_name( $wc->get_billing_last_name() ?: $wc->get_last_name() );
      $order->set_billing_email( $wc->get_billing_email() ?: ( $wc->get_email() ?: $email ) );
      $order->set_billing_phone( $wc->get_billing_phone() ?: $phone );
      return;
    }
    if ( '' !== $email ) {
      $order->set_billing_email( $email );
    }
    if ( '' !== $phone ) {
      $order->set_billing_phone( $phone );
    }
    if ( '' !== $name ) {
      $parts = explode( ' ', $name, 2 );
      $order->set_billing_first_name( $parts[0] );
      $order->set_billing_last_name( $parts[1] ?? '' );
    }
  }
}
