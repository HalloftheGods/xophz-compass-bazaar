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
class Xophz_Compass_Bazaar_Admin {
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

  /**
  * Initialize the class and set its properties.
  *
  * @since    1.0.0
  * @param      string    $plugin_name       The name of this plugin.
  * @param      string    $version    The version of this plugin.
  */
  public function __construct( $plugin_name, $version ) {
    $this->plugin_name = $plugin_name;
    $this->version = $version;
  }

  /**
  * Register the stylesheets for the admin area.
  *
  * @since    1.0.0
  */
  public function enqueue_styles() {
    /**
    * This function is provided for demonstration purposes only.
    *
    * An instance of this class should be passed to the run() function
    * defined in Xophz_Compass_Bazaar_Loader as all of the hooks are defined
    * in that particular class.
    *
    * The Xophz_Compass_Bazaar_Loader will then create the relationship
    * between the defined hooks and the functions defined in this
    * class.
    */
    wp_enqueue_style( $this->plugin_name, plugin_dir_url( __FILE__ ) . 'css/xophz-compass-bazaar-admin.css', array(), $this->version, 'all' );
  }

  /**
  * Register the JavaScript for the admin area.
  *
  * @since    1.0.0
  */
  public function enqueue_scripts() {
    /**
    * This function is provided for demonstration purposes only.
    *
    * An instance of this class should be passed to the run() function
    * defined in Xophz_Compass_Bazaar_Loader as all of the hooks are defined
    * in that particular class.
    *
    * The Xophz_Compass_Bazaar_Loader will then create the relationship
    * between the defined hooks and the functions defined in this
    * class.
    */

    wp_enqueue_script( $this->plugin_name, plugin_dir_url( __FILE__ ) . 'js/xophz-compass-bazaar-admin.js', array( 'jquery' ), $this->version, false );
  }

  /**
  * Add menu item 
  *
  * @since    1.0.0
  */
  public function addToMenu(){
    Xophz_Compass::add_submenu($this->plugin_name);
  }


  public function getOrders(){
    $args = Xophz_Compass::get_input_json();

    Xophz_Compass::output_json([
      'total_count' => (int) $orders->total, 
      'data'        => $orders
    ]);
  }

  public function getOrderIds($args){
    return wc_get_orders(array_merge(
      [
        'return'    => 'ids',
        'paginate'  => true
      ], 
      (array) $args
    ));
  }

  public function getProducts(){
    $args = Xophz_Compass::get_input_json();
    $wc_products = Xophz_Compass_Bazaar_Admin::getProductIds( $args );
    $products = Xophz_Compass_Bazaar_Admin::getProductsDataByIds( $wc_products->products );

    Xophz_Compass::output_json([
      'total_count' => $wc_products->total,
      'data' => $products
    ]);
  }


  public function getProductsStats(){
    $args = Xophz_Compass::get_input_json();

    $args->paginate = false;
    $args->limit = -1;

    $wc_products = Xophz_Compass_Bazaar_Admin::getPostIdsBySku( $args->filters->sku ); 

    // Xophz_Compass_Bazaar_Admin::getPostIdsByFilters( $args->filters );
    // $total_stock = 0;

    $unique_in_stock = 0;
    $stock_value = 0;

    $qtys = [];

    foreach($wc_products as $product){
      $product = wc_get_product($product);

      $price = $product->get_price();
      // $price = $product->is_on_sale() 
      //   ? $product->get_sale_price()
      //   : $product->get_regulprice();

      if ( $product->managing_stock() && $product->is_in_stock() ){
        $unique_in_stock++;

        $total_stock = $total_stock + $product->get_stock_quantity();

        $qtys[] = $product->get_stock_quantity();

        $in_stock_value = $in_stock_value 
          + ( $product->get_stock_quantity() * $price );
      }

      unset($product);
    }

    // $wc_products = Xophz_Compass_Bazaar_Admin::getProductIds( $args );

    Xophz_Compass::output_json([
      'data' => [
        'ids' => $wc_products,
        'total_stock' => $total_stock,
        'unique_stock' => count($wc_products),
        'unique_in_stock' => $unique_in_stock,
        'in_stock_value' => $in_stock_value,
        'avg_discount'  => $avg_discount,
        'est_discount'  => $est_discount,
        'total_sales'   => $total_sales,
        'est_sales'     => $est_sales 
      ] 
    ]);
  }

