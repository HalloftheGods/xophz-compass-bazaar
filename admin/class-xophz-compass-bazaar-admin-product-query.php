<?php
/**
 * Reads for the product list and its stats (get_products, get_products_stats).
 *
 * Who may call: Xophz_Compass_Bazaar_Security_Policy maps both actions to staff
 * capabilities (POS sellers, catalog and stock managers), and the guard runs
 * before these handlers. This class only decides what the request may ask for.
 *
 * Guarantees:
 * - Only page, limit, category, type, stock_status, search and filters reach
 *   wc_get_products; anything else in the request body is ignored.
 * - Title and SKU searches use $wpdb->prepare, so an apostrophe is safe.
 * - A search that matches nothing answers total 0 and no rows (never the
 *   whole catalog).
 *
 * Not guaranteed: SKU matches are prefix matches and title matches are
 * "contains"; letter case follows the database collation.
 *
 * @package    Xophz_Compass_Bazaar
 * @subpackage Xophz_Compass_Bazaar/admin
 */
class Xophz_Compass_Bazaar_Admin_Product_Query {

  const MAX_LIMIT = 200;

  const STOCK_STATUSES = [ 'instock', 'outofstock', 'onbackorder' ];

  /** AJAX: get_products. Answers {total_count, data}. */
  public function list_products() {
    $result = self::query( self::clean_args( Xophz_Compass::get_input_json() ) );

    Xophz_Compass::output_json( [
      'total_count' => isset( $result->total ) ? (int) $result->total : 0,
      'data'        => self::rows( isset( $result->products ) ? $result->products : [] ),
    ] );
  }

  /** AJAX: get_products_stats. Stats cover every match, not one page. */
  public function stats() {
    $args          = self::clean_args( Xophz_Compass::get_input_json() );
    $args['limit'] = -1;
    unset( $args['page'] );

    $result = self::query( $args );
    $ids    = isset( $result->products ) ? $result->products : [];

    $unique_in_stock = 0;
    $total_stock     = 0;
    $in_stock_value  = 0;
    $total_sales     = 0;

    foreach ( $ids as $product_id ) {
      $product = wc_get_product( $product_id );
      if ( ! $product ) {
        continue;
      }
      if ( $product->managing_stock() && $product->is_in_stock() ) {
        $unique_in_stock++;
        $total_stock    += $product->get_stock_quantity();
        $in_stock_value += $product->get_stock_quantity() * $product->get_price();
      }
      $total_sales += $product->get_total_sales();
    }

    Xophz_Compass::output_json( [
      'data' => [
        'ids'             => $ids,
        'total_stock'     => $total_stock,
        'unique_stock'    => count( $ids ),
        'unique_in_stock' => $unique_in_stock,
        'in_stock_value'  => $in_stock_value,
        'avg_discount'    => 0,
        'est_discount'    => 0,
        'total_sales'     => $total_sales,
        'est_sales'       => 0,
      ],
    ] );
  }

  /**
   * Keep only the arguments the list may ask for, each coerced to a safe shape.
   *
   * @param  mixed $input Decoded request body (object or array).
   * @return array        page, limit, filters, and when given category, stock_status, type/downloadable/virtual.
   */
  public static function clean_args( $input ) {
    $in    = is_object( $input ) || is_array( $input ) ? (array) $input : [];
    $clean = [ 'filters' => self::clean_filters( isset( $in['filters'] ) ? $in['filters'] : [] ) ];

    // A plain `search` term means a title search.
    $search = self::strings( isset( $in['search'] ) ? $in['search'] : [] );
    if ( $search && empty( $clean['filters']['title'] ) ) {
      $clean['filters']['title'] = $search[0];
    }

    $clean['page']  = isset( $in['page'] ) ? max( 1, (int) $in['page'] ) : 1;
    $clean['limit'] = isset( $in['limit'] ) ? min( self::MAX_LIMIT, max( 1, (int) $in['limit'] ) ) : 10;

    $category = self::strings( isset( $in['category'] ) ? $in['category'] : [] );
    if ( $category ) {
      $clean['category'] = array_map( 'sanitize_title', $category );
    }

    if ( isset( $in['stock_status'] ) && in_array( $in['stock_status'], self::STOCK_STATUSES, true ) ) {
      $clean['stock_status'] = $in['stock_status'];
    }

    if ( isset( $in['type'] ) && is_string( $in['type'] ) ) {
      $clean += self::type_args( $in['type'] );
    }

    return $clean;
  }

