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
    'wp_ajax_update_order_status' => 'updateOrderStatus',
    'wp_ajax_validate_pos_coupon' => 'validatePosCoupon',
    'wp_ajax_send_pos_receipt' => 'sendPosReceipt',
    'wp_ajax_get_pos_customers' => 'getPosCustomers',
    'wp_ajax_email_shift_summary' => 'emailShiftSummary',
    'wp_ajax_get_pos_order_for_refund' => 'getPosOrderForRefund',
    'wp_ajax_process_pos_refund' => 'processPosRefund',
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

  public function getOrders(){
    $args   = Xophz_Compass::get_input_json();
    $args->return = 'objects';

    $orderIds = Xophz_Compass_Bazaar_Admin_Orders::getOrderIds($args);

    $mapOrderData = function($id){
      $order = wc_get_order($id);
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

    $orders_data = array_map( $mapOrderData, $orderIds->orders );

    Xophz_Compass::output_json([
      'total_count' => (int) $orderIds->total, 
      'data'        => $orders_data
    ]);
  }

  public function validatePosCoupon() {
    $args = Xophz_Compass::get_input_json();
    $code = isset($args->code) ? sanitize_text_field($args->code) : '';

    if (!$code) {
        Xophz_Compass::output_json(['success' => false, 'message' => 'Coupon code is required.']);
        return;
    }

    $coupon = new WC_Coupon($code);
    if (!$coupon->get_id() || !$coupon->is_valid()) {
        Xophz_Compass::output_json(['success' => false, 'message' => 'Invalid or expired coupon code.']);
        return;
    }

    Xophz_Compass::output_json([
        'success' => true,
        'code' => $coupon->get_code(),
        'type' => $coupon->get_discount_type(),
        'amount' => $coupon->get_amount(),
    ]);
  }

  public function sendPosReceipt() {
    $args = Xophz_Compass::get_input_json();
    $recipient = isset($args->recipient) ? sanitize_text_field($args->recipient) : '';
    $order_id = isset($args->order_id) ? intval($args->order_id) : 0;

    if (!$recipient || !$order_id) {
        Xophz_Compass::output_json(['success' => false, 'message' => 'Recipient and Order ID are required.']);
        return;
    }

    $order = wc_get_order($order_id);
    if (!$order) {
        Xophz_Compass::output_json(['success' => false, 'message' => 'Order not found.']);
        return;
    }

    $is_email = strpos($recipient, '@') !== false;

    if ($is_email) {
        $order->set_billing_email($recipient);
        $order->save();
        
        $subscribe_newsletter = isset($args->subscribe_newsletter) ? rest_sanitize_boolean($args->subscribe_newsletter) : false;
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

        Xophz_Compass::output_json([
            'success' => true,
            'type' => 'email',
            'message' => 'Receipt invoice email sent successfully.'
        ]);
    } else {
        $sanitized_phone = preg_replace('/[^0-9+]/', '', $recipient);
        
        do_action('xophz_compass_send_sms_receipt', $sanitized_phone, $order_id);

        Xophz_Compass::output_json([
            'success' => true,
            'type' => 'sms',
            'message' => 'Receipt SMS request dispatched.'
        ]);
    }
  }

  public function emailShiftSummary() {
    $args = Xophz_Compass::get_input_json();
    $current_user = wp_get_current_user();
    
    if (!$current_user || !$current_user->exists()) {
        Xophz_Compass::output_json(['success' => false, 'message' => 'Not authenticated.']);
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

    Xophz_Compass::output_json([
        'success' => $sent,
        'message' => $sent ? 'Shift summary emailed to ' . implode(', ', $recipients) : 'Failed to send email.'
    ]);
  }

  public function createPosOrder() {
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
        Xophz_Compass::output_json(['success' => false, 'message' => 'Cart is empty.']);
        return;
    }

    try {
        $order = wc_create_order();
        
        $customerId = isset($args->customerId) ? intval($args->customerId) : 0;
        if ($customerId) {
            $order->set_customer_id($customerId);
            $customer = new WC_Customer($customerId);
            if ($customer) {
                $order->set_billing_first_name($customer->get_billing_first_name() ?: $customer->get_first_name());
                $order->set_billing_last_name($customer->get_billing_last_name() ?: $customer->get_last_name());
                $order->set_billing_email($customer->get_billing_email() ?: $customer->get_email());
                $order->set_billing_phone($customer->get_billing_phone() ?: $customer->get_meta('billing_phone'));
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
            $product_id = intval($item->product_id);
            $quantity = intval($item->quantity);
            $product = wc_get_product($product_id);

            if ($product) {
                $order->add_product($product, $quantity);
            }
        }

        if ($discount > 0) {
            $item = new WC_Order_Item_Fee();
            $item->set_name('POS Discount');
            $item->set_amount(-$discount);
            $item->set_total(-$discount);
            $order->add_item($item);
        }

        if (is_array($appliedCoupons)) {
            foreach ($appliedCoupons as $code) {
                if (is_string($code)) {
                    $order->apply_coupon(sanitize_text_field($code));
                }
            }
        }

        if (is_array($customDiscounts)) {
            foreach ($customDiscounts as $cd) {
                $amount = isset($cd->amount) ? floatval($cd->amount) : 0;
                $name = isset($cd->name) && !empty($cd->name) ? sanitize_text_field($cd->name) : 'Custom Discount';
                
                if ($amount > 0) {
                    $item = new WC_Order_Item_Fee();
                    $item->set_name($name);
                    $item->set_amount(-$amount);
                    $item->set_total(-$amount);
                    $order->add_item($item);
                }
            }
        }

        if ($tipAmount > 0) {
            $item = new WC_Order_Item_Fee();
            $item->set_name('Tip');
            $item->set_amount($tipAmount);
            $item->set_total($tipAmount);
            $order->add_item($item);
            $order->update_meta_data('_pos_tip_amount', $tipAmount);
            if (isset($cashier_id)) {
                $order->update_meta_data('_pos_tip_cashier_id', $cashier_id);
            }
        }

        // Set payment method and origin
        $order->set_payment_method($paymentMethod);
        $order->set_payment_method_title($paymentMethod === 'bazaar_split' ? 'Split Payment' : ucfirst($paymentMethod));
        $order->set_created_via('bazaar_pos');

        if ($paymentMethod === 'bazaar_split' && !empty($splitPayments)) {
            $order->update_meta_data('_pos_split_payments', json_encode($splitPayments));
            $note = "Split Payment Breakdown:\n";
            foreach ($splitPayments as $sp) {
                $method = isset($sp->method) ? $sp->method : 'unknown';
                $amt = isset($sp->amount) ? floatval($sp->amount) : 0;
                $note .= "- " . ucfirst($method) . ": $" . number_format($amt, 2) . "\n";
            }
            $order->add_order_note($note);
        }

        // Calculate totals
        $order->calculate_totals();

        // Mimic WooCommerce behavior for manual payment gateways
        if ( in_array( $paymentMethod, ['bacs', 'cheque'] ) ) {
            $order->update_status('on-hold', 'Order created via Bazaar POS.');
        } elseif ( $paymentMethod === 'cod' ) {
            $order->update_status('processing', 'Order created via Bazaar POS.');
        } else {
            // For card/cash payments at POS, we assume immediate completion
            $order->update_status('completed', 'Order created via Bazaar POS.');
        }

        if ($customerId) {
            do_action('xophz_compass_record_action', 'bazaar_pos_purchase', $customerId, [
                'order_id' => $order->get_id(),
                'total' => $order->get_total(),
                'payment_method' => $paymentMethod
            ]);
        }

        Xophz_Compass::output_json([
            'success' => true, 
            'order_id' => $order->get_id(),
            'order_key' => $order->get_order_key()
        ]);
    } catch (Exception $e) {
        Xophz_Compass::output_json([
            'success' => false, 
            'message' => $e->getMessage()
        ]);
    }
  }

  public function getPaymentGateways() {
      if ( ! function_exists( 'WC' ) ) {
          Xophz_Compass::output_json(['gateways' => []]);
          return;
      }
      
      $gateways = WC()->payment_gateways()->get_available_payment_gateways();
      $data = [];
      
      foreach($gateways as $gateway) {
          $data[] = [
              'id' => $gateway->id,
              'title' => $gateway->title,
              'method_title' => $gateway->get_method_title()
          ];
      }
      
      Xophz_Compass::output_json(['gateways' => array_values($data)]);
  }

  public function updateOrderStatus() {
      $args = Xophz_Compass::get_input_json();
      $order_id = isset($args->order_id) ? intval($args->order_id) : 0;
      $status = isset($args->status) ? sanitize_text_field($args->status) : '';

      if (!$order_id || !$status) {
          Xophz_Compass::output_json(['success' => false, 'message' => 'Invalid order ID or status']);
          return;
      }

      $order = wc_get_order($order_id);
      if (!$order) {
          Xophz_Compass::output_json(['success' => false, 'message' => 'Order not found']);
          return;
      }

      try {
          $order->update_status($status, 'Order status updated via COMPASS Bazaar UI.');
          
          Xophz_Compass::output_json([
              'success' => true,
              'order_id' => $order_id,
              'new_status' => $order->get_status()
          ]);
      } catch (Exception $e) {
          Xophz_Compass::output_json(['success' => false, 'message' => $e->getMessage()]);
      }
  }

  /**
    * undocumented function
    *
    * @return void
    */
  public function getCategories()
  {
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
        'hide_empty'         => 1,
        'taxonomy'           => 'product_cat',
    );

    if ( 'order' === $args['orderby'] ) {
      $args['orderby']  = 'meta_value_num';
      $args['meta_key'] = 'order'; // phpcs:ignore
    }

    $categories = get_terms($args['taxonomy'],$args);
    $walker = new Walker_Simple_String($args);

    $walker->walk($categories,0);

    Xophz_Compass::output_json([
      'categories' => $walker->categories 
    ]);
  }

  public function getPosCustomers() {
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
          $customer = new WC_Customer($user->ID);
          $phone = '';
          if ($customer) {
              $phone = $customer->get_billing_phone() ?: get_user_meta($user->ID, 'billing_phone', true);
          }
          
          $data[] = [
              'id' => $user->ID,
              'name' => $user->display_name,
              'email' => $user->user_email,
              'phone' => $phone
          ];
      }

      Xophz_Compass::output_json([
        'success' => true,
        'customers' => $customers_data
    ]);
  }

  public function getPosOrderForRefund() {
    $args = Xophz_Compass::get_input_json();
    $order_id = isset($args->order_id) ? intval($args->order_id) : 0;

    if (!$order_id) {
        Xophz_Compass::output_json(['success' => false, 'message' => 'Order ID is required.']);
        return;
    }

    $order = wc_get_order($order_id);
    if (!$order) {
        Xophz_Compass::output_json(['success' => false, 'message' => 'Order not found.']);
        return;
    }

    $items_data = [];
    foreach ($order->get_items() as $item_id => $item) {
        $product = $item->get_product();
        $refunded_qty = $order->get_qty_refunded_for_item($item_id);
        $qty_available = $item->get_quantity() + $refunded_qty; // refunded is negative
        
        if ($qty_available > 0) {
            $items_data[] = [
                'item_id' => $item_id,
                'product_id' => $item->get_product_id(),
                'name' => $item->get_name(),
                'qty_available' => $qty_available,
                'price' => $order->get_item_total($item, false, false),
                'total' => $order->get_line_total($item, false, false),
                'tax' => $order->get_line_tax($item),
                'thumb' => $product ? wp_get_attachment_image_url($product->get_image_id(), 'thumbnail') : ''
            ];
        }
    }

    Xophz_Compass::output_json([
        'success' => true,
        'order' => [
            'id' => $order->get_id(),
            'status' => $order->get_status(),
            'total' => $order->get_total(),
            'total_refunded' => $order->get_total_refunded(),
            'items' => $items_data
        ]
    ]);
  }

  public function processPosRefund() {
    $args = Xophz_Compass::get_input_json();
    $order_id = isset($args->order_id) ? intval($args->order_id) : 0;
    $refund_items = isset($args->items) ? $args->items : [];
    $reason = isset($args->reason) ? sanitize_text_field($args->reason) : 'POS Return';

    if (!$order_id || empty($refund_items)) {
        Xophz_Compass::output_json(['success' => false, 'message' => 'Invalid refund parameters.']);
        return;
    }

    $order = wc_get_order($order_id);
    if (!$order) {
        Xophz_Compass::output_json(['success' => false, 'message' => 'Order not found.']);
        return;
    }

    $line_items = [];
    $refund_amount = 0;

    foreach ($refund_items as $r_item) {
        $item_id = intval($r_item->item_id);
        $qty = intval($r_item->qty);
        
        $order_item = $order->get_item($item_id);
        if (!$order_item) continue;

        $unit_total = $order->get_item_total($order_item, false, false);
        $unit_tax = $order->get_line_tax($order_item) / $order_item->get_quantity();

        $line_total = $unit_total * $qty;
        $line_tax = $unit_tax * $qty;

        $refund_amount += ($line_total + $line_tax);

        $line_items[$item_id] = [
            'qty' => $qty,
            'refund_total' => $line_total,
            'refund_tax' => [$order_item->get_taxes()['total'] ? key($order_item->get_taxes()['total']) : 0 => $line_tax]
        ];
    }

    try {
        $refund = wc_create_refund([
            'amount'         => $refund_amount,
            'reason'         => $reason,
            'order_id'       => $order_id,
            'line_items'     => $line_items,
            'refund_payment' => false, // Do not auto-refund gateway for POS, handle manually if needed
            'restock_items'  => true
        ]);

        if (is_wp_error($refund)) {
            throw new Exception($refund->get_error_message());
        }

        Xophz_Compass::output_json([
            'success' => true,
            'message' => 'Refund processed successfully.',
            'refund_id' => $refund->get_id(),
            'amount' => $refund_amount
        ]);

    } catch (Exception $e) {
        Xophz_Compass::output_json([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
  }

  public static function getOrderIds($args){
    $default = [
      'return'    => 'ids',
      'paginate'  => true
    ];

    return wc_get_orders( array_merge($default, (array) $args) );
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
    $pad = str_repeat( '&nbsp;', $depth * 3 );

    $cat_name = apply_filters( 'list_product_cats', $cat->name, $cat );
    $this->categories[] = [
      'id'    => $cat->term_id,
      'text'  => esc_html( $pad . $cat_name ) . '&nbsp;(' . absint( $cat->count ) . ')',
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
    if ( ! $element || ( empty( $element->count ) && ! empty( $args[0]['hide_empty'] ) ) ) {
      return;
    }
    parent::display_element( $element, $children_elements, $max_depth, $depth, $args, $output );
	}
}
