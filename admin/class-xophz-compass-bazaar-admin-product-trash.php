<?php
/**
 * Move a product to the Trash, list the Trash, bring a product back, or delete it for good.
 *
 * Who may call: bazaar_trash_product, bazaar_restore_product,
 * bazaar_list_trashed_products and bazaar_delete_product_permanently need
 * bazaar_manage_catalog and a wp_rest nonce (Security_Policy and the guard).
 *
 * Guaranteed:
 * - Only bazaar_delete_product_permanently deletes for good, and only a product
 *   already in the Trash that is on no order of any status (trashed orders
 *   count). WordPress turns wp_trash_post into a permanent delete when
 *   EMPTY_TRASH_DAYS is 0, so trashing is refused on that site instead.
 * - The product's own photo goes with it only when that photo was uploaded to
 *   this product and no other post uses it; otherwise the photo stays and the
 *   answer says so.
 * - A product on an open order (OPEN_ORDER_STATUSES) stays where it is. Line
 *   items keep the parent in _product_id and the choice in _variation_id, so a
 *   variable product is refused when any of its choices is on an open order.
 * - The check reads the order table in use: HPOS or wp_posts. The line item
 *   tables are the same under both. Refunds are not orders here.
 * - WooCommerce trashes and restores a variable product's choices with it, puts
 *   back the earlier status, and renames a SKU that was reused meanwhile.
 *
 * Not guaranteed: the order check and the trash are two steps, so a sale in
 * between is not refused. Purchase orders and open POS carts are not checked.
 *
 * @package    Xophz_Compass_Bazaar
 * @subpackage Xophz_Compass_Bazaar/admin
 */
class Xophz_Compass_Bazaar_Admin_Product_Trash {

  const OPEN_ORDER_STATUSES = [ 'pending', 'processing', 'on-hold', 'ready-pickup' ];

  /** @var string */
  private $plugin_name;

  /** @var string */
  private $version;

  public $action_hooks = [
    'wp_ajax_bazaar_trash_product'   => 'trashProduct',
    'wp_ajax_bazaar_restore_product' => 'restoreProduct',
    'wp_ajax_bazaar_list_trashed_products'      => 'listTrashed',
    'wp_ajax_bazaar_delete_product_permanently' => 'deletePermanently',
  ];

  const PER_PAGE_DEFAULT = 20;
  const PER_PAGE_MAX     = 50;

  public function __construct( $plugin_name, $version ) {
    $this->plugin_name = $plugin_name;
    $this->version     = $version;
  }

  /** Clean active output buffers and emit the JSON payload. */
  private function send_json_response( $payload ) {
    while ( ob_get_level() > 0 ) {
      @ob_end_clean();
    }
    Xophz_Compass::output_json( $payload );
  }

  /** @throws \Exception 'Permission denied.' when the user may not change the catalog. */
  private function require_catalog(): void {
    if ( ! Xophz_Compass_Bazaar_Security_Guard::can_any( 'bazaar_manage_catalog' ) ) {
      throw new \Exception( 'Permission denied.' );
    }
  }

  private function requested_id(): int {
    $args = Xophz_Compass_Bazaar_Core_Request::legacy_args();
    return isset( $args->id ) && is_scalar( $args->id ) ? max( 0, (int) $args->id ) : 0;
  }

  /** AJAX: answers {success, id, name, trash_days} or {success:false, code, message}. */
  public function trashProduct() {
    // Sent outside the try: output_json ends in wp_die, which must not read as a failure and answer twice.
    try {
      $payload = $this->trash_answer();
    } catch ( \Throwable $e ) {
      $payload = [ 'success' => false, 'message' => $e->getMessage() ];
    }
    $this->send_json_response( $payload );
  }

  /** AJAX: answers {success, id, name} or {success:false, message}. */
  public function restoreProduct() {
    try {
      $payload = $this->restore_answer();
    } catch ( \Throwable $e ) {
      $payload = [ 'success' => false, 'message' => $e->getMessage() ];
    }
    $this->send_json_response( $payload );
  }

  /** AJAX: answers {success, items, total, page, per_page, trash_days} or {success:false, message}. */
  public function listTrashed() {
    try {
      $payload = $this->list_answer();
    } catch ( \Throwable $e ) {
      $payload = [ 'success' => false, 'message' => $e->getMessage() ];
    }
    $this->send_json_response( $payload );
  }

