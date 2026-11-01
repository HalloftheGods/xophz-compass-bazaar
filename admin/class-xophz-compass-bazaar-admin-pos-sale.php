<?php
/**
 * POS sale handlers: create the order and validate a coupon.
 *
 * Split out of Admin_Orders (#6946). The two action names are unchanged.
 *
 * Guaranteed: the array fields (items, customDiscounts, appliedCoupons,
 * splitPayments) are read from JSON bodies, form posts and stringified JSON
 * alike; a percent custom discount is that percent of the item subtotal; the
 * recorded cashier is always the logged-in user.
 *
 * Not guaranteed: this is still the legacy handler. It creates the order
 * before validating, trusts the prices WooCommerce holds and deletes the order
 * when a step fails. Checkout v2 (docs/plugins/bazaar-core-v2.md) replaces it.
 *
 * @package    Xophz_Compass_Bazaar
 * @subpackage Xophz_Compass_Bazaar/admin
 */
class Xophz_Compass_Bazaar_Admin_Pos_Sale {

  /** @var string */
  private $plugin_name;

  /** @var string */
  private $version;

  public $action_hooks = [
    'wp_ajax_create_pos_order'    => 'createPosOrder',
    'wp_ajax_validate_pos_coupon' => 'validatePosCoupon',
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

  public function validatePosCoupon() {
    try {
      if ( ! Xophz_Compass_Bazaar_Security_Guard::can_any( 'bazaar_sell' ) ) {
        throw new \Exception( 'Permission denied.' );
      }
      $args = Xophz_Compass_Bazaar_Core_Request::legacy_args();
      $code = isset( $args->code ) ? sanitize_text_field( $args->code ) : '';

      if ( ! $code ) {
        $this->send_json_response( [ 'success' => false, 'message' => 'Coupon code is required.' ] );
        return;
      }

      $coupon = new WC_Coupon( $code );
      if ( ! $coupon->get_id() || ! $coupon->is_valid() ) {
        $this->send_json_response( [ 'success' => false, 'message' => 'Invalid or expired coupon code.' ] );
        return;
      }

      $this->send_json_response( [
        'success' => true,
        'code'    => $coupon->get_code(),
        'type'    => $coupon->get_discount_type(),
        'amount'  => $coupon->get_amount(),
      ] );
    } catch ( \Throwable $e ) {
      $this->send_json_response( [ 'success' => false, 'message' => $e->getMessage() ] );
    }
  }

  /** Add a fee line (negative for a discount). */
  private function add_fee( $order, string $name, float $amount ): void {
    $fee = new WC_Order_Item_Fee();
    $fee->set_name( $name );
    $fee->set_amount( $amount );
    $fee->set_total( $amount );
    $added = $order->add_item( $fee );
    if ( is_wp_error( $added ) ) {
      throw new \Exception( $added->get_error_message() );
    }
  }

  /**
   * The amount of one custom discount. A percent discount is that percent
   * (capped at 100) of the item subtotal; anything else is a money amount.
   *
   * @param array $discount     One customDiscounts entry.
   * @param float $item_subtotal Sum of the product lines before discounts.
   */
  public static function custom_discount_amount( array $discount, float $item_subtotal ): float {
    $amount = isset( $discount['amount'] ) ? (float) $discount['amount'] : 0.0;
    if ( $amount <= 0 ) {
      return 0.0;
    }
    $type = strtolower( (string) ( $discount['type'] ?? '' ) );
    if ( 'percent' === $type || 'percentage' === $type ) {
      return round( $item_subtotal * min( $amount, 100.0 ) / 100, 2 );
    }
    return $amount;
  }

  /** Customer account lookup or creation. Returns the customer id, 0 for a guest. */
  private function resolve_customer_id( int $customer_id, string $email, string $phone, string $name, bool $create_account ): int {
    if ( $customer_id || empty( $email ) ) {
      return $customer_id;
    }
    $existing_user = get_user_by( 'email', $email );
    if ( $existing_user ) {
      return (int) $existing_user->ID;
    }
    if ( ! $create_account || ! function_exists( 'wc_create_new_customer' ) ) {
      return 0;
    }
    $username = wc_create_new_customer_username( $email );
    $password = wp_generate_password( 12, true );
    $new_id   = wc_create_new_customer( $email, $username, $password );
    if ( is_wp_error( $new_id ) ) {
      return 0;
    }
    if ( ! empty( $name ) ) {
      $parts = explode( ' ', $name, 2 );
      $fname = $parts[0];
      $lname = isset( $parts[1] ) ? $parts[1] : '';
      update_user_meta( $new_id, 'first_name', $fname );
      update_user_meta( $new_id, 'last_name', $lname );
      update_user_meta( $new_id, 'billing_first_name', $fname );
      update_user_meta( $new_id, 'billing_last_name', $lname );
    }
    if ( ! empty( $phone ) ) {
      update_user_meta( $new_id, 'billing_phone', $phone );
    }
    return (int) $new_id;
  }

  /** Copy the customer onto the order and record marketing opt-ins. */
  private function attach_customer( $order, int $customer_id, string $email, string $phone, string $name, array $opt_ins ): void {
    if ( $customer_id ) {
      $order->set_customer_id( $customer_id );
      $customer = new WC_Customer( $customer_id );
      $order->set_billing_first_name( $customer->get_billing_first_name() ?: ( $customer->get_first_name() ?: $name ) );
      $order->set_billing_last_name( $customer->get_billing_last_name() ?: $customer->get_last_name() );
      $order->set_billing_email( $customer->get_billing_email() ?: ( $customer->get_email() ?: $email ) );
      $order->set_billing_phone( $customer->get_billing_phone() ?: ( $customer->get_meta( 'billing_phone' ) ?: $phone ) );
    } else {
      if ( ! empty( $email ) ) {
        $order->set_billing_email( $email );
      }
      if ( ! empty( $phone ) ) {
        $order->set_billing_phone( $phone );
      }
      if ( ! empty( $name ) ) {
        $parts = explode( ' ', $name, 2 );
        $order->set_billing_first_name( $parts[0] );
        $order->set_billing_last_name( isset( $parts[1] ) ? $parts[1] : '' );
      }
    }

    foreach ( $opt_ins as $key => $on ) {
      if ( ! $on ) {
        continue;
      }
      $order->update_meta_data( "_pos_{$key}_optin", 'yes' );
      $order->update_meta_data( "_pos_{$key}_optin_at", current_time( 'mysql' ) );
      if ( $customer_id ) {
        $channel = 'marketing' === $key ? 'email' : $key;
        update_user_meta( $customer_id, "_marketing_{$channel}_optin", 'yes' );
        update_user_meta( $customer_id, "_marketing_{$channel}_optin_at", current_time( 'mysql' ) );
      }
    }
  }

  public function createPosOrder() {
    $order = null;
    try {
      if ( ! Xophz_Compass_Bazaar_Security_Guard::can_any( 'bazaar_sell' ) ) {
        throw new \Exception( 'Permission denied.' );
      }

      $R    = 'Xophz_Compass_Bazaar_Core_Request';
      $args = $R::legacy_args();

      $items           = $R::array_param( $args, 'items' );
      $customDiscounts = $R::array_param( $args, 'customDiscounts' );
      $appliedCoupons  = $R::array_param( $args, 'appliedCoupons' );
      $splitPayments   = $R::array_param( $args, 'splitPayments' );
      $discount        = isset( $args->discount ) ? floatval( $args->discount ) : 0;
      $tipAmount       = isset( $args->tipAmount ) ? floatval( $args->tipAmount ) : 0;
      $paymentMethod   = isset( $args->paymentMethod ) ? $args->paymentMethod : 'cash';

      if ( empty( $items ) ) {
        $this->send_json_response( [ 'success' => false, 'message' => 'Cart is empty.' ] );
        return;
      }

      $order = wc_create_order();
      if ( ! $order || is_wp_error( $order ) ) {
        $msg = is_wp_error( $order ) ? $order->get_error_message() : 'Failed to create order.';
        throw new \Exception( $msg );
      }

      $customerEmail = isset( $args->customerEmail ) ? sanitize_email( $args->customerEmail ) : '';
      $customerPhone = isset( $args->customerPhone ) ? sanitize_text_field( $args->customerPhone ) : '';
      $customerName  = isset( $args->customerName ) ? sanitize_text_field( $args->customerName ) : '';
      $customerId    = $this->resolve_customer_id(
        isset( $args->customerId ) ? intval( $args->customerId ) : 0,
        $customerEmail,
        $customerPhone,
        $customerName,
        $R::bool_param( $args, 'createAccount' )
      );
      $this->attach_customer( $order, $customerId, $customerEmail, $customerPhone, $customerName, [
        'marketing' => $R::bool_param( $args, 'marketingOptIn' ),
        'sms'       => $R::bool_param( $args, 'smsOptIn' ),
      ] );

      // The cashier is the logged-in user. A different id from the register is
      // ignored and noted: the client cannot name who rang the sale.
      $cashier_id = get_current_user_id();
      $sent_id    = isset( $args->cashierId ) ? intval( $args->cashierId ) : 0;
      if ( $cashier_id ) {
        $order->update_meta_data( '_pos_cashier_id', $cashier_id );
      }
      if ( $sent_id && $sent_id !== $cashier_id ) {
        $order->add_order_note( sprintf( 'The register sent cashier id %d. It was ignored; the sale is recorded for user %d.', $sent_id, $cashier_id ) );
      }

      foreach ( $items as $item ) {
        $item       = is_array( $item ) ? $item : [];
        $product_id = isset( $item['product_id'] ) ? intval( $item['product_id'] ) : 0;
        $quantity   = isset( $item['quantity'] ) ? intval( $item['quantity'] ) : 0;
        if ( $quantity <= 0 ) {
          continue;
        }
        $product = wc_get_product( $product_id );

        if ( $product && ! is_wp_error( $product ) ) {
          $added = $order->add_product( $product, $quantity );
          if ( is_wp_error( $added ) ) {
            throw new \Exception( $added->get_error_message() );
          }
        }
      }

      if ( $discount > 0 ) {
        $this->add_fee( $order, 'POS Discount', -$discount );
      }

      foreach ( $appliedCoupons as $code ) {
        if ( is_string( $code ) ) {
          $applied = $order->apply_coupon( sanitize_text_field( $code ) );
          if ( is_wp_error( $applied ) ) {
            throw new \Exception( $applied->get_error_message() );
          }
        }
      }

      $item_subtotal = 0.0;
      foreach ( $order->get_items() as $line ) {
        $item_subtotal += (float) $line->get_subtotal();
      }
      foreach ( $customDiscounts as $cd ) {
        $cd     = is_array( $cd ) ? $cd : [];
        $amount = self::custom_discount_amount( $cd, $item_subtotal );
        if ( $amount > 0 ) {
          $name = ! empty( $cd['name'] ) ? sanitize_text_field( $cd['name'] ) : 'Custom Discount';
          $this->add_fee( $order, $name, -$amount );
        }
      }

      if ( $tipAmount > 0 ) {
        $this->add_fee( $order, 'Tip', $tipAmount );
        $order->update_meta_data( '_pos_tip_amount', $tipAmount );
        $order->update_meta_data( '_pos_tip_cashier_id', $cashier_id );
      }

      // Set payment method and origin
      $order->set_payment_method( $paymentMethod );

      $method_title = ucfirst( $paymentMethod );
      if ( $paymentMethod === 'bazaar_split' ) {
        $method_title = 'Split Payment';
      } elseif ( function_exists( 'WC' ) ) {
        $gateways_obj = WC()->payment_gateways();
        if ( ! $gateways_obj ) {
          throw new \Exception( 'Payment gateways unavailable.' );
        }
        $gateways = $gateways_obj->payment_gateways();
        if ( isset( $gateways[ $paymentMethod ] ) ) {
          $gw           = $gateways[ $paymentMethod ];
          $method_title = Xophz_Compass_Bazaar_Admin_Payment_Settings::clean_payment_title( $gw->title ?: $gw->get_method_title(), $paymentMethod );
        }
      }

      $order->set_payment_method_title( $method_title );
      $order->set_created_via( 'bazaar_pos' );

      if ( $paymentMethod === 'bazaar_split' && ! empty( $splitPayments ) ) {
        $order->update_meta_data( '_pos_split_payments', wp_json_encode( $splitPayments ) );
        $note = "Split Payment Breakdown:\n";
        foreach ( $splitPayments as $sp ) {
          $method = is_array( $sp ) && isset( $sp['method'] ) ? (string) $sp['method'] : 'unknown';
          $amt    = is_array( $sp ) && isset( $sp['amount'] ) ? floatval( $sp['amount'] ) : 0;
          $note  .= '- ' . ucfirst( $method ) . ': $' . number_format( $amt, 2 ) . "\n";
        }
        $order->add_order_note( $note );
      }

      $order->calculate_totals();

      if ( $paymentMethod === 'bazaar_split' ) {
        $split_sum = 0.0;
        foreach ( $splitPayments as $sp ) {
          $split_sum += is_array( $sp ) && isset( $sp['amount'] ) ? floatval( $sp['amount'] ) : 0.0;
        }
        if ( abs( $split_sum - $order->get_total() ) >= 0.01 ) {
          throw new \Exception( 'Split payment sum does not match order total.' );
        }
      }

      // Digital tenders are only paid when the server issued a one-time
      // authorization for this cashier, method and total (#6783).
      if ( Xophz_Compass_Bazaar_Pos_Authorization::requires_authorization( (string) $paymentMethod, (string) $method_title ) ) {
        $token  = sanitize_text_field( (string) ( $args->authorizationToken ?? '' ) );
        $reason = Xophz_Compass_Bazaar_Pos_Authorization::consume( $token, get_current_user_id(), sanitize_key( (string) $paymentMethod ), $order->get_total() );
        if ( '' !== $reason ) {
          throw new \Exception( sprintf( 'Payment not authorized (%s). %s was not charged and the sale was not recorded.', $reason, $method_title ) );
        }
        $order->add_order_note( 'Payment authorized through the Bazaar POS test portal (no processor connected).' );
      }

      // Mimic WooCommerce behavior for manual payment gateways
      if ( in_array( $paymentMethod, [ 'bacs', 'cheque' ] ) ) {
        $status_res = $order->update_status( 'on-hold', 'Order created via Bazaar POS.' );
      } elseif ( $paymentMethod === 'cod' ) {
        $status_res = $order->update_status( 'processing', 'Order created via Bazaar POS.' );
      } else {
        // For card/cash payments at POS, we assume immediate completion
        $status_res = $order->update_status( 'completed', 'Order created via Bazaar POS.' );
      }
      if ( is_wp_error( $status_res ) ) {
        throw new \Exception( $status_res->get_error_message() );
      }

      $save_res = $order->save();
      if ( is_wp_error( $save_res ) ) {
        throw new \Exception( $save_res->get_error_message() );
      }

      if ( $customerId ) {
        do_action( 'xophz_compass_record_action', 'bazaar_pos_purchase', $customerId, [
          'order_id'       => $order->get_id(),
          'total'          => $order->get_total(),
          'payment_method' => $paymentMethod,
        ] );
      }

      $this->send_json_response( [
        'success'   => true,
        'order_id'  => $order->get_id(),
        'order_key' => $order->get_order_key(),
      ] );
    } catch ( \Throwable $e ) {
      if ( $order && is_a( $order, 'WC_Order' ) && $order->get_id() ) {
        $order->delete( true );
      }
      $this->send_json_response( [ 'success' => false, 'message' => $e->getMessage() ] );
    }
  }
}
