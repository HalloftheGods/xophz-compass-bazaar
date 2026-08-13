<?php

/**
 * The admin-specific functionality of the plugin.
 *
 * @link       http://example.com
 * @since      1.0.0
 *
 * @package    Xophz_Compass_Bazaar
 * @subpackage Xophz_Compass_Bazaar/admin
 */

/**
 * The admin-specific functionality of the plugin.
 *
 * Defines the plugin name, version, and two examples hooks for how to
 * enqueue the admin-specific stylesheet and JavaScript.
 *
 * @package    Xophz_Compass_Bazaar
 * @subpackage Xophz_Compass_Bazaar/admin
 * @author     Your Name <email@example.com>
 */
class Xophz_Compass_Bazaar_Admin_Orders {
  /**
  * The ID of this plugin.
  *
  * @since    1.0.0
  * @access   private
  * @var      string    $plugin_name    The ID of this plugin.
  */
  private $plugin_name;

  /**
  * The version of this plugin.
  *
  * @since    1.0.0
  * @access   private
  * @var      string    $version    The current version of this plugin.
  */
  private $version;

  public  $action_hooks = [
    'wp_ajax_get_orders' => 'getOrders',
    'wp_ajax_get_categories' => 'getCategories',
    'wp_ajax_create_pos_order' => 'createPosOrder',
    'wp_ajax_get_payment_gateways' => 'getPaymentGateways',
    'wp_ajax_get_all_payment_gateways' => 'getAllPaymentGateways',
    'wp_ajax_toggle_payment_gateway' => 'togglePaymentGateway',
    'wp_ajax_update_order_status' => 'updateOrderStatus',
    'wp_ajax_validate_pos_coupon' => 'validatePosCoupon',
    'wp_ajax_send_pos_receipt' => 'sendPosReceipt',
    'wp_ajax_get_pos_customers' => 'getPosCustomers',
    'wp_ajax_email_shift_summary' => 'emailShiftSummary',
    'wp_ajax_get_pos_order_for_refund' => 'getPosOrderForRefund',
    'wp_ajax_process_pos_refund' => 'processPosRefund',
    'wp_ajax_bazaar_get_coupons' => 'getCoupons',
    'wp_ajax_bazaar_save_coupon' => 'saveCoupon',
    'wp_ajax_bazaar_delete_coupon' => 'deleteCoupon',
  ];


  /**
  * Initialize the class and set its properties.
  *
  * @since    1.0.0
  * @param    string    $plugin_name  The name of this plugin.
  * @param    string    $version      The version of this plugin.
  */
  public function __construct( $plugin_name, $version ) {
    $this->plugin_name = $plugin_name;
    $this->version = $version;
  }

  /**
   * Clean active output buffer and emit structured JSON response.
   *
   * @param array $payload Response data payload.
   * @return void
   */
  private function send_json_response($payload) {
      while (ob_get_level() > 0) {
          @ob_end_clean();
      }
      Xophz_Compass::output_json($payload);
  }

  public function getOrders(){
    try {
      if (!current_user_can('manage_woocommerce') && !current_user_can('edit_shop_orders')) {
          throw new \Exception('Permission denied.');
      }
      $args   = Xophz_Compass::get_input_json();
      $args->return = 'objects';

      $orderIds = Xophz_Compass_Bazaar_Admin_Orders::getOrderIds($args);
      if (is_wp_error($orderIds)) {
          throw new \Exception($orderIds->get_error_message());
      }

      $mapOrderData = function($id){
        $order = wc_get_order($id);
        if (!$order || is_wp_error($order)) {
            return null;
        }
        $data = $order->get_data();
        $data['order'] = $order->get_order_number();
        if (isset($data['date_created']) && is_a($data['date_created'], 'WC_DateTime')) {
            $data['date_created'] = $data['date_created']->date('Y-m-d H:i:s');
        }
        
        $cashier_id = $order->get_meta('_pos_cashier_id');
        if ($cashier_id) {
            $cashier = get_userdata($cashier_id);
            if ($cashier) {
                $data['cashier_name'] = $cashier->display_name;
            }
        }
        
        return $data;
      };

      $raw_orders = isset($orderIds->orders) && is_array($orderIds->orders) ? $orderIds->orders : [];
      $orders_data = array_values(array_filter(array_map( $mapOrderData, $raw_orders )));

      $this->send_json_response([
        'success'     => true,
        'total_count' => isset($orderIds->total) ? (int) $orderIds->total : 0, 
        'data'        => $orders_data
      ]);
    } catch (\Throwable $e) {
      $this->send_json_response([
        'success' => false,
        'message' => $e->getMessage()
      ]);
    }
  }

  public function validatePosCoupon() {
    try {
      if (!current_user_can('manage_woocommerce') && !current_user_can('edit_shop_orders')) {
          throw new \Exception('Permission denied.');
      }
      $args = Xophz_Compass::get_input_json();
      $code = isset($args->code) ? sanitize_text_field($args->code) : '';

      if (!$code) {
          $this->send_json_response(['success' => false, 'message' => 'Coupon code is required.']);
          return;
      }

      $coupon = new WC_Coupon($code);
      if (!$coupon->get_id() || !$coupon->is_valid()) {
          $this->send_json_response(['success' => false, 'message' => 'Invalid or expired coupon code.']);
          return;
      }

      $this->send_json_response([
          'success' => true,
          'code'    => $coupon->get_code(),
          'type'    => $coupon->get_discount_type(),
          'amount'  => $coupon->get_amount(),
      ]);
    } catch (\Throwable $e) {
      $this->send_json_response(['success' => false, 'message' => $e->getMessage()]);
    }
  }

