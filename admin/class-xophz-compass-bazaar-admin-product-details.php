<?php
/**
 * Product details from a barcode lookup, server side: used by a Quick Add
 * create (and so a pile item's pricing walk) to fill the descriptions of a
 * new product, and by the one-time "Fill missing descriptions" action for
 * products already in the catalog.
 *
 *   bazaar_fill_descriptions {cursor}  -> one small batch, see fillDescriptions()
 *
 * Who may call: bazaar_manage_catalog and a wp_rest nonce (Security_Policy).
 *
 * Guarantees:
 * - Nothing from the phone is trusted: the text comes from the lookup this
 *   server cached (Barcode_Memory, then the lookup transient, then the
 *   providers), never from the request.
 * - Only empty fields are filled, and only on a product being created or one
 *   whose long description is empty. A description someone wrote stays.
 * - A lookup that fails, is slow or is rate limited changes nothing: a create
 *   still saves, with no description. Every call is wrapped for that.
 * - A category is added only to a new product and only when a product_cat of
 *   that name already exists. A category is never created.
 * - Text goes through wp_kses_post before it is stored on a product.
 * - Nothing here runs by itself. The backfill runs one batch per request, on
 *   request, and stops at the first rate_limited answer.
 *
 * Not guaranteed: brand. The Add Product editor does nothing with a brand
 * except the title the providers already joined it to, so neither does this.
 *
 * @package    Xophz_Compass_Bazaar
 * @subpackage Xophz_Compass_Bazaar/admin
 */
class Xophz_Compass_Bazaar_Admin_Product_Details {

  /** Products asked of the lookup in one request (UPCitemdb's trial allows about 100 a day). */
  const BATCH_LOOKUPS = 5;

  /** Blank-description products read in one request, with or without a barcode. */
  const BATCH_READ = 100;

  /** A batch stops starting lookups after this many seconds. */
  const BATCH_SECONDS = 20;

  /** @var string */
  private $plugin_name;

  /** @var string */
  private $version;

  public $action_hooks = [
    'wp_ajax_bazaar_fill_descriptions' => 'fillDescriptions',
  ];

  public function __construct( $plugin_name, $version ) {
    $this->plugin_name = $plugin_name;
    $this->version     = $version;
  }

  /** The Bazaar caps the action needs (any one of them). @return array<string,string[]> */
  public static function policy(): array {
    return [ 'bazaar_fill_descriptions' => [ Xophz_Compass_Bazaar_Security_Capabilities::MANAGE_CATALOG ] ];
  }

  /* ------------------------------------------------------------------ */
  /* Lookup                                                              */
  /* ------------------------------------------------------------------ */

  /**
   * What the lookup knows about a code, as product details. Never throws and
   * never writes to the catalog.
   *
   * @param array $code Barcode::normalize output.
   * @return array{status:string,details:array} status is 'found', 'none' (the
   *   lookup answered and has no text), 'rate_limited', 'error' or 'skipped'
   *   (not a valid code, so no lookup was made).
   */
  public static function details_for( array $code ): array {
    $out = [ 'status' => 'skipped', 'details' => Xophz_Compass_Bazaar_Barcode_Details::none() ];
    if ( empty( $code['valid'] ) || '' === (string) ( $code['normalized'] ?? '' ) ) {
      return $out;
    }
    try {
      $found = Xophz_Compass_Bazaar_Admin::global_barcode_lookup( (string) $code['normalized'], true );
    } catch ( \Throwable $e ) {
      error_log( 'bazaar product details: lookup failed: ' . $e->getMessage() );
      $out['status'] = 'error';
      return $out;
    }
    $status = (string) ( $found['status'] ?? 'error' );
    if ( 'found' === $status ) {
      $out['details'] = Xophz_Compass_Bazaar_Barcode_Details::from_product( $found['product'] ?? null );
      $out['status']  = '' !== $out['details']['description'] ? 'found' : 'none';
      return $out;
    }
    $out['status'] = 'not_found' === $status ? 'none' : ( 'rate_limited' === $status ? 'rate_limited' : 'error' );
    return $out;
  }

  /* ------------------------------------------------------------------ */
  /* Writes                                                              */
  /* ------------------------------------------------------------------ */

  /**
   * Put the details on a product that has not been saved yet: the long and
   * short description when empty, and an existing store category when the
   * product has none. Does not save.
   *
   * @return array The details that were written (description, short_description).
   */
  public static function fill_new_product( WC_Product $product, array $details ): array {
    $written = self::fill_descriptions( $product, $details );
    if ( [] === $product->get_category_ids() ) {
      $ids = self::existing_category_ids( (array) ( $details['category_hints'] ?? [] ) );
      if ( [] !== $ids ) {
        $product->set_category_ids( $ids );
      }
    }
    return $written;
  }

  /**
   * Set the empty descriptions of a product from the details. Does not save.
   *
   * @return array{description?:string,short_description?:string} What was written.
   */
  public static function fill_descriptions( WC_Product $product, array $details ): array {
    $fill = Xophz_Compass_Bazaar_Barcode_Details::fill(
      [ 'description' => $product->get_description( 'edit' ), 'short_description' => $product->get_short_description( 'edit' ) ],
      $details
    );
    $written = [];
    if ( isset( $fill['description'] ) ) {
      $written['description'] = wp_kses_post( $fill['description'] );
      $product->set_description( $written['description'] );
    }
    if ( isset( $fill['short_description'] ) ) {
      $written['short_description'] = wp_kses_post( $fill['short_description'] );
      $product->set_short_description( $written['short_description'] );
    }
    return $written;
  }

