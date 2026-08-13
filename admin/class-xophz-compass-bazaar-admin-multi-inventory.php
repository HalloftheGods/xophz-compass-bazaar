<?php

/**
 * Multi-Inventory & Warehouse Stock Management for COMPASS PRO
 *
 * @package    Xophz_Compass_Bazaar
 * @subpackage Xophz_Compass_Bazaar/admin
 */

class Xophz_Compass_Bazaar_Admin_Multi_Inventory {
  private $plugin_name;
  private $version;

  public $action_hooks = [
    'init' => 'registerLocationCpt',
    'wp_ajax_bazaar_get_inventory_locations' => 'getInventoryLocations',
    'wp_ajax_bazaar_save_inventory_location' => 'saveInventoryLocation',
    'wp_ajax_bazaar_delete_inventory_location' => 'deleteInventoryLocation',
    'wp_ajax_bazaar_get_product_inventories' => 'getProductInventories',
    'wp_ajax_bazaar_save_product_inventories' => 'saveProductInventories',
    'wp_ajax_bazaar_bulk_update_inventory_stock' => 'bulkUpdateInventoryStock',
    'wp_ajax_bazaar_export_inventory_csv' => 'exportInventoryCsv',
    'wp_ajax_bazaar_import_inventory_csv' => 'importInventoryCsv',
    'woocommerce_reduce_order_stock' => 'reduceLocationOrderStock',
  ];

  public function __construct( $plugin_name, $version ) {
    $this->plugin_name = $plugin_name;
    $this->version     = $version;
  }

  /**
   * Verify if current user or specified user ID is a PRO subscriber.
   */
  public static function is_pro_user( $user_id = 0 ) {
    if ( class_exists( 'Xophz_Compass_Xp_Players' ) && method_exists( 'Xophz_Compass_Xp_Players', 'is_pro_user' ) ) {
      return Xophz_Compass_Xp_Players::is_pro_user( $user_id );
    }
    if ( ! $user_id ) {
      $user_id = get_current_user_id();
    }
    if ( ! $user_id ) {
      return false;
    }
    $is_pro = get_user_meta( $user_id, '_xp_is_pro', true );
    if ( in_array( $is_pro, [ 'yes', '1', 1, true ], true ) ) {
      return true;
    }
    $user = get_userdata( $user_id );
    if ( $user && array_intersect( (array) $user->roles, [ 'administrator', 'editor', 'shop_manager', 'pro', 'achiever' ] ) ) {
      return true;
    }
    return (bool) apply_filters( 'xophz_compass_is_pro_user', false, $user_id );
  }

  /**
   * Register Custom Post Type for Warehouse/Store Inventory Locations.
   */
  public function registerLocationCpt() {
    $labels = [
      'name'               => _x( 'Inventory Locations', 'post type general name', 'xophz-compass-bazaar' ),
      'singular_name'      => _x( 'Inventory Location', 'post type singular name', 'xophz-compass-bazaar' ),
      'menu_name'          => _x( 'Inventory Locations', 'admin menu', 'xophz-compass-bazaar' ),
      'add_new'            => _x( 'Add New Location', 'location', 'xophz-compass-bazaar' ),
      'add_new_item'       => __( 'Add New Inventory Location', 'xophz-compass-bazaar' ),
      'edit_item'          => __( 'Edit Inventory Location', 'xophz-compass-bazaar' ),
      'new_item'           => __( 'New Location', 'xophz-compass-bazaar' ),
      'view_item'          => __( 'View Location', 'xophz-compass-bazaar' ),
      'search_items'       => __( 'Search Locations', 'xophz-compass-bazaar' ),
      'not_found'          => __( 'No locations found', 'xophz-compass-bazaar' ),
      'not_found_in_trash' => __( 'No locations found in trash', 'xophz-compass-bazaar' ),
    ];

    $args = [
      'labels'             => $labels,
      'public'             => false,
      'publicly_queryable' => false,
      'show_ui'            => false,
      'show_in_menu'       => false,
      'query_var'          => false,
      'rewrite'            => false,
      'capability_type'    => 'post',
      'has_archive'        => false,
      'hierarchical'       => true,
      'supports'           => [ 'title', 'excerpt' ],
    ];

    register_post_type( 'compass_warehouse', $args );
  }

