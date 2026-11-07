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

  /** get_products: see Xophz_Compass_Bazaar_Admin_Product_Query. */
  public function getProducts(){
    (new Xophz_Compass_Bazaar_Admin_Product_Query())->list_products();
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
      // isset, not !empty: an emptied SKU field has to clear the SKU.
      if (isset($args->sku)) {
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

      if (!empty($args->remove_image)) {
        $product->set_image_id(0);
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

      if ($product_id) {
        $image_failed = self::attach_lookup_image($product_id, $args);
        // Like category_ids, a form post sends the list as a JSON string.
        $tag_list = isset($args->tags) && is_string($args->tags) ? json_decode($args->tags, true) : ($args->tags ?? null);
        if (is_array($tag_list)) {
          $tags = array_values(array_unique(array_filter(array_map('sanitize_text_field', array_map('strval', array_filter($tag_list, 'is_scalar'))))));
          wp_set_object_terms($product_id, $tags, 'product_tag');
        }
        if (isset($args->meta)) {
          Xophz_Compass_Bazaar_Admin_Product_Seo::save($product_id, $args->meta);
        }
      }

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
        if (!empty($image_failed)) {
          $response['image_error'] = 'The product was saved, but its picture could not be fetched.';
        }
      } else {
        $response = ['success' => false, 'message' => 'Failed to save product'];
      }
    } catch (\Throwable $e) {
      $response = ['success' => false, 'message' => $e->getMessage()];
    }
    Xophz_Compass::output_json($response);
  }

  /**
   * Attach the picture a barcode lookup found, when the editor asks for it and
   * no other image was given. The URL must be one this user's last lookup of
   * the code offered. Returns true when it was asked for and did not attach.
   */
  private static function attach_lookup_image(int $product_id, $args): bool {
    if (empty($args->image_url) || !empty($args->image_id) || !empty($args->image_data)) {
      return false;
    }
    $url  = Xophz_Compass_Bazaar_Barcode_Image::accept_url($args->image_url);
    $code = Xophz_Compass_Bazaar_Barcode::normalize(isset($args->barcode) && is_scalar($args->barcode) ? sanitize_text_field((string) $args->barcode) : '');
    $code = '' !== $code['normalized'] ? $code['normalized'] : $code['raw'];
    if ('' === $url || '' === $code
      || !in_array($url, Xophz_Compass_Bazaar_Barcode_Image::candidates((int) get_current_user_id(), $code), true)) {
      return true;
    }
    $product = wc_get_product($product_id);
    $attachment_id = $product ? Xophz_Compass_Bazaar_Barcode_Image::sideload($product_id, $url, $product->get_name()) : 0;
    if ($attachment_id < 1) {
      return true;
    }
    $product->set_image_id($attachment_id);
    $product->save();
    return false;
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

      if ('found' === $found['status'] && !empty($found['product']['title'])) {
        // Same as Quick Add: a save may attach only a picture this lookup offered.
        $code   = Xophz_Compass_Bazaar_Barcode::normalize($barcode);
        $images = Xophz_Compass_Bazaar_Barcode_Image::clean_list($found['product']['image_urls'] ?? [$found['product']['image_url']]);
        Xophz_Compass_Bazaar_Barcode_Image::remember_candidates(
          (int) get_current_user_id(),
          '' !== $code['normalized'] ? $code['normalized'] : $code['raw'],
          $images
        );
      }

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
          'image_url' => $images[0] ?? '',
          'image_urls' => $images,
          'barcode' => $barcode
        ]
      ]);
    } catch (\Throwable $e) {
      Xophz_Compass::output_json(['success' => false, 'message' => $e->getMessage()]);
    }
  }

  /** Transient prefix of the provider cache (key: md5 of the normalized code). */
  const LOOKUP_CACHE_PREFIX = 'bazaar_bc_lookup_';

  /**
   * Look a barcode up: the shared Barcode_Memory, then this site's cached
   * answer, then the providers. Returns data and never sends a response, so
   * the old editor lookup and Quick Add share it.
   *
   * Providers are asked in turn until one finds the code: Open Food Facts;
   * Open Beauty, Pet Food and Products Facts together; the UPCitemdb trial
   * API. A memory hit makes no HTTP calls and has source 'memory'.
   *
   * One merged outcome per normalized code is cached in a transient: found
   * for 30 days, not found for 3 days, anything else (rate limited, timeout,
   * server error) for 15 minutes. A 429 is 'rate_limited', never 'not_found'.
   * A code a provider found also goes in the memory, unless it may not be
   * shared (see Barcode::is_shared_gtin).
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

    $memory = Xophz_Compass_Bazaar_Barcode_Memory::find($query);
    if (null !== $memory) {
      return array_merge($empty, [
        'status' => 'found',
        'source' => 'memory',
        'product' => [
          'title' => $memory['title'],
          'brand' => $memory['brand'],
          'description' => '',
          'category' => '',
          'image_url' => $memory['image_url'],
          'image_urls' => '' !== $memory['image_url'] ? [$memory['image_url']] : [],
        ],
      ]);
    }

    $cache_key = self::LOOKUP_CACHE_PREFIX . md5($query);
    $cached = get_transient($cache_key);
    if (is_array($cached) && isset($cached['status'])) {
      $cached['cached'] = true;
      return array_merge($empty, $cached);
    }

    $open_facts_code = '' !== $code['normalized'] ? Xophz_Compass_Bazaar_Barcode::off_query_code($code) : $query;
    $result = array_merge($empty, self::ask_providers($open_facts_code, $query));
    $ttl = [
      'found' => 30 * DAY_IN_SECONDS,
      'not_found' => 3 * DAY_IN_SECONDS,
    ][$result['status']] ?? 15 * MINUTE_IN_SECONDS;
    $store = $result;
    unset($store['cached']);
    set_transient($cache_key, $store, $ttl);
    if ('found' === $result['status'] && is_array($result['product'])) {
      Xophz_Compass_Bazaar_Barcode_Memory::remember_provider($query, $result['product']);
    }
    return $result;
  }

  /** Ask each provider in turn until one finds the code. @return array One merged outcome. */
  private static function ask_providers(string $open_facts_code, string $query): array {
    $sources = 'Xophz_Compass_Bazaar_Barcode_Sources';
    $food = Xophz_Compass_Bazaar_Barcode_Fetcher::get($sources::open_facts_url($sources::OPEN_FOOD_FACTS, $open_facts_code));
    $outcomes = [$sources::parse_open_facts($food['status'], $food['body'], $sources::OPEN_FOOD_FACTS)];
    if ('found' === $outcomes[0]['status']) {
      return $sources::merge($outcomes);
    }

    $urls = [];
    foreach ($sources::OPEN_FACTS_OTHERS as $source) {
      $urls[$source] = $sources::open_facts_url($source, $open_facts_code);
    }
    foreach (Xophz_Compass_Bazaar_Barcode_Fetcher::get_many($urls) as $source => $answer) {
      $outcomes[] = $sources::parse_open_facts($answer['status'], $answer['body'], $source);
    }
    $merged = $sources::merge($outcomes);
    if ('found' === $merged['status']) {
      return $merged;
    }

    $outcomes[] = self::lookup_upcitemdb($query);
    return $sources::merge($outcomes);
  }

  /** UPCitemdb trial API. @return array status, source, product, price_hint */
  private static function lookup_upcitemdb(string $code): array {
    $response = wp_remote_get('https://api.upcitemdb.com/prod/trial/lookup?upc=' . rawurlencode($code), [
      'timeout' => Xophz_Compass_Bazaar_Barcode_Sources::TIMEOUT,
      'headers' => ['Accept' => 'application/json'],
    ]);
    if (is_wp_error($response)) {
      return Xophz_Compass_Bazaar_Barcode_Sources::parse_upcitemdb(0, '');
    }
    return Xophz_Compass_Bazaar_Barcode_Sources::parse_upcitemdb(
      (int) wp_remote_retrieve_response_code($response),
      (string) wp_remote_retrieve_body($response)
    );
  }

  /** update_product_stock: see Xophz_Compass_Bazaar_Admin_Product_Stock. */
  public function updateProductStock(){
    (new Xophz_Compass_Bazaar_Admin_Product_Stock())->update();
  }

  /** get_products_stats: see Xophz_Compass_Bazaar_Admin_Product_Query. */
  public function getProductsStats(){
    (new Xophz_Compass_Bazaar_Admin_Product_Query())->stats();
  }

  public static function getProductsDataByIds($ids){
    // The editor reads these back: thumb is a URL or '', plus tag names and the SEO fields.
    return array_map( function ( $row ) {
      $row['thumb'] = is_string( $row['thumb'] ?? false ) ? $row['thumb'] : '';
      $row['tags']  = wc_get_product_terms( (int) $row['id'], 'product_tag', [ 'fields' => 'names' ] );
      $row['meta']  = Xophz_Compass_Bazaar_Admin_Product_Seo::read( (int) $row['id'] );
      return $row;
    }, Xophz_Compass_Bazaar_Admin_Product_Query::rows( $ids ) );
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


}