  public function sendPosReceipt() {
    try {
      if (!current_user_can('manage_woocommerce') && !current_user_can('edit_shop_orders')) {
          throw new \Exception('Permission denied.');
      }
      $args = Xophz_Compass::get_input_json();
      $recipient = isset($args->recipient) ? sanitize_text_field($args->recipient) : '';
      $order_id = isset($args->order_id) ? intval($args->order_id) : 0;

      if (!$recipient || !$order_id) {
          $this->send_json_response(['success' => false, 'message' => 'Recipient and Order ID are required.']);
          return;
      }

      $order = wc_get_order($order_id);
      if (!$order || is_wp_error($order)) {
          $this->send_json_response(['success' => false, 'message' => 'Order not found.']);
          return;
      }

      $is_email = strpos($recipient, '@') !== false;

      if ($is_email) {
          $order->set_billing_email($recipient);
          $save_res = $order->save();
          if (is_wp_error($save_res)) {
              throw new \Exception($save_res->get_error_message());
          }
          
          $subscribe_newsletter = isset($args->subscribe_newsletter) ? !empty($args->subscribe_newsletter) : false;
          if ($subscribe_newsletter) {
              global $wpdb;
              $table = $wpdb->prefix . 'bomb_bag_subscribers';
              $junction = $wpdb->prefix . 'bomb_bag_list_subscribers';
              
              // Check if email already exists in Bomb Bag
              $existing_id = $wpdb->get_var($wpdb->prepare(
                  "SELECT id FROM $table WHERE email = %s", $recipient
              ));
              
              if (!$existing_id) {
                  $wpdb->insert($table, array(
                      'email'      => $recipient,
                      'first_name' => $order->get_billing_first_name() ?: '',
                      'last_name'  => $order->get_billing_last_name() ?: '',
                      'source'     => 'pos_checkout',
                      'status'     => 'active'
                  ));
                  $existing_id = $wpdb->insert_id;
              }
              
              if ($existing_id) {
                  // Find the first available list or fallback to 1
                  $lists_table = $wpdb->prefix . 'bomb_bag_lists';
                  $default_list_id = $wpdb->get_var("SELECT id FROM $lists_table ORDER BY id ASC LIMIT 1");
                  
                  if ($default_list_id) {
                      $wpdb->query($wpdb->prepare(
                          "INSERT IGNORE INTO $junction (list_id, subscriber_id) VALUES (%d, %d)",
                          $default_list_id, $existing_id
                      ));
                  }
              }
          }

          if (function_exists('WC')) {
              $mailer = WC()->mailer();
              $invoice_email = isset($mailer->emails['WC_Email_Customer_Invoice']) ? $mailer->emails['WC_Email_Customer_Invoice'] : null;
              if ($invoice_email) {
                  $invoice_email->trigger($order_id);
              }
          }

          $this->send_json_response([
              'success' => true,
              'type'    => 'email',
              'message' => 'Receipt invoice email sent successfully.'
          ]);
      } else {
          $sanitized_phone = preg_replace('/[^0-9+]/', '', $recipient);
          
          do_action('xophz_compass_send_sms_receipt', $sanitized_phone, $order_id);

          $this->send_json_response([
              'success' => true,
              'type'    => 'sms',
              'message' => 'Receipt SMS request dispatched.'
          ]);
      }
    } catch (\Throwable $e) {
      $this->send_json_response(['success' => false, 'message' => $e->getMessage()]);
    }
  }

  public function emailShiftSummary() {
    try {
      if (!current_user_can('manage_woocommerce') && !current_user_can('edit_shop_orders')) {
          throw new \Exception('Permission denied.');
      }
      $args = Xophz_Compass::get_input_json();
      $current_user = wp_get_current_user();
      
      if (!$current_user || !$current_user->exists()) {
          $this->send_json_response(['success' => false, 'message' => 'Not authenticated.']);
          return;
      }

      $cash = isset($args->cash) ? floatval($args->cash) : 0;
      $card = isset($args->card) ? floatval($args->card) : 0;
      $coupons = isset($args->coupons) ? floatval($args->coupons) : 0;
      $customDiscounts = isset($args->customDiscounts) ? floatval($args->customDiscounts) : 0;
      $totalTips = isset($args->totalTips) ? floatval($args->totalTips) : 0;
      $totalOrders = isset($args->totalOrders) ? intval($args->totalOrders) : 0;
      $totalSales = $cash + $card;

      $site_name = get_bloginfo('name');
      $date_str = current_time('M d, Y, h:i A');

      $html = "
      <div style='font-family: sans-serif; max-width: 500px; margin: 0 auto; padding: 20px; border: 1px solid #eaeaea; border-radius: 8px;'>
          <h2 style='text-align: center; color: #333;'>$site_name - POS Shift Summary</h2>
          <p style='text-align: center; color: #666; font-size: 14px;'>Report generated: $date_str</p>
          <hr style='border: none; border-top: 1px solid #eaeaea; margin: 20px 0;'>
          <table style='width: 100%; border-collapse: collapse;'>
              <tr><td style='padding: 8px 0; color: #555;'>Total Orders:</td><td style='text-align: right; font-weight: bold;'>$totalOrders</td></tr>
              <tr><td style='padding: 8px 0; color: #555;'>Cash Tended:</td><td style='text-align: right; font-weight: bold; color: #28a745;'>$" . number_format($cash, 2) . "</td></tr>
              <tr><td style='padding: 8px 0; color: #555;'>Card/Electronic:</td><td style='text-align: right; font-weight: bold; color: #007bff;'>$" . number_format($card, 2) . "</td></tr>
              <tr><td style='padding: 8px 0; color: #555;'>Tips Collected:</td><td style='text-align: right; font-weight: bold; color: #20c997;'>+$" . number_format($totalTips, 2) . "</td></tr>
              <tr><td style='padding: 8px 0; color: #555;'>Coupons Applied:</td><td style='text-align: right; font-weight: bold; color: #ffc107;'>-$" . number_format($coupons, 2) . "</td></tr>
              <tr><td style='padding: 8px 0; color: #555;'>Custom Discounts:</td><td style='text-align: right; font-weight: bold; color: #ffc107;'>-$" . number_format($customDiscounts, 2) . "</td></tr>
          </table>
          <hr style='border: none; border-top: 1px solid #eaeaea; margin: 20px 0;'>
          <div style='display: flex; justify-content: space-between; font-size: 18px; font-weight: bold;'>
              <span>Total Shift Sales:</span>
              <span>$" . number_format($totalSales, 2) . "</span>
          </div>
      </div>
      ";

      $headers = ['Content-Type: text/html; charset=UTF-8'];
      
      $recipients = [$current_user->user_email];
      $cashierId = isset($args->cashierId) ? intval($args->cashierId) : 0;
      if ($cashierId && $cashierId !== $current_user->ID) {
          $cashier_user = get_userdata($cashierId);
          if ($cashier_user && !empty($cashier_user->user_email)) {
              $recipients[] = $cashier_user->user_email;
          }
      }

      $sent = wp_mail($recipients, "POS Shift Summary - $site_name", $html, $headers);

      $this->send_json_response([
          'success' => (bool)$sent,
          'message' => $sent ? 'Shift summary emailed to ' . implode(', ', $recipients) : 'Failed to send email.'
      ]);
    } catch (\Throwable $e) {
      $this->send_json_response(['success' => false, 'message' => $e->getMessage()]);
    }
  }

