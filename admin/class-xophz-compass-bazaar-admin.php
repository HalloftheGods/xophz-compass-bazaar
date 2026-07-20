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
    if (!$args) $args = new stdClass();
    
    $wc_products = Xophz_Compass_Bazaar_Admin::getProductIds( $args );
    
    if (!$wc_products) {
       Xophz_Compass::output_json(['total_count' => 0, 'data' => []]);
       return;
    }

    $products = Xophz_Compass_Bazaar_Admin::getProductsDataByIds( isset($wc_products->products) ? $wc_products->products : [] );

    Xophz_Compass::output_json([
      'total_count' => isset($wc_products->total) ? $wc_products->total : 0,
      'data' => $products
    ]);
  }

  public function saveProduct() {
    $args = Xophz_Compass::get_input_json();
    if (!$args) {
      Xophz_Compass::output_json(['success' => false, 'message' => 'Invalid payload']);
      return;
    }

    $product = isset($args->id) && $args->id ? wc_get_product($args->id) : new WC_Product_Simple();
    
    if (!$product) {
      Xophz_Compass::output_json(['success' => false, 'message' => 'Product not found']);
      return;
    }

    if (isset($args->title)) $product->set_name($args->title);
    if (isset($args->description)) $product->set_description($args->description);
    if (isset($args->short_description)) $product->set_short_description($args->short_description);
    if (isset($args->regular_price)) $product->set_regular_price($args->regular_price);
    if (isset($args->sale_price)) $product->set_sale_price($args->sale_price);
    if (isset($args->sku)) $product->set_sku($args->sku);
    
    if (isset($args->manage_stock) && $args->manage_stock) {
      $product->set_manage_stock(true);
      if (isset($args->stock_quantity)) $product->set_stock_quantity($args->stock_quantity);
    } else {
      $product->set_manage_stock(false);
    }

    if (isset($args->stock_status)) $product->set_stock_status($args->stock_status);
    
    if (isset($args->category_ids) && is_array($args->category_ids)) {
      $product->set_category_ids($args->category_ids);
    }

    // Handle Image Upload from base64
    if (!empty($args->image_data)) {
      $upload_dir = wp_upload_dir();
      $image_data = base64_decode(preg_replace('#^data:image/\w+;base64,#i', '', $args->image_data));
      $filename = 'product-image-' . time() . '.png';
      $file_path = $upload_dir['path'] . '/' . $filename;
      
      file_put_contents($file_path, $image_data);
      
      $wp_filetype = wp_check_filetype($filename, null);
      $attachment = array(
          'post_mime_type' => $wp_filetype['type'],
          'post_title'     => sanitize_file_name($filename),
          'post_content'   => '',
          'post_status'    => 'inherit'
      );
      
      $attach_id = wp_insert_attachment($attachment, $file_path);
      require_once(ABSPATH . 'wp-admin/includes/image.php');
      $attach_data = wp_generate_attachment_metadata($attach_id, $file_path);
      wp_update_attachment_metadata($attach_id, $attach_data);
      
      $product->set_image_id($attach_id);
    }

    if (!isset($args->id) || !$args->id) {
      $product->set_status('publish');
    }
    
    $product_id = $product->save();

    if ($product_id) {
      // Get full product data to return
      $products = Xophz_Compass_Bazaar_Admin::getProductsDataByIds([$product_id]);
      Xophz_Compass::output_json([
        'success' => true,
        'product' => isset($products[0]) ? $products[0] : null
      ]);
    } else {
      Xophz_Compass::output_json(['success' => false, 'message' => 'Failed to save product']);
    }
  }


  public function updateProductStock(){
    $args = Xophz_Compass::get_input_json();
    $product_id = isset($args->product_id) ? intval($args->product_id) : 0;
    $quantity = isset($args->quantity) ? intval($args->quantity) : 0;
    $action = isset($args->action) ? $args->action : 'set'; // 'set', 'add', 'subtract'

    if(!$product_id) {
        Xophz_Compass::output_json(['success' => false, 'message' => 'Invalid product ID']);
        return;
    }

    $product = wc_get_product($product_id);
    if(!$product || !$product->managing_stock()){
        Xophz_Compass::output_json(['success' => false, 'message' => 'Product not found or not managing stock']);
        return;
    }

    $current_stock = $product->get_stock_quantity();
    $new_stock = $current_stock;

    if ($action === 'set') {
        $new_stock = $quantity;
    } else if ($action === 'add') {
        $new_stock = $current_stock + $quantity;
    } else if ($action === 'subtract') {
        $new_stock = $current_stock - $quantity;
    }

    wc_update_product_stock($product, $new_stock, 'set');
    
    // Get updated product data
    $updated_products = Xophz_Compass_Bazaar_Admin::getProductsDataByIds([$product_id]);

    Xophz_Compass::output_json([
        'success' => true,
        'product' => isset($updated_products[0]) ? $updated_products[0] : null
    ]);
  }

  public function getProductsStats(){
    $args = Xophz_Compass::get_input_json();

    $args->paginate = false;
    $args->limit = -1;

    $wc_products = Xophz_Compass_Bazaar_Admin::getPostIdsBySku( $args->filters->sku ); 

    $unique_in_stock = 0;
    $stock_value = 0;
    $total_stock = 0;
    $in_stock_value = 0;
    $avg_discount = 0;
    $est_discount = 0;
    $total_sales = 0;
    $est_sales = 0;

    foreach($wc_products as $product_id){
      $product = wc_get_product($product_id);
      if (!$product) continue;

      $price = $product->get_price();

      if ( $product->managing_stock() && $product->is_in_stock() ){
        $unique_in_stock++;
        $total_stock += $product->get_stock_quantity();
        $in_stock_value += ( $product->get_stock_quantity() * $price );
      }
      
      $total_sales += $product->get_total_sales();

      unset($product);
    }

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
    $posts = (!empty($args->filters)) 
      ? Xophz_Compass_Bazaar_Admin::getPostIdsByFilters( $args->filters ) : [];

    $default = [
      'include'   => !empty($posts)  ? $posts : '',
      'return'    => 'ids',
      'paginate'  => true
    ];

    $args_array = (array) $args;
    if (isset($args_array['category']) && is_string($args_array['category'])) {
        $args_array['category'] = [$args_array['category']];
    }

    return wc_get_products( array_merge($default, $args_array) );
  } 

  public static function getProductsDataByIds($ids){
    $products = [];
    foreach($ids as $i => $id){
      $p = new WC_Product($id);
      $data = $p->get_data();
      $data['thumb'] = wp_get_attachment_image_url( $p->get_image_id() );
      $data['image'] = $p->get_image();
      $data['title'] = $p->get_name();
      $data['stock'] = $p->get_stock_quantity();
      $products[$i] = $data;
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
