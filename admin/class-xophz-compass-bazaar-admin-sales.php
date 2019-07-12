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
    'wp_ajax_get_sales' => 'getSales',
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
    # Get Search Props
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
}
