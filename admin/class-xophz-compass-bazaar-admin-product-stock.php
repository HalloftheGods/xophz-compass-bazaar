<?php
/**
 * The update_product_stock handler and the stock-write helpers the other admin
 * handlers share (spec 8.9).
 *
 * Guarantees:
 * - In ledger mode every write here goes through Stock_Service (adjust,
 *   set_count, set_enabled), so the ledger and `_stock` change together.
 * - In shadow and off modes stock changes use wc_update_product_stock
 *   (an atomic increase, decrease or set) inside a Stock_Context.
 * - update() reads `operation` (set, count, add or subtract; default set). The
 *   legacy body field `action` is read only when it holds one of those four
 *   words, so an old client keeps working.
 *
 * Not guaranteed: in shadow and off modes `expected` is compared before the
 * write and not atomically with it, and add or subtract has no idempotency key.
 * Variations whose stock is managed by the parent are not covered by the
 * location helpers (they read and write meta of the product id they are given).
 *
 * @package    Xophz_Compass_Bazaar
 * @subpackage Xophz_Compass_Bazaar/admin
 */
class Xophz_Compass_Bazaar_Admin_Product_Stock {

  const OPERATIONS = [ 'set', 'count', 'add', 'subtract' ];

  const WC_OPERATIONS = [
    'set'      => 'set',
    'count'    => 'set',
    'add'      => 'increase',
    'subtract' => 'decrease',
  ];

  /** AJAX: update_product_stock. Answers {success, product} or {success:false, code?, message, ...}. */
  public function update() {
    if ( ! Xophz_Compass_Bazaar_Security_Guard::can_any( Xophz_Compass_Bazaar_Security_Capabilities::MANAGE_STOCK ) ) {
      wp_send_json( [ 'success' => false, 'code' => 'forbidden', 'message' => 'You do not have permission to change stock.' ], 403 );
    }
    $args       = Xophz_Compass::get_input_json();
    $product_id = isset( $args->product_id ) ? intval( $args->product_id ) : 0;
    $operation  = self::operation( $args );
    $quantity   = self::number( $args->quantity ?? null );
    $expected   = self::number( $args->expected ?? null );
    $uuid       = self::client_uuid( $args );
    $location   = isset( $args->location_id ) ? max( 0, intval( $args->location_id ) ) : 0;

    $problem = null;
    if ( ! $product_id ) {
      $problem = 'Invalid product ID';
    } elseif ( null === $operation ) {
      $problem = 'Operation must be set, count, add or subtract.';
    } elseif ( self::quantity_problem( $quantity ) ) {
      $problem = self::quantity_problem( $quantity );
    } elseif ( $quantity < 0 || ( in_array( $operation, [ 'add', 'subtract' ], true ) && ! ( $quantity > 0 ) ) ) {
      $problem = 'Quantity must be more than 0 for add and subtract, and 0 or more for set and count.';
    }
    $product = $problem ? null : wc_get_product( $product_id );
    if ( ! $problem && ( ! $product || ! $product->managing_stock() ) ) {
      $problem = 'Product not found or not managing stock';
    }
    if ( $problem ) {
      wp_send_json( [ 'success' => false, 'message' => $problem ] );
    }

    $error = null;
    try {
      if ( Xophz_Compass_Bazaar_Stock_Mode::is_ledger() ) {
        self::ledger_write( $product, $operation, $quantity, in_array( $operation, [ 'set', 'count' ], true ) ? $expected : null, $uuid, $location );
      } else {
        self::legacy_write( $product, $operation, $quantity, $expected );
      }
    } catch ( Xophz_Compass_Bazaar_Core_Http_Error $e ) {
      $error = array_merge( $e->data(), [ 'success' => false, 'code' => $e->error_code(), 'message' => $e->getMessage() ] );
    } catch ( InvalidArgumentException $e ) {
      $error = [ 'success' => false, 'message' => $e->getMessage() ];
    }
    if ( $error ) {
      unset( $error['status'] );
      wp_send_json( $error );
    }

    $updated = Xophz_Compass_Bazaar_Admin::getProductsDataByIds( [ $product_id ] );
    wp_send_json( [ 'success' => true, 'product' => isset( $updated[0] ) ? $updated[0] : null ] );
  }

