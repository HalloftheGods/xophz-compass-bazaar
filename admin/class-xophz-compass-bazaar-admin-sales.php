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
class Xophz_Compass_Bazaar_Admin_Sales{
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
    'wp_ajax_get_monthly_sales' => 'getMonthlySales',
    'wp_ajax_export_monthly_sales_report' => 'exportMonthlySalesReport',
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

  public function getSales(){
      $args = Xophz_Compass::get_input_json();

      $args->paginate  = false;
      $args->limit     = -1;

      // $args->type = 'shop_order';
      $orders = Xophz_Compass_Bazaar_Admin_Orders::getOrderIds($args);

      $remaining_stock = 0;
      $unique_in_stock = [];
      $in_stock_value = 0;
      $subtotal = 0;
      $total_sales = 0;
      $discount = 0;
      $total_tax = 0;
      $total_qty = 0;
      $total_items_sold = 0;
      $shipping = 0;
      $sales = 0;
      $items = [];

      foreach($orders as $id){
        $o = wc_get_order($id);

        # loop thru items
        foreach ($o->get_items() as $item_id => $item) {
          $skip = false;

          # get product 
          $p = $item->get_product();

          if(!$p){
            $skip = true;
          }else{
            $sku = $p->get_sku(); 

            // If filtering skus... 
            if($args->sku){
              switch($args->sku_scope){
                case "start":
                  if( strpos( $sku, $args->sku) !== 0 )
                    $skip = true;
                  break;
                case "end":
                  if( substr( $sku, -strlen($args->sku) ) !== $args->sku )
                    $skip = true;
                  break;
                case "contain":
                  if( !stristr( $sku, $args->sku) )
                    $skip = true;
                  break;
                case "exact":
                  if( $sku !== $args->sku )
                    $skip = true;
                  break;
                case "not":
                  if( stristr( $sku, $args->sku) )
                    $skip = true;
                  break;
              }
            }

            // $items[$sku] += $p->get_stock_quantity(); 
          }
          
          if($skip){
            unset($orders[$id]);
            unset($o);
            unset($p);
            continue;
          }

          if(
            'instock' == $p->get_stock_status() 
            ||
            $p->get_stock_quantity()
          ){
            $unique_in_stock[$sku]++;

            # Get Total Stock of In Stock Prodcuts
            $remaining_stock += $p->get_stock_quantity(); 

            # Get Total Value of Stock
            $in_stock_value += $p->get_stock_quantity() * $p->get_price();
          }

          $items[$sku]++; 
          // $discount += $item->get_total_discount();
          // $shipping +=  $item->get_shipping_total();

          $subtotal     += $item->get_subtotal();

          # Get Total Sales
          $total_sales  += $item->get_total();

          $discount     += ( $item->get_subtotal() - $item->get_total() );
          $total_tax    += $item->get_total_tax();
          # get total unique products sold
          # get of unique products total in stock
          $total_qty    += $item->get_quantity();
          $total_items_sold += $item->get_quantity();

        }

        if($o){
          // $total_item_count += $o->get_item_count();
          $shipping +=  $o->get_shipping_total();
          // $subtotal +=  $o->get_subtotal();
          // $total_tax +=  $o->get_total_tax();
          // $total_sales +=  $o->get_total();
          $sales++;
        }
      }

      setlocale(LC_MONETARY, 'en_US');

      # Get Avg. Discount of Products Sold
      $avg_discount = ($discount / $subtotal) * 100;

      # Get Est. Value Discount of Avg Discount Applied to Stock Value
      $est_discount = $in_stock_value * ($discount / $subtotal);

      # Get Stock value - Est Discount 
      $est_sales = $in_stock_value - $est_discount;

      $profit = $est_sales / 2;

      Xophz_Compass::output_json([
        'sales' => [
          'args' => $args,
          'total_orders' => count($orders),
          'unique_products' => count($items),
          'total_items_sold' => $total_items_sold,
          'unique_stock' => $total_qty,
          'remaining_stock' => $remaining_stock,
          'unique_in_stock' => count($unique_in_stock),
          'in_stock_value' => $in_stock_value,
          'discount' => $discount,
          'avg_discount' => number_format(
            $avg_discount, 2 
          ),
          'est_discount' => $est_discount,
          'total_sales' => $total_sales,
          'subtotal' => $subtotal,
          'est_sales' => $est_sales,
          'profit' => $profit,
          'shipping' => $shipping,
          'total_tax' => $total_tax,
          'items' => $items 
        ]
      ]);
  }