  public static function getProductIds($args){
    $posts = ($args->filters) 
      ? Xophz_Compass_Bazaar_Admin::getPostIdsByFilters( $args->filters ) : [];

    $default = [
      // 'status'               => 'publish',
      // 'featured'             => true,
      // 'orderby'              => $ordering['orderby'],
      // 'order'                => $ordering['order'],
      'include'   => !empty($posts)  ? $posts : '',
      'return'    => 'ids',
      'paginate'  => true
    ];

    // if( !empty($args->filters) && !empty($posts) ){
    //   return [];
    // }

    return wc_get_products( array_merge($default, (array) $args) );
  } 

  public static function getProductsDataByIds($ids){
    $products = [];
    foreach($ids as $i => $id){
      $p = new WC_Product($id);
      $products[$i] = $p->get_data();
      $products[$i]['thumb'] = wp_get_attachment_image_url( $p->get_image_id() );
      $products[$i]['image'] = $p->get_image();
      // $products[] = [
      //   'price' => $p->get_price(),
      //   'image' => $p->get_image(),
      //   'thumb' => wp_get_attachment_image_url( $p->get_image_id() ),
      //   'title' => $p->get_name(),
      //   'stock' => $p->get_stock_quantity(),
      //   'sku'   => $p->get_sku(),
      //   'id'    => $p->get_id(),
      // ];
    }
    return $products;
  }

  public function getProductsStatsOld($Products){
    $total_stock = 0;
    $unique_in_stock = 0;
    $stock_value = 0;

    $Products = wc_get_products(array(
      'limit'                => -1,
      'paginate'             => true,
      'return'               => 'ids',
      // 'meta_key'             => '_price',
      // 'status'               => 'publish',
      // 'page'                 => $json->page,
      // 'featured'             => true,
      // 'orderby'              => $ordering['orderby'],
      // 'order'                => $ordering['order'],
    ));


    return [
      'total_stock' => $total_stock,
      'in_stock_value' => $in_stock_value,
      'unique_in_stock' => $unique_in_stock,
      'unique_stock' => $Products->total 
    ];
  }

  public static function getPostIdsByTitle($title){
    global $wpdb;
    return $wpdb->get_col("
      SELECT id FROM {$wpdb->posts}  
      WHERE (post_title LIKE '%".$wpdb->esc_like( $title )."%')
    ");
  }

  public static function getPostIdsBySku($sku){
    global $wpdb;
    return $wpdb->get_col("
      SELECT post_id FROM {$wpdb->postmeta}  
      WHERE (meta_key='_sku' AND meta_value LIKE '".$wpdb->esc_like( $sku )."%')
    ");
  }

  public static function getProductIdsByPostIds($ids){
    $productIds = [];
    foreach($ids as $id){
      array_push($productIds, wc_get_product($id)->get_parent_id());
    }
    return $productIds;
  }

  public static function getPostIdsByFilters($filters){
    $posts = [];
    $postIdsByTitle = $postIdsBySku = $productIdsByPostIds = [];

    if($filters->title){
      $postIdsByTitle = Xophz_Compass_Bazaar_Admin::getPostIdsByTitle( $filters->title );
    }

    if($filters->sku){
      $postIdsBySku = Xophz_Compass_Bazaar_Admin::getPostIdsBySku( $filters->sku );
      $productIdsByPostIds = Xophz_Compass_Bazaar_Admin::getProductIdsByPostIds( $postIdsBySku );
    }

    return array_merge($productIdsByPostIds, $postIdsByTitle, $postIdsBySku); 
  }
}