  /** Attribution for a ledger batch: the operator and the acting user from the guard. */
  public static function meta(): Xophz_Compass_Bazaar_Stock_Meta {
    $ctx = Xophz_Compass_Bazaar_Security_Guard::context();
    return new Xophz_Compass_Bazaar_Stock_Meta(
      [
        'actor_user_id'    => $ctx->operator_user_id,
        'cashier_user_id'  => $ctx->acting_user_id,
        'staff_session_id' => $ctx->staff_session_id > 0 ? $ctx->staff_session_id : null,
        'device_id'        => $ctx->device_id,
      ]
    );
  }

  /** The request's client_uuid when it is 1 to 64 of [A-Za-z0-9_-], else a new uuid4. */
  public static function client_uuid( $args ): string {
    $uuid = $args->client_uuid ?? null;
    return ( is_string( $uuid ) && preg_match( '/^[A-Za-z0-9_-]{1,64}$/', $uuid ) ) ? $uuid : wp_generate_uuid4();
  }

  /**
   * The stock fields of the product form (saveProduct). Sets the manage_stock
   * flag (read with bool_param, so the string 'false' is false) and the stock
   * status on the product, and returns the quantity to write after the save,
   * null when none is to be written. A change to any of the three needs
   * bazaar_manage_stock, else nothing is changed and an error is thrown.
   *
   * @param object $args The decoded request body.
   * @throws Xophz_Compass_Bazaar_Core_Http_Error
   */
  public static function apply_form( WC_Product $product, $args ): ?float {
    $manage   = Xophz_Compass_Bazaar_Core_Request::bool_param( $args, 'manage_stock' );
    $quantity = $manage ? self::number( $args->stock_quantity ?? null ) : null;
    $status   = isset( $args->stock_status ) ? sanitize_text_field( $args->stock_status ) : null;
    $changes  = $manage !== (bool) $product->get_manage_stock()
      || ( null !== $quantity && abs( $quantity - (float) $product->get_stock_quantity() ) > 0.00005 )
      || ( null !== $status && $status !== $product->get_stock_status() );
    if ( $changes && ! Xophz_Compass_Bazaar_Security_Guard::can_any( Xophz_Compass_Bazaar_Security_Capabilities::MANAGE_STOCK ) ) {
      throw new Xophz_Compass_Bazaar_Core_Http_Error( 'forbidden', 'You do not have permission to change stock.', 403 );
    }
    if ( isset( $args->stock_quantity ) && $manage && self::quantity_problem( $quantity ) ) {
      throw new Xophz_Compass_Bazaar_Core_Http_Error( 'invalid_quantity', self::quantity_problem( $quantity ), 422 );
    }
    $product->set_manage_stock( $manage );
    if ( null !== $status ) {
      $product->set_stock_status( $status );
    }
    return $quantity;
  }

  /** adjust:{acting user}:{uuid}, the key of a manual change. */
  public static function adjust_key( string $uuid ): string {
    return Xophz_Compass_Bazaar_Stock_Keys::adjust( Xophz_Compass_Bazaar_Security_Guard::context()->acting_user_id, $uuid );
  }

  /** A plain number from a request value, null for anything else. */
  public static function number( $value ): ?float {
    if ( is_int( $value ) || is_float( $value ) || ( is_string( $value ) && is_numeric( trim( $value ) ) ) ) {
      return (float) $value;
    }
    return null;
  }

  /** Why a quantity cannot be written, null when it can. WooCommerce rounds stock unless a filter allows decimals. */
  public static function quantity_problem( ?float $quantity ): ?string {
    if ( null === $quantity || ! is_finite( $quantity ) ) {
      return 'Quantity must be a number.';
    }
    if ( abs( (float) wc_stock_amount( $quantity ) - $quantity ) > 0.00005 ) {
      return 'Quantity must be a whole number.';
    }
    return null;
  }