  /** AJAX: answers {success, id, name, photo, message} or {success:false, code, message}. */
  public function deletePermanently() {
    try {
      $payload = $this->forever_answer();
    } catch ( \Throwable $e ) {
      $payload = [ 'success' => false, 'message' => $e->getMessage() ];
    }
    $this->send_json_response( $payload );
  }

  private function list_answer(): array {
    $this->require_catalog();

    $args     = Xophz_Compass_Bazaar_Core_Request::legacy_args();
    $page     = isset( $args->page ) && is_scalar( $args->page ) ? max( 1, (int) $args->page ) : 1;
    $per_page = isset( $args->per_page ) && is_scalar( $args->per_page ) ? (int) $args->per_page : self::PER_PAGE_DEFAULT;
    $per_page = min( self::PER_PAGE_MAX, max( 1, $per_page ) );
    $days     = self::trash_days();

    $query = new \WP_Query( [
      'post_type'           => 'product',
      'post_status'         => 'trash',
      'posts_per_page'      => $per_page,
      'paged'               => $page,
      'orderby'             => [ 'modified' => 'DESC', 'ID' => 'DESC' ],
      'ignore_sticky_posts' => true,
    ] );

    $items = [];
    foreach ( $query->posts as $post ) {
      $product = wc_get_product( $post->ID );
      if ( ! $product ) {
        continue;
      }
      $deleted = self::deleted_at( (int) $post->ID, (string) $post->post_modified_gmt );
      $items[] = [
        'id'            => (int) $post->ID,
        'name'          => wp_specialchars_decode( $post->post_title, ENT_QUOTES ),
        'price'         => (string) $product->get_price(),
        'regular_price' => (string) $product->get_regular_price(),
        'sale_price'    => (string) $product->get_sale_price(),
        'photo'         => (string) wp_get_attachment_image_url( (int) $product->get_image_id(), 'thumbnail' ),
        'deleted_at'    => gmdate( 'c', $deleted ),
        'days_left'     => self::days_left( $deleted, $days, time() ),
        'has_orders'    => self::order_count( self::delete_scope_ids( $product ), null ) > 0,
      ];
    }

    return [
      'success'    => true,
      'items'      => $items,
      'total'      => (int) $query->found_posts,
      'page'       => $page,
      'per_page'   => $per_page,
      'trash_days' => $days,
    ];
  }

  private function forever_answer(): array {
    $this->require_catalog();

    $id   = $this->requested_id();
    $post = $id > 0 ? get_post( $id ) : null;
    if ( ! $post || 'product' !== $post->post_type ) {
      // A second tap on a slow phone lands here: the product is already gone.
      throw new \Exception( 'That product is already gone.' );
    }
    if ( 'trash' !== $post->post_status ) {
      throw new \Exception( 'Only a product in Recently deleted can be deleted forever.' );
    }

    $name    = wp_specialchars_decode( $post->post_title, ENT_QUOTES );
    $product = wc_get_product( $id );
    if ( ! $product ) {
      throw new \Exception( 'That product could not be found. It may already be gone.' );
    }

    $orders = self::order_count( self::delete_scope_ids( $product ), null );
    if ( $orders > 0 ) {
      return [
        'success' => false,
        'code'    => 'has_orders',
        'message' => self::has_orders_message( $name, $orders ),
      ];
    }

    $photo_id = (int) $product->get_image_id();
    $keeps    = $photo_id > 0 && ! self::photo_is_private( $photo_id, $id );

    if ( ! wp_delete_post( $id, true ) || get_post( $id ) ) {
      throw new \Exception( 'The product could not be deleted. Nothing was changed.' );
    }

    $photo = 'none';
    if ( $photo_id > 0 ) {
      $photo = ( ! $keeps && wp_delete_attachment( $photo_id, true ) ) ? 'removed' : 'kept';
    }

    return [
      'success' => true,
      'id'      => $id,
      'name'    => $name,
      'photo'   => $photo,
      'message' => self::forever_message( $name, $photo ),
    ];
  }