  public function createPosOrder() {
    $order = null;
    try {
      if (!current_user_can('manage_woocommerce') && !current_user_can('edit_shop_orders')) {
          throw new \Exception('Permission denied.');
      }

      $args = Xophz_Compass::get_input_json();
      $items = isset($args->items) ? $args->items : [];

      // Parse stringified JSON items array from FormData
      if (is_string($items)) {
          $decoded = json_decode($items);
          if (json_last_error() !== JSON_ERROR_NONE) {
              $raw_input = file_get_contents('php://input');
              parse_str($raw_input, $raw_parsed);
              $decoded = isset($raw_parsed['items']) ? json_decode(stripslashes($raw_parsed['items'])) : [];
          }
          $items = $decoded;
      }

      $discount = isset($args->discount) ? floatval($args->discount) : 0;
      $customDiscounts = isset($args->customDiscounts) ? $args->customDiscounts : [];
      $appliedCoupons = isset($args->appliedCoupons) ? $args->appliedCoupons : [];
      $tipAmount = isset($args->tipAmount) ? floatval($args->tipAmount) : 0;
      $paymentMethod = isset($args->paymentMethod) ? $args->paymentMethod : 'cash';
      $splitPayments = isset($args->splitPayments) ? $args->splitPayments : [];

      if (empty($items)) {
          $this->send_json_response(['success' => false, 'message' => 'Cart is empty.']);
          return;
      }

      $order = wc_create_order();
      if (!$order || is_wp_error($order)) {
          $msg = is_wp_error($order) ? $order->get_error_message() : 'Failed to create order.';
          throw new \Exception($msg);
      }
      
      $customerId = isset($args->customerId) ? intval($args->customerId) : 0;
      $customerEmail = isset($args->customerEmail) ? sanitize_email($args->customerEmail) : '';
      $customerPhone = isset($args->customerPhone) ? sanitize_text_field($args->customerPhone) : '';
      $customerName = isset($args->customerName) ? sanitize_text_field($args->customerName) : '';
      $createAccount = !empty($args->createAccount) && ( $args->createAccount === true || $args->createAccount === 'true' || $args->createAccount === 1 );
      $marketingOptIn = !empty($args->marketingOptIn) && ( $args->marketingOptIn === true || $args->marketingOptIn === 'true' || $args->marketingOptIn === 1 );
      $smsOptIn = !empty($args->smsOptIn) && ( $args->smsOptIn === true || $args->smsOptIn === 'true' || $args->smsOptIn === 1 );

      if (!$customerId && !empty($customerEmail)) {
          $existing_user = get_user_by('email', $customerEmail);
          if ($existing_user) {
              $customerId = $existing_user->ID;
          } elseif ($createAccount && function_exists('wc_create_new_customer')) {
              $username = wc_create_new_customer_username($customerEmail);
              $password = wp_generate_password(12, true);
              $new_id = wc_create_new_customer($customerEmail, $username, $password);
              if (!is_wp_error($new_id)) {
                  $customerId = $new_id;
                  if (!empty($customerName)) {
                      $parts = explode(' ', $customerName, 2);
                      $fname = $parts[0];
                      $lname = isset($parts[1]) ? $parts[1] : '';
                      update_user_meta($customerId, 'first_name', $fname);
                      update_user_meta($customerId, 'last_name', $lname);
                      update_user_meta($customerId, 'billing_first_name', $fname);
                      update_user_meta($customerId, 'billing_last_name', $lname);
                  }
                  if (!empty($customerPhone)) {
                      update_user_meta($customerId, 'billing_phone', $customerPhone);
                  }
              }
          }
      }

      if ($customerId) {
          $order->set_customer_id($customerId);
          $customer = new WC_Customer($customerId);
          if ($customer) {
              $order->set_billing_first_name($customer->get_billing_first_name() ?: ($customer->get_first_name() ?: $customerName));
              $order->set_billing_last_name($customer->get_billing_last_name() ?: $customer->get_last_name());
              $order->set_billing_email($customer->get_billing_email() ?: ($customer->get_email() ?: $customerEmail));
              $order->set_billing_phone($customer->get_billing_phone() ?: ($customer->get_meta('billing_phone') ?: $customerPhone));
          }
      } else {
          if (!empty($customerEmail)) {
              $order->set_billing_email($customerEmail);
          }
          if (!empty($customerPhone)) {
              $order->set_billing_phone($customerPhone);
          }
          if (!empty($customerName)) {
              $parts = explode(' ', $customerName, 2);
              $order->set_billing_first_name($parts[0]);
              $order->set_billing_last_name(isset($parts[1]) ? $parts[1] : '');
          }
      }

      if ($marketingOptIn) {
          $order->update_meta_data('_pos_marketing_optin', 'yes');
          $order->update_meta_data('_pos_marketing_optin_at', current_time('mysql'));
          if ($customerId) {
              update_user_meta($customerId, '_marketing_email_optin', 'yes');
              update_user_meta($customerId, '_marketing_email_optin_at', current_time('mysql'));
          }
      }

      if ($smsOptIn) {
          $order->update_meta_data('_pos_sms_optin', 'yes');
          $order->update_meta_data('_pos_sms_optin_at', current_time('mysql'));
          if ($customerId) {
              update_user_meta($customerId, '_marketing_sms_optin', 'yes');
              update_user_meta($customerId, '_marketing_sms_optin_at', current_time('mysql'));
          }
      }

      $cashier_id = isset($args->cashierId) ? intval($args->cashierId) : get_current_user_id();
      if ($cashier_id) {
          $order->update_meta_data('_pos_cashier_id', $cashier_id);
          
          // Track the WP post author to the cashier for global attribution and CRM
          if ( get_post_type( $order->get_id() ) === 'shop_order' ) {
              wp_update_post( [
                  'ID'          => $order->get_id(),
                  'post_author' => $cashier_id
              ] );
          }
      }

      foreach ($items as $item) {
          $product_id = is_object($item) ? (isset($item->product_id) ? intval($item->product_id) : 0) : (isset($item['product_id']) ? intval($item['product_id']) : 0);
          $quantity = is_object($item) ? (isset($item->quantity) ? intval($item->quantity) : 0) : (isset($item['quantity']) ? intval($item['quantity']) : 0);
          if ($quantity <= 0) {
              continue;
          }
          $product = wc_get_product($product_id);

          if ($product && !is_wp_error($product)) {
              $added = $order->add_product($product, $quantity);
              if (is_wp_error($added)) {
                  throw new \Exception($added->get_error_message());
              }
          }
      }

      if ($discount > 0) {
          $item = new WC_Order_Item_Fee();
          $item->set_name('POS Discount');
          $item->set_amount(-$discount);
          $item->set_total(-$discount);
          $added = $order->add_item($item);
          if (is_wp_error($added)) {
              throw new \Exception($added->get_error_message());
          }
      }

      if (is_array($appliedCoupons)) {
          foreach ($appliedCoupons as $code) {
              if (is_string($code)) {
                  $applied = $order->apply_coupon(sanitize_text_field($code));
                  if (is_wp_error($applied)) {
                      throw new \Exception($applied->get_error_message());
                  }
              }
          }
      }

      if (is_array($customDiscounts)) {
          foreach ($customDiscounts as $cd) {
              $amount = is_object($cd) ? (isset($cd->amount) ? floatval($cd->amount) : 0) : (isset($cd['amount']) ? floatval($cd['amount']) : 0);
              $name = is_object($cd) ? (isset($cd->name) && !empty($cd->name) ? sanitize_text_field($cd->name) : 'Custom Discount') : (isset($cd['name']) && !empty($cd['name']) ? sanitize_text_field($cd['name']) : 'Custom Discount');
              
              if ($amount > 0) {
                  $item = new WC_Order_Item_Fee();
                  $item->set_name($name);
                  $item->set_amount(-$amount);
                  $item->set_total(-$amount);
                  $added = $order->add_item($item);
                  if (is_wp_error($added)) {
                      throw new \Exception($added->get_error_message());
                  }
              }
          }
      }

      if ($tipAmount > 0) {
          $item = new WC_Order_Item_Fee();
          $item->set_name('Tip');
          $item->set_amount($tipAmount);
          $item->set_total($tipAmount);
          $added = $order->add_item($item);
          if (is_wp_error($added)) {
              throw new \Exception($added->get_error_message());
          }
          $order->update_meta_data('_pos_tip_amount', $tipAmount);
          if (isset($cashier_id)) {
              $order->update_meta_data('_pos_tip_cashier_id', $cashier_id);
          }
      }

      // Set payment method and origin
      $order->set_payment_method($paymentMethod);
      
      $method_title = ucfirst($paymentMethod);
      if ($paymentMethod === 'bazaar_split') {
          $method_title = 'Split Payment';
      } else if ( function_exists( 'WC' ) ) {
          $gateways_obj = WC()->payment_gateways();
          if (!$gateways_obj) {
              throw new \Exception('Payment gateways unavailable.');
          }
          $gateways = $gateways_obj->payment_gateways();
          if ( isset( $gateways[$paymentMethod] ) ) {
              $gw = $gateways[$paymentMethod];
              $raw = $gw->title ?: $gw->get_method_title();
              $method_title = $this->clean_payment_title($raw, $paymentMethod);
          }
      }

      $order->set_payment_method_title($method_title);
      $order->set_created_via('bazaar_pos');

      if ($paymentMethod === 'bazaar_split' && !empty($splitPayments)) {
          $order->update_meta_data('_pos_split_payments', json_encode($splitPayments));
          $note = "Split Payment Breakdown:\n";
          foreach ($splitPayments as $sp) {
              $method = is_object($sp) ? (isset($sp->method) ? $sp->method : 'unknown') : (isset($sp['method']) ? $sp['method'] : 'unknown');
              $amt = is_object($sp) ? (isset($sp->amount) ? floatval($sp->amount) : 0) : (isset($sp['amount']) ? floatval($sp['amount']) : 0);
              $note .= "- " . ucfirst($method) . ": $" . number_format($amt, 2) . "\n";
          }
          $order->add_order_note($note);
      }

      // Calculate totals
      $order->calculate_totals();

      if ($paymentMethod === 'bazaar_split') {
          $split_sum = 0.0;
          if (is_array($splitPayments)) {
              foreach ($splitPayments as $sp) {
                  $amt = is_object($sp) ? (isset($sp->amount) ? floatval($sp->amount) : 0.0) : (isset($sp['amount']) ? floatval($sp['amount']) : 0.0);
                  $split_sum += $amt;
              }
          }
          if (abs($split_sum - $order->get_total()) >= 0.01) {
              throw new \Exception('Split payment sum does not match order total.');
          }
      }

      // Mimic WooCommerce behavior for manual payment gateways
      if ( in_array( $paymentMethod, ['bacs', 'cheque'] ) ) {
          $status_res = $order->update_status('on-hold', 'Order created via Bazaar POS.');
      } elseif ( $paymentMethod === 'cod' ) {
          $status_res = $order->update_status('processing', 'Order created via Bazaar POS.');
      } else {
          // For card/cash payments at POS, we assume immediate completion
          $status_res = $order->update_status('completed', 'Order created via Bazaar POS.');
      }
      if (is_wp_error($status_res)) {
          throw new \Exception($status_res->get_error_message());
      }

      $save_res = $order->save();
      if (is_wp_error($save_res)) {
          throw new \Exception($save_res->get_error_message());
      }

      if ($customerId) {
          do_action('xophz_compass_record_action', 'bazaar_pos_purchase', $customerId, [
              'order_id' => $order->get_id(),
              'total' => $order->get_total(),
              'payment_method' => $paymentMethod
          ]);
      }

      $this->send_json_response([
          'success' => true, 
          'order_id' => $order->get_id(),
          'order_key' => $order->get_order_key()
      ]);
    } catch (\Throwable $e) {
        if ($order && is_a($order, 'WC_Order') && $order->get_id()) {
            $order->delete(true);
        }
        $this->send_json_response([
            'success' => false, 
            'message' => $e->getMessage()
        ]);
    }
  }

