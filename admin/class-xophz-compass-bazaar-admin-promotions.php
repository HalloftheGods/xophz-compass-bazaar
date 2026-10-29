<?php

/**
 * Bulk sale pricing ("Sales & Promotions").
 *
 * Sets or clears WooCommerce sale prices across a selection of products,
 * using the native _sale_price and date_on_sale_from / date_on_sale_to
 * fields. Variable products are priced per variation. The SPA previews a
 * page at a time, then applies in batches of BATCH_SIZE parent products.
 *
 * @package    Xophz_Compass_Bazaar
 * @subpackage Xophz_Compass_Bazaar/admin
 */
class Xophz_Compass_Bazaar_Admin_Promotions {

  const BATCH_SIZE      = 25;
  const MAX_PER_PAGE    = 100;
  const MAX_MATCHED_IDS = 5000;

  private $plugin_name;
  private $version;

  public $action_hooks = [
    'wp_ajax_bazaar_search_sale_products' => 'searchSaleProducts',
    'wp_ajax_bazaar_preview_sale_prices' => 'previewSalePrices',
    'wp_ajax_bazaar_apply_sale_prices'   => 'applySalePrices',
    'wp_ajax_bazaar_clear_sale_prices'   => 'clearSalePrices',
  ];

  public function __construct( $plugin_name, $version ) {
    $this->plugin_name = $plugin_name;
    $this->version     = $version;
  }

  private function send_json_response( $payload ) {
    while ( ob_get_level() > 0 ) {
      @ob_end_clean();
    }
    Xophz_Compass::output_json( $payload );
  }

  /**
   * Product picker lookup for the multi-select: id, name, SKU.
   *
   * Input: search (title or SKU), at most 20 results.
   */
  public function searchSaleProducts() {
    Xophz_Compass_Security::enforce_spa_ajax( 'manage_woocommerce', 'edit_products' );

    try {
      $args   = Xophz_Compass::get_input_json();
      $search = sanitize_text_field( (string) ( $args->search ?? '' ) );
      if ( strlen( $search ) < 2 ) {
        $this->send_json_response( [ 'success' => true, 'products' => [] ] );
        return;
      }

      $ids = get_posts( [
        'post_type'      => 'product',
        'post_status'    => [ 'publish', 'private', 'draft', 'pending', 'future' ],
        'fields'         => 'ids',
        's'              => $search,
        'posts_per_page' => 20,
      ] );
      $sku_id = wc_get_product_id_by_sku( $search );
      if ( $sku_id && 'product' === get_post_type( $sku_id ) ) {
        array_unshift( $ids, $sku_id );
      }

      $products = [];
      foreach ( array_slice( array_unique( array_map( 'intval', $ids ) ), 0, 20 ) as $id ) {
        $product = wc_get_product( $id );
        if ( $product ) {
          $products[] = [ 'id' => $id, 'name' => $product->get_name(), 'sku' => $product->get_sku(), 'type' => $product->get_type() ];
        }
      }

      $this->send_json_response( [ 'success' => true, 'products' => $products ] );
    } catch ( \Throwable $e ) {
      $this->send_json_response( [ 'success' => false, 'message' => $e->getMessage(), 'products' => [] ] );
    }
  }

  /**
   * Resolve the selection and return one page of rows with their new prices.
   *
   * Input: search, category_id, product_ids, mode, value, page, per_page.
   * Also returns matched_ids (every parent id in the selection) so the SPA
   * can apply or clear in batches.
   */
  public function previewSalePrices() {
    Xophz_Compass_Security::enforce_spa_ajax( 'manage_woocommerce', 'edit_products' );

    try {
      $args     = Xophz_Compass::get_input_json();
      $page     = max( 1, intval( $args->page ?? 1 ) );
      $per_page = min( self::MAX_PER_PAGE, max( 1, intval( $args->per_page ?? 25 ) ) );
      $mode     = sanitize_key( $args->mode ?? '' );
      $value    = $args->value ?? '';

      $selection = $this->build_selection_query( $args );
      if ( null === $selection ) {
        $this->send_json_response( [ 'success' => false, 'message' => 'Choose products by search, category, or selection first.' ] );
        return;
      }

      $matched = new WP_Query( array_merge( $selection, [
        'posts_per_page' => self::MAX_MATCHED_IDS,
        'no_found_rows'  => false,
      ] ) );
      $matched_ids = array_map( 'intval', $matched->posts );
      $total       = count( $matched_ids );
      $page_ids    = array_slice( $matched_ids, ( $page - 1 ) * $per_page, $per_page );

      $rows = [];
      foreach ( $page_ids as $product_id ) {
        $product = wc_get_product( $product_id );
        if ( ! $product ) {
          continue;
        }
        foreach ( $this->priced_items( $product ) as $item ) {
          $rows[] = $this->preview_row( $item, $product, $mode, $value );
        }
      }

      $this->send_json_response( [
        'success'     => true,
        'total'       => $total,
        'truncated'   => (int) $matched->found_posts > $total,
        'page'        => $page,
        'per_page'    => $per_page,
        'matched_ids' => $matched_ids,
        'rows'        => $rows,
      ] );
    } catch ( \Throwable $e ) {
      $this->send_json_response( [ 'success' => false, 'message' => $e->getMessage() ] );
    }
  }