  /** Downloadable and virtual are flags in WooCommerce, not product types. */
  private static function type_args( $type ) {
    if ( 'downloadable' === $type || 'virtual' === $type ) {
      return [ $type => true ];
    }
    $known = function_exists( 'wc_get_product_types' ) ? array_keys( wc_get_product_types() ) : [];
    return in_array( $type, $known, true ) ? [ 'type' => $type ] : [];
  }

  /** @return array{title?:string,sku?:string[]} Non-empty search terms only. */
  private static function clean_filters( $filters ) {
    $filters = is_object( $filters ) || is_array( $filters ) ? (array) $filters : [];
    $clean   = [];

    $titles = self::strings( isset( $filters['title'] ) ? $filters['title'] : [] );
    if ( $titles ) {
      $clean['title'] = $titles[0];
    }
    $skus = self::strings( isset( $filters['sku'] ) ? $filters['sku'] : [] );
    if ( $skus ) {
      $clean['sku'] = $skus;
    }
    return $clean;
  }

  /** Accept a string or a list of strings; drop blanks and anything else. */
  private static function strings( $value ) {
    $out = [];
    foreach ( (array) $value as $item ) {
      if ( is_string( $item ) && '' !== trim( $item ) ) {
        $out[] = trim( $item );
      }
    }
    return $out;
  }

  /**
   * Run the query. Returns an object shaped like wc_get_products(paginate).
   *
   * @param array $args Output of clean_args().
   */
  public static function query( array $args ) {
    $empty = (object) [ 'products' => [], 'total' => 0, 'max_num_pages' => 0 ];

    if ( ! function_exists( 'wc_get_products' ) ) {
      return $empty;
    }

    $filters = isset( $args['filters'] ) ? $args['filters'] : [];
    unset( $args['filters'] );
    $args['return']   = 'ids';
    $args['paginate'] = true;

    if ( ! empty( $filters ) ) {
      $ids = self::ids_by_filters( $filters );
      if ( empty( $ids ) ) {
        return $empty; // include='' would mean "no restriction".
      }
      $args['include'] = $ids;
    }

    return wc_get_products( $args );
  }

  /** Product ids matching the title OR any sku term; a variation resolves to its parent. */
  public static function ids_by_filters( $filters ) {
    $filters  = (array) $filters;
    $post_ids = [];

    if ( ! empty( $filters['title'] ) ) {
      $post_ids = array_merge( $post_ids, self::ids_by_title( $filters['title'] ) );
    }
    foreach ( self::strings( isset( $filters['sku'] ) ? $filters['sku'] : [] ) as $sku ) {
      $post_ids = array_merge( $post_ids, self::ids_by_sku( $sku ) );
    }

    return self::to_product_ids( $post_ids );
  }

  public static function ids_by_title( $title ) {
    global $wpdb;
    return $wpdb->get_col( $wpdb->prepare(
      "SELECT ID FROM {$wpdb->posts}
       WHERE post_title LIKE %s
         AND post_type IN ('product','product_variation')
         AND post_status NOT IN ('trash','auto-draft')",
      '%' . $wpdb->esc_like( (string) $title ) . '%'
    ) );
  }

  public static function ids_by_sku( $sku ) {
    global $wpdb;
    return $wpdb->get_col( $wpdb->prepare(
      "SELECT pm.post_id FROM {$wpdb->postmeta} pm
       INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
       WHERE pm.meta_key = '_sku'
         AND pm.meta_value LIKE %s
         AND p.post_type IN ('product','product_variation')
         AND p.post_status NOT IN ('trash','auto-draft')",
      $wpdb->esc_like( (string) $sku ) . '%'
    ) );
  }

  /** Map post ids to listable product ids: a variation becomes its parent. */
  public static function to_product_ids( $ids ) {
    $out = [];
    foreach ( $ids as $id ) {
      $product = wc_get_product( $id );
      if ( ! $product ) {
        continue;
      }
      $parent = $product->get_parent_id();
      $out[]  = $parent ? $parent : (int) $id;
    }
    return array_values( array_unique( $out ) );
  }

  /** Row payload for the list screen. */
  public static function rows( $ids ) {
    $rows = [];
    foreach ( $ids as $id ) {
      $p = wc_get_product( $id );
      if ( ! $p ) {
        continue;
      }
      $data          = $p->get_data();
      $data['thumb'] = wp_get_attachment_image_url( $p->get_image_id() );
      $data['image'] = $p->get_image();
      $data['title'] = $p->get_name();
      $data['stock'] = $p->get_stock_quantity();
      $rows[]        = $data;
    }
    return $rows;
  }
}