  /**
   * AJAX: Get list of inventory locations.
   */
  public function getInventoryLocations() {
    $is_pro = self::is_pro_user();
    
    $posts = get_posts( [
      'post_type'      => 'compass_warehouse',
      'posts_per_page' => -1,
      'post_status'    => 'publish',
      'orderby'        => 'menu_order title',
      'order'          => 'ASC',
    ] );

    $locations = [];
    foreach ( $posts as $post ) {
      $id = $post->ID;
      $locations[] = [
        'id'                    => $id,
        'title'                 => $post->post_title,
        'slug'                  => $post->post_name,
        'description'           => $post->post_excerpt,
        'address'               => get_post_meta( $id, '_location_address', true ) ?: '',
        'phone'                 => get_post_meta( $id, '_location_phone', true ) ?: '',
        'email'                 => get_post_meta( $id, '_location_email', true ) ?: '',
        'manager'               => get_post_meta( $id, '_location_manager', true ) ?: '',
        'lat'                   => floatval( get_post_meta( $id, '_location_lat', true ) ),
        'lng'                   => floatval( get_post_meta( $id, '_location_lng', true ) ),
        'parent_id'             => intval( $post->post_parent ),
        'restricted_shipping'   => (array) get_post_meta( $id, '_location_restricted_shipping', true ),
        'restricted_payments'  => (array) get_post_meta( $id, '_location_restricted_payments', true ),
        'is_default'            => (bool) get_post_meta( $id, '_location_is_default', true ),
      ];
    }

    wp_send_json_success( [
      'is_pro'    => $is_pro,
      'locations' => $locations,
    ] );
  }

  /**
   * AJAX: Save or create an inventory location (PRO required).
   */
  public function saveInventoryLocation() {
    if ( ! self::is_pro_user() ) {
      wp_send_json_error( [ 'message' => 'PRO subscription required for multi-inventory features.' ], 403 );
    }

    $id          = isset( $_POST['id'] ) ? intval( $_POST['id'] ) : 0;
    $title       = isset( $_POST['title'] ) ? sanitize_text_field( $_POST['title'] ) : '';
    $description = isset( $_POST['description'] ) ? sanitize_textarea_field( $_POST['description'] ) : '';
    $address     = isset( $_POST['address'] ) ? sanitize_text_field( $_POST['address'] ) : '';
    $phone       = isset( $_POST['phone'] ) ? sanitize_text_field( $_POST['phone'] ) : '';
    $email       = isset( $_POST['email'] ) ? sanitize_email( $_POST['email'] ) : '';
    $manager     = isset( $_POST['manager'] ) ? sanitize_text_field( $_POST['manager'] ) : '';
    $lat         = isset( $_POST['lat'] ) ? floatval( $_POST['lat'] ) : 0;
    $lng         = isset( $_POST['lng'] ) ? floatval( $_POST['lng'] ) : 0;
    $parent_id   = isset( $_POST['parent_id'] ) ? intval( $_POST['parent_id'] ) : 0;
    $is_default  = ! empty( $_POST['is_default'] );

    if ( empty( $title ) ) {
      wp_send_json_error( [ 'message' => 'Location title is required.' ], 400 );
    }

    $post_data = [
      'post_title'   => $title,
      'post_excerpt' => $description,
      'post_type'    => 'compass_warehouse',
      'post_status'  => 'publish',
      'post_parent'  => $parent_id,
    ];

    if ( $id > 0 ) {
      $post_data['ID'] = $id;
      $location_id = wp_update_post( $post_data );
    } else {
      $location_id = wp_insert_post( $post_data );
    }

    if ( is_wp_error( $location_id ) ) {
      wp_send_json_error( [ 'message' => $location_id->get_error_message() ], 500 );
    }

    update_post_meta( $location_id, '_location_address', $address );
    update_post_meta( $location_id, '_location_phone', $phone );
    update_post_meta( $location_id, '_location_email', $email );
    update_post_meta( $location_id, '_location_manager', $manager );
    update_post_meta( $location_id, '_location_lat', $lat );
    update_post_meta( $location_id, '_location_lng', $lng );
    update_post_meta( $location_id, '_location_is_default', $is_default );

    if ( isset( $_POST['restricted_shipping'] ) && is_array( $_POST['restricted_shipping'] ) ) {
      $sanitized_shipping = array_map( 'sanitize_text_field', $_POST['restricted_shipping'] );
      update_post_meta( $location_id, '_location_restricted_shipping', $sanitized_shipping );
    }

    if ( isset( $_POST['restricted_payments'] ) && is_array( $_POST['restricted_payments'] ) ) {
      $sanitized_payments = array_map( 'sanitize_text_field', $_POST['restricted_payments'] );
      update_post_meta( $location_id, '_location_restricted_payments', $sanitized_payments );
    }

    wp_send_json_success( [
      'message'     => 'Inventory location saved successfully.',
      'location_id' => $location_id,
    ] );
  }