  /**
   * Apply a sale to one batch of parent products (at most BATCH_SIZE).
   *
   * Input: product_ids, mode ('fixed'|'percent'), value, date_from, date_to.
   */
  public function applySalePrices() {
    Xophz_Compass_Security::enforce_spa_ajax( 'manage_woocommerce', 'edit_products' );

    try {
      $args  = Xophz_Compass::get_input_json();
      $ids   = Xophz_Compass_Bazaar_Sale_Pricing::clean_ids( $args->product_ids ?? [], self::BATCH_SIZE );
      $mode  = sanitize_key( $args->mode ?? '' );
      $value = $args->value ?? '';
      $dates = Xophz_Compass_Bazaar_Sale_Pricing::normalize_dates(
        sanitize_text_field( (string) ( $args->date_from ?? '' ) ),
        sanitize_text_field( (string) ( $args->date_to ?? '' ) )
      );

      if ( empty( $ids ) ) {
        $this->send_json_response( [ 'success' => false, 'message' => 'No products in this batch.' ] );
        return;
      }
      if ( ! in_array( $mode, [ Xophz_Compass_Bazaar_Sale_Pricing::MODE_FIXED, Xophz_Compass_Bazaar_Sale_Pricing::MODE_PERCENT ], true ) || ! is_numeric( $value ) ) {
        $this->send_json_response( [ 'success' => false, 'message' => 'Choose a fixed sale price or a percent off.' ] );
        return;
      }
      if ( null === $dates ) {
        $this->send_json_response( [ 'success' => false, 'message' => 'Sale dates must be YYYY-MM-DD, and the end date cannot be before the start date.' ] );
        return;
      }

      $updated = 0;
      $skipped = [];
      foreach ( $ids as $product_id ) {
        $product = wc_get_product( $product_id );
        if ( ! $product ) {
          $skipped[] = [ 'id' => $product_id, 'name' => '', 'reason' => 'Product not found' ];
          continue;
        }
        foreach ( $this->priced_items( $product ) as $item ) {
          $sale = Xophz_Compass_Bazaar_Sale_Pricing::compute( $item->get_regular_price(), $mode, $value, wc_get_price_decimals() );
          if ( null === $sale ) {
            $skipped[] = [ 'id' => $item->get_id(), 'name' => $item->get_name(), 'reason' => $this->skip_reason( $item ) ];
            continue;
          }
          $item->set_sale_price( wc_format_decimal( $sale ) );
          $item->set_date_on_sale_from( $dates['from'] );
          $item->set_date_on_sale_to( $dates['to'] );
          $item->save();
          $updated++;
        }
        $this->sync_parent( $product );
      }

      $this->send_json_response( [ 'success' => true, 'updated' => $updated, 'skipped' => $skipped ] );
    } catch ( \Throwable $e ) {
      $this->send_json_response( [ 'success' => false, 'message' => $e->getMessage() ] );
    }
  }

