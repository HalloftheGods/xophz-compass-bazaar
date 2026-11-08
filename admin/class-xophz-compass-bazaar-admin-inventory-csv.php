<?php
/**
 * Multi-inventory CSV export and import, moved out of
 * Xophz_Compass_Bazaar_Admin_Multi_Inventory (which delegates to this class).
 *
 * Guarantees:
 * - import() needs bazaar_manage_stock (PRO is not a check). Each row sets one
 *   product location through Admin_Product_Stock::set_location_stock: in
 *   ledger mode a set_count and set_enabled (reason count), else the meta and
 *   an atomic WooCommerce set of the enabled total.
 * - Location 0 is the product's own stock (a product without locations), the
 *   same 0 that update_product_stock uses. The export writes 0 for those rows,
 *   so a file exported and imported again sets the same counts.
 * - A row that cannot be applied is counted in `failed` and the import goes on.
 *   A row that is not a data row (too short, no product, bad location, a
 *   product that does not manage stock, a negative count) is counted in
 *   `skipped` and named in `errors`.
 *
 * Not guaranteed: replay safety. The batch keys of an inventory import are
 * random, so uploading the same file twice counts the locations twice (the
 * second time the counts already match, so it changes nothing, unless stock
 * moved in between).
 *
 * @package    Xophz_Compass_Bazaar
 * @subpackage Xophz_Compass_Bazaar/admin
 */
class Xophz_Compass_Bazaar_Admin_Inventory_Csv {

  /** Send the multi-inventory levels as a CSV download, then exit. */
  public function export() {
    $products = wc_get_products( [
      'limit'  => -1,
      'status' => 'publish',
    ] );

    header( 'Content-Type: text/csv; charset=utf-8' );
    $filename = 'compass_multi_inventory_' . date( 'Y-m-d' ) . '.csv';
    header( 'Content-Disposition: attachment; filename=' . $filename );
    header( 'Content-Name: ' . $filename ); // The SPA's download helper names the saved file from this.

    $output = fopen( 'php://output', 'w' );
    fputcsv( $output, [ 'Product ID', 'SKU', 'Product Name', 'Location ID', 'Location Stock', 'Low Stock Threshold', 'Location Price' ] );

    foreach ( $products as $product ) {
      $pid         = $product->get_id();
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
          0,
          $product->get_stock_quantity() ?: 0,
          5,
          $product->get_price(),
        ] );
      }
    }

    fclose( $output );
    exit;
  }

  /** AJAX: import a CSV file (field `file`) to set location stock. */
  public function import() {
    if ( ! Xophz_Compass_Bazaar_Security_Guard::can_any( Xophz_Compass_Bazaar_Security_Capabilities::MANAGE_STOCK ) ) {
      wp_send_json_error( [ 'message' => 'You do not have permission to manage stock.', 'code' => 'forbidden' ], 403 );
    }

    if ( empty( $_FILES['file']['tmp_name'] ) ) {
      wp_send_json_error( [ 'message' => 'No CSV file uploaded.' ], 400 );
    }

    $handle = fopen( $_FILES['file']['tmp_name'], 'r' );
    if ( ! $handle ) {
      wp_send_json_error( [ 'message' => 'Failed to read uploaded CSV.' ], 500 );
    }

    fgetcsv( $handle );
    $processed = 0;
    $failed    = 0;
    $skipped   = 0;
    $errors    = [];
    $row_no    = 1; // The header row.

    while ( ( $data = fgetcsv( $handle ) ) !== false ) {
      $row_no++;
      if ( array( null ) === $data ) {
        continue; // A blank line is not a row.
      }
      $problem = $this->row_problem( $data );
      if ( $problem ) {
        $skipped++;
        $errors[] = 'Row ' . $row_no . ': skipped. ' . $problem;
        continue;
      }
      $product_id  = intval( $data[0] );
      $location_id = intval( $data[3] );
      try {
        if ( 0 === $location_id ) {
          Xophz_Compass_Bazaar_Admin_Product_Stock::set_quantity(
            $product_id,
            (float) intval( $data[4] ),
            'count',
            Xophz_Compass_Bazaar_Admin_Product_Stock::adjust_key( wp_generate_uuid4() . '-0' )
          );
          $processed++;
          continue;
        }
        Xophz_Compass_Bazaar_Admin_Product_Stock::set_location_stock(
          $product_id,
          $location_id,
          (float) intval( $data[4] ),
          wp_generate_uuid4(),
          [
            'location_id'         => $location_id,
            'low_stock_threshold' => isset( $data[5] ) ? intval( $data[5] ) : 5,
            'sku'                 => isset( $data[1] ) ? sanitize_text_field( $data[1] ) : '',
            'price'               => isset( $data[6] ) && $data[6] !== '' ? floatval( $data[6] ) : null,
            'is_enabled'          => true,
          ]
        );
        $processed++;
      } catch ( \Throwable $e ) {
        $failed++;
        $errors[] = 'Product ' . $product_id . ', location ' . $location_id . ': ' . $e->getMessage();
      }
    }

    fclose( $handle );
    wp_send_json_success( [
      'message'        => 'CSV imported successfully.',
      'rows_processed' => $processed,
      'failed'         => $failed,
      'skipped'        => $skipped,
      'errors'         => $errors,
    ] );
  }

  /** Why a CSV row cannot be applied, null when it can. */
  private function row_problem( array $data ): ?string {
    if ( count( $data ) < 5 ) {
      return 'It has fewer than 5 columns.';
    }
    $product = intval( $data[0] ) > 0 ? wc_get_product( intval( $data[0] ) ) : false;
    if ( ! $product ) {
      return 'No product with ID "' . sanitize_text_field( (string) $data[0] ) . '".';
    }
    // A legacy export wrote "default" for location 0, so a non-number counts as 0.
    if ( intval( $data[3] ) < 0 ) {
      return 'The location ID is negative.';
    }
    if ( intval( $data[4] ) < 0 ) {
      return 'The stock count is negative.';
    }
    if ( 0 === intval( $data[3] ) && ! $product->managing_stock() ) {
      return '"' . $product->get_name() . '" does not manage stock.';
    }
    return null;
  }
}