  /**
   * The location a stock write targets: 0 for a product without locations;
   * otherwise the requested one or the default, which must be one of the
   * product's own locations.
   *
   * @throws Xophz_Compass_Bazaar_Core_Http_Error
   */
  public static function location( int $product_id, int $requested = 0 ): int {
    $entries = Xophz_Compass_Bazaar_Stock_Locations::inventory_entries( Xophz_Compass_Bazaar_Stock_Locations::stock_key( $product_id ) );
    if ( ! $entries ) {
      if ( $requested > 0 ) {
        throw new Xophz_Compass_Bazaar_Core_Http_Error( 'unsupported_field', 'This product has no stock locations.', 422 );
      }
      return 0;
    }
    $location = $requested > 0 ? $requested : Xophz_Compass_Bazaar_Stock_Locations::default_location_id();
    if ( ! isset( $entries[ $location ] ) ) {
      throw new Xophz_Compass_Bazaar_Core_Http_Error( 'location_required', 'Choose one of the product\'s stock locations.', 422 );
    }
    return $location;
  }

  /**
   * Set a product's stock to a quantity: set_count with $key in ledger mode,
   * an atomic wc_update_product_stock set in a Stock_Context otherwise.
   *
   * @throws Xophz_Compass_Bazaar_Core_Http_Error|InvalidArgumentException
   */
  public static function set_quantity( int $product_id, float $quantity, string $reason, string $key ): void {
    if ( Xophz_Compass_Bazaar_Stock_Mode::is_ledger() ) {
      $location = self::location( $product_id );
      self::prepare_stock( [ Xophz_Compass_Bazaar_Stock_Locations::stock_key( $product_id ) ] );
      Xophz_Compass_Bazaar_Stock_Factory::service()->set_count( $key, $product_id, $location, $quantity, null, $reason, self::meta() );
      return;
    }
    $product = wc_get_product( $product_id );
    if ( $product ) {
      self::wc_set( $product, $quantity, $reason, [ 'type' => 'product', 'id' => $product_id ] );
    }
  }

  /**
   * Before a ledger write: make sure each stock product has a numeric `_stock`
   * row. ensure_stock_rows adds a missing row, but WooCommerce stores NULL
   * when stock management is turned off, and a NULL row stays NULL when the
   * projection adds to it, so it is set to 0 here (task #6976 asks the port to do it).
   *
   * @param int[] $stock_keys
   */
  public static function prepare_stock( array $stock_keys ): void {
    global $wpdb;
    Xophz_Compass_Bazaar_Stock_Factory::port()->ensure_stock_rows( $stock_keys );
    foreach ( $stock_keys as $key ) {
      $raw = $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_stock' ORDER BY meta_id LIMIT 1", $key ) );
      if ( null === $raw || '' === $raw ) {
        Xophz_Compass_Bazaar_Stock_Wc_Port_Wpdb::while_writing(
          function () use ( $key ) {
            update_post_meta( $key, '_stock', '0' );
          }
        );
      }
    }
  }

  /** Shadow and off modes: an atomic WooCommerce set inside a Stock_Context. */
  public static function wc_set( WC_Product $product, float $quantity, string $reason, array $source = [] ): void {
    Xophz_Compass_Bazaar_Stock_Context::with(
      $reason,
      function () use ( $product, $quantity ) {
        wc_update_product_stock( $product, $quantity, 'set' );
      },
      $source
    );
  }