    /**
    * undocumented function
    *
    * @return void
    */
    public function getMonthlySales()
    {
      global $wpdb;

      $args = Xophz_Compass::get_input_json();

      if( empty($args->status) )
        Xophz_Compass::output_error(
          "Order Status Required",
          400
        );

      $settings['date'] = !empty($args->date) ? $args->date : date('Y-m');
      $settings['status'] = $args->status;
      $settings['sku'] = !empty($args->sku) ? $args->sku : '';
      $settings['sku_scope'] = !empty($args->sku_scope) ? $args->sku_scope : 'start';
      $settings['gmt'] = !empty($args->gmt) ? $args->gmt : false;

      $monthly_sales = Xophz_Compass_Bazaar_Admin_Sales::getMonthlyReportSql($settings);

      $sql = "
        SELECT 
          COALESCE(count(DISTINCT Product), 0) as unique_products,
          COALESCE(sum(Sold), 0) as total_items_sold,
          COALESCE(ROUND(sum(Gross), 2), 0) as gross,
          COALESCE(ROUND(sum(Discount), 2), 0) as discounts,
          CASE WHEN sum(Gross) > 0 THEN ROUND(100 * (sum(Discount) / sum(Gross)), 1) ELSE 0 END as discount_percentage,
          CASE WHEN sum(Gross) > 0 THEN ROUND(sum(StockValue) * (sum(Discount) / sum(Gross)), 1) ELSE 0 END as projected_discount,
          COALESCE(ROUND(sum(StockValue) - (CASE WHEN sum(Gross) > 0 THEN (sum(StockValue) * (sum(Discount) / sum(Gross))) ELSE 0 END), 2), 0) as est_revenue,
          COALESCE(ROUND(sum(Sales), 2), 0) as total_sales,
          COALESCE(sum(Stock), 0) as remaining_stock,
          COALESCE(ROUND(sum(StockValue), 2), 0) as in_stock_value,
          COALESCE(count(CASE WHEN Stock > 0 THEN 1 END), 0) as unique_in_stock 
        FROM
        (
          {$monthly_sales}
        ) sales
      "; 

      $results = $wpdb->get_results($sql);
      $sales = !empty($results) ? $results[0] : null;

      if (!$sales || is_null($sales->unique_products)) {
        $sales = (object)[
          'unique_products' => 0,
          'total_items_sold' => 0,
          'gross' => 0,
          'discounts' => 0,
          'discount_percentage' => 0,
          'projected_discount' => 0,
          'est_revenue' => 0,
          'total_sales' => 0,
          'remaining_stock' => 0,
          'in_stock_value' => 0,
          'unique_in_stock' => 0
        ];
      }

      Xophz_Compass::output_json([
        'sales' => $sales 
      ]);
    }

  /**
   * undocumented function
   *
   * @return void
   */
  public function exportMonthlySalesReport()
  {
    global $wpdb;

    $args = $_REQUEST;
    // output headers so that the file is downloaded rather than displayed
    header("Cache-Control: public");
    header("Content-Description: File Transfer");
    header('Content-Type: text/csv; charset=utf-8');
    header("Content-Disposition: attachment; filename={$args['filename']}");
    header("Content-Transfer-Encoding: binary");
    header("Content-Type: binary/octet-stream");
    header("Content-Name: {$args['filename']}");

    // create a file pointer connected to the output stream
    $output = fopen('php://output', 'w');

    $sql = Xophz_Compass_Bazaar_Admin_Sales::getMonthlyReportSql($args);

    $results = $wpdb->get_results($sql);

    $columns = [
      'Product',
      'SKU',
      'Sold',
      'Gross',
      'Discount',
      'Sales',
      'PriceTag',
      'Stock',
      'StockValue',
    ];

    fputcsv($output, $columns);

    foreach($results as $result){
      $row = [];
      foreach($columns as $column){
        $row[] = $result->$column;
      }
      fputcsv($output, $row);
    }

    wp_die();
  }