  /**
   * The id of the most specific category hint (they run general to specific)
   * that matches an existing product_cat by name, as a one-item list; [] when
   * none match. Creates nothing.
   *
   * @param string[] $hints
   * @return int[]
   */
  public static function existing_category_ids( array $hints ): array {
    foreach ( array_reverse( $hints ) as $hint ) {
      $term = get_term_by( 'name', (string) $hint, 'product_cat' );
      if ( $term instanceof WP_Term && 'uncategorized' !== $term->slug ) {
        return [ (int) $term->term_id ];
      }
    }
    return [];
  }

  /* ------------------------------------------------------------------ */
  /* Backfill                                                            */
  /* ------------------------------------------------------------------ */

  /**
   * AJAX bazaar_fill_descriptions {cursor}: one batch of the backfill. Answers
   * {success, cursor, examined, looked_up, filled, no_text, no_code, failed,
   * remaining, done, rate_limited}. cursor is the last product id handled; send
   * it back for the next batch. A batch looks up at most BATCH_LOOKUPS codes
   * and stops at the first rate_limited answer, leaving that product for the
   * next run.
   */
  public function fillDescriptions() {
    $caps = self::policy()['bazaar_fill_descriptions'];
    Xophz_Compass_Security::enforce_spa_ajax( ...$caps );
    $args   = Xophz_Compass_Bazaar_Core_Request::legacy_args();
    $cursor = isset( $args->cursor ) && is_numeric( $args->cursor ) ? max( 0, (int) $args->cursor ) : 0;
    try {
      $response = [ 'success' => true ] + $this->run_batch( $cursor );
    } catch ( \Throwable $e ) {
      error_log( 'bazaar_fill_descriptions: ' . $e->getMessage() );
      $response = [ 'success' => false, 'code' => 'failed', 'message' => 'That did not work. Nothing was changed. Try again.' ];
    }
    while ( ob_get_level() > 0 ) {
      @ob_end_clean(); // phpcs:ignore WordPress.PHP.NoSilencedErrors
    }
    Xophz_Compass::output_json( $response );
  }

  /** One batch from the product id after $cursor. @return array */
  private function run_batch( int $cursor ): array {
    $stats = [ 'cursor' => $cursor, 'examined' => 0, 'looked_up' => 0, 'filled' => 0, 'no_text' => 0, 'no_code' => 0, 'failed' => 0, 'rate_limited' => false ];
    $began = microtime( true );
    $ids   = self::blank_product_ids( $cursor, self::BATCH_READ );
    foreach ( $ids as $id ) {
      $product = wc_get_product( $id );
      if ( ! $product || $product->is_type( 'variation' ) || '' !== trim( (string) $product->get_description( 'edit' ) ) ) {
        $stats['cursor'] = $id;
        continue;
      }
      $code = Xophz_Compass_Bazaar_Barcode::normalize( (string) $product->get_sku( 'edit' ) );
      if ( empty( $code['valid'] ) ) {
        $stats['cursor'] = $id;
        $stats['examined']++;
        $stats['no_code']++;
        continue;
      }
      if ( $stats['looked_up'] >= self::BATCH_LOOKUPS || microtime( true ) - $began > self::BATCH_SECONDS ) {
        break;
      }
      $answer = self::details_for( $code );
      $stats['looked_up']++;
      if ( 'rate_limited' === $answer['status'] ) {
        $stats['rate_limited'] = true;
        break;
      }
      $stats['cursor'] = $id;
      $stats['examined']++;
      if ( 'found' === $answer['status'] && [] !== self::fill_descriptions( $product, $answer['details'] ) ) {
        $product->save();
        $stats['filled']++;
      } elseif ( 'error' === $answer['status'] ) {
        $stats['failed']++;
      } else {
        $stats['no_text']++;
      }
    }
    $stats['remaining'] = self::count_blank_products( $stats['cursor'] );
    $stats['done']      = ! $stats['rate_limited'] && 0 === $stats['remaining'];
    return $stats;
  }

  /**
   * Products with an empty long description and a SKU with at least eight
   * digits (a barcode, or something that may be), after $after, oldest first.
   * Variations are not products here.
   *
   * @return int[]
   */
  private static function blank_product_ids( int $after, int $limit ): array {
    global $wpdb;
    $sql = "SELECT p.ID FROM {$wpdb->posts} p" . self::blank_product_where() . ' ORDER BY p.ID ASC LIMIT %d';
    return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( $sql, $after, $limit ) ) );
  }

  /** How many such products are left after $after. */
  private static function count_blank_products( int $after ): int {
    global $wpdb;
    return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} p" . self::blank_product_where(), $after ) );
  }

  private static function blank_product_where(): string {
    global $wpdb;
    return " INNER JOIN {$wpdb->postmeta} sku ON sku.post_id = p.ID AND sku.meta_key = '_sku' AND sku.meta_value REGEXP '[0-9]{8}'"
      . " WHERE p.post_type = 'product' AND p.post_status IN ('publish', 'draft', 'pending', 'private')"
      . " AND TRIM(p.post_content) = '' AND p.ID > %d";
  }
}