  private function clean_payment_title($raw_title, $gateway_id = '') {
      $title = trim($raw_title);
      
      if (preg_match('/WooPayments\s*\(([^)]+)\)/i', $title, $matches)) {
          $inner = trim($matches[1]);
          if (strcasecmp($inner, 'Card') === 0) {
              return 'Credit Card';
          }
          return $inner;
      }

      if (strcasecmp($title, 'WooPayments') === 0) {
          return 'Credit Card';
      }

      return $title;
  }

  public function getPaymentGateways() {
    try {
      if (!current_user_can('manage_woocommerce') && !current_user_can('edit_shop_orders')) {
          throw new \Exception('Permission denied.');
      }

      if ( ! function_exists( 'WC' ) ) {
          $this->send_json_response(['success' => true, 'gateways' => []]);
          return;
      }
      
      $gateways_obj = WC()->payment_gateways();
      if (!$gateways_obj) {
          throw new \Exception('Payment gateways unavailable.');
      }
      $gateways = $gateways_obj->get_available_payment_gateways();
      if (is_wp_error($gateways)) {
          throw new \Exception($gateways->get_error_message());
      }
      $data = [];
      
      foreach($gateways as $gateway) {
          $raw_title = $gateway->title ?: $gateway->get_method_title();
          $data[] = [
              'id' => $gateway->id,
              'title' => $this->clean_payment_title($raw_title, $gateway->id),
              'method_title' => $gateway->get_method_title()
          ];
      }
      
      $this->send_json_response(['success' => true, 'gateways' => array_values($data)]);
    } catch (\Throwable $e) {
      $this->send_json_response(['success' => false, 'message' => $e->getMessage(), 'gateways' => []]);
    }
  }