  /**
   * undocumented function
   *
   * @return void
   */
  public static function getMonthlyReportSql($settings)
  {
    global $wpdb;

    $raw_date = !empty($settings['date']) ? $settings['date'] : date('Y-m');
    $time = strtotime($raw_date);
    if ($time === false) {
      $time = time();
    }

    $thisMonth = date('Y-m-01 00:00:00', $time);
    $nextMonth = date('Y-m-01 00:00:00', strtotime('+1 month', strtotime($thisMonth)));

    $sku = !empty($settings['sku']) ? trim($settings['sku']) : '';

    $raw_status = is_array($settings['status']) ? $settings['status'] : [$settings['status']];
    $status_list = [];
    foreach ($raw_status as $st) {
      $clean_st = preg_replace('/^wc-/', '', trim($st));
      $status_list[] = "wc-{$clean_st}";
      $status_list[] = "{$clean_st}";
    }
    $status_sql = "'" . implode("','", array_unique($status_list)) . "'";

    $sku_where = "";
    if (!empty($sku)) {
      $escaped_sku = esc_sql($sku);
      $scope = !empty($settings['sku_scope']) ? $settings['sku_scope'] : 'start';
      switch ($scope) {
        case 'contain':
          $sku_clause = "LIKE '%{$escaped_sku}%'";
          break;
        case 'exact':
          $sku_clause = "= '{$escaped_sku}'";
          break;
        case 'not':
          $sku_clause = "NOT LIKE '%{$escaped_sku}%'";
          break;
        case 'end':
          $sku_clause = "LIKE '%{$escaped_sku}'";
          break;
        case 'start':
        default:
          $sku_clause = "LIKE '{$escaped_sku}%'";
          break;
      }
      $sku_where = " WHERE COALESCE(pm.meta_value, '') {$sku_clause} ";
    }

    $gmt = !empty($settings['gmt']) ? '_gmt' : '';

    $hpos_enabled = class_exists('\Automattic\WooCommerce\Utilities\OrderUtil') && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();

    $orders_table = $hpos_enabled ? "{$wpdb->prefix}wc_orders" : "{$wpdb->posts}";
    $date_col = $hpos_enabled ? "date_created{$gmt}" : "post_date{$gmt}";
    $modified_col = $hpos_enabled ? "date_updated{$gmt}" : "post_modified{$gmt}";
    $type_col = $hpos_enabled ? "type" : "post_type";
    $status_col = $hpos_enabled ? "status" : "post_status";
    $order_id_col = $hpos_enabled ? "id" : "ID";

    $type_filter = $hpos_enabled ? "p.type IN ('shop_order', 'shop_order_refund')" : "p.post_type IN ('shop_order', 'shop_order_refund')";

    $sql = "
      SELECT
        itemName as Product,
        COALESCE(pm.meta_value, '') as SKU,
        sum(Qty) as Sold,
        sum( (Qty * COALESCE(pm3.meta_value, subtotal/NULLIF(Qty,0), 0)) ) as Gross,
        sum( ( (Qty * COALESCE(pm3.meta_value, subtotal/NULLIF(Qty,0), 0)) - lineTotal) ) as Discount,
        sum(lineTotal) as Sales,
        COALESCE(pm2.meta_value, 0) as Stock,
        COALESCE(pm3.meta_value, 0) as PriceTag,
        (COALESCE(pm2.meta_value, 0) * COALESCE(pm3.meta_value, 0)) as StockValue
      FROM
      (
        SELECT 
          *, 
          (subtotal - lineTotal) as discount,
          CASE WHEN variationID != 0 AND variationID IS NOT NULL THEN variationID ELSE productID END as post_id
        FROM 
        (
          SELECT 
            oi.order_id as orderId,
            oi.order_item_id as itemId,
            oi.order_item_name as itemName,
            oi.order_item_type as itemType,
            max( CASE WHEN oim.meta_key = '_product_id' THEN oim.meta_value END ) as productID, 
            max( CASE WHEN oim.meta_key = '_qty' THEN CAST(oim.meta_value AS UNSIGNED) END ) as Qty,
            max( CASE WHEN oim.meta_key = '_variation_id' THEN CAST(oim.meta_value AS UNSIGNED) END ) as variationID,
            max( CASE WHEN oim.meta_key = '_line_total' THEN CAST(oim.meta_value AS DECIMAL(10,2)) END ) as lineTotal,
            max( CASE WHEN oim.meta_key = '_line_subtotal_tax' THEN CAST(oim.meta_value AS DECIMAL(10,2)) END ) as subTotalTax,
            max( CASE WHEN oim.meta_key = '_line_tax' THEN CAST(oim.meta_value AS DECIMAL(10,2)) END ) as Tax,
            max( CASE WHEN oim.meta_key = '_tax_class' THEN oim.meta_value END ) as taxClass,
            max( CASE WHEN oim.meta_key = '_line_subtotal' THEN CAST(oim.meta_value AS DECIMAL(10,2)) END ) as subtotal
          FROM
            {$orders_table} p 
            INNER JOIN 
            {$wpdb->prefix}woocommerce_order_items oi ON p.{$order_id_col} = oi.order_id AND oi.order_item_type = 'line_item'
            LEFT JOIN 
            {$wpdb->prefix}woocommerce_order_itemmeta as oim ON oi.order_item_id = oim.order_item_id
          WHERE 
            {$type_filter}
            AND
            (
              ( 
                p.{$date_col} >= '{$thisMonth}' AND p.{$date_col} < '{$nextMonth}'
              ) 
              OR
              (
                p.{$modified_col} >= '{$thisMonth}' AND p.{$modified_col} < '{$nextMonth}'
                AND
                p.{$type_col} = 'shop_order_refund'
              )
            )
            AND
            p.{$status_col} IN ({$status_sql})
          GROUP BY
            oi.order_item_id 
        ) raw_items
      ) sales
      LEFT JOIN 
        {$wpdb->postmeta} as pm ON pm.post_id = sales.post_id AND pm.meta_key = '_sku'
      LEFT JOIN 
        {$wpdb->postmeta} as pm2 ON pm2.post_id = sales.post_id AND pm2.meta_key = '_stock'
      LEFT JOIN 
        {$wpdb->postmeta} as pm3 ON pm3.post_id = sales.post_id AND pm3.meta_key = '_price' 

      {$sku_where}

      GROUP BY
        sales.post_id, SKU
      ORDER BY 
        Sales DESC
    ";

    return $sql;
  }
  
}
