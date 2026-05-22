<?php

/**
 * The plugin bootstrap file
 *
 * This file is read by WordPress to generate the plugin information in the plugin
 * admin area. This file also includes all of the dependencies used by the plugin,
 * registers the activation and deactivation functions, and defines a function
 * that starts the plugin.
 *
 * @link              http://example.com
 * @since             1.0.0
 * @package           Xophz_Compass_Bazaar
 *
 * @wordpress-plugin
 * Category:          Command Deck
 * Group:             POS
 * Plugin Name:       Xophz Bazaar Foresight
 * Plugin URI:        https://github.com/HalloftheGods/xophz-compass-bazaar
 * Description:       This supercharged UI helps you quickly manage your woocommerce inventory, orders, and view sales data in real time.
 * Version:           26.5.22.247
 * Author:            Hall of the Gods, Inc.
 * Author URI:        http://example.com/
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
define( 'XOPHZ_COMPASS_BAZAAR_VERSION', '26.5.22.247' );

/**
 * The code that runs during plugin activation.
 * This action is documented in includes/class-xophz-compass-bazaar-activator.php
 */
function activate_xophz_compass_bazaar() {
  require_once plugin_dir_path( __FILE__ ) . 'includes/class-xophz-compass-bazaar-activator.php';
  Xophz_Compass_Bazaar_Activator::activate();
}

/**
 * The code that runs during plugin deactivation.
 * This action is documented in includes/class-xophz-compass-bazaar-deactivator.php
 */
function deactivate_xophz_compass_bazaar() {
  require_once plugin_dir_path( __FILE__ ) . 'includes/class-xophz-compass-bazaar-deactivator.php';
  Xophz_Compass_Bazaar_Deactivator::deactivate();
}

register_activation_hook( __FILE__, 'activate_xophz_compass_bazaar' );
register_deactivation_hook( __FILE__, 'deactivate_xophz_compass_bazaar' );

add_action( 'before_woocommerce_init', function() {
  if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
    \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
  }
} );

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
      echo '<div class="error"><h2><strong>Xophz Bazaar Foresight</strong> requires Compass to run. It has self <strong>deactivated</strong>.</h2></div>';
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
    $provided_key = isset($_GET['key']) ? sanitize_text_field($_GET['key']) : '';
    if ($provided_key !== $order->get_order_key()) {
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
    $subtotal = 0;
    foreach ($items as $item) {
      $subtotal += $item->get_total();
    }
    $discounts = $order->get_total_discount();
    $total = $order->get_total();
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

    $self_url = home_url('/?xophz_bazaar_receipt=' . $order_id . '&key=' . $order->get_order_key());
    $qr_api_url = 'https://api.qrserver.com/v1/create-qr-code/?size=100&data=' . urlencode($self_url);
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
      <meta charset="UTF-8">
      <meta name="viewport" content="width=device-width, initial-scale=1.0">
      <title>Receipt #<?php echo $order_id; ?> - <?php echo esc_html($site_name); ?></title>
      <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
      <style>
        body {
          background: #f4f4f6;
          color: #111111;
          font-family: 'Courier New', Courier, monospace;
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
          max-width: 80mm;
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
        .receipt-logo-fallback {
          font-size: 32px;
          color: #333;
          margin-bottom: 8px;
        }
        .title {
          font-size: 18px;
          font-weight: bold;
          text-transform: uppercase;
          letter-spacing: 1px;
        }
        .subtitle {
          font-size: 11px;
          color: #666;
        }
        .receipt-divider {
          border-top: 1px dashed rgba(0, 0, 0, 0.2);
          height: 0;
          margin: 12px 0;
        }
        .details-table, .items-table {
          width: 100%;
          border-collapse: collapse;
          font-size: 12px;
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
          font-size: 16px;
          margin-top: 8px;
        }
        .qr-container {
          margin-top: 16px;
          display: flex;
          flex-direction: column;
          align-items: center;
        }
        .qr-img {
          width: 100px;
          height: 100px;
          display: block;
          margin-bottom: 6px;
        }
        .qr-label {
          font-size: 9px;
          color: #666;
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
          padding: 10px 16px;
          border-radius: 50px;
          font-family: inherit;
          cursor: pointer;
          box-shadow: 0 4px 10px rgba(0, 0, 0, 0.2);
          font-size: 12px;
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
          <?php else: ?>
            <div class="receipt-logo-fallback"><i class="fas fa-store"></i></div>
          <?php endif; ?>
          <div class="title"><?php echo esc_html($site_name); ?></div>
          <div class="subtitle">Cashier: <?php echo esc_html($cashier_name); ?></div>
        </div>

        <div class="receipt-divider"></div>

        <table class="details-table">
          <tr>
            <td>Order ID:</td>
            <td class="text-right font-bold">#<?php echo $order_id; ?></td>
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
              $qty = $item->get_quantity();
              $line_total = $item->get_total();
            ?>
              <tr>
                <td><?php echo esc_html($name); ?> <span class="subtitle">x<?php echo $qty; ?></span></td>
                <td class="text-right font-bold">$<?php echo number_format($line_total, 2); ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>

        <div class="receipt-divider"></div>

        <div class="details-table">
          <div class="flex-between mb-1">
            <span>Subtotal</span>
            <span>$<?php echo number_format($subtotal, 2); ?></span>
          </div>
          <?php if ($discounts > 0): ?>
            <div class="flex-between mb-1">
              <span>Discounts</span>
              <span>-$<?php echo number_format($discounts, 2); ?></span>
            </div>
          <?php endif; ?>
          <div class="receipt-divider"></div>
          <div class="flex-between font-bold total-row">
            <span>Total</span>
            <span>$<?php echo number_format($total, 2); ?></span>
          </div>
        </div>

        <div class="receipt-divider"></div>

        <div class="qr-container text-center">
          <img src="<?php echo esc_url($qr_api_url); ?>" class="qr-img" alt="Receipt QR">
          <span class="qr-label">w4 Quick Scan</span>
        </div>

        <div class="text-center mt-3 subtitle italic" style="margin-top: 15px;">
          Thank you for shopping at <?php echo esc_html($site_name); ?>!
          <?php if ($site_description): ?>
            <div style="margin-top: 4px;"><?php echo esc_html($site_description); ?></div>
          <?php endif; ?>
        </div>
      </div>

      <button class="print-btn" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
    </body>
    </html>
    <?php
    exit;
  }
});