  private function trash_answer(): array {
    $this->require_catalog();

    $id      = $this->requested_id();
    $product = $id > 0 ? wc_get_product( $id ) : false;
    if ( ! $product ) {
      throw new \Exception( 'That product could not be found. It may already be gone.' );
    }
    if ( $product->is_type( 'variation' ) ) {
      throw new \Exception( 'That is one choice of a product. Delete the whole product instead.' );
    }

    $name = wp_specialchars_decode( $product->get_name(), ENT_QUOTES );
    $days = self::trash_days();

    // A second tap on a slow phone lands here: the product is where it was asked to go.
    if ( 'trash' === $product->get_status() ) {
      return [ 'success' => true, 'id' => $id, 'name' => $name, 'trash_days' => $days ];
    }

    if ( $days < 1 ) {
      return [ 'success' => false, 'code' => 'trash_off', 'message' => self::trash_off_message() ];
    }

    $open = self::open_order_count( self::ids_on_order_lines( $product ) );
    if ( $open > 0 ) {
      return [
        'success'     => false,
        'code'        => 'open_orders',
        'open_orders' => $open,
        'message'     => self::open_orders_message( $name, $open ),
      ];
    }

    $product->delete( false );
    if ( 'trash' !== get_post_status( $id ) ) {
      throw new \Exception( 'The product could not be deleted. Nothing was changed.' );
    }

    return [ 'success' => true, 'id' => $id, 'name' => $name, 'trash_days' => $days ];
  }

  private function restore_answer(): array {
    $this->require_catalog();

    $id   = $this->requested_id();
    $post = $id > 0 ? get_post( $id ) : null;
    if ( ! $post || 'product' !== $post->post_type ) {
      throw new \Exception( 'That product is no longer in the Trash.' );
    }

    if ( 'trash' === $post->post_status && ! wp_untrash_post( $id ) ) {
      throw new \Exception( 'The product could not be restored. Try again.' );
    }

    return [
      'success' => true,
      'id'      => $id,
      'name'    => wp_specialchars_decode( $post->post_title, ENT_QUOTES ),
    ];
  }

  /** How long the Trash keeps a product on this site; 0 means WordPress has no Trash. */
  public static function trash_days(): int {
    return defined( 'EMPTY_TRASH_DAYS' ) ? max( 0, (int) EMPTY_TRASH_DAYS ) : 30;
  }

  public static function trash_off_message(): string {
    return 'The Trash is turned off on this website, so this product was not deleted.';
  }

  public static function open_orders_message( string $name, int $count ): string {
    $where = 1 === $count ? 'an open order' : $count . ' open orders';
    $those = 1 === $count ? 'that order' : 'those orders';
    return "$name is on $where, so it can't be deleted yet. Finish or cancel $those first.";
  }

  public static function has_orders_message( string $name, int $count ): string {
    $where = 1 === $count ? 'an order' : $count . ' orders';
    return "$name is on $where, so it can't be deleted forever. Its sales history needs it.";
  }

  public static function forever_message( string $name, string $photo ): string {
    $text = "$name was deleted forever.";
    return 'kept' === $photo ? "$text Its photo was kept because it may be used somewhere else." : $text;
  }

  /** When the product went to the Trash: WordPress's own stamp, else the last change. */
  public static function deleted_at( int $id, string $modified_gmt ): int {
    $stamp = (int) get_post_meta( $id, '_wp_trash_meta_time', true );
    return $stamp > 0 ? $stamp : (int) strtotime( $modified_gmt . ' UTC' );
  }

  /** Whole days before WordPress empties the Trash, counted up so "0 days" never shows for a product that is still there. */
  public static function days_left( int $deleted, int $keep_days, int $now ): int {
    return max( 0, (int) ceil( ( $deleted + $keep_days * 86400 - $now ) / 86400 ) );
  }

