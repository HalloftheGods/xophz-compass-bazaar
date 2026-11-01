<?php
/**
 * Stock locations (spec 8.1): location 0 is store-level stock; every other
 * location is a compass_warehouse post id.
 *
 * Guarantees:
 * - default_location_id() is the lowest-id compass_warehouse (any status but
 *   trash and auto-draft) whose _location_is_default meta is 'yes', '1' or
 *   'true', else 0. The admin screen stores the flag as '1'.
 * - A product is location-tracked when its stock key (the product that holds
 *   the stock) has at least one _compass_multi_inventory entry.
 * - sale_location() follows spec 8.1. It does not check that the requested
 *   warehouse exists or is listed on the product.
 *
 * @package Xophz_Compass_Bazaar
 */
final class Xophz_Compass_Bazaar_Stock_Locations {

  const POST_TYPE      = 'compass_warehouse';
  const DEFAULT_META   = '_location_is_default';
  const INVENTORY_META = '_compass_multi_inventory';
  const TRUE_VALUES    = [ 'yes', '1', 'true' ];

  /** The compass_warehouse marked default, else 0. */
  public static function default_location_id(): int {
    $ids = get_posts(
      [
        'post_type'        => self::POST_TYPE,
        'post_status'      => array_diff( array_keys( get_post_stati() ), [ 'trash', 'auto-draft' ] ),
        'meta_query'       => [ [ 'key' => self::DEFAULT_META, 'value' => self::TRUE_VALUES, 'compare' => 'IN' ] ],
        'orderby'          => 'ID',
        'order'            => 'ASC',
        'numberposts'      => 1,
        'fields'           => 'ids',
        'suppress_filters' => true,
      ]
    );
    return $ids ? (int) $ids[0] : 0;
  }

  /** True when the product's stock key has _compass_multi_inventory entries. */
  public static function is_location_tracked( int $product_id ): bool {
    $key = self::stock_key( $product_id );
    return $key > 0 && (bool) self::inventory_entries( $key );
  }

  /**
   * Spec 8.1: 0 for a product that is not location-tracked; otherwise the
   * requested warehouse, or the default warehouse (0 when none) when no
   * warehouse was requested.
   */
  public static function sale_location( int $product_id, ?int $requested = null ): int {
    if ( ! self::is_location_tracked( $product_id ) ) {
      return 0;
    }
    if ( null !== $requested && $requested > 0 ) {
      return $requested;
    }
    return self::default_location_id();
  }

  /** The id of the product that holds the stock (WC_Product::get_stock_managed_by_id), 0 when the product is missing. */
  public static function stock_key( int $product_id ): int {
    $product = $product_id > 0 && function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;
    return $product ? (int) $product->get_stock_managed_by_id() : 0;
  }

  /**
   * The product's _compass_multi_inventory entries, keyed by location id.
   * Every entry is kept, disabled ones included. An entry whose location id
   * is not positive is skipped.
   *
   * @return array loc => ['qty' => float, 'enabled' => bool]
   */
  public static function inventory_entries( int $stock_product_id ): array {
    $raw = get_post_meta( $stock_product_id, self::INVENTORY_META, true );
    return self::parse_entries( $raw );
  }

  /** @param mixed $raw The unserialized meta value. @return array loc => ['qty' => float, 'enabled' => bool] */
  public static function parse_entries( $raw ): array {
    if ( ! is_array( $raw ) ) {
      return [];
    }
    $out = [];
    foreach ( $raw as $key => $entry ) {
      if ( ! is_array( $entry ) ) {
        continue;
      }
      $loc = (int) ( $entry['location_id'] ?? $key );
      if ( $loc <= 0 ) {
        continue;
      }
      $out[ $loc ] = [
        'qty'     => (float) ( $entry['stock_quantity'] ?? 0 ),
        'enabled' => ! empty( $entry['is_enabled'] ),
      ];
    }
    ksort( $out );
    return $out;
  }

  /**
   * Location 0 ("Store") plus every published or private compass_warehouse,
   * by menu order then title. A warehouse with no title is named "Unknown".
   *
   * @return array[] Each ['id' => int, 'name' => string].
   */
  public static function all(): array {
    $out   = [ [ 'id' => 0, 'name' => __( 'Store', 'xophz-compass-bazaar' ) ] ];
    $posts = get_posts(
      [
        'post_type'        => self::POST_TYPE,
        'post_status'      => [ 'publish', 'private' ],
        'orderby'          => 'menu_order title',
        'order'            => 'ASC',
        'numberposts'      => -1,
        'suppress_filters' => true,
      ]
    );
    foreach ( $posts as $post ) {
      $out[] = [ 'id' => (int) $post->ID, 'name' => '' !== (string) $post->post_title ? (string) $post->post_title : __( 'Unknown', 'xophz-compass-bazaar' ) ];
    }
    return $out;
  }
}
