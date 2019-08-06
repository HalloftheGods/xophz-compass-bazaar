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
                  if( substr( $sku, -strlen($args->sku) ) !== $arks->sku )
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

      $settings['date'] = $args->date;
      $settings['status'] = $args->status;
      $settings['sku'] = $args->sku;
      $settings['sku_scope'] = $args->sku_scope;
      $settings['gmt'] = $args->gmt;

      $monthly_sales = Xophz_Compass_Bazaar_Admin_Sales::getMonthlyReportSql($settings);

      $sql = "
        SELECT 
          count(Product) as unique_products,
          sum(Sold) as total_items_sold,
          sum(Gross) as gross,
          sum(Discount) as discounts,
          ROUND(100*(sum(Discount) / sum(Gross)),1) as discount_percentage,
          ROUND(sum(StockValue) * (sum(Discount) / sum(Gross) ),1) as projected_discount,
          sum(StockValue) - ( sum(StockValue) * (sum(Discount) / sum(Gross) ) ) as est_revenue,
          sum(Sales) as total_sales,
          sum(Stock) as remaining_stock,
          sum(StockValue) as in_stock_value,
          count(case when Stock > 0 then 1 end) as unique_in_stock 
        FROM
        (
          {$monthly_sales}
        ) sales
      "; 

      $sales = $wpdb->get_results($sql)[0];

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
  public function getMonthlyReportSql($settings)
  {
    global $wpdb;

    $thisMonth  = $settings['date'] . "-01"; 

    $date = new DateTime($thisMonth);
    $date->modify('first day of next month');
    $nextMonth = $date->format('Y-m-d');

    $sku = $settings['sku']; 

    $status = implode("','wc-", $settings['status']);

    if($sku){
      switch( $settings['sku_scope'] ){
        case 'contain':
          $sku = "LIKE '%{$sku}%'";
        break;
        case 'exact':
          $sku = "= '{$sku}'";
        break;
        case 'not':
          $sku = "NOT LIKE '%{$sku}%'";
        break;
        case 'start':
          $sku = "LIKE '{$sku}%'";
        break;
        case 'end':
          $sku = "LIKE '%{$sku}'";
        break;
      }
      $sku = " WHERE pm.meta_value {$sku} ";
    }

    $gmt = $settings['gmt'] ? '_gmt' : '';

    // max( CASE WHEN oim.meta_key = 'SKU' and oi.order_item_id = oim.order_item_id THEN oim.meta_value END ) as sku,
    $sql = "
      Select
        itemName as Product,
        pm.meta_value as SKU,
        sum(Qty) as Sold,
        sum(( (Qty * pm3.meta_value) )) as Gross,
        sum(( (Qty * pm3.meta_value) - lineTotal) ) as Discount,
        sum(lineTotal) as Sales,
        pm2.meta_value as Stock,
        pm3.meta_value as PriceTag,
        (pm2.meta_value * pm3.meta_value) as StockValue
      FROM
      (
        Select 
        *, -- all data in select below
        (subtotal - lineTotal) as discount,
        CASE WHEN variationID != 0 THEN variationID ELSE productID END as post_id -- get single id for variation or simple
        From 
        (
          SELECT 
            oi.order_id as orderId,
            oi.order_item_id as itemId,
            oi.order_item_name as itemName,
            oi.order_item_type as itemType,
            max( CASE WHEN oim.meta_key = '_product_id' and oi.order_item_id = oim.order_item_id THEN oim.meta_value END ) as productID, 
            max( CASE WHEN oim.meta_key = '_qty' and oi.order_item_id = oim.order_item_id THEN oim.meta_value END ) as Qty,
            max( CASE WHEN oim.meta_key = '_variation_id' and oi.order_item_id = oim.order_item_id THEN oim.meta_value END ) as variationID,
            max( CASE WHEN oim.meta_key = '_line_total' and oi.order_item_id = oim.order_item_id THEN oim.meta_value END ) as lineTotal,
            max( CASE WHEN oim.meta_key = '_line_subtotal_tax' and oi.order_item_id = oim.order_item_id THEN oim.meta_value END ) as subTotalTax,
            max( CASE WHEN oim.meta_key = '_line_tax' and oi.order_item_id = oim.order_item_id THEN oim.meta_value END ) as Tax,
            max( CASE WHEN oim.meta_key = '_tax_class' and oi.order_item_id = oim.order_item_id THEN oim.meta_value END ) as taxClass,
            max( CASE WHEN oim.meta_key = '_line_subtotal' and oi.order_item_id = oim.order_item_id THEN oim.meta_value END ) as subtotal
        FROM
          {$wpdb->posts} p 
          LEFT JOIN 
          {$wpdb->prefix}woocommerce_order_items oi on p.ID = oi.order_id		
          LEFT JOIN 
          {$wpdb->prefix}woocommerce_order_itemmeta as oim on 	oi.order_item_id = oim.order_item_id
        WHERE 
          (
            ( 
              p.post_date{$gmt} BETWEEN '{$thisMonth}' AND '{$nextMonth}' 
            ) 
            OR
            (
              p.post_modified{$gmt} BETWEEN '{$thisMonth}' AND '{$nextMonth}'
              AND
              p.post_type = 'shop_order_refund'
            )
          )
        AND
          p.post_status in ('wc-{$status}')
        GROUP BY
          oi.order_item_id 
        ORDER BY 
          p.post_date DESC
        ) sales 
      ) sales
      JOIN 
        {$wpdb->postmeta} as pm on pm.post_id = sales.post_id and pm.meta_key = '_sku'
      JOIN 
        {$wpdb->postmeta} as pm2 on pm2.post_id = sales.post_id and pm2.meta_key = '_stock'
      JOIN 
        {$wpdb->postmeta} as pm3 on pm3.post_id = sales.post_id and pm3.meta_key = '_price' 

      {$sku}

      GROUP BY
        SKU
      ORDER BY 
        Sales DESC

    ";
    return $sql;

  }
  
}
