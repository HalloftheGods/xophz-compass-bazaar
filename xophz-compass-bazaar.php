<?php

/**
 * The plugin bootstrap file
 *
 * This file is read by WordPress to generate the plugin information in the plugin
 * admin area. This file also includes all of the dependencies used by the plugin,
 * registers the activation and deactivation functions, and defines a function
 * that starts the plugin.
 *
 * @link              https://youmeos.com
 * @since             1.0.0
 * @package           Xophz_Compass_Bazaar
 *
 * @wordpress-plugin
 * Category:          Command Deck
 * Group:             POS
 * Plugin Name:       Xophz Bazaar Warehouse
 * Plugin URI:        https://github.com/HalloftheGods/xophz-compass-bazaar
 * Description:       Manage inventory stock levels, order fulfillment, point of sale transactions, and financial revenue analytics in real time.
 * Version:           26.11.8
 * Author:            Hall of the Gods, Inc.
 * Author URI:        https://youmeos.com/
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       xophz-compass-bazaar
 * Domain Path:       /languages
 * Update URI:        https://github.com/HalloftheGods/xophz-compass-bazaar
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
  die;
}

/**
 * Currently plugin version.
 * Start at version 1.0.0 and use SemVer - https://semver.org
 * Rename this for your plugin and update it as you release new versions.
 */
define( 'XOPHZ_COMPASS_BAZAAR_VERSION', '26.11.8' );

/**
 * The code that runs during plugin activation.
 * This action is documented in includes/class-xophz-compass-bazaar-activator.php
 */
function activate_xophz_compass_bazaar() {
  require_once plugin_dir_path( __FILE__ ) . 'includes/class-xophz-compass-bazaar-activator.php';
  require_once plugin_dir_path( __FILE__ ) . 'includes/class-xophz-bazaar-buy-router.php';
  Xophz_Compass_Bazaar_Activator::activate();
  Xophz_Bazaar_Buy_Router::register_rewrite_rules();
  flush_rewrite_rules();
}

/**
 * The code that runs during plugin deactivation.
 * This action is documented in includes/class-xophz-compass-bazaar-deactivator.php
 */
function deactivate_xophz_compass_bazaar() {
	Xophz_Compass_Bazaar::deactivate();
	flush_rewrite_rules();
}

register_activation_hook( __FILE__, 'activate_xophz_compass_bazaar' );
register_deactivation_hook( __FILE__, 'deactivate_xophz_compass_bazaar' );

add_action( 'before_woocommerce_init', function() {
  if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
    \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
  }
} );

/**
 * Checkout & Commerce Service Infrastructure
 */
require_once plugin_dir_path( __FILE__ ) . 'includes/class-xophz-bazaar-checkout-service.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-xophz-bazaar-buy-router.php';
Xophz_Bazaar_Buy_Router::init();

/**
 * The core plugin class that is used to define internationalization,
 * admin-specific hooks, and public-facing site hooks.
 */
require plugin_dir_path( __FILE__ ) . 'includes/class-xophz-compass-bazaar.php';

/**
 * Begins execution of the plugin.
 *
 * Since everything within the plugin is registered via hooks,
 * then kicking off the plugin from this point in the file does
 * not affect the page life cycle.
 *
 * @since    1.0.0
 */
function run_xophz_compass_bazaar() {
  if ( ! class_exists( 'Xophz_Compass' ) ) {
    add_action( 'admin_init', 'shutoff_xophz_compass_bazaar' );
    add_action( 'admin_notices', 'admin_notice_xophz_compass_bazaar' );

    function shutoff_xophz_compass_bazaar() {
      if ( ! function_exists( 'deactivate_plugins' ) ) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
      }
      deactivate_plugins( plugin_basename( __FILE__ ) );
    }

    function admin_notice_xophz_compass_bazaar() {
      echo '<div class="error"><h2><strong>Xophz Bazaar Warehouse</strong> requires Compass to run. It has self <strong>deactivated</strong>.</h2></div>';
      if ( isset( $_GET['activate'] ) )
        unset( $_GET['activate'] );
    }
  } else {
    $plugin = new Xophz_Compass_Bazaar();
    $plugin->run();
  }
}
add_action( 'plugins_loaded', 'run_xophz_compass_bazaar' );