  public function getAllPaymentGateways() {
    try {
      if (!current_user_can('manage_woocommerce') && !current_user_can('edit_shop_orders')) {
          throw new \Exception('Permission denied.');
      }

      if ( ! function_exists( 'WC' ) ) {
          $this->send_json_response(['success' => false, 'gateways' => []]);
          return;
      }
      
      $gateways_obj = WC()->payment_gateways();
      if (!$gateways_obj) {
          throw new \Exception('Payment gateways unavailable.');
      }
      $gateways = $gateways_obj->payment_gateways();
      if (is_wp_error($gateways)) {
          throw new \Exception($gateways->get_error_message());
      }
      $data = [];
      
      foreach($gateways as $gateway) {
          $is_enabled = ($gateway->enabled === 'yes');
          $needs_setup = false;

          // Detect credential requirements (e.g. Stripe)
          if ($gateway->id === 'stripe' || strpos($gateway->id, 'stripe') !== false) {
              $secret_key = $gateway->get_option('secret_key') ?: $gateway->get_option('test_secret_key');
              if (empty($secret_key)) {
                  $needs_setup = true;
              }
          }
          
          $raw_title = $gateway->title ?: $gateway->get_method_title();

          $data[] = [
              'id' => $gateway->id,
              'title' => $this->clean_payment_title($raw_title, $gateway->id),
              'method_title' => $gateway->get_method_title(),
              'description' => $gateway->description ?: '',
              'enabled' => $is_enabled,
              'needs_setup' => $needs_setup
          ];
      }
      
      $this->send_json_response(['success' => true, 'gateways' => array_values($data)]);
    } catch (\Throwable $e) {
      $this->send_json_response(['success' => false, 'message' => $e->getMessage(), 'gateways' => []]);
    }
  }

  public function togglePaymentGateway() {
    try {
      if (!current_user_can('manage_woocommerce') && !current_user_can('edit_shop_orders')) {
          throw new \Exception('Permission denied.');
      }

      if ( ! function_exists( 'WC' ) ) {
          $this->send_json_response(['success' => false, 'message' => 'WooCommerce is not active.']);
          return;
      }

      $args = Xophz_Compass::get_input_json();
      $gateway_id = isset($args->gateway_id) ? sanitize_text_field($args->gateway_id) : '';
      $enabled = isset($args->enabled) ? (bool)$args->enabled : false;

      if (!$gateway_id) {
          $this->send_json_response(['success' => false, 'message' => 'Gateway ID is required.']);
          return;
      }

      $gateways_obj = WC()->payment_gateways();
      if (!$gateways_obj) {
          throw new \Exception('Payment gateways unavailable.');
      }
      $gateways = $gateways_obj->payment_gateways();
      if (!isset($gateways[$gateway_id])) {
          $this->send_json_response(['success' => false, 'message' => 'Payment gateway not found.']);
          return;
      }

      $gateway = $gateways[$gateway_id];
      $gateway->update_option('enabled', $enabled ? 'yes' : 'no');

      // Re-initialize payment gateways
      $gateways_obj->init();

      $this->send_json_response([
          'success' => true,
          'gateway_id' => $gateway_id,
          'enabled' => $enabled
      ]);
    } catch (\Throwable $e) {
      $this->send_json_response(['success' => false, 'message' => $e->getMessage()]);
    }
  }