  /**
   * Write one location of a multi-inventory product (spec 8.9). Meta fields in
   * $fields (sku, price, low_stock_threshold, is_enabled) are kept in
   * _compass_multi_inventory; the quantity goes through the ledger in ledger
   * mode (set_count, and set_enabled when is_enabled is given), else into the
   * meta and the total into `_stock`.
   *
   * @throws Xophz_Compass_Bazaar_Core_Http_Error|InvalidArgumentException
   */
  public static function set_location_stock( int $product_id, int $location, float $quantity, string $uuid, array $fields = [] ): void {
    $meta_key  = Xophz_Compass_Bazaar_Stock_Locations::INVENTORY_META;
    $product   = wc_get_product( $product_id );
    $manages   = $product && $product->get_manage_stock();
    $ledger    = $manages && Xophz_Compass_Bazaar_Stock_Mode::is_ledger();
    if ( $ledger ) {
      self::prepare_stock( [ Xophz_Compass_Bazaar_Stock_Locations::stock_key( $product_id ) ] );
      Xophz_Compass_Bazaar_Stock_Factory::service()->ensure_initialized( $product_id );
    }
    wp_cache_delete( $product_id, 'post_meta' );
    $inventories = get_post_meta( $product_id, $meta_key, true );
    $inventories = is_array( $inventories ) ? $inventories : [];
    $entry       = $inventories[ $location ] ?? [ 'location_id' => $location, 'stock_quantity' => 0, 'low_stock_threshold' => 5, 'sku' => '', 'price' => null, 'is_enabled' => true ];
    $entry       = array_merge( $entry, $fields );
    if ( ! $ledger ) {
      $entry['stock_quantity']    = $quantity;
      $inventories[ $location ]   = $entry;
      update_post_meta( $product_id, $meta_key, $inventories );
      if ( $manages ) {
        self::wc_set( $product, self::enabled_total( $inventories ), 'count', [ 'type' => 'inventory', 'id' => $product_id ] );
      }
      return;
    }
    $inventories[ $location ] = $entry;
    update_post_meta( $product_id, $meta_key, $inventories );
    $service = Xophz_Compass_Bazaar_Stock_Factory::service();
    $meta    = self::meta();
    if ( array_key_exists( 'is_enabled', $fields ) ) {
      $service->set_enabled( $product_id, $location, (bool) $fields['is_enabled'], $meta );
    }
    $service->set_count( self::adjust_key( $uuid . '-' . $location ), $product_id, $location, $quantity, null, 'count', $meta );
  }

  /**
   * Save every location of a product from sanitized rows (location id =>
   * location_id, stock_quantity, low_stock_threshold, sku, price, is_enabled).
   * Returns the product's `_stock` afterwards.
   *
   * In ledger mode the meta keeps sku, price, threshold and flag, the
   * projection owns stock_quantity, and a location missing from the rows is
   * dropped from the meta only when its stock is 0.
   *
   * @throws Xophz_Compass_Bazaar_Core_Http_Error|InvalidArgumentException
   */
  public static function save_inventories( int $product_id, array $rows ): float {
    $meta_key = Xophz_Compass_Bazaar_Stock_Locations::INVENTORY_META;
    $product  = wc_get_product( $product_id );
    $manages  = $product && $product->get_manage_stock();
    if ( ! $manages || ! Xophz_Compass_Bazaar_Stock_Mode::is_ledger() ) {
      update_post_meta( $product_id, $meta_key, $rows );
      $total = self::enabled_total( $rows );
      if ( $manages ) {
        self::wc_set( $product, $total, 'count', [ 'type' => 'inventory', 'id' => $product_id ] );
      }
      return $total;
    }
    self::prepare_stock( [ Xophz_Compass_Bazaar_Stock_Locations::stock_key( $product_id ) ] );
    $service = Xophz_Compass_Bazaar_Stock_Factory::service();
    $service->ensure_initialized( $product_id );
    wp_cache_delete( $product_id, 'post_meta' );
    $before = get_post_meta( $product_id, $meta_key, true );
    $before = is_array( $before ) ? $before : [];
    $stored = [];
    foreach ( $rows as $location => $row ) {
      $row['stock_quantity'] = $before[ $location ]['stock_quantity'] ?? 0;
      $stored[ $location ]   = $row;
    }
    foreach ( $before as $location => $entry ) {
      if ( ! isset( $rows[ $location ] ) && is_array( $entry ) && (float) ( $entry['stock_quantity'] ?? 0 ) !== 0.0 ) {
        $stored[ $location ] = $entry;
      }
    }
    update_post_meta( $product_id, $meta_key, $stored );
    $meta = self::meta();
    $uuid = wp_generate_uuid4();
    foreach ( $rows as $location => $row ) {
      if ( $location > 0 ) {
        $service->set_enabled( $product_id, $location, (bool) $row['is_enabled'], $meta );
        $service->set_count( self::adjust_key( $uuid . '-' . $location ), $product_id, $location, (float) $row['stock_quantity'], null, 'count', $meta );
      }
    }
    wp_cache_delete( $product_id, 'post_meta' );
    return (float) get_post_meta( $product_id, '_stock', true );
  }