  /**
   * Remove the sale price and schedule from one batch of parent products.
   *
   * Input: product_ids.
   */
  public function clearSalePrices() {
    Xophz_Compass_Security::enforce_spa_ajax( 'manage_woocommerce', 'edit_products' );

    try {
      $args = Xophz_Compass::get_input_json();
      $ids  = Xophz_Compass_Bazaar_Sale_Pricing::clean_ids( $args->product_ids ?? [], self::BATCH_SIZE );
      if ( empty( $ids ) ) {
        $this->send_json_response( [ 'success' => false, 'message' => 'No products in this batch.' ] );
        return;
      }

      $cleared = 0;
      foreach ( $ids as $product_id ) {
        $product = wc_get_product( $product_id );
        if ( ! $product ) {
          continue;
        }
        foreach ( $this->priced_items( $product ) as $item ) {
          if ( '' === $item->get_sale_price() && ! $item->get_date_on_sale_from() && ! $item->get_date_on_sale_to() ) {
            continue;
          }
          $item->set_sale_price( '' );
          $item->set_date_on_sale_from( '' );
          $item->set_date_on_sale_to( '' );
          $item->save();
          $cleared++;
        }
        $this->sync_parent( $product );
      }

      $this->send_json_response( [ 'success' => true, 'cleared' => $cleared ] );
    } catch ( \Throwable $e ) {
      $this->send_json_response( [ 'success' => false, 'message' => $e->getMessage() ] );
    }
  }

  /**
   * WP_Query args for the selection, or null when nothing narrows it.
   *
   * Explicit product_ids win; otherwise search and category combine.
   */
  private function build_selection_query( $args ): ?array {
    $query = [
      'post_type'   => 'product',
      'post_status' => [ 'publish', 'private', 'draft', 'pending', 'future' ],
      'fields'      => 'ids',
      'orderby'     => 'title',
      'order'       => 'ASC',
    ];

    $ids = Xophz_Compass_Bazaar_Sale_Pricing::clean_ids( $args->product_ids ?? [], self::MAX_MATCHED_IDS );
    if ( ! empty( $ids ) ) {
      $query['post__in'] = $ids;
      return $query;
    }

    $search      = sanitize_text_field( (string) ( $args->search ?? '' ) );
    $category_id = intval( $args->category_id ?? 0 );
    if ( '' === $search && $category_id <= 0 ) {
      return null;
    }

    if ( '' !== $search ) {
      $query['s'] = $search;
    }
    if ( $category_id > 0 ) {
      $query['tax_query'] = [ [
        'taxonomy'         => 'product_cat',
        'field'            => 'term_id',
        'terms'            => [ $category_id ],
        'include_children' => true,
      ] ];
    }
    return $query;
  }

  /**
   * The products that actually carry a price: each variation of a variable
   * product, nothing for a grouped product, otherwise the product itself.
   *
   * @return WC_Product[]
   */
  private function priced_items( WC_Product $product ): array {
    if ( $product->is_type( 'grouped' ) ) {
      return [];
    }
    if ( ! $product->is_type( 'variable' ) ) {
      return [ $product ];
    }
    $items = [];
    foreach ( $product->get_children() as $variation_id ) {
      $variation = wc_get_product( $variation_id );
      if ( $variation ) {
        $items[] = $variation;
      }
    }
    return $items;
  }

  private function preview_row( WC_Product $item, WC_Product $parent, string $mode, $value ): array {
    $sale = Xophz_Compass_Bazaar_Sale_Pricing::compute( $item->get_regular_price(), $mode, $value, wc_get_price_decimals() );
    $from = $item->get_date_on_sale_from();
    $to   = $item->get_date_on_sale_to();

    return [
      'id'             => $item->get_id(),
      'parent_id'      => $parent->get_id(),
      'name'           => $item->get_name(),
      'sku'            => $item->get_sku(),
      'type'           => $item->get_type(),
      'regular_price'  => (string) $item->get_regular_price(),
      'sale_price'     => (string) $item->get_sale_price(),
      'sale_from'      => $from ? $from->date( 'Y-m-d' ) : '',
      'sale_to'        => $to ? $to->date( 'Y-m-d' ) : '',
      'new_sale_price' => null === $sale ? null : wc_format_decimal( $sale, wc_get_price_decimals() ),
      'skip_reason'    => null === $sale && '' !== $mode && is_numeric( $value ) ? $this->skip_reason( $item ) : '',
    ];
  }

  private function skip_reason( WC_Product $item ): string {
    $regular = $item->get_regular_price();
    if ( '' === $regular || ! is_numeric( $regular ) || (float) $regular <= 0 ) {
      return 'No regular price';
    }
    return 'Sale price would not be below the regular price';
  }

  private function sync_parent( WC_Product $product ): void {
    if ( $product->is_type( 'variable' ) ) {
      WC_Product_Variable::sync( $product->get_id() );
    }
    wc_delete_product_transients( $product->get_id() );
  }
}