  public function updateOrderStatus() {
    try {
      if (!current_user_can('manage_woocommerce') && !current_user_can('edit_shop_orders')) {
          throw new \Exception('Permission denied.');
      }
      $args = Xophz_Compass::get_input_json();
      $order_id = isset($args->order_id) ? intval($args->order_id) : 0;
      $status = isset($args->status) ? sanitize_text_field($args->status) : '';

      if (!$order_id || !$status) {
          $this->send_json_response(['success' => false, 'message' => 'Invalid order ID or status']);
          return;
      }

      $order = wc_get_order($order_id);
      if (!$order || is_wp_error($order)) {
          $this->send_json_response(['success' => false, 'message' => 'Order not found']);
          return;
      }

      $res = $order->update_status($status, 'Order status updated via COMPASS Bazaar UI.');
      if (is_wp_error($res)) {
          throw new \Exception($res->get_error_message());
      }
      
      $this->send_json_response([
          'success' => true,
          'order_id' => $order_id,
          'new_status' => $order->get_status()
      ]);
    } catch (\Throwable $e) {
        $this->send_json_response(['success' => false, 'message' => $e->getMessage()]);
    }
  }

  /**
    * undocumented function
    *
    * @return void
    */
  public function getCategories()
  {
    try {
      if (!current_user_can('manage_woocommerce') && !current_user_can('edit_shop_orders')) {
          throw new \Exception('Permission denied.');
      }

      global $wp_query;

      $args['orderby']  = 'meta_value_num';
      $args['meta_key'] = 'order'; // phpcs:ignore

      $args = 
        array(
          'meta_key' => 'order',
          'orderby' => 'meta_value_num',
          'pad_counts'         => 1,
          'show_count'         => 1,
          'hierarchical'       => 1,
          'hide_empty'         => 0,
          'taxonomy'           => 'product_cat',
      );

      if ( 'order' === $args['orderby'] ) {
        $args['orderby']  = 'meta_value_num';
        $args['meta_key'] = 'order'; // phpcs:ignore
      }

      $categories = get_terms($args['taxonomy'],$args);
      if (is_wp_error($categories)) {
          throw new \Exception($categories->get_error_message());
      }
      $walker = new Walker_Simple_String($args);

      $walker->walk($categories,0);

      $this->send_json_response([
        'success' => true,
        'categories' => $walker->categories 
      ]);
    } catch (\Throwable $e) {
      $this->send_json_response(['success' => false, 'message' => $e->getMessage(), 'categories' => []]);
    }
  }

  public function getPosCustomers() {
    try {
      if (!current_user_can('manage_woocommerce') && !current_user_can('edit_shop_orders')) {
          throw new \Exception('Permission denied.');
      }

      $args = Xophz_Compass::get_input_json();
      $search = isset($args->search) ? sanitize_text_field($args->search) : '';

      $query_args = [
          'number' => 20,
          'orderby' => 'display_name',
          'order' => 'ASC',
      ];

      if ($search) {
          $query_args['search'] = '*' . esc_attr($search) . '*';
          $query_args['search_columns'] = ['user_login', 'user_email', 'user_nicename', 'display_name'];
      }

      $user_query = new WP_User_Query($query_args);
      $users = $user_query->get_results();
      $data = [];

      foreach ($users as $user) {
          $phone = '';
          if (class_exists('WC_Customer')) {
              try {
                  $customer = new WC_Customer($user->ID);
                  if ($customer) {
                      $phone = $customer->get_billing_phone();
                  }
              } catch (\Throwable $e) {
                  // Fallback to user meta
              }
          }
          if (!$phone) {
              $phone = get_user_meta($user->ID, 'billing_phone', true);
          }

          $data[] = [
              'id' => $user->ID,
              'name' => $user->display_name,
              'email' => $user->user_email,
              'phone' => $phone
          ];
      }

      $this->send_json_response([
        'success' => true,
        'customers' => $data
      ]);
    } catch (\Throwable $e) {
      $this->send_json_response(['success' => false, 'message' => $e->getMessage(), 'customers' => []]);
    }
  }

  public function getPosOrderForRefund() {
    try {
      if (!current_user_can('manage_woocommerce') && !current_user_can('edit_shop_orders')) {
          throw new \Exception('Permission denied.');
      }

      $args = Xophz_Compass::get_input_json();
      $order_id = isset($args->order_id) ? intval($args->order_id) : 0;

      if (!$order_id) {
          $this->send_json_response(['success' => false, 'message' => 'Order ID is required.']);
          return;
      }

      $order = wc_get_order($order_id);
      if (!$order || is_wp_error($order)) {
          $this->send_json_response(['success' => false, 'message' => 'Order not found.']);
          return;
      }

      $items_data = [];
      foreach ($order->get_items() as $item_id => $item) {
          $product = $item->get_product();
          $refunded_qty = absint($order->get_qty_refunded_for_item($item_id));
          $item_qty = $item->get_quantity();
          $qty_available = $item_qty - $refunded_qty;
          
          if ($qty_available > 0) {
              $items_data[] = [
                  'item_id' => $item_id,
                  'product_id' => $item->get_product_id(),
                  'name' => $item->get_name(),
                  'qty_available' => $qty_available,
                  'price' => $order->get_item_total($item, false, false),
                  'total' => $order->get_line_total($item, false, false),
                  'tax' => $order->get_line_tax($item),
                  'thumb' => ($product && !is_wp_error($product)) ? wp_get_attachment_image_url($product->get_image_id(), 'thumbnail') : ''
              ];
          }
      }

      $this->send_json_response([
          'success' => true,
          'order' => [
              'id' => $order->get_id(),
              'status' => $order->get_status(),
              'total' => $order->get_total(),
              'total_refunded' => $order->get_total_refunded(),
              'items' => $items_data
          ]
      ]);
    } catch (\Throwable $e) {
      $this->send_json_response(['success' => false, 'message' => $e->getMessage()]);
    }
  }