  /**
   * AJAX: Delete an inventory location (PRO required).
   */
  public function deleteInventoryLocation() {
    if ( ! self::is_pro_user() ) {
      wp_send_json_error( [ 'message' => 'PRO subscription required.' ], 403 );
    }

    $location_id = isset( $_POST['location_id'] ) ? intval( $_POST['location_id'] ) : 0;
    if ( ! $location_id ) {
      wp_send_json_error( [ 'message' => 'Invalid location ID.' ], 400 );
    }

    $deleted = wp_delete_post( $location_id, true );
    if ( ! $deleted ) {
      wp_send_json_error( [ 'message' => 'Failed to delete location.' ], 500 );
    }

    wp_send_json_success( [ 'message' => 'Location deleted successfully.' ] );
  }

  /**
   * AJAX: Get multi-location product inventory levels.
   */
  public function getProductInventories() {
    $product_id = isset( $_GET['product_id'] ) ? intval( $_GET['product_id'] ) : 0;
    if ( ! $product_id ) {
      wp_send_json_error( [ 'message' => 'Product ID is required.' ], 400 );
    }

    $inventories = get_post_meta( $product_id, '_compass_multi_inventory', true );
    if ( ! is_array( $inventories ) ) {
      $inventories = [];
    }

    wp_send_json_success( [
      'product_id'  => $product_id,
      'inventories' => $inventories,
    ] );
  }

  /**
   * AJAX: Save multi-location stock for a product (PRO required).
   */
  public function saveProductInventories() {
    if ( ! self::is_pro_user() ) {
      wp_send_json_error( [ 'message' => 'PRO subscription required.' ], 403 );
    }

    $product_id = isset( $_POST['product_id'] ) ? intval( $_POST['product_id'] ) : 0;
    if ( ! $product_id ) {
      wp_send_json_error( [ 'message' => 'Product ID is required.' ], 400 );
    }

    $raw_inventories = isset( $_POST['inventories'] ) ? $_POST['inventories'] : [];
    if ( is_string( $raw_inventories ) ) {
      $raw_inventories = json_decode( stripslashes( $raw_inventories ), true );
    }

    if ( ! is_array( $raw_inventories ) ) {
      wp_send_json_error( [ 'message' => 'Invalid inventories payload format.' ], 400 );
    }

    $sanitized = [];
    $total_stock = 0;

    foreach ( $raw_inventories as $loc_id => $data ) {
      $location_id = intval( $loc_id );
      $stock_qty   = isset( $data['stock_quantity'] ) ? intval( $data['stock_quantity'] ) : 0;
      $low_thresh  = isset( $data['low_stock_threshold'] ) ? intval( $data['low_stock_threshold'] ) : 5;
      $sku         = isset( $data['sku'] ) ? sanitize_text_field( $data['sku'] ) : '';
      $price       = isset( $data['price'] ) && $data['price'] !== '' ? floatval( $data['price'] ) : null;
      $is_enabled  = ! empty( $data['is_enabled'] );

      $sanitized[ $location_id ] = [
        'location_id'         => $location_id,
        'stock_quantity'      => $stock_qty,
        'low_stock_threshold' => $low_thresh,
        'sku'                 => $sku,
        'price'               => $price,
        'is_enabled'          => $is_enabled,
      ];

      if ( $is_enabled ) {
        $total_stock += $stock_qty;
      }
    }

    update_post_meta( $product_id, '_compass_multi_inventory', $sanitized );

    // Sync cumulative total stock with standard WooCommerce stock
    $product = wc_get_product( $product_id );
    if ( $product && $product->get_manage_stock() ) {
      $product->set_stock_quantity( $total_stock );
      $product->save();
    }

    wp_send_json_success( [
      'message'     => 'Multi-inventory updated.',
      'total_stock' => $total_stock,
    ] );
  }