  /**
   * True when a location still holds stock: a ledger balance that is not 0, or
   * a product whose _compass_multi_inventory entry for it has a quantity.
   */
  public static function location_has_stock( int $location ): bool {
    global $wpdb;
    $balances = Xophz_Compass_Bazaar_Core_Db::table( 'stock_balances' );
    if ( (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $balances WHERE location_id = %d AND on_hand <> 0", $location ) ) > 0 ) {
      return true;
    }
    $like = '%' . $wpdb->esc_like( 'i:' . $location . ';' ) . '%';
    $rows = $wpdb->get_col( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value LIKE %s", Xophz_Compass_Bazaar_Stock_Locations::INVENTORY_META, $like ) );
    foreach ( $rows as $raw ) {
      $entries = Xophz_Compass_Bazaar_Stock_Locations::parse_entries( maybe_unserialize( $raw ) );
      if ( isset( $entries[ $location ] ) && abs( $entries[ $location ]['qty'] ) > 0.00005 ) {
        return true;
      }
    }
    return false;
  }

  /** The sum of the enabled locations' quantities. */
  public static function enabled_total( array $inventories ): float {
    $total = 0.0;
    foreach ( $inventories as $inv ) {
      if ( ! empty( $inv['is_enabled'] ) ) {
        $total += (float) $inv['stock_quantity'];
      }
    }
    return $total;
  }

  private static function operation( $args ): ?string {
    $operation = $args->operation ?? null;
    if ( null === $operation && isset( $args->action ) && in_array( $args->action, self::OPERATIONS, true ) ) {
      $operation = $args->action;
    }
    $operation = null === $operation ? 'set' : $operation;
    return in_array( $operation, self::OPERATIONS, true ) ? $operation : null;
  }

  private static function ledger_write( WC_Product $product, string $operation, float $quantity, ?float $expected, string $uuid, int $requested ): void {
    $product_id = $product->get_id();
    $location   = self::location( $product_id, $requested );
    self::prepare_stock( [ (int) $product->get_stock_managed_by_id() ] );
    $service = Xophz_Compass_Bazaar_Stock_Factory::service();
    $key     = self::adjust_key( $uuid );
    if ( in_array( $operation, [ 'add', 'subtract' ], true ) ) {
      $service->adjust( $key, $product_id, $location, 'add' === $operation ? $quantity : -$quantity, 'adjust', self::meta() );
      return;
    }
    $service->set_count( $key, $product_id, $location, $quantity, $expected, 'count', self::meta() );
  }

  private static function legacy_write( WC_Product $product, string $operation, float $quantity, ?float $expected ): void {
    $reason = in_array( $operation, [ 'add', 'subtract' ], true ) ? 'adjust' : 'count';
    if ( 'count' === $reason && null !== $expected ) {
      $current = (float) $product->get_stock_quantity();
      if ( Xophz_Compass_Bazaar_Stock_Planner::differs( $current, $expected ) ) {
        throw Xophz_Compass_Bazaar_Stock_Conflict_Exception::stock_changed( $current );
      }
    }
    Xophz_Compass_Bazaar_Stock_Context::with(
      $reason,
      function () use ( $product, $quantity, $operation ) {
        wc_update_product_stock( $product, $quantity, self::WC_OPERATIONS[ $operation ] );
      },
      [ 'type' => 'adjustment' ]
    );
  }
}