add_action('template_redirect', function() {
  if (isset($_GET['xophz_bazaar_receipt'])) {
    $order_id = intval($_GET['xophz_bazaar_receipt']);
    $order = wc_get_order($order_id);
    if (!$order) {
      wp_die('Order not found.', 'Receipt Error', ['response' => 404]);
    }
    $provided_key = isset($_GET['key']) ? sanitize_text_field(wp_unslash($_GET['key'])) : '';
    $order_key    = (string) $order->get_order_key();
    if ('' === $order_key || !hash_equals($order_key, $provided_key)) {
      wp_die('Access denied. Invalid receipt key.', 'Access Denied', ['response' => 403]);
    }
    $site_name = get_bloginfo('name');
    $site_icon_url = '';
    $custom_logo_id = get_theme_mod('custom_logo');
    if ($custom_logo_id) {
      $logo_img = wp_get_attachment_image_src($custom_logo_id, 'full');
      if ($logo_img) {
        $site_icon_url = $logo_img[0];
      }
    }
    if (!$site_icon_url) {
      $site_icon_url = get_site_icon_url();
    }
    if (!$site_icon_url) {
      $site_icon_url = '/favicon.ico';
    }
    $date_created = $order->get_date_created();
    $date_str = $date_created ? $date_created->date('M d, Y, h:i A') : '';
    $items = $order->get_items();
    // Subtotal is before discounts, so the discount is taken off once, and the lines add up to the total.
    $money = 'Xophz_Compass_Bazaar_Core_Money';
    $total_minor = $money::to_minor(wc_format_decimal($order->get_total()));
    $receipt_lines = Xophz_Compass_Bazaar_Checkout_Receipt_Lines::lines([
      'subtotal' => $money::to_minor(wc_format_decimal($order->get_subtotal())),
      'discount' => $money::to_minor(wc_format_decimal($order->get_total_discount())),
      'fees'     => $money::to_minor(wc_format_decimal($order->get_total_fees())),
      'shipping' => $money::to_minor(wc_format_decimal($order->get_shipping_total())),
      'tax'      => $money::to_minor(wc_format_decimal($order->get_total_tax())),
      'total'    => $total_minor,
    ]);
    $refund_lines = Xophz_Compass_Bazaar_Checkout_Receipt_Lines::refund_lines(
      $total_minor,
      $money::to_minor(wc_format_decimal($order->get_total_refunded()))
    );
    $payment_method = $order->get_payment_method_title();
    
    $cashier_name = 'System';
    $cashier_id = $order->get_meta('_pos_cashier_id');
    if ($cashier_id) {
        $cashier_user = get_userdata($cashier_id);
        if ($cashier_user) {
            $cashier_name = $cashier_user->display_name;
        }
    }
    
    $site_description = get_bloginfo('description');
    $store = Xophz_Compass_Bazaar_Checkout_Receipt_Store::info();

    // Cash handed over and change, from the live payment rows; card sales and old orders have none.
    $cash_rows = [];
    if (class_exists('Xophz_Compass_Bazaar_Payments_Repository')) {
      try {
        foreach (Xophz_Compass_Bazaar_Payments_Repository::for_order($order_id) as $pay) {
          if (in_array($pay->status, Xophz_Compass_Bazaar_Payments_Repository::COUNTED_STATUSES, true)) {
            $cash_rows[] = (array) $pay;
          }
        }
      } catch (Throwable $e) {
        $cash_rows = [];
      }
    }
    $cash_lines = Xophz_Compass_Bazaar_Checkout_Receipt_Lines::cash_lines($cash_rows);

    // The code is drawn here, so printing a receipt never calls another site.
    $self_url = home_url('/?xophz_bazaar_receipt=' . $order_id . '&key=' . $order_key);
    $qr_svg = Xophz_Compass_Bazaar_Checkout_Qr::svg($self_url);
    $qr_src = '' === $qr_svg ? '' : 'data:image/svg+xml;base64,' . base64_encode($qr_svg);
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
      <meta charset="UTF-8">
      <meta name="viewport" content="width=device-width, initial-scale=1.0">
      <title>Receipt #<?php echo (int) $order_id; ?> - <?php echo esc_html($site_name); ?></title>
      <style>
        body {
          background: #f4f4f6;
          color: #111111;
          font-family: 'Courier New', Courier, monospace;
          font-size: 16px;
          margin: 0;
          padding: 20px;
          display: flex;
          justify-content: center;
          align-items: center;
          min-height: 100vh;
        }
        .receipt-container {
          background: #ffffff;
          width: 100%;
          max-width: 420px;
          padding: 20px;
          box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
          border-radius: 4px;
          box-sizing: border-box;
        }
        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .mb-1 { margin-bottom: 4px; }
        .mb-2 { margin-bottom: 8px; }
        .mb-3 { margin-bottom: 12px; }
        .mb-4 { margin-bottom: 16px; }
        .my-2 { margin-top: 8px; margin-bottom: 8px; }
        .receipt-logo {
          max-width: 48px;
          height: auto;
          margin: 0 auto 8px auto;
          display: block;
        }
        .title {
          font-size: 20px;
          font-weight: bold;
          text-transform: uppercase;
          letter-spacing: 1px;
        }
        .subtitle {
          font-size: 14px;
          color: #444;
        }
        .receipt-divider {
          border-top: 1px dashed rgba(0, 0, 0, 0.2);
          height: 0;
          margin: 12px 0;
        }
        .details-table, .items-table {
          width: 100%;
          border-collapse: collapse;
          font-size: 16px;
        }
        .details-table td {
          padding: 2px 0;
        }
        .items-table th, .items-table td {
          padding: 4px 0;
          vertical-align: top;
        }
        .items-table th {
          border-bottom: 1px dashed rgba(0, 0, 0, 0.2);
          text-align: left;
          font-weight: bold;
        }
        .flex-between {
          display: flex;
          justify-content: space-between;
        }
        .font-bold { font-weight: bold; }
        .total-row {
          font-size: 20px;
          margin-top: 8px;
        }
        .qr-container {
          margin-top: 16px;
          display: flex;
          flex-direction: column;
          align-items: center;
        }
        .qr-img {
          width: 120px;
          height: 120px;
          display: block;
          margin-bottom: 6px;
        }
        .qr-label {
          font-size: 14px;
          color: #444;
          text-transform: uppercase;
          letter-spacing: 0.5px;
        }
        .italic { font-style: italic; }
        .print-btn {
          position: fixed;
          bottom: 20px;
          right: 20px;
          background: #111111;
          color: #ffffff;
          border: none;
          min-height: 48px;
          min-width: 120px;
          padding: 0 24px;
          border-radius: 50px;
          font-family: inherit;
          cursor: pointer;
          box-shadow: 0 4px 10px rgba(0, 0, 0, 0.2);
          font-size: 16px;
          display: flex;
          align-items: center;
          gap: 6px;
          transition: transform 0.2s;
        }
        .print-btn:hover {
          transform: scale(1.05);
        }
        @media print {
          body {
            background: #ffffff;
            padding: 0;
            display: block;
          }
          .receipt-container {
            box-shadow: none;
            max-width: 100%;
            padding: 0;
          }
          .print-btn {
            display: none;
          }
        }
      </style>
    </head>
    <body>
      <div class="receipt-container">
        <div class="text-center mb-4">
          <?php if ($site_icon_url && $site_icon_url !== '/favicon.ico'): ?>
            <img src="<?php echo esc_url($site_icon_url); ?>" class="receipt-logo" alt="Logo">
          <?php endif; ?>
          <div class="title"><?php echo esc_html($site_name); ?></div>
          <?php foreach ($store['address_lines'] as $address_line): ?>
            <div class="subtitle"><?php echo esc_html($address_line); ?></div>
          <?php endforeach; ?>
          <?php if ('' !== $store['phone']): ?>
            <div class="subtitle"><?php echo esc_html($store['phone']); ?></div>
          <?php endif; ?>
          <div class="subtitle">Cashier: <?php echo esc_html($cashier_name); ?></div>
        </div>

        <div class="receipt-divider"></div>

        <table class="details-table">
          <tr>
            <td>Order ID:</td>
            <td class="text-right font-bold">#<?php echo (int) $order_id; ?></td>
          </tr>
          <tr>
            <td>Date:</td>
            <td class="text-right"><?php echo esc_html($date_str); ?></td>
          </tr>
          <tr>
            <td>Payment:</td>
            <td class="text-right"><?php echo esc_html($payment_method); ?></td>
          </tr>
        </table>

        <div class="receipt-divider"></div>

        <table class="items-table">
          <thead>
            <tr>
              <th>Item</th>
              <th class="text-right" style="width: 70px;">Total</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($items as $item): 
              $product = $item->get_product();
              $name = $item->get_name();
              $qty = (string) $item->get_quantity();
              $line_total = $money::to_minor(wc_format_decimal($item->get_total()));
              // Unit price is before discounts; the right-hand figure is what was paid for the line.
              $unit = $money::to_minor(wc_format_decimal((float) $item->get_subtotal() / max((float) $qty, 1)));
            ?>
              <tr>
                <td>
                  <?php echo esc_html($name); ?>
                  <div class="subtitle"><?php echo esc_html(Xophz_Compass_Bazaar_Checkout_Receipt_Lines::item_detail($qty, $unit)); ?></div>
                </td>
                <td class="text-right font-bold"><?php echo esc_html(Xophz_Compass_Bazaar_Checkout_Receipt_Lines::money($line_total)); ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>

        <div class="receipt-divider"></div>

        <div class="details-table">
          <?php foreach ($receipt_lines as $line): ?>
            <div class="flex-between mb-1">
              <span><?php echo esc_html($line['label']); ?></span>
              <span><?php echo esc_html(Xophz_Compass_Bazaar_Checkout_Receipt_Lines::money($line['minor'])); ?></span>
            </div>
          <?php endforeach; ?>
          <div class="receipt-divider"></div>
          <div class="flex-between font-bold total-row">
            <span>Total</span>
            <span><?php echo esc_html(Xophz_Compass_Bazaar_Checkout_Receipt_Lines::money($total_minor)); ?></span>
          </div>
          <?php foreach ($cash_lines as $line): ?>
            <div class="flex-between mb-1">
              <span><?php echo esc_html($line['label']); ?></span>
              <span><?php echo esc_html(Xophz_Compass_Bazaar_Checkout_Receipt_Lines::money($line['minor'])); ?></span>
            </div>
          <?php endforeach; ?>
          <?php foreach ($refund_lines as $line): ?>
            <div class="flex-between mb-1">
              <span><?php echo esc_html($line['label']); ?></span>
              <span><?php echo esc_html(Xophz_Compass_Bazaar_Checkout_Receipt_Lines::money($line['minor'])); ?></span>
            </div>
          <?php endforeach; ?>
        </div>

        <div class="receipt-divider"></div>

        <?php if ('' !== $qr_src): ?>
          <div class="qr-container text-center">
            <img src="<?php echo esc_attr($qr_src); ?>" class="qr-img" alt="Receipt QR code">
            <span class="qr-label">w4 Quick Scan</span>
          </div>
        <?php endif; ?>

        <?php if ('' !== $store['policy']): ?>
          <div class="text-center subtitle" style="margin-top: 15px;"><?php echo nl2br(esc_html($store['policy'])); ?></div>
        <?php endif; ?>

        <div class="text-center mt-3 subtitle italic" style="margin-top: 15px;">
          Thank you for shopping at <?php echo esc_html($site_name); ?>!
          <?php if ($site_description): ?>
            <div style="margin-top: 4px;"><?php echo esc_html($site_description); ?></div>
          <?php endif; ?>
        </div>
      </div>

      <button class="print-btn" onclick="window.print()">Print</button>
    </body>
    </html>
    <?php
    exit;
  }
});