  /**
   * AJAX: Bulk update inventory stock across multiple products (PRO required).
   */
  public function bulkUpdateInventoryStock() {
    if ( ! self::is_pro_user() ) {
      wp_send_json_error( [ 'message' => 'PRO subscription required.' ], 403 );
    }

    $raw_updates = isset( $_POST['updates'] ) ? $_POST['updates'] : [];
    if ( is_string( $raw_updates ) ) {
      $raw_updates = json_decode( stripslashes( $raw_updates ), true );
    }

    if ( ! is_array( $raw_updates ) ) {
      wp_send_json_error( [ 'message' => 'Invalid updates payload.' ], 400 );
    }

    $updated_count = 0;
    foreach ( $raw_updates as $item ) {
      $product_id  = isset( $item['product_id'] ) ? intval( $item['product_id'] ) : 0;
      $location_id = isset( $item['location_id'] ) ? intval( $item['location_id'] ) : 0;
      $new_stock   = isset( $item['stock_quantity'] ) ? intval( $item['stock_quantity'] ) : 0;

      if ( ! $product_id || ! $location_id ) {
        continue;
      }

      $inventories = get_post_meta( $product_id, '_compass_multi_inventory', true );
      if ( ! is_array( $inventories ) ) {
        $inventories = [];
      }

      if ( ! isset( $inventories[ $location_id ] ) ) {
        $inventories[ $location_id ] = [
          'location_id'         => $location_id,
          'stock_quantity'      => 0,
          'low_stock_threshold' => 5,
          'sku'                 => '',
          'price'               => null,
          'is_enabled'          => true,
        ];
      }

      $inventories[ $location_id ]['stock_quantity'] = $new_stock;
      update_post_meta( $product_id, '_compass_multi_inventory', $inventories );

      // Sync cumulative stock to WC Product
      $total_stock = 0;
      foreach ( $inventories as $inv ) {
        if ( ! empty( $inv['is_enabled'] ) ) {
          $total_stock += intval( $inv['stock_quantity'] );
        }
      }
      $product = wc_get_product( $product_id );
      if ( $product && $product->get_manage_stock() ) {
        $product->set_stock_quantity( $total_stock );
        $product->save();
      }

      $updated_count++;
    }

    wp_send_json_success( [
      'message'       => 'Bulk stock updated.',
      'updated_count' => $updated_count,
    ] );
  }

  /**
   * AJAX: Export multi-inventory levels as CSV.
   */
  public function exportInventoryCsv() {
    $is_pro = self::is_pro_user();

    $products = wc_get_products( [
      'limit'  => -1,
      'status' => 'publish',
    ] );

    header( 'Content-Type: text/csv; charset=utf-8' );
    header( 'Content-Disposition: attachment; filename=compass_multi_inventory_' . date( 'Y-m-d' ) . '.csv' );

    $output = fopen( 'php://output', 'w' );
    fputcsv( $output, [ 'Product ID', 'SKU', 'Product Name', 'Location ID', 'Location Stock', 'Low Stock Threshold', 'Location Price' ] );

    foreach ( $products as $product ) {
      $pid = $product->get_id();
      $inventories = get_post_meta( $pid, '_compass_multi_inventory', true );
      if ( is_array( $inventories ) && ! empty( $inventories ) ) {
        foreach ( $inventories as $loc_id => $inv ) {
          fputcsv( $output, [
            $pid,
            $inv['sku'] ?: $product->get_sku(),
            $product->get_name(),
            $loc_id,
            $inv['stock_quantity'],
            $inv['low_stock_threshold'],
            $inv['price'] !== null ? $inv['price'] : $product->get_price(),
          ] );
        }
      } else {
        fputcsv( $output, [
          $pid,
          $product->get_sku(),
          $product->get_name(),
          'default',
          $product->get_stock_quantity() ?: 0,
          5,
          $product->get_price(),
        ] );
      }
    }

    fclose( $output );
    exit;
  }

