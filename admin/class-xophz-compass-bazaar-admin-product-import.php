<?php
/**
 * The product CSV import (bazaar_import_products_csv), moved out of
 * Xophz_Compass_Bazaar_Admin.
 *
 * Guarantees:
 * - Catalog columns need bazaar_manage_catalog. The stock columns
 *   (stock_quantity, stock_status) need bazaar_manage_stock: without it they
 *   are ignored and the result says so in `errors`.
 * - A stock_quantity is written after the product save with
 *   Admin_Product_Stock::set_quantity: set_count with key
 *   import:{sha256 of the rows}:{row} and reason import in ledger mode, an
 *   atomic WooCommerce set otherwise. The sha256 is of the JSON of the posted
 *   rows, because the browser parses the CSV, so the same rows posted again
 *   replay and change nothing.
 *
 * Not guaranteed: all rows succeeding together; each row is its own save.
 *
 * @package    Xophz_Compass_Bazaar
 * @subpackage Xophz_Compass_Bazaar/admin
 */
class Xophz_Compass_Bazaar_Admin_Product_Import {

  public function import() {
    Xophz_Compass::output_json( $this->run() );
  }

  /** The response body; sending it is import()'s job. */
  private function run(): array {
    try {
      if ( ! Xophz_Compass_Bazaar_Security_Guard::can_any( Xophz_Compass_Bazaar_Security_Capabilities::MANAGE_CATALOG ) ) {
        return [ 'success' => false, 'message' => 'Permission denied.' ];
      }

      $args     = Xophz_Compass::get_input_json();
      $products = isset( $args->products ) ? $args->products : [];

      if ( is_string( $products ) ) {
        $products = json_decode( $products, true );
      }

      if ( empty( $products ) || ! is_array( $products ) ) {
        return [ 'success' => false, 'message' => 'No product rows provided.' ];
      }

      $can_stock = Xophz_Compass_Bazaar_Security_Guard::can_any( Xophz_Compass_Bazaar_Security_Capabilities::MANAGE_STOCK );
      $file_hash = hash( 'sha256', (string) wp_json_encode( $products ) );
      $created   = 0;
      $updated   = 0;
      $skipped   = 0;
      $errors    = [];
      $row_no    = 0;
      $ignored   = false;

      foreach ( $products as $pData ) {
        $row_no++;
        $pObj  = is_object( $pData ) ? $pData : (object) $pData;
        $title = isset( $pObj->title ) ? trim( sanitize_text_field( $pObj->title ) ) : '';
        if ( empty( $title ) ) {
          $skipped++;
          $errors[] = 'Row #' . $row_no . ': Skipped due to missing title.';
          continue;
        }

        $sku         = isset( $pObj->sku ) ? trim( sanitize_text_field( $pObj->sku ) ) : '';
        $existing_id = 0;

        if ( $sku ) {
          $existing_id = wc_get_product_id_by_sku( $sku );
        }

        if ( ! $existing_id ) {
          $page = get_page_by_title( $title, OBJECT, 'product' );
          if ( $page ) {
            $existing_id = $page->ID;
          }
        }

        $product = $existing_id > 0 ? wc_get_product( $existing_id ) : new WC_Product_Simple();
        if ( ! $product ) {
          $skipped++;
          $errors[] = 'Row #' . $row_no . ': Failed to load product instance.';
          continue;
        }

        $is_new = ( 0 === $existing_id );

        $product->set_name( $title );
        if ( $sku ) {
          $product->set_sku( $sku );
        }
        if ( isset( $pObj->regular_price ) && '' !== $pObj->regular_price ) {
          $product->set_regular_price( wc_format_decimal( $pObj->regular_price ) );
        }
        if ( isset( $pObj->sale_price ) && '' !== $pObj->sale_price ) {
          $product->set_sale_price( wc_format_decimal( $pObj->sale_price ) );
        }
        if ( isset( $pObj->description ) ) {
          $product->set_description( wp_kses_post( $pObj->description ) );
        }
        if ( isset( $pObj->short_description ) ) {
          $product->set_short_description( wp_kses_post( $pObj->short_description ) );
        }

        $quantity = null;
        if ( isset( $pObj->stock_quantity ) && '' !== $pObj->stock_quantity ) {
          $quantity = $can_stock ? Xophz_Compass_Bazaar_Admin_Product_Stock::number( $pObj->stock_quantity ) : null;
          $problem  = $can_stock ? Xophz_Compass_Bazaar_Admin_Product_Stock::quantity_problem( $quantity ) : null;
          if ( $problem || ( null !== $quantity && $quantity < 0 ) ) {
            $errors[] = 'Row #' . $row_no . ' (' . $title . '): stock quantity not used. ' . ( $problem ?: 'Quantity cannot be negative.' );
            $quantity = null;
          } elseif ( null !== $quantity ) {
            $product->set_manage_stock( true );
          } else {
            $ignored = true;
          }
        }

        if ( isset( $pObj->stock_status ) && ! empty( $pObj->stock_status ) ) {
          $status = strtolower( trim( $pObj->stock_status ) );
          if ( ! $can_stock ) {
            $ignored = true;
          } elseif ( in_array( $status, [ 'instock', 'outofstock', 'onbackorder' ], true ) ) {
            $product->set_stock_status( $status );
          }
        }

        if ( isset( $pObj->categories ) && ! empty( $pObj->categories ) ) {
          $cat_ids = $this->category_ids( (string) $pObj->categories );
          if ( ! empty( $cat_ids ) ) {
            $product->set_category_ids( $cat_ids );
          }
        }

        if ( $is_new ) {
          $product->set_status( 'publish' );
        }

        $saved_id = $product->save();
        if ( ! $saved_id ) {
          $skipped++;
          $errors[] = 'Row #' . $row_no . ' (' . $title . '): Failed to save product.';
          continue;
        }
        if ( null !== $quantity ) {
          try {
            Xophz_Compass_Bazaar_Admin_Product_Stock::set_quantity( (int) $saved_id, $quantity, 'import', Xophz_Compass_Bazaar_Stock_Keys::import( $file_hash, $row_no ) );
          } catch ( \Throwable $e ) {
            $errors[] = 'Row #' . $row_no . ' (' . $title . '): saved, but stock not set. ' . $e->getMessage();
          }
        }
        if ( $is_new ) {
          $created++;
        } else {
          $updated++;
        }
      }

      if ( $ignored ) {
        $errors[] = 'Stock columns were ignored for rows that had them: stock permission is required.';
      }

      return [
        'success'       => true,
        'created_count' => $created,
        'updated_count' => $updated,
        'skipped_count' => $skipped,
        'errors'        => $errors,
      ];
    } catch ( \Throwable $e ) {
      return [ 'success' => false, 'message' => $e->getMessage() ];
    }
  }

  /** Category ids for a comma-separated list of names, creating the missing categories. */
  private function category_ids( string $names ): array {
    $ids = [];
    foreach ( array_map( 'trim', explode( ',', $names ) ) as $name ) {
      if ( empty( $name ) ) {
        continue;
      }
      $term = get_term_by( 'name', $name, 'product_cat' );
      if ( ! $term ) {
        $created = wp_insert_term( $name, 'product_cat' );
        if ( ! is_wp_error( $created ) && isset( $created['term_id'] ) ) {
          $ids[] = intval( $created['term_id'] );
        }
      } else {
        $ids[] = intval( $term->term_id );
      }
    }
    return $ids;
  }
}