  /**
   * True when the photo was uploaded to this product and nothing else points at it:
   * not another post's featured image, gallery or text.
   */
  public static function photo_is_private( int $photo_id, int $product_id ): bool {
    global $wpdb;

    $attachment = get_post( $photo_id );
    if ( ! $attachment || 'attachment' !== $attachment->post_type || (int) $attachment->post_parent !== $product_id ) {
      return false;
    }

    $used = $wpdb->get_var( $wpdb->prepare(
      "SELECT COUNT(*) FROM {$wpdb->postmeta}
       WHERE post_id <> %d
         AND ( ( meta_key = '_thumbnail_id' AND meta_value = %s )
            OR ( meta_key = '_product_image_gallery' AND CONCAT(',', meta_value, ',') LIKE %s ) )",
      $product_id,
      (string) $photo_id,
      '%,' . $wpdb->esc_like( (string) $photo_id ) . ',%'
    ) );
    $in_text = $wpdb->get_var( $wpdb->prepare(
      "SELECT COUNT(*) FROM {$wpdb->posts} WHERE ID <> %d AND post_type <> 'revision' AND post_content LIKE %s",
      $product_id,
      '%wp-image-' . $wpdb->esc_like( (string) $photo_id ) . '%'
    ) );

    // An unknown answer is "in use": the photo stays.
    return null !== $used && null !== $in_text && 0 === (int) $used && 0 === (int) $in_text;
  }

  /** Every id a line item could carry for this product, including choices that are in the Trash with it. */
  public static function delete_scope_ids( $product ): array {
    $ids = self::ids_on_order_lines( $product );
    if ( $product->is_type( 'variable' ) ) {
      $ids = array_merge( $ids, array_map( 'intval', get_posts( [
        'post_type'      => 'product_variation',
        'post_status'    => 'any',
        'post_parent'    => (int) $product->get_id(),
        'fields'         => 'ids',
        'posts_per_page' => -1,
      ] ) ) );
    }
    return $ids;
  }

  /** The product, plus its choices when it has them. A grouped product's children are products of their own. */
  public static function ids_on_order_lines( $product ): array {
    $ids = [ (int) $product->get_id() ];
    return $product->is_type( 'variable' ) ? array_merge( $ids, array_map( 'intval', $product->get_children() ) ) : $ids;
  }

  /** Table and columns of an order row, for HPOS or wp_posts storage. */
  public static function order_source( bool $hpos, string $prefix, string $posts_table ): array {
    return $hpos
      ? [ 'table' => $prefix . 'wc_orders', 'id' => 'id', 'status' => 'status', 'type' => 'type' ]
      : [ 'table' => $posts_table, 'id' => 'ID', 'status' => 'post_status', 'type' => 'post_type' ];
  }

  private static function hpos_enabled(): bool {
    return class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
      && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
  }

  /**
   * How many open orders hold any of these product ids.
   *
   * @throws \Exception When the query fails: an unknown answer must not read as "no orders".
   */
  public static function open_order_count( array $ids ): int {
    return self::order_count( $ids, self::OPEN_ORDER_STATUSES );
  }

  /**
   * How many orders hold any of these product ids. $statuses null counts every
   * status, trashed orders included: their history still names the product.
   *
   * @throws \Exception When the query fails: an unknown answer must not read as "no orders".
   */
  public static function order_count( array $ids, ?array $statuses ): int {
    global $wpdb;

    $ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
    if ( ! $ids ) {
      return 0;
    }

    $source   = self::order_source( self::hpos_enabled(), $wpdb->prefix, $wpdb->posts );
    $statuses = null === $statuses ? [] : array_map( function ( $status ) {
      return 'wc-' . $status;
    }, $statuses );
    $id_marks      = implode( ',', array_fill( 0, count( $ids ), '%s' ) );
    $status_marks  = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
    $status_clause = $statuses ? "AND o.{$source['status']} IN ($status_marks)" : '';

    // meta_value is text, so the ids are compared as text and the column stays uncast.
    $count = $wpdb->get_var( $wpdb->prepare(
      "SELECT COUNT(DISTINCT oi.order_id)
       FROM {$wpdb->prefix}woocommerce_order_items oi
       INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim ON oim.order_item_id = oi.order_item_id
       INNER JOIN {$source['table']} o ON o.{$source['id']} = oi.order_id
       WHERE oi.order_item_type = 'line_item'
         AND oim.meta_key IN ('_product_id', '_variation_id')
         AND oim.meta_value IN ($id_marks)
         AND o.{$source['type']} = 'shop_order'
         $status_clause",
      array_merge( array_map( 'strval', $ids ), $statuses )
    ) );

    if ( null === $count ) {
      throw new \Exception( 'The open orders could not be checked, so nothing was changed. Try again.' );
    }
    return (int) $count;
  }
}