  /**
   * AJAX: Import CSV to update location stock (PRO required).
   */
  public function importInventoryCsv() {
    if ( ! self::is_pro_user() ) {
      wp_send_json_error( [ 'message' => 'PRO subscription required.' ], 403 );
    }

    if ( empty( $_FILES['file']['tmp_name'] ) ) {
      wp_send_json_error( [ 'message' => 'No CSV file uploaded.' ], 400 );
    }

    $handle = fopen( $_FILES['file']['tmp_name'], 'r' );
    if ( ! $handle ) {
      wp_send_json_error( [ 'message' => 'Failed to read uploaded CSV.' ], 500 );
    }

    $header = fgetcsv( $handle );
    $rows_processed = 0;

    while ( ( $data = fgetcsv( $handle ) ) !== false ) {
      if ( count( $data ) < 5 ) {
        continue;
      }
      $product_id  = intval( $data[0] );
      $location_id = intval( $data[3] );
      $stock_qty   = intval( $data[4] );
      $low_thresh  = isset( $data[5] ) ? intval( $data[5] ) : 5;
      $price       = isset( $data[6] ) && $data[6] !== '' ? floatval( $data[6] ) : null;

      if ( ! $product_id || ! $location_id ) {
        continue;
      }

      $inventories = get_post_meta( $product_id, '_compass_multi_inventory', true );
      if ( ! is_array( $inventories ) ) {
        $inventories = [];
      }

      $inventories[ $location_id ] = [
        'location_id'         => $location_id,
        'stock_quantity'      => $stock_qty,
        'low_stock_threshold' => $low_thresh,
        'sku'                 => isset( $data[1] ) ? sanitize_text_field( $data[1] ) : '',
        'price'               => $price,
        'is_enabled'          => true,
      ];

      update_post_meta( $product_id, '_compass_multi_inventory', $inventories );

      // Sync cumulative stock to WC Product
      $total_stock = 0;
      foreach ( $inventories as $inv ) {
        if ( ! empty( $inv['is_enabled'] ) ) {
          $total_stock += intval( $inv['stock_quantity'] );
        }
      }
      $product = wc_get_product( $product_id );
      if ( $product && $product->get_manage_stock() ) {
        $product->set_stock_quantity( $total_stock );
        $product->save();
      }

      $rows_processed++;
    }

    fclose( $handle );
    wp_send_json_success( [
      'message'        => 'CSV imported successfully.',
      'rows_processed' => $rows_processed,
    ] );
  }

  /**
   * Hook into WooCommerce order stock reduction to deduct from specific location inventory.
   */
  public function reduceLocationOrderStock( $order ) {
    if ( ! $order ) {
      return;
    }

    $location_id = $order->get_meta( '_pos_location_id' );
    if ( ! $location_id ) {
      return;
    }

    $location_id = intval( $location_id );
    foreach ( $order->get_items() as $item ) {
      $product_id = $item->get_product_id();
      $qty        = $item->get_quantity();

      if ( ! $product_id || ! $qty ) {
        continue;
      }

      $inventories = get_post_meta( $product_id, '_compass_multi_inventory', true );
      if ( is_array( $inventories ) && isset( $inventories[ $location_id ] ) ) {
        $current_loc_stock = intval( $inventories[ $location_id ]['stock_quantity'] );
        $inventories[ $location_id ]['stock_quantity'] = max( 0, $current_loc_stock - $qty );
        update_post_meta( $product_id, '_compass_multi_inventory', $inventories );
      }
    }
  }
}