  public function processPosRefund() {
    try {
      if (!current_user_can('manage_woocommerce') && !current_user_can('edit_shop_orders')) {
          throw new \Exception('Permission denied.');
      }

      $args = Xophz_Compass::get_input_json();
      $order_id = isset($args->order_id) ? intval($args->order_id) : 0;
      $refund_items = isset($args->items) ? $args->items : [];
      $reason = isset($args->reason) ? sanitize_text_field($args->reason) : 'POS Return';

      if (!$order_id || empty($refund_items)) {
          $this->send_json_response(['success' => false, 'message' => 'Invalid refund parameters.']);
          return;
      }

      $order = wc_get_order($order_id);
      if (!$order || is_wp_error($order)) {
          $this->send_json_response(['success' => false, 'message' => 'Order not found.']);
          return;
      }

      $line_items = [];
      $refund_amount = 0;

      foreach ($refund_items as $r_item) {
          $item_id = is_object($r_item) ? (isset($r_item->item_id) ? intval($r_item->item_id) : 0) : (isset($r_item['item_id']) ? intval($r_item['item_id']) : 0);
          $qty = is_object($r_item) ? (isset($r_item->qty) ? intval($r_item->qty) : 0) : (isset($r_item['qty']) ? intval($r_item['qty']) : 0);
          if ($qty <= 0) {
              continue;
          }
          
          $order_item = $order->get_item($item_id);
          if (!$order_item || is_wp_error($order_item)) {
              continue;
          }

          $item_qty = $order_item->get_quantity();
          $refunded_qty = absint($order->get_qty_refunded_for_item($item_id));
          $available_qty = $item_qty - $refunded_qty;
          if ($available_qty <= 0) {
              continue;
          }

          if ($qty > $available_qty) {
              $qty = $available_qty;
          }

          $unit_total = $order->get_item_total($order_item, false, false);
          $unit_tax = $item_qty > 0 ? ($order->get_line_tax($order_item) / $item_qty) : 0;

          $line_total = $unit_total * $qty;
          $line_tax = $unit_tax * $qty;

          $refund_amount += ($line_total + $line_tax);

          $item_taxes = $order_item->get_taxes();
          $refund_tax = [];
          if ($line_tax > 0 && !empty($item_taxes['total'])) {
              $tax_rate_id = key($item_taxes['total']);
              if ($tax_rate_id) {
                  $refund_tax[$tax_rate_id] = $line_tax;
              }
          }

          $line_items[$item_id] = [
              'qty' => $qty,
              'refund_total' => $line_total,
              'refund_tax' => $refund_tax
          ];
      }

      $refund = wc_create_refund([
          'amount'         => $refund_amount,
          'reason'         => $reason,
          'order_id'       => $order_id,
          'line_items'     => $line_items,
          'refund_payment' => false, // Do not auto-refund gateway for POS, handle manually if needed
          'restock_items'  => true
      ]);

      if (is_wp_error($refund)) {
          throw new \Exception($refund->get_error_message());
      }

      $this->send_json_response([
          'success' => true,
          'message' => 'Refund processed successfully.',
          'refund_id' => $refund->get_id(),
          'amount' => $refund_amount
      ]);

    } catch (\Throwable $e) {
      $this->send_json_response([
          'success' => false,
          'message' => $e->getMessage()
      ]);
    }
  }

  public static function getOrderIds($args){
    $query_args = (array) $args;

    $default = [
      'return'    => 'ids',
      'paginate'  => true,
      'status'    => 'any',
      'type'      => 'shop_order',
    ];

    if (!empty($query_args['limit'])) {
      $default['limit'] = intval($query_args['limit']);
    }
    if (!empty($query_args['page'])) {
      $default['page'] = intval($query_args['page']);
    }

    if ( ! function_exists( 'wc_get_orders' ) ) {
        return (object) [ 'orders' => [], 'total' => 0, 'max_num_pages' => 0 ];
    }

    $final_args = array_merge($default, $query_args);
    return wc_get_orders( $final_args );
  }

  public function getCoupons() {
    try {
      if (!current_user_can('manage_woocommerce') && !current_user_can('edit_shop_orders')) {
          throw new \Exception('Permission denied.');
      }

      if (!class_exists('WooCommerce')) {
        $this->send_json_response(['success' => false, 'message' => 'WooCommerce is not active.', 'coupons' => []]);
        return;
      }

      $args = Xophz_Compass::get_input_json();
      $search = isset($args->search) ? sanitize_text_field($args->search) : '';

      $query_args = [
        'post_type'      => 'shop_coupon',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'orderby'        => 'date',
        'order'          => 'DESC',
      ];

      if (!empty($search)) {
        $query_args['s'] = $search;
      }

      $posts = get_posts($query_args);
      $coupons = [];

      foreach ($posts as $post) {
        try {
          $coupon = new WC_Coupon($post->ID);
          if (!$coupon || !$coupon->get_id()) continue;

          $expiry = $coupon->get_date_expires();
          $coupons[] = [
            'id'                     => $coupon->get_id(),
            'code'                   => $coupon->get_code(),
            'description'            => $coupon->get_description(),
            'discount_type'          => $coupon->get_discount_type(),
            'amount'                 => floatval($coupon->get_amount()),
            'date_expires'           => $expiry ? $expiry->date('Y-m-d') : null,
            'usage_count'            => (int) $coupon->get_usage_count(),
            'usage_limit'            => (int) $coupon->get_usage_limit(),
            'usage_limit_per_user'   => (int) $coupon->get_usage_limit_per_user(),
            'limit_usage_to_x_items' => (int) $coupon->get_limit_usage_to_x_items(),
            'free_shipping'          => (bool) $coupon->get_free_shipping(),
            'minimum_amount'         => floatval($coupon->get_minimum_amount()),
            'maximum_amount'         => floatval($coupon->get_maximum_amount()),
            'individual_use'         => (bool) $coupon->get_individual_use(),
            'exclude_sale_items'     => (bool) $coupon->get_exclude_sale_items(),
          ];
        } catch (\Throwable $ex) {
          continue;
        }
      }

      $this->send_json_response([
        'success' => true,
        'coupons' => $coupons
      ]);
    } catch (\Throwable $e) {
      $this->send_json_response(['success' => false, 'message' => $e->getMessage(), 'coupons' => []]);
    }
  }

