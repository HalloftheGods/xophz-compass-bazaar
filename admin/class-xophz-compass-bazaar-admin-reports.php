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
if(function_exists('WC')){
  include_once( WC()->plugin_path() . '/includes/admin/reports/class-wc-admin-report.php' );
  include_once( WC()->plugin_path() . '/includes/admin/reports/class-wc-report-sales-by-date.php' );
}


class Xophz_Compass_Bazaar_Admin_Reports{
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
    'wp_ajax_get_report'          => 'getReport',
    'wp_ajax_get_reports'         => 'getReports',
    'wp_ajax_get_sales_sparkline' => 'getSalesSparkline',
    'wp_ajax_get_total_orders'    => 'getTotalOrders',
    'wp_ajax_get_total_sales'     => 'getTotalSales',
    'wp_ajax_get_total_users'     => 'getTotalUsers',
    'wp_ajax_get_total_views'     => 'getTotalViews',
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

  public function getSalesSparkline(){
    $args   = Xophz_Compass::get_input_json();

    // $reports = new WC_Admin_Reports();
    // $salesByDate = $reports->get_report('sales_by_date');

    $salesByDate = new WC_Report_Sales_By_Date();

    Xophz_Compass::output_json([
      'sparkline'        => [
        // 'value' => $salesByDate->sales_sparkline(),
        'labels' => $salesByDate->get_chart_legend(),
      ] 
    ]);
  }

  public function getTotalOrders(){
    global $wpdb;
    $results = $wpdb->get_results("
      SELECT 
        MONTHNAME(post_date) as month, 
        SUM(meta.meta_value) AS total_shipping, 
        COUNT(posts.ID) AS total_orders
      FROM 
        {$wpdb->posts} AS posts
      LEFT JOIN 
        {$wpdb->postmeta} AS meta 
        ON 
          posts.ID = meta.post_id
      LEFT JOIN 
        {$wpdb->term_relationships} AS rel 
        ON 
          posts.ID=rel.object_ID
      LEFT JOIN 
        {$wpdb->term_taxonomy} AS tax 
        USING( term_taxonomy_id )
      LEFT JOIN 
        {$wpdb->terms} AS term 
        USING( term_id )
      WHERE 
        meta.meta_key = '_order_shipping'
        AND 
        posts.post_type = 'shop_order'
        AND 
        post_date > DATE_SUB(now(), INTERVAL 6 MONTH)
      GROUP BY 
        YEAR(post_date), MONTH(post_date)
    ");

    $labels = array_column($results,'month');
    $orders = array_map('intval', array_column($results,'total_orders'));
    $shipping = array_map('intval', array_column($results,'total_shipping'));

    $total_orders = array_sum($orders);
    $total_shipping = array_sum($shipping);

    array_pop($labels);
    array_pop($orders);

    Xophz_Compass::output_json([
      'sparkline' =>  [
        'labels' => $labels,
        'value' => $orders
      ],
      'total_orders'        => $total_orders,
      'total_shipping'      => $total_shipping,
    ]);
  }
  public function getTotalUsers(){
    global $wpdb;
    $results = $wpdb->get_results("
      SELECT 
        MONTHNAME(FROM_UNIXTIME(meta.meta_value)) as month, 
        COUNT(users.ID) AS total_users
      FROM 
        {$wpdb->users} AS users 
      LEFT JOIN 
        {$wpdb->usermeta} AS meta 
        ON 
          users.ID = meta.user_id
      WHERE 
        meta.meta_key = 'wc_last_active'
        AND 
        meta.meta_value > UNIX_TIMESTAMP(now() - INTERVAL 6 MONTH)
      GROUP BY 
        YEAR(FROM_UNIXTIME(meta.meta_value)), MONTH(FROM_UNIXTIME(meta.meta_value))
    ");

    $labels = array_column($results,'month');
    $users = array_map('intval', array_column($results,'total_users'));

    $total_users = array_sum($users);

    array_pop($labels);
    array_pop($users);

    Xophz_Compass::output_json([
      'sparkline' =>  [
        'labels' => $labels,
        'value' => $users
      ],
      'total_users'        => $total_users,
    ]);
  }

  public function getTotalViews(){
    global $wpdb;
    $results = $wpdb->get_results("
      SELECT 
        MONTHNAME(post_date) as month, 
        SUM(meta.meta_value) AS total_views, 
        COUNT(posts.ID) AS total_posts 
      FROM 
        {$wpdb->posts} AS posts
      LEFT JOIN 
        {$wpdb->postmeta} AS meta 
        ON 
          posts.ID = meta.post_id
      WHERE 
        meta.meta_key = 'post_views_count'
        AND 
        post_date > DATE_SUB(now(), INTERVAL 6 MONTH)
      GROUP BY 
        YEAR(post_date), MONTH(post_date)
    ");

    $labels = array_column($results,'month');
    $views = array_map('intval', array_column($results,'total_views'));
    $posts = array_map('intval', array_column($results,'total_posts'));

    $total_views = array_sum($views);
    $total_posts = array_sum($posts);

    array_pop($labels);
    array_pop($views);

    Xophz_Compass::output_json([
      'sparkline' =>  [
        'labels' => $labels,
        'value' => $views
      ],
      'total_views'        => $total_views,
      'total_posts'        => $total_posts,
    ]);
  }

  public function getTotalSales() {
    global $wpdb;
    $results = apply_filters( 'woocommerce_reports_sales_overview_order_totals', $wpdb->get_results("
      SELECT 
        MONTHNAME(post_date) as month, 
        SUM(meta.meta_value) AS total_sales, 
        COUNT(posts.ID) AS total_orders 
      FROM 
        {$wpdb->posts} AS posts
      LEFT JOIN 
        {$wpdb->postmeta} AS meta 
      ON 
        posts.ID = meta.post_id
      WHERE 
        meta.meta_key = '_order_total'
        AND 
        posts.post_type = 'shop_order'
        AND 
        posts.post_status IN ( '" 
          . implode( "','", array( 'wc-completed', 'wc-processing', 'wc-refunded' ) ) . "' 
        )
        AND 
        post_date > DATE_SUB(now(), INTERVAL 6 MONTH)
      GROUP BY 
        YEAR(post_date), MONTH(post_date)
    "));

    $years = array_column($results,'month');
    $totals = array_map('intval', array_column($results,'total_sales'));

    $total_sales = array_sum($totals);

    array_pop($labels);
    array_pop($totals);

    Xophz_Compass::output_json([
      'sparkline' =>  [
        'labels' => $years,
        'value' => $totals
      ],
      'total_sales'        => array_sum($totals)
    ]);
  }

  public function getReport(){
    $reports = new WC_Admin_Reports();
    $args   = Xophz_Compass::get_input_json();
    Xophz_Compass::output_json([
      'report'        => $reports->get_report($args->report)
    ]);
  }
  
  public function getReports(){
    $reports = new WC_Admin_Reports();
    Xophz_Compass::output_json([
      'reports'        => $reports->get_reports()
    ]);
  }
}
