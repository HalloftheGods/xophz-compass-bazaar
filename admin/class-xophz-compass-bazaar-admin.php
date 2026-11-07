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
    wp_enqueue_style( $this->plugin_name, plugin_dir_url( __FILE__ ) . 'css/xophz-compass-bazaar-admin.css', array(), $this->version, 'all' );
  }

  /**
  * Register the JavaScript for the admin area.
  *
  * @since    1.0.0
  */
  public function enqueue_scripts() {
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
    Xophz_Compass_Security::enforce_spa_ajax( 'manage_woocommerce', 'edit_products' );

    $args = Xophz_Compass::get_input_json();
    if (!$args) {
      Xophz_Compass::output_json(['success' => false, 'message' => 'Invalid payload']);
      return;
    }

    try {
      $product_id = (!empty($args->id) && is_numeric($args->id) && intval($args->id) > 0) ? intval($args->id) : 0;
      $product    = $product_id > 0 ? wc_get_product($product_id) : new WC_Product_Simple();
      
      if (!$product) {
        Xophz_Compass::output_json(['success' => false, 'message' => 'Product not found']);
        return;
      }

      if (isset($args->title) && !empty($args->title)) {
        $product->set_name(sanitize_text_field($args->title));
      }
      if (isset($args->description)) {
        $product->set_description(wp_kses_post($args->description));
      }
      if (isset($args->short_description)) {
        $product->set_short_description(wp_kses_post($args->short_description));
      }
      if (isset($args->regular_price) && $args->regular_price !== '') {
        $product->set_regular_price(wc_format_decimal($args->regular_price));
      } else {
        $product->set_regular_price('');
      }
      if (isset($args->sale_price) && $args->sale_price !== '') {
        $product->set_sale_price(wc_format_decimal($args->sale_price));
      } else {
        $product->set_sale_price('');
      }
      if (isset($args->sku) && !empty($args->sku)) {
        $product->set_sku(sanitize_text_field($args->sku));
      }
      
      // Stock fields need bazaar_manage_stock; the quantity is written after the save, through the ledger in ledger mode.
      $stock_quantity = Xophz_Compass_Bazaar_Admin_Product_Stock::apply_form($product, $args);

      if (isset($args->category_ids)) {
        $cat_ids = is_string($args->category_ids) ? json_decode($args->category_ids, true) : $args->category_ids;
        if (is_array($cat_ids)) {
          $product->set_category_ids(array_map('intval', array_filter($cat_ids)));
        }
      }

      // Handle Image Assignment (from Media Library) or Upload (from base64)
      if (!empty($args->image_id)) {
        $product->set_image_id(intval($args->image_id));
      } else if (!empty($args->image_data)) {
        $upload_dir = wp_upload_dir();
        $image_data = base64_decode(preg_replace('#^data:image/\w+;base64,#i', '', $args->image_data));
        if ($image_data) {
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
          if ($attach_id && !is_wp_error($attach_id)) {
            require_once(ABSPATH . 'wp-admin/includes/image.php');
            $attach_data = wp_generate_attachment_metadata($attach_id, $file_path);
            wp_update_attachment_metadata($attach_id, $attach_data);
            $product->set_image_id($attach_id);
          }
        }
      }

      if (!$product_id) {
        $product->set_status('publish');
      }
      
      $product_id = $product->save();

      if ($product_id && null !== $stock_quantity) {
        Xophz_Compass_Bazaar_Admin_Product_Stock::set_quantity(
          $product_id,
          $stock_quantity,
          'product_form',
          Xophz_Compass_Bazaar_Admin_Product_Stock::adjust_key(Xophz_Compass_Bazaar_Admin_Product_Stock::client_uuid($args))
        );
      }

      if ($product_id) {
        $products = Xophz_Compass_Bazaar_Admin::getProductsDataByIds([$product_id]);
        $response = ['success' => true, 'product' => isset($products[0]) ? $products[0] : null];
      } else {
        $response = ['success' => false, 'message' => 'Failed to save product'];
      }
    } catch (\Throwable $e) {
      $response = ['success' => false, 'message' => $e->getMessage()];
    }
    Xophz_Compass::output_json($response);
  }

  /** bazaar_import_products_csv: see Xophz_Compass_Bazaar_Admin_Product_Import. */
  public function importProductsCsv() {
    (new Xophz_Compass_Bazaar_Admin_Product_Import())->import();
  }

  /**
   * bazaar_lookup_barcode: the global product lookup for the big product
   * editor. Response shape unchanged: {success, product:{title, brand,
   * description, category, image_url, barcode}} or {success:false, message}.
   */
  public function lookupBarcode() {
    try {
      if (!current_user_can('manage_woocommerce') && !current_user_can('edit_products')) {
        Xophz_Compass::output_json(['success' => false, 'message' => 'Permission denied.']);
        return;
      }

      $args = Xophz_Compass::get_input_json();
      $barcode = isset($args->barcode) ? trim(sanitize_text_field($args->barcode)) : '';

      if (empty($barcode)) {
        Xophz_Compass::output_json(['success' => false, 'message' => 'Barcode string is required.']);
        return;
      }

      $found = self::global_barcode_lookup($barcode);

      if ('found' !== $found['status'] || empty($found['product']['title'])) {
        Xophz_Compass::output_json([
          'success' => false,
          'message' => "No product record found in global database for barcode {$barcode}."
        ]);
        return;
      }

      $p = $found['product'];
      Xophz_Compass::output_json([
        'success' => true,
        'product' => [
          'title' => $p['title'],
          'brand' => $p['brand'],
          'description' => $p['description'],
          'category' => $p['category'],
          'image_url' => $p['image_url'],
          'barcode' => $barcode
        ]
      ]);
    } catch (\Throwable $e) {
      Xophz_Compass::output_json(['success' => false, 'message' => $e->getMessage()]);
    }
  }

  /** User-Agent Open Food Facts asks every API client to send. */
  const OFF_USER_AGENT = 'WestwoodCompassBazaar/1.0 (+https://www.westwooddiscount.com)';

  /** Seconds each provider request may take. */
  const LOOKUP_TIMEOUT = 4;

  /** Transient prefix of the provider cache (key: md5 of the normalized code). */
  const LOOKUP_CACHE_PREFIX = 'bazaar_bc_lookup_';

  /**
   * Look a barcode up in the global databases: Open Food Facts first, then the
   * UPCitemdb trial API. Returns data and never sends a response, so the old
   * editor lookup and Quick Add share it.
   *
   * Results are cached in a transient keyed by the normalized code: found for
   * 30 days, not found for 3 days, anything else (rate limited, timeout,
   * server error) for 15 minutes. A UPCitemdb 429 is 'rate_limited', never
   * 'not_found'.
   *
   * @param string $barcode As scanned or typed.
   * @return array{status:string,source:string,product:?array,price_hint:?array,cached:bool}
   *   status: 'found', 'not_found', 'rate_limited' or 'error'.
   *   product: title, brand, description, category, image_url (strings).
   *   price_hint: low, high (strings with 2 decimals), source.
   */
  public static function global_barcode_lookup(string $barcode): array {
    $code = Xophz_Compass_Bazaar_Barcode::normalize($barcode);
    $query = '' !== $code['normalized'] ? $code['normalized'] : $code['raw'];
    $empty = ['status' => 'not_found', 'source' => '', 'product' => null, 'price_hint' => null, 'cached' => false];
    if ('' === $query) {
      return $empty;
    }

    $cache_key = self::LOOKUP_CACHE_PREFIX . md5($query);
    $cached = get_transient($cache_key);
    if (is_array($cached) && isset($cached['status'])) {
      $cached['cached'] = true;
      return array_merge($empty, $cached);
    }

    $off_code = '' !== $code['normalized'] ? Xophz_Compass_Bazaar_Barcode::off_query_code($code) : $query;
    $off = self::lookup_open_food_facts($off_code);
    $result = $off;
    if ('found' !== $off['status']) {
      $upc = self::lookup_upcitemdb($query);
      if ('found' === $upc['status']) {
        $result = $upc;
      } elseif ('not_found' === $off['status'] && 'not_found' === $upc['status']) {
        $result = $upc;
      } elseif ('rate_limited' === $upc['status']) {
        $result = $upc;
      } else {
        $result = ['status' => 'error', 'source' => '', 'product' => null, 'price_hint' => null];
      }
    }

    $ttl = [
      'found' => 30 * DAY_IN_SECONDS,
      'not_found' => 3 * DAY_IN_SECONDS,
    ][$result['status']] ?? 15 * MINUTE_IN_SECONDS;
    $result = array_merge($empty, $result);
    $store = $result;
    unset($store['cached']);
    set_transient($cache_key, $store, $ttl);
    return $result;
  }

  /** Open Food Facts v2. @return array status, source, product, price_hint */
  private static function lookup_open_food_facts(string $code): array {
    $out = ['status' => 'error', 'source' => 'openfoodfacts', 'product' => null, 'price_hint' => null];
    $url = 'https://world.openfoodfacts.org/api/v2/product/' . rawurlencode($code) . '.json';
    $response = wp_remote_get($url, [
      'timeout' => self::LOOKUP_TIMEOUT,
      'user-agent' => self::OFF_USER_AGENT,
      'headers' => ['User-Agent' => self::OFF_USER_AGENT, 'Accept' => 'application/json'],
    ]);
    if (is_wp_error($response)) {
      return $out;
    }
    $status = (int) wp_remote_retrieve_response_code($response);
    $data = json_decode((string) wp_remote_retrieve_body($response), true);
    if (404 === $status || (200 === $status && is_array($data) && isset($data['status']) && 1 !== (int) $data['status'])) {
      $out['status'] = 'not_found';
      return $out;
    }
    if (429 === $status) {
      $out['status'] = 'rate_limited';
      return $out;
    }
    if (200 !== $status || !is_array($data) || !isset($data['product']) || !is_array($data['product'])) {
      return $out;
    }

    $p = $data['product'];
    $title = self::lookup_string($p['product_name'] ?? '');
    $brand = self::lookup_string($p['brands'] ?? '');
    if ('' === $title) {
      // A record without a name is no help to a person adding stock.
      $out['status'] = 'not_found';
      return $out;
    }
    if ('' !== $brand && stripos($title, $brand) === false) {
      $title = $brand . ' ' . $title;
    }
    $out['status'] = 'found';
    $out['product'] = [
      'title' => $title,
      'brand' => $brand,
      'description' => self::lookup_string($p['generic_name'] ?? ''),
      'category' => self::lookup_string($p['categories'] ?? ($p['categories_old'] ?? '')),
      'image_url' => self::lookup_string($p['image_url'] ?? ($p['image_front_url'] ?? '')),
    ];
    return $out;
  }

  /** UPCitemdb trial API. A 429 is rate_limited. @return array status, source, product, price_hint */
  private static function lookup_upcitemdb(string $code): array {
    $out = ['status' => 'error', 'source' => 'upcitemdb', 'product' => null, 'price_hint' => null];
    $response = wp_remote_get('https://api.upcitemdb.com/prod/trial/lookup?upc=' . rawurlencode($code), [
      'timeout' => self::LOOKUP_TIMEOUT,
      'headers' => ['Accept' => 'application/json'],
    ]);
    if (is_wp_error($response)) {
      return $out;
    }
    $status = (int) wp_remote_retrieve_response_code($response);
    if (429 === $status) {
      $out['status'] = 'rate_limited';
      return $out;
    }
    if (400 === $status || 404 === $status) {
      // 400 is INVALID_UPC: the code is not one UPCitemdb can know.
      $out['status'] = 'not_found';
      return $out;
    }
    $data = json_decode((string) wp_remote_retrieve_body($response), true);
    if (200 !== $status || !is_array($data)) {
      return $out;
    }
    $items = isset($data['items']) && is_array($data['items']) ? $data['items'] : [];
    $item = isset($items[0]) && is_array($items[0]) ? $items[0] : null;
    $title = $item ? self::lookup_string($item['title'] ?? '') : '';
    if ('' === $title) {
      $out['status'] = 'not_found';
      return $out;
    }
    $images = isset($item['images']) && is_array($item['images']) ? array_values($item['images']) : [];
    $out['status'] = 'found';
    $out['product'] = [
      'title' => $title,
      'brand' => self::lookup_string($item['brand'] ?? ''),
      'description' => self::lookup_string($item['description'] ?? ''),
      'category' => self::lookup_string($item['category'] ?? ''),
      'image_url' => isset($images[0]) ? self::lookup_string($images[0]) : '',
    ];
    $low = $item['lowest_recorded_price'] ?? null;
    $high = $item['highest_recorded_price'] ?? null;
    if (is_numeric($low) && is_numeric($high) && (float) $low > 0 && (float) $high >= (float) $low) {
      $out['price_hint'] = [
        'low' => number_format((float) $low, 2, '.', ''),
        'high' => number_format((float) $high, 2, '.', ''),
        'source' => 'upcitemdb',
      ];
    }
    return $out;
  }

  /** A provider field as a trimmed plain string ('' for arrays and null). */
  private static function lookup_string($value): string {
    return is_scalar($value) ? trim(wp_strip_all_tags((string) $value)) : '';
  }


  /** update_product_stock: see Xophz_Compass_Bazaar_Admin_Product_Stock. */
  public function updateProductStock(){
    (new Xophz_Compass_Bazaar_Admin_Product_Stock())->update();
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

    if ( ! function_exists( 'wc_get_products' ) ) {
        return (object) [ 'products' => [], 'total' => 0, 'max_num_pages' => 0 ];
    }

    return wc_get_products( array_merge($default, $args_array) );
  } 

  public static function getProductsDataByIds($ids){
    $products = [];
    foreach($ids as $id){
      $p = wc_get_product($id);
      if (!$p) continue;
      $data = $p->get_data();
      $data['thumb'] = wp_get_attachment_image_url( $p->get_image_id() );
      $data['image'] = $p->get_image();
      $data['title'] = $p->get_name();
      $data['stock'] = $p->get_stock_quantity();
      $products[] = $data;
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