  public function saveCoupon() {
    try {
      if (!current_user_can('manage_woocommerce') && !current_user_can('edit_shop_orders')) {
          throw new \Exception('Permission denied.');
      }

      if (!class_exists('WooCommerce')) {
        $this->send_json_response(['success' => false, 'message' => 'WooCommerce is not active.']);
        return;
      }

      $args = Xophz_Compass::get_input_json();
      $id   = isset($args->id) ? intval($args->id) : 0;
      $code = isset($args->code) ? sanitize_text_field($args->code) : '';

      if (empty($code)) {
        $this->send_json_response(['success' => false, 'message' => 'Coupon code is required.']);
        return;
      }

      $coupon = $id ? new WC_Coupon($id) : new WC_Coupon();

      $coupon->set_code($code);
      if (isset($args->description)) {
        $coupon->set_description(sanitize_textarea_field($args->description));
      }
      if (isset($args->discount_type)) {
        $coupon->set_discount_type(sanitize_text_field($args->discount_type));
      }
      if (isset($args->amount)) {
        $coupon->set_amount(floatval($args->amount));
      }
      if (!empty($args->date_expires)) {
        $coupon->set_date_expires(sanitize_text_field($args->date_expires));
      } else {
        $coupon->set_date_expires(null);
      }
      if (isset($args->usage_limit)) {
        $coupon->set_usage_limit(intval($args->usage_limit));
      }
      if (isset($args->usage_limit_per_user)) {
        $coupon->set_usage_limit_per_user(intval($args->usage_limit_per_user));
      }
      if (isset($args->free_shipping)) {
        $coupon->set_free_shipping(filter_var($args->free_shipping, FILTER_VALIDATE_BOOLEAN));
      }
      if (isset($args->minimum_amount)) {
        $coupon->set_minimum_amount(floatval($args->minimum_amount));
      }
      if (isset($args->maximum_amount)) {
        $coupon->set_maximum_amount(floatval($args->maximum_amount));
      }
      if (isset($args->individual_use)) {
        $coupon->set_individual_use(filter_var($args->individual_use, FILTER_VALIDATE_BOOLEAN));
      }
      if (isset($args->exclude_sale_items)) {
        $coupon->set_exclude_sale_items(filter_var($args->exclude_sale_items, FILTER_VALIDATE_BOOLEAN));
      }

      $saved_id = $coupon->save();

      if (is_wp_error($saved_id)) {
        throw new \Exception($saved_id->get_error_message());
      }

      if ($saved_id) {
        $this->send_json_response([
          'success' => true,
          'id'      => $saved_id,
          'message' => 'Coupon saved successfully.'
        ]);
      } else {
        $this->send_json_response([
          'success' => false,
          'message' => 'Failed to save coupon.'
        ]);
      }
    } catch (\Throwable $e) {
      $this->send_json_response([
        'success' => false,
        'message' => $e->getMessage()
      ]);
    }
  }

  public function deleteCoupon() {
    try {
      if (!current_user_can('manage_woocommerce') && !current_user_can('edit_shop_orders')) {
          throw new \Exception('Permission denied.');
      }

      if (!class_exists('WooCommerce')) {
        $this->send_json_response(['success' => false, 'message' => 'WooCommerce is not active.']);
        return;
      }

      $args = Xophz_Compass::get_input_json();
      $id   = isset($args->id) ? intval($args->id) : 0;

      if (!$id) {
        $this->send_json_response(['success' => false, 'message' => 'Coupon ID is required.']);
        return;
      }

      $coupon = new WC_Coupon($id);
      if (!$coupon->get_id()) {
        $this->send_json_response(['success' => false, 'message' => 'Coupon not found.']);
        return;
      }

      $coupon->delete(true);

      $this->send_json_response([
        'success' => true,
        'message' => 'Coupon deleted successfully.'
      ]);
    } catch (\Throwable $e) {
      $this->send_json_response([
        'success' => false,
        'message' => $e->getMessage()
      ]);
    }
  }
}

class Walker_Simple_String extends Walker {
	/**
	 * What the class handles.
	 *
	 * @var string
	 */
	public $tree_type = 'category';
	public $categories = [];

	/**
	 * DB fields to use.
	 *
	 * @var array
	 */
	public $db_fields = array(
    'parent' => 'parent',
    'id'     => 'term_id',
    'slug'   => 'slug',
	);

	/**
	 * Starts the list before the elements are added.
	 *
	 * @see Walker::start_el()
	 * @since 2.1.0
	 *
	 * @param string $output            Passed by reference. Used to append additional content.
	 * @param object $cat               Category.
	 * @param int    $depth             Depth of category in reference to parents.
	 * @param array  $args              Arguments.
	 * @param int    $current_object_id Current object ID.
	 */
	public function start_el( &$output, $cat, $depth = 0, $args = array(), $current_object_id = 0 ) {
    $indent = $depth > 0 ? str_repeat( '- ', $depth ) : '';

    $cat_name = apply_filters( 'list_product_cats', $cat->name, $cat );
    $cat_name = html_entity_decode( $cat_name, ENT_QUOTES, 'UTF-8' );
    $this->categories[] = [
      'id'    => $cat->term_id,
      'name'  => $cat_name,
      'text'  => $indent . $cat_name . ' (' . absint( $cat->count ) . ')',
      'value' => $cat->slug
    ];
	}

	/**
	 * Traverse elements to create list from elements.
	 *
	 * Display one element if the element doesn't have any children otherwise,
	 * display the element and its children. Will only traverse up to the max.
	 * depth and no ignore elements under that depth. It is possible to set the.
	 * max depth to include all depths, see walk() method.
	 *
	 * This method shouldn't be called directly, use the walk() method instead.
	 *
	 * @since 2.5.0
	 *
	 * @param object $element           Data object.
	 * @param array  $children_elements List of elements to continue traversing.
	 * @param int    $max_depth         Max depth to traverse.
	 * @param int    $depth             Depth of current element.
	 * @param array  $args              Arguments.
	 * @param string $output            Passed by reference. Used to append additional content.
	 * @return null Null on failure with no changes to parameters.
	 */
	public function display_element( $element, &$children_elements, $max_depth, $depth, $args, &$output ) {
    if ( ! $element ) {
      return;
    }
    parent::display_element( $element, $children_elements, $max_depth, $depth, $args, $output );
	}
}
