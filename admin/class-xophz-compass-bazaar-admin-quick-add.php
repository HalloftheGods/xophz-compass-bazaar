<?php
/**
 * Quick Add: the phone-first add/restock screen's four admin-ajax actions.
 *
 *   bazaar_quick_lookup  {barcode}                  -> barcode, existing, draft, price_hint
 *   bazaar_quick_save    {client_uuid, mode, ...}   -> action, product, stock_before/after, undo_token
 *   bazaar_quick_undo    {undo_token}               -> action 'undone', product|null
 *   bazaar_quick_recent  {}                         -> today_count, items (current user, today)
 *   bazaar_quick_image   {url}                      -> mime, data_base64 (a found picture, through this server)
 *
 * bazaar_quick_image answers JSON, not image bytes: an <img> cannot send the
 * X-WP-Nonce header the guard asks for, and a nonce should not sit in a URL.
 *
 * Scan a pile (see Admin_Quick_Pile): a save may carry pile_id (modes add and
 * create). The pile row is taken before any stock is written and marked done
 * (saved_product_id, done_at) when the save succeeds, else put back; a row
 * that is already done answers code pile_done and changes nothing. With
 * pile_qty_seen (the count the screen showed), scans of that code that
 * landed after it stay in the pile as an open row. The
 * response then carries pile_item, the undo data records pile_id, and an
 * undo puts the row back in the pile (response pile_item).
 *
 * Guarantees:
 * - The computer never sets a price or a count: every price and quantity
 *   written comes from the request. A lookup never writes to the catalog.
 * - Existing items are found by exact SKU match on each candidate form of the
 *   code (never LIKE or prefix), then by WooCommerce's global unique id.
 * - Stock writes go through the same path as Admin_Product_Stock: the ledger
 *   service (adjust / set_count with adjust:{user}:{uuid} keys) in ledger
 *   mode, wc_update_product_stock inside a Stock_Context otherwise.
 * - Fix it ('set') and every undo change stock by a difference (adjust at
 *   the default location), never by writing a total, so sales made in
 *   between are kept and other stock locations are not touched.
 * - A create that fails after its product was saved removes that product,
 *   so a failed save leaves nothing behind.
 * - All four actions are mapped for the Bazaar Security_Guard through
 *   policy() (see Security_Guard::MODULE_POLICIES).
 * - A save is applied at most once per user and client_uuid within 24
 *   hours: a repeat returns the stored result. A save with no answer stays
 *   on the phone, so the repeat can come long after the first try. Only
 *   successes are stored, so "Try again" with the same client_uuid re-runs
 *   a failed save.
 * - A create attaches a picture only from this user's last lookup of the
 *   code, or an Open*Facts host, trying each in turn for at most
 *   IMAGE_BUDGET seconds; no picture still saves the item.
 * - A create with a shareable code also goes in the Barcode_Memory, so other
 *   stores on this install can name the item (never its price or stock). An
 *   undo of that create takes the memory row back to how it was. A failure
 *   there never fails the save or the undo.
 * - A price under 0.25 or over 50 is refused with code confirm_price unless
 *   the request has price_confirmed true.
 * - An undo token works once, for 15 minutes, for the user who saved.
 *
 * Not guaranteed: in off and shadow modes two different client_uuids saving
 * the same item at the same moment both apply (as with update_product_stock);
 * an undo of a restock after the item sold below the added quantity can be
 * refused by the ledger (code failed).
 *
 * TODO(quick-add v2): photo + AI naming for items with no barcode or no
 * global record. The hook point is the 'not found' branch of quickLookup()
 * (draft stays null today) and the 'create' save, which already accepts a
 * title and an allowlisted image_url.
 *
 * @package    Xophz_Compass_Bazaar
 * @subpackage Xophz_Compass_Bazaar/admin
 */
class Xophz_Compass_Bazaar_Admin_Quick_Add {

  /** @var string */
  private $plugin_name;

  /** @var string */
  private $version;

  public $action_hooks = [
    'wp_ajax_bazaar_quick_lookup' => 'quickLookup',
    'wp_ajax_bazaar_quick_save'   => 'quickSave',
    'wp_ajax_bazaar_quick_undo'   => 'quickUndo',
    'wp_ajax_bazaar_quick_recent' => 'quickRecent',
    'wp_ajax_bazaar_quick_image'  => 'quickImage',
  ];

  const MODES              = [ 'add', 'create', 'set' ];
  const IDEMPOTENCY_TTL    = 86400; // 24 hours (a literal: unit tests load this class without WordPress).
  const IDEM_PREFIX        = 'bazaar_qa_idem_';
  const LOCK_PREFIX        = 'bazaar_qa_lock_';
  const UNDO_TTL           = 900;
  const LOCK_STALE_AFTER   = 60;
  const LOG_META           = '_bazaar_quick_add';
  const LOG_MAX            = 200;
  const RECENT_MAX         = 20;
  const LAST_QTY_META      = '_bazaar_quick_add_last_qty';
  const CREATED_META       = '_bazaar_quick_add_created';
  const SKU_PREFIX_OPTION  = 'bazaar_quick_add_sku_prefix';
  const SKU_COUNTER_OPTION = 'bazaar_quick_add_sku_counter';
  const DEFAULT_SKU_PREFIX = 'QA-';
  const PRICE_MIN          = 0.01;
  const PRICE_MAX          = 100000.0;
  const CONFIRM_BELOW      = 0.25;
  const CONFIRM_ABOVE      = 50.0;
  const QTY_MAX_ADD        = 10000;
  const QTY_MAX_SET        = 100000;
  const TITLE_MAX          = 200;
  const IMAGE_HOSTS        = Xophz_Compass_Bazaar_Barcode_Sources::IMAGE_HOSTS;
  const IMAGE_BUDGET       = 15;

  /**
   * The Bazaar caps each action needs (any one of them). Read by
   * Security_Guard::caps_for for the admin-ajax wrapper, and by authorize()
   * here, so the guard and the handler can never disagree.
   *
   * @return array<string,string[]> action (without wp_ajax_) => caps
   */
  public static function policy(): array {
    $c = 'Xophz_Compass_Bazaar_Security_Capabilities';
    return [
      'bazaar_quick_lookup' => [ $c::MANAGE_CATALOG, $c::MANAGE_STOCK, $c::RECEIVE ],
      'bazaar_quick_save'   => [ $c::MANAGE_STOCK, $c::MANAGE_CATALOG ],
      'bazaar_quick_undo'   => [ $c::MANAGE_STOCK, $c::MANAGE_CATALOG ],
      'bazaar_quick_recent' => [ $c::MANAGE_CATALOG, $c::MANAGE_STOCK, $c::RECEIVE ],
      'bazaar_quick_image'  => [ $c::MANAGE_CATALOG, $c::MANAGE_STOCK, $c::RECEIVE ],
    ];
  }

  public function __construct( $plugin_name, $version ) {
    $this->plugin_name = $plugin_name;
    $this->version     = $version;
  }

  /* ------------------------------------------------------------------ */
  /* Actions                                                             */
  /* ------------------------------------------------------------------ */

  /** AJAX bazaar_quick_lookup. */
  public function quickLookup() {
    $this->authorize( 'bazaar_quick_lookup' );
    $args = Xophz_Compass::get_input_json();
    try {
      $response = $this->lookup( isset( $args->barcode ) ? $args->barcode : '' );
    } catch ( \Throwable $e ) {
      error_log( 'bazaar_quick_lookup: ' . $e->getMessage() );
      $response = self::failure( 'failed', 'That did not work. Try again.' );
    }
    self::respond( $response );
  }

  /** AJAX bazaar_quick_save. */
  public function quickSave() {
    $this->authorize( 'bazaar_quick_save' );
    $args = Xophz_Compass::get_input_json();
    $uuid = isset( $args->client_uuid ) && is_string( $args->client_uuid ) ? $args->client_uuid : '';
    // 62, not Stock_Keys' 64: the stock writes append a two-character suffix (-a, -c, -s).
    if ( ! preg_match( '/^[A-Za-z0-9_-]{1,62}$/', $uuid ) ) {
      self::respond( self::failure( 'invalid_request', 'client_uuid is required (1 to 62 letters, digits, _ or -).' ) );
    }

    $user_id  = (int) get_current_user_id();
    $idem_key = self::IDEM_PREFIX . self::save_hash( $user_id, $uuid );
    $stored   = get_transient( $idem_key );
    if ( is_array( $stored ) ) {
      self::respond( $stored );
    }

    $lock = self::LOCK_PREFIX . self::save_hash( $user_id, $uuid );
    if ( ! self::acquire_lock( $lock ) ) {
      self::respond( self::failure( 'in_progress', 'Still saving. Try again in a moment.' ) );
    }
    // Read again under the lock: a twin request may have finished in between.
    $stored = self::stored_answer( $idem_key );
    if ( is_array( $stored ) ) {
      self::release_lock( $lock );
      self::respond( $stored );
    }
    try {
      $response = $this->save( $args, $uuid, $user_id );
    } catch ( Xophz_Compass_Bazaar_Core_Http_Error $e ) {
      $response = self::failure( $e->error_code(), $e->getMessage() );
    } catch ( \Throwable $e ) {
      error_log( 'bazaar_quick_save: ' . $e->getMessage() );
      $response = self::failure( 'failed', "That didn't save. Nothing was lost." );
    }
    if ( ! empty( $response['success'] ) ) {
      set_transient( $idem_key, $response, self::IDEMPOTENCY_TTL );
    }
    self::release_lock( $lock );
    self::respond( $response );
  }

  /** AJAX bazaar_quick_undo. */
  public function quickUndo() {
    $this->authorize( 'bazaar_quick_undo' );
    $args  = Xophz_Compass::get_input_json();
    $token = isset( $args->undo_token ) && is_string( $args->undo_token ) ? $args->undo_token : '';
    try {
      $response = $this->undo( $token, (int) get_current_user_id() );
    } catch ( \Throwable $e ) {
      error_log( 'bazaar_quick_undo: ' . $e->getMessage() );
      $response = self::failure( 'failed', 'That did not undo. Nothing was changed.' );
    }
    self::respond( $response );
  }

  /** AJAX bazaar_quick_recent. */
  public function quickRecent() {
    $this->authorize( 'bazaar_quick_recent' );
    $user_id = (int) get_current_user_id();
    $today   = wp_date( 'Y-m-d' );
    $items   = [];
    $count   = 0;
    foreach ( array_reverse( self::read_log( $user_id ) ) as $entry ) {
      if ( ! empty( $entry['undone'] ) || wp_date( 'Y-m-d', (int) $entry['at'] ) !== $today ) {
        continue;
      }
      if ( in_array( $entry['action'], [ 'created', 'stock_added' ], true ) ) {
        $count++;
      }
      if ( count( $items ) < self::RECENT_MAX ) {
        $items[] = [
          'product_id' => (int) $entry['product_id'],
          'title'      => (string) ( $entry['title'] ?? '' ),
          'qty'        => self::number_out( (float) ( $entry['qty'] ?? 0 ) ),
          'price'      => (string) ( $entry['price'] ?? '' ),
          'action'     => (string) $entry['action'],
          'at'         => wp_date( 'c', (int) $entry['at'] ),
        ];
      }
    }
    self::respond( [ 'success' => true, 'today_count' => $count, 'items' => $items ] );
  }

  /** AJAX bazaar_quick_image. */
  public function quickImage() {
    $this->authorize( 'bazaar_quick_image' );
    $args = Xophz_Compass::get_input_json();
    try {
      $picture = Xophz_Compass_Bazaar_Barcode_Image::fetch( isset( $args->url ) && is_string( $args->url ) ? $args->url : '' );
    } catch ( \Throwable $e ) {
      error_log( 'bazaar_quick_image: ' . $e->getMessage() );
      $picture = null;
    }
    self::respond( $picture ? [ 'success' => true ] + $picture : self::failure( 'no_picture', 'That picture could not be shown.' ) );
  }

  /* ------------------------------------------------------------------ */
  /* Lookup                                                              */
  /* ------------------------------------------------------------------ */

  private function lookup( $raw ): array {
    $code = Xophz_Compass_Bazaar_Barcode::normalize( is_scalar( $raw ) ? sanitize_text_field( (string) $raw ) : '' );
    if ( '' === $code['raw'] ) {
      return self::failure( 'invalid_barcode', 'Scan or type the numbers under the bars.' );
    }
    $out = [
      'success'    => true,
      'barcode'    => [ 'raw' => $code['raw'], 'normalized' => $code['normalized'], 'valid' => $code['valid'] ],
      'existing'   => null,
      'draft'      => null,
      'price_hint' => null,
    ];

    $product_id = self::find_existing( $code );
    $product    = $product_id ? self::editable_product( $product_id ) : null;
    if ( $product_id && ! $product ) {
      return self::unavailable( $product_id );
    }
    if ( $product ) {
      $last = get_post_meta( $product->get_id(), self::LAST_QTY_META, true );
      $out['existing'] = self::product_view( $product ) + [
        'last_added_qty' => is_numeric( $last ) && (int) $last > 0 ? (int) $last : null,
      ];
      return $out;
    }

    // Only a code with a valid check digit is worth a provider call; a typo is not.
    if ( $code['valid'] ) {
      $found = Xophz_Compass_Bazaar_Admin::global_barcode_lookup( $code['normalized'] );
      if ( 'found' === $found['status'] && is_array( $found['product'] ) ) {
        $p      = $found['product'];
        $images = Xophz_Compass_Bazaar_Barcode_Image::clean_list( $p['image_urls'] ?? [ $p['image_url'] ] );
        $out['draft'] = [
          'title'       => (string) $p['title'],
          'brand'       => (string) $p['brand'],
          'description' => (string) $p['description'],
          'image_url'   => $images[0] ?? '',
          'image_urls'  => $images,
          'source'      => (string) $found['source'],
        ];
        Xophz_Compass_Bazaar_Barcode_Image::remember_candidates( (int) get_current_user_id(), $code['normalized'], $images );
      }
      if ( is_array( $found['price_hint'] ) ) {
        $out['price_hint'] = $found['price_hint'];
      }
    }
    // TODO(quick-add v2): when draft is null, offer photo + AI naming here.
    return $out;
  }

  /**
   * The product whose SKU exactly equals one of the code's candidate forms,
   * else whose global unique id does. 0 when none.
   */
  public static function find_existing( array $code ): int {
    $candidates = isset( $code['candidates'] ) && is_array( $code['candidates'] ) ? $code['candidates'] : [];
    foreach ( $candidates as $candidate ) {
      $id = (int) wc_get_product_id_by_sku( $candidate );
      if ( $id > 0 ) {
        return $id;
      }
    }
    if ( function_exists( 'wc_get_product_id_by_global_unique_id' ) ) {
      foreach ( $candidates as $candidate ) {
        if ( ! ctype_digit( $candidate ) ) {
          continue;
        }
        $id = (int) wc_get_product_id_by_global_unique_id( $candidate );
        if ( $id > 0 ) {
          return $id;
        }
      }
    }
    return 0;
  }

  /* ------------------------------------------------------------------ */
  /* Save                                                                */
  /* ------------------------------------------------------------------ */

  /** Validate and apply one save. Returns the response body. */
  private function save( $args, string $uuid, int $user_id ): array {
    $mode = isset( $args->mode ) && is_string( $args->mode ) ? $args->mode : '';
    if ( ! in_array( $mode, self::MODES, true ) ) {
      return self::failure( 'invalid_request', 'mode must be add, create or set.' );
    }

    $price_raw = $args->regular_price ?? null;
    $price     = null;
    if ( null !== $price_raw && '' !== $price_raw ) {
      $price = self::parse_price( $price_raw );
      if ( null === $price ) {
        return self::failure( 'invalid_price', 'The price must be between $0.01 and $100,000.' );
      }
    }

    $qty_raw = $args->quantity ?? null;
    $qty     = null;
    if ( 'set' !== $mode || ( null !== $qty_raw && '' !== $qty_raw ) ) {
      $qty = self::parse_quantity( $qty_raw, 'set' === $mode ? 0 : 1, 'set' === $mode ? self::QTY_MAX_SET : self::QTY_MAX_ADD );
      if ( null === $qty ) {
        return 'set' === $mode
          ? self::failure( 'invalid_quantity', 'The count must be a whole number from 0 to 100,000.' )
          : self::failure( 'invalid_quantity', 'The count must be a whole number from 1 to 10,000.' );
      }
    }

    if ( 'create' === $mode && null === $price ) {
      return self::failure( 'price_required', 'A new item needs a price.' );
    }
    if ( 'set' === $mode && null === $price && null === $qty ) {
      return self::failure( 'invalid_request', 'Send a price, a count, or both.' );
    }
    if ( null !== $price && ( $price < self::CONFIRM_BELOW || $price > self::CONFIRM_ABOVE ) && ! self::confirmed( $args ) ) {
      return [
        'success' => false,
        'code'    => 'confirm_price',
        'message' => 'Is $' . self::price_out( $price ) . ' right?',
        'price'   => self::price_out( $price ),
      ];
    }

    $barcode = Xophz_Compass_Bazaar_Barcode::normalize( isset( $args->barcode ) && is_scalar( $args->barcode ) ? sanitize_text_field( (string) $args->barcode ) : '' );

    // Scan a pile: a save of a pile row (add or create) takes that row first,
    // so the same row can never add stock twice. Fix it ('set') ignores pile_id.
    $pile_id  = 0;
    $raw_pile = $args->pile_id ?? null;
    if ( in_array( $mode, [ 'add', 'create' ], true ) && null !== $raw_pile && '' !== $raw_pile ) {
      $pile_id = Xophz_Compass_Bazaar_Quick_Pile_Store::parse_id( $raw_pile );
      if ( $pile_id <= 0 ) {
        return self::failure( 'invalid_request', 'pile_id must be a number.' );
      }
      // pile_qty_seen: the count the screen showed. Scans that landed after it
      // go back in the pile instead of being closed with this row.
      $seen_raw = $args->pile_qty_seen ?? null;
      $seen_qty = null === $seen_raw ? null : Xophz_Compass_Bazaar_Quick_Pile_Store::parse_qty( $seen_raw );
      $claim    = Xophz_Compass_Bazaar_Quick_Pile_Store::claim( $pile_id, self::save_hash( $user_id, $uuid ), $seen_qty );
      if ( 'done' === $claim ) {
        return self::failure( 'pile_done', 'This item was already put on the shelf. Nothing was added.' );
      }
      if ( 'missing' === $claim ) {
        // Taken out of the pile meanwhile; the item is still in hand, so save it.
        $pile_id = 0;
      }
    }
    if ( $pile_id <= 0 ) {
      return $this->apply_mode( $mode, $args, $barcode, $qty, $price, $uuid, $user_id );
    }

    try {
      $response = $this->apply_mode( $mode, $args, $barcode, $qty, $price, $uuid, $user_id );
    } catch ( \Throwable $e ) {
      self::put_back_in_pile( $pile_id );
      throw $e;
    }
    if ( empty( $response['success'] ) || ! in_array( $response['action'] ?? '', [ 'created', 'stock_added' ], true ) ) {
      self::put_back_in_pile( $pile_id );
      return $response;
    }
    // The stock is written: a failure from here on must not turn the save into an error.
    try {
      Xophz_Compass_Bazaar_Quick_Pile_Store::finish( $pile_id, (int) $response['product']['product_id'] );
      self::attach_to_undo( (string) $response['undo_token'], [ 'pile_id' => $pile_id ] );
      $response['pile_item'] = Xophz_Compass_Bazaar_Quick_Pile_Store::item( $pile_id );
    } catch ( \Throwable $e ) {
      error_log( 'bazaar_quick_save: pile row ' . $pile_id . ' saved but not recorded: ' . $e->getMessage() );
      $response['pile_item'] = null;
    }
    return $response;
  }

  /** Put a taken pile row back after a save that changed nothing (never throws). */
  private static function put_back_in_pile( int $pile_id ): void {
    try {
      Xophz_Compass_Bazaar_Quick_Pile_Store::reopen( $pile_id );
    } catch ( \Throwable $e ) {
      error_log( 'bazaar_quick_save: could not put pile row ' . $pile_id . ' back: ' . $e->getMessage() );
    }
  }

  /** The save for one validated mode (add, create or set). */
  private function apply_mode( string $mode, $args, array $barcode, ?float $qty, ?float $price, string $uuid, int $user_id ): array {
    if ( 'create' === $mode ) {
      // A create that failed after the product was saved left nothing behind
      // (see apply_create), so a retry with the same client_uuid starts clean.
      $existing = self::find_existing( $barcode );
      if ( $existing > 0 ) {
        // apply_add answers unsupported_product when the match cannot be restocked here.
        return $this->apply_add( $existing, $qty, $price, $uuid, $user_id );
      }
      $title = self::parse_title( $args->title ?? '' );
      if ( '' === $title ) {
        return self::failure( 'title_required', 'A new item needs a short name.' );
      }
      $images = self::images_to_try( $args->image_url ?? null, $barcode, $user_id );
      return $this->apply_create( $barcode, $title, $price, $qty, $images, $uuid, $user_id );
    }

    $product_id = isset( $args->product_id ) && is_numeric( $args->product_id ) ? (int) $args->product_id : 0;
    if ( $product_id <= 0 ) {
      return self::failure( 'invalid_request', 'product_id is required.' );
    }
    if ( 'add' === $mode ) {
      return $this->apply_add( $product_id, $qty, $price, $uuid, $user_id );
    }
    $seen_raw = $args->stock_seen ?? null;
    $seen     = is_int( $seen_raw ) || is_float( $seen_raw ) || ( is_string( $seen_raw ) && is_numeric( $seen_raw ) ) ? (float) $seen_raw : null;
    return $this->apply_set( $product_id, $qty, $price, $seen, $uuid, $user_id );
  }

  /** mode add (and create of a code that already exists): add to stock, change the price when sent and different. */
  private function apply_add( int $product_id, float $qty, ?float $price, string $uuid, int $user_id ): array {
    $product = self::editable_product( $product_id );
    if ( ! $product ) {
      return self::unavailable( $product_id );
    }
    self::require_cap( Xophz_Compass_Bazaar_Security_Capabilities::MANAGE_STOCK );

    $prev_price    = (string) $product->get_regular_price();
    $price_changed = null !== $price && ! self::same_price( $prev_price, $price );
    if ( $price_changed ) {
      self::require_cap( Xophz_Compass_Bazaar_Security_Capabilities::MANAGE_CATALOG );
    }
    $stock_before = $product->managing_stock() ? self::stock_number( $product ) : null;

    $dirty = self::ensure_stock_managed( $product );
    if ( $price_changed ) {
      $product->set_regular_price( self::price_out( $price ) );
      $dirty = true;
    }
    if ( $dirty ) {
      $product->save();
    }

    self::add_stock( $product_id, $qty, $uuid . '-a' );
    $product = self::fresh( $product_id );

    update_post_meta( $product_id, self::LAST_QTY_META, (int) $qty );
    $log_id = self::log( $user_id, [
      'product_id' => $product_id,
      'title'      => $product->get_name(),
      'qty'        => $qty,
      'price'      => self::price_string( $product ),
      'action'     => 'stock_added',
    ] );
    $token = self::issue_undo( $user_id, [
      'action'        => 'stock_added',
      'product_id'    => $product_id,
      'qty'           => $qty,
      'price_changed' => $price_changed,
      'prev_price'    => $prev_price,
      'new_price'     => $price_changed ? self::price_out( $price ) : null,
      'log_id'        => $log_id,
    ] );
    return self::success( 'stock_added', $product, $stock_before, $token );
  }

  /** mode create: a new simple product, published, managing stock. */
  private function apply_create( array $barcode, string $title, float $price, float $qty, array $images, string $uuid, int $user_id ): array {
    self::require_cap( Xophz_Compass_Bazaar_Security_Capabilities::MANAGE_CATALOG );
    self::require_cap( Xophz_Compass_Bazaar_Security_Capabilities::MANAGE_STOCK );

    $sku     = self::sku_for( $barcode );
    $product = new WC_Product_Simple();
    $product->set_name( $title );
    $product->set_regular_price( self::price_out( $price ) );
    $product->set_status( 'publish' );
    $product->set_manage_stock( true );
    try {
      $product->set_sku( $sku );
    } catch ( WC_Data_Exception $e ) {
      // Another save took this SKU since the duplicate check: restock that item instead.
      $existing = self::find_existing( $barcode );
      if ( $existing > 0 ) {
        return $this->apply_add( $existing, $qty, $price, $uuid, $user_id );
      }
      throw $e;
    }
    if ( $barcode['valid'] && method_exists( $product, 'set_global_unique_id' ) ) {
      try {
        $product->set_global_unique_id( $barcode['normalized'] );
      } catch ( WC_Data_Exception $e ) {
        // chemx-allow: the SKU already holds the code; a refused global id (taken or malformed) is not worth failing the save.
      }
    }
    $product_id = (int) $product->save();
    if ( $product_id <= 0 ) {
      return self::failure( 'failed', "That didn't save. Nothing was lost." );
    }
    // From here until the stock is written, a failure removes the new
    // product again, so "Nothing was lost" is true and a retry with the same
    // client_uuid does not find (or duplicate) a half-made item.
    try {
      update_post_meta( $product_id, self::CREATED_META, time() );
      $image = self::attach_first_image( $product_id, $images, $title );
      // Same path as saveProduct: save first, then write the quantity.
      Xophz_Compass_Bazaar_Admin_Product_Stock::set_quantity(
        $product_id,
        $qty,
        'product_form',
        Xophz_Compass_Bazaar_Admin_Product_Stock::adjust_key( $uuid . '-c' )
      );
    } catch ( \Throwable $e ) {
      self::discard_new_product( $product_id );
      throw $e;
    }
    $product = self::fresh( $product_id );

    update_post_meta( $product_id, self::LAST_QTY_META, (int) $qty );
    $log_id = self::log( $user_id, [
      'product_id' => $product_id,
      'title'      => $product->get_name(),
      'qty'        => $qty,
      'price'      => self::price_string( $product ),
      'action'     => 'created',
    ] );
    $token = self::issue_undo( $user_id, [
      'action'     => 'created',
      'product_id' => $product_id,
      'qty'        => $qty,
      'log_id'     => $log_id,
      'memory'     => self::remember_created( $barcode, $product->get_name(), $image ),
    ] );
    return self::success( 'created', $product, null, $token );
  }

  /** Tell the Barcode_Memory about a saved create (never throws). @return array|null Its undo record. */
  private static function remember_created( array $barcode, string $title, string $image ): ?array {
    if ( ! $barcode['valid'] ) {
      return null;
    }
    try {
      return Xophz_Compass_Bazaar_Barcode_Memory::remember_store( $barcode['normalized'], $title, $image );
    } catch ( \Throwable $e ) {
      error_log( 'bazaar_quick_save: barcode memory not updated: ' . $e->getMessage() );
      return null;
    }
  }

  /**
   * mode set (Fix it): a price and/or a stock total on one product.
   *
   * The stock is written as a change, never as an absolute: the change is
   * quantity minus stock_seen (the total the phone last showed), else minus
   * the stock now. So a sale between the save and the fix is kept, and in
   * ledger mode only the default location moves (as with add_stock).
   */
  private function apply_set( int $product_id, ?float $qty, ?float $price, ?float $stock_seen, string $uuid, int $user_id ): array {
    $product = self::editable_product( $product_id );
    if ( ! $product ) {
      return self::unavailable( $product_id );
    }
    if ( null !== $qty ) {
      self::require_cap( Xophz_Compass_Bazaar_Security_Capabilities::MANAGE_STOCK );
    }
    $prev_price    = (string) $product->get_regular_price();
    $price_changed = null !== $price && ! self::same_price( $prev_price, $price );
    if ( $price_changed ) {
      self::require_cap( Xophz_Compass_Bazaar_Security_Capabilities::MANAGE_CATALOG );
    }
    $stock_before = $product->managing_stock() ? self::stock_number( $product ) : null;

    $dirty = null !== $qty && self::ensure_stock_managed( $product );
    if ( $price_changed ) {
      $product->set_regular_price( self::price_out( $price ) );
      $dirty = true;
    }
    if ( $dirty ) {
      $product->save();
    }
    $delta = 0.0;
    if ( null !== $qty ) {
      $now   = null !== $stock_before ? $stock_before : 0.0;
      $base  = null !== $stock_seen ? $stock_seen : $now;
      // Never take the shelf below 0.
      $delta = max( $qty - $base, -max( 0.0, $now ) );
      if ( abs( $delta ) > 0.00005 ) {
        self::add_stock( $product_id, $delta, $uuid . '-s' );
      } else {
        $delta = 0.0;
      }
    }
    $stock_changed = 0.0 !== $delta;
    $product       = self::fresh( $product_id );
    $stock_after   = $product->managing_stock() ? self::stock_number( $product ) : null;

    // Fix it corrects today's entry for this item instead of adding a second one.
    $fixed = self::fix_log_entry( $user_id, $product_id, $product, $delta );
    if ( null === $fixed ) {
      $log_id = self::log( $user_id, [
        'product_id' => $product_id,
        'title'      => $product->get_name(),
        'qty'        => null !== $stock_after ? $stock_after : 0,
        'price'      => self::price_string( $product ),
        'action'     => 'updated',
      ] );
      $fixed = [ 'log_id' => $log_id, 'log_before' => null ];
    }
    $token = self::issue_undo( $user_id, [
      'action'        => 'updated',
      'product_id'    => $product_id,
      'price_changed' => $price_changed,
      'prev_price'    => $prev_price,
      'new_price'     => $price_changed ? self::price_out( $price ) : null,
      'stock_changed' => $stock_changed,
      'stock_delta'   => $delta,
      'log_id'        => $fixed['log_id'],
      'log_before'    => $fixed['log_before'],
    ] );
    return self::success( 'updated', $product, $stock_before, $token );
  }

  /* ------------------------------------------------------------------ */
  /* Undo                                                                */
  /* ------------------------------------------------------------------ */

  private function undo( string $token, int $user_id ): array {
    if ( ! preg_match( '/^[a-f0-9]{32}$/', $token ) ) {
      return self::failure( 'expired', 'It is too late to undo that.' );
    }
    $key  = self::undo_key( $token );
    $data = get_transient( $key );
    if ( ! is_array( $data ) || empty( $data['action'] ) ) {
      return self::failure( 'expired', 'It is too late to undo that.' );
    }
    if ( (int) ( $data['user_id'] ?? 0 ) !== $user_id ) {
      return self::failure( 'forbidden', 'Only the person who saved this can undo it.' );
    }
    // Claim the token: a second tap (or a second device) finds it gone.
    if ( ! delete_transient( $key ) ) {
      return self::failure( 'expired', 'It is too late to undo that.' );
    }

    try {
      $product = $this->reverse( $data, $token );
    } catch ( \Throwable $e ) {
      $left = max( 60, (int) ( $data['expires'] ?? 0 ) - time() );
      set_transient( $key, $data, $left );
      if ( $e instanceof Xophz_Compass_Bazaar_Core_Http_Error ) {
        return self::failure( $e->error_code(), $e->getMessage() );
      }
      throw $e;
    }

    if ( ! empty( $data['log_before'] ) && is_array( $data['log_before'] ) ) {
      self::replace_log_entry( $user_id, (string) $data['log_id'], $data['log_before'] );
    } else {
      self::mark_undone( $user_id, (string) ( $data['log_id'] ?? '' ) );
    }
    $response = [ 'success' => true, 'action' => 'undone', 'product' => $product ? self::product_view( $product ) : null ];
    if ( ! empty( $data['memory'] ) && is_array( $data['memory'] ) ) {
      // The stock is already back: a failure here must not report the undo as failed.
      try {
        Xophz_Compass_Bazaar_Barcode_Memory::restore( $data['memory'] );
      } catch ( \Throwable $e ) {
        error_log( 'bazaar_quick_undo: barcode memory not restored: ' . $e->getMessage() );
      }
    }
    if ( ! empty( $data['pile_id'] ) ) {
      // The stock is already back: a failure here must not report the undo as failed.
      try {
        $open_id               = Xophz_Compass_Bazaar_Quick_Pile_Store::reopen( (int) $data['pile_id'] );
        $response['pile_item'] = $open_id > 0 ? Xophz_Compass_Bazaar_Quick_Pile_Store::item( $open_id ) : null;
      } catch ( \Throwable $e ) {
        error_log( 'bazaar_quick_undo: could not put pile row ' . (int) $data['pile_id'] . ' back: ' . $e->getMessage() );
        $response['pile_item'] = null;
      }
    }
    return $response;
  }

  /** Apply the reverse of one save. Returns the product afterwards, null when it was trashed or is gone. */
  private function reverse( array $data, string $token ): ?WC_Product {
    $product_id = (int) $data['product_id'];
    $product    = wc_get_product( $product_id );
    if ( ! $product || 'trash' === $product->get_status() ) {
      return null;
    }
    $undo_uuid = 'undo-' . $token;
    $qty       = (float) ( $data['qty'] ?? 0 );

    if ( 'created' === $data['action'] ) {
      self::require_cap( Xophz_Compass_Bazaar_Security_Capabilities::MANAGE_CATALOG );
      $untouched = 0 === (int) $product->get_total_sales()
        && $product->managing_stock()
        && abs( self::stock_number( $product ) - $qty ) < 0.00005;
      $take = self::undo_quantity( $product, $qty );
      if ( $take > 0 ) {
        self::add_stock( $product_id, -$take, $undo_uuid );
      }
      if ( $untouched ) {
        $product = self::fresh( $product_id );
        $product->delete( false );
        return null;
      }
      return self::fresh( $product_id );
    }

    if ( 'stock_added' === $data['action'] ) {
      self::require_cap( Xophz_Compass_Bazaar_Security_Capabilities::MANAGE_STOCK );
      $take = self::undo_quantity( $product, $qty );
      if ( $take > 0 ) {
        self::add_stock( $product_id, -$take, $undo_uuid );
      }
      self::restore_price( $product_id, $data );
      return self::fresh( $product_id );
    }

    // updated: take back exactly the change the fix made, so sales since stay counted.
    self::restore_price( $product_id, $data );
    $delta = (float) ( $data['stock_delta'] ?? 0 );
    if ( ! empty( $data['stock_changed'] ) && 0.0 !== $delta ) {
      self::require_cap( Xophz_Compass_Bazaar_Security_Capabilities::MANAGE_STOCK );
      $back = $delta > 0 ? -self::undo_quantity( $product, $delta ) : -$delta;
      if ( 0.0 !== $back ) {
        self::add_stock( $product_id, $back, $undo_uuid );
      }
    }
    return self::fresh( $product_id );
  }

  /**
   * How much an undo takes back: the quantity added, but never more than is
   * on hand now (so an undo after a sale does not leave stock below 0).
   */
  private static function undo_quantity( WC_Product $product, float $qty ): float {
    if ( ! $product->managing_stock() || $qty <= 0 ) {
      return 0.0;
    }
    return min( $qty, max( 0.0, self::stock_number( $product ) ) );
  }

  /** Put the previous regular price back when this save changed it and nobody changed it since. */
  private static function restore_price( int $product_id, array $data ): void {
    if ( empty( $data['price_changed'] ) ) {
      return;
    }
    $product = self::fresh( $product_id );
    if ( null !== $data['new_price'] && ! self::same_price( (string) $product->get_regular_price(), (float) $data['new_price'] ) ) {
      return;
    }
    self::require_cap( Xophz_Compass_Bazaar_Security_Capabilities::MANAGE_CATALOG );
    $product->set_regular_price( (string) $data['prev_price'] );
    $product->save();
  }

  /** Store the undo data for 15 minutes and return its token. */
  private static function issue_undo( int $user_id, array $data ): string {
    $token = bin2hex( random_bytes( 16 ) );
    $data  = array_merge( $data, [ 'user_id' => $user_id, 'expires' => time() + self::UNDO_TTL ] );
    set_transient( self::undo_key( $token ), $data, self::UNDO_TTL );
    return $token;
  }

  /** Add fields to stored undo data, keeping its expiry. */
  private static function attach_to_undo( string $token, array $fields ): void {
    $key  = self::undo_key( $token );
    $data = get_transient( $key );
    if ( ! is_array( $data ) ) {
      return;
    }
    $left = max( 60, (int) ( $data['expires'] ?? 0 ) - time() );
    set_transient( $key, array_merge( $data, $fields ), $left );
  }

  private static function undo_key( string $token ): string {
    return 'bazaar_qa_undo_' . $token;
  }

  /* ------------------------------------------------------------------ */
  /* Stock path (same as Admin_Product_Stock)                            */
  /* ------------------------------------------------------------------ */

  /**
   * Add (or, with a negative delta, subtract) stock. Ledger mode: Stock_Service
   * adjust at the product's location with key adjust:{user}:{uuid}. Off and
   * shadow modes: an atomic wc_update_product_stock inside a Stock_Context.
   *
   * @throws Xophz_Compass_Bazaar_Core_Http_Error|InvalidArgumentException
   */
  private static function add_stock( int $product_id, float $delta, string $uuid ): void {
    $product = self::fresh( $product_id );
    if ( Xophz_Compass_Bazaar_Stock_Mode::is_ledger() ) {
      $location = Xophz_Compass_Bazaar_Admin_Product_Stock::location( $product_id );
      Xophz_Compass_Bazaar_Admin_Product_Stock::prepare_stock( [ (int) $product->get_stock_managed_by_id() ] );
      Xophz_Compass_Bazaar_Stock_Factory::service()->adjust(
        Xophz_Compass_Bazaar_Admin_Product_Stock::adjust_key( $uuid ),
        $product_id,
        $location,
        $delta,
        'adjust',
        Xophz_Compass_Bazaar_Admin_Product_Stock::meta()
      );
      return;
    }
    Xophz_Compass_Bazaar_Stock_Context::with(
      'adjust',
      function () use ( $product, $delta ) {
        wc_update_product_stock( $product, abs( $delta ), $delta >= 0 ? 'increase' : 'decrease' );
      },
      [ 'type' => 'adjustment' ]
    );
  }

  /**
   * Turn stock management on when it is off. Off and shadow modes also give a
   * NULL `_stock` a 0 so an increase has a number to add to (ledger mode does
   * that in prepare_stock). Returns true when the product needs a save.
   */
  private static function ensure_stock_managed( WC_Product $product ): bool {
    $dirty = false;
    if ( ! $product->get_manage_stock() ) {
      $product->set_manage_stock( true );
      $dirty = true;
    }
    if ( ! Xophz_Compass_Bazaar_Stock_Mode::is_ledger() && null === $product->get_stock_quantity() ) {
      $product->set_stock_quantity( 0 );
      $dirty = true;
    }
    return $dirty;
  }

  /* ------------------------------------------------------------------ */
  /* Log ("Items added today")                                           */
  /* ------------------------------------------------------------------ */

  /** @return array[] Oldest first. */
  private static function read_log( int $user_id ): array {
    $log = get_user_meta( $user_id, self::LOG_META, true );
    if ( ! is_array( $log ) ) {
      return [];
    }
    return array_values( array_filter( $log, function ( $entry ) {
      return is_array( $entry ) && isset( $entry['id'], $entry['product_id'], $entry['action'], $entry['at'] );
    } ) );
  }

  /** Keep entries from the last two days, at most LOG_MAX. */
  private static function write_log( int $user_id, array $log ): void {
    $cutoff = time() - 2 * DAY_IN_SECONDS;
    $log    = array_values( array_filter( $log, function ( $entry ) use ( $cutoff ) {
      return (int) $entry['at'] >= $cutoff;
    } ) );
    if ( count( $log ) > self::LOG_MAX ) {
      $log = array_slice( $log, -self::LOG_MAX );
    }
    update_user_meta( $user_id, self::LOG_META, $log );
  }

  /** Append an entry; returns its id. */
  private static function log( int $user_id, array $entry ): string {
    $id    = bin2hex( random_bytes( 8 ) );
    $log   = self::read_log( $user_id );
    $log[] = array_merge( $entry, [ 'id' => $id, 'at' => time(), 'undone' => false ] );
    self::write_log( $user_id, $log );
    return $id;
  }

  private static function mark_undone( int $user_id, string $id ): void {
    if ( '' === $id ) {
      return;
    }
    $log = self::read_log( $user_id );
    foreach ( $log as $i => $entry ) {
      if ( $entry['id'] === $id ) {
        $log[ $i ]['undone'] = true;
      }
    }
    self::write_log( $user_id, $log );
  }

  private static function replace_log_entry( int $user_id, string $id, array $before ): void {
    $log = self::read_log( $user_id );
    foreach ( $log as $i => $entry ) {
      if ( $entry['id'] === $id ) {
        $log[ $i ] = $before;
      }
    }
    self::write_log( $user_id, $log );
  }

  /**
   * After a Fix it: update the newest of today's add/create entries for the
   * product (its price, and its qty by the stock change the fix made).
   * Returns log_id and log_before, or null when there is no such entry.
   */
  private static function fix_log_entry( int $user_id, int $product_id, WC_Product $product, float $delta ): ?array {
    $log   = self::read_log( $user_id );
    $today = wp_date( 'Y-m-d' );
    for ( $i = count( $log ) - 1; $i >= 0; $i-- ) {
      $entry = $log[ $i ];
      if ( (int) $entry['product_id'] !== $product_id || ! empty( $entry['undone'] )
        || ! in_array( $entry['action'], [ 'created', 'stock_added' ], true )
        || wp_date( 'Y-m-d', (int) $entry['at'] ) !== $today ) {
        continue;
      }
      $before         = $entry;
      $entry['price'] = self::price_string( $product );
      $entry['title'] = $product->get_name();
      $entry['qty'] = max( 0.0, (float) $entry['qty'] + $delta );
      $log[ $i ] = $entry;
      self::write_log( $user_id, $log );
      return [ 'log_id' => (string) $entry['id'], 'log_before' => $before ];
    }
    return null;
  }

  /* ------------------------------------------------------------------ */
  /* Products                                                            */
  /* ------------------------------------------------------------------ */

  /** A product Quick Add may change: simple or variation, or any type that already manages its own stock. */
  private static function editable_product( int $product_id ): ?WC_Product {
    $product = wc_get_product( $product_id );
    if ( ! $product || 'trash' === $product->get_status() ) {
      return null;
    }
    if ( in_array( $product->get_type(), [ 'grouped', 'external' ], true ) ) {
      return null;
    }
    if ( ! in_array( $product->get_type(), [ 'simple', 'variation' ], true ) && ! $product->get_manage_stock() ) {
      return null;
    }
    return $product;
  }

  /** Why a product cannot be changed here: gone, or a kind Quick Add does not restock. */
  private static function unavailable( int $product_id ): array {
    $product = $product_id > 0 ? wc_get_product( $product_id ) : null;
    if ( ! $product || 'trash' === $product->get_status() ) {
      return self::failure( 'not_found', 'That item is not in the store any more.' );
    }
    return self::failure(
      'unsupported_product',
      'This item is set up in a way Quick Add cannot change. Ask Xopher, or use Product Inventory.'
    );
  }

  /** Remove a product this request just created (and the picture it sideloaded), for a create that failed partway. */
  private static function discard_new_product( int $product_id ): void {
    try {
      $image_id = (int) get_post_thumbnail_id( $product_id );
      if ( $image_id > 0 ) {
        wp_delete_attachment( $image_id, true );
      }
      $product = wc_get_product( $product_id );
      if ( $product ) {
        $product->delete( true );
      } else {
        wp_delete_post( $product_id, true );
      }
    } catch ( \Throwable $e ) {
      error_log( 'bazaar_quick_save: could not remove half-made product ' . $product_id . ': ' . $e->getMessage() );
    }
  }

  /** The product read again after a stock write (meta cache dropped). */
  private static function fresh( int $product_id ): WC_Product {
    wp_cache_delete( $product_id, 'post_meta' );
    $product = wc_get_product( $product_id );
    if ( ! $product ) {
      throw new RuntimeException( 'Product ' . $product_id . ' could not be read.' );
    }
    return $product;
  }

  /** {product_id, title, price, stock, thumb} */
  private static function product_view( WC_Product $product ): array {
    return [
      'product_id' => (int) $product->get_id(),
      'title'      => (string) $product->get_name(),
      'price'      => self::price_string( $product ),
      'stock'      => $product->managing_stock() ? self::number_out( self::stock_number( $product ) ) : null,
      'thumb'      => self::thumb( $product ),
    ];
  }

  private static function success( string $action, WC_Product $product, ?float $stock_before, string $token ): array {
    $view = self::product_view( $product );
    return [
      'success'      => true,
      'action'       => $action,
      'product'      => $view,
      'stock_before' => null === $stock_before ? null : self::number_out( $stock_before ),
      'stock_after'  => $view['stock'],
      'undo_token'   => $token,
    ];
  }

  private static function stock_number( WC_Product $product ): float {
    return (float) $product->get_stock_quantity();
  }

  /** The regular price with 2 decimals, else the active price, else ''. */
  private static function price_string( WC_Product $product ): string {
    $price = $product->get_regular_price();
    if ( '' === (string) $price ) {
      $price = $product->get_price();
    }
    return is_numeric( $price ) ? self::price_out( (float) $price ) : '';
  }

  private static function thumb( WC_Product $product ): string {
    $image_id = (int) $product->get_image_id();
    if ( ! $image_id && $product->get_parent_id() ) {
      $parent   = wc_get_product( $product->get_parent_id() );
      $image_id = $parent ? (int) $parent->get_image_id() : 0;
    }
    if ( ! $image_id ) {
      return '';
    }
    $url = wp_get_attachment_image_url( $image_id, 'woocommerce_thumbnail' );
    return is_string( $url ) ? $url : '';
  }

  /**
   * The SKU of a new item: the normalized code when its check digit is valid;
   * the digits of an all-digit code that is not (a typed code is kept as the
   * person read it); a house code with letters exactly as typed; else a
   * generated code.
   */
  private static function sku_for( array $barcode ): string {
    if ( $barcode['valid'] ) {
      return $barcode['normalized'];
    }
    $raw = (string) $barcode['raw'];
    if ( '' !== $barcode['digits'] && ! preg_match( '/[A-Za-z]/', $raw ) ) {
      return $barcode['digits'];
    }
    $house = (string) preg_replace( '/[^A-Za-z0-9._-]/', '', $raw );
    return '' !== $house ? $house : self::generated_sku();
  }

  /**
   * The SKU for an item with no barcode: prefix (option
   * bazaar_quick_add_sku_prefix, else the filter
   * bazaar_quick_add_default_sku_prefix, default 'QA-') and a per-site
   * counter, e.g. QA-00042. Skips numbers already used as SKUs.
   */
  private static function generated_sku(): string {
    $prefix = get_option( self::SKU_PREFIX_OPTION, '' );
    if ( ! is_string( $prefix ) || '' === trim( $prefix ) ) {
      $prefix = (string) apply_filters( 'bazaar_quick_add_default_sku_prefix', self::DEFAULT_SKU_PREFIX );
    }
    $prefix = strtoupper( (string) preg_replace( '/[^A-Za-z0-9-]/', '', $prefix ) );
    if ( '' === $prefix ) {
      $prefix = self::DEFAULT_SKU_PREFIX;
    }
    for ( $i = 0; $i < 50; $i++ ) {
      $sku = $prefix . str_pad( (string) self::next_counter(), 5, '0', STR_PAD_LEFT );
      if ( ! wc_get_product_id_by_sku( $sku ) ) {
        return $sku;
      }
    }
    return $prefix . strtoupper( wp_generate_password( 8, false ) );
  }

  /** Increment the per-site counter in one UPDATE and read it back. */
  private static function next_counter(): int {
    global $wpdb;
    add_option( self::SKU_COUNTER_OPTION, '0', '', 'no' );
    $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = CAST(option_value AS UNSIGNED) + 1 WHERE option_name = %s", self::SKU_COUNTER_OPTION ) );
    wp_cache_delete( self::SKU_COUNTER_OPTION, 'options' );
    wp_cache_delete( 'alloptions', 'options' );
    return (int) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::SKU_COUNTER_OPTION ) );
  }

  /** The URL when it is https on an allowlisted image host (filter bazaar_quick_add_image_hosts), else null. */
  private static function allowed_image_url( $url ): ?string {
    if ( ! is_string( $url ) || '' === trim( $url ) ) {
      return null;
    }
    $parts = wp_parse_url( trim( $url ) );
    if ( ! is_array( $parts ) || 'https' !== strtolower( (string) ( $parts['scheme'] ?? '' ) )
      || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['port'] ) ) {
      return null;
    }
    $hosts = (array) apply_filters( 'bazaar_quick_add_image_hosts', self::IMAGE_HOSTS );
    if ( ! in_array( strtolower( (string) ( $parts['host'] ?? '' ) ), $hosts, true ) ) {
      return null;
    }
    return esc_url_raw( trim( $url ), [ 'https' ] );
  }

  /**
   * The pictures a create may try, the one asked for first: it must be from
   * this user's last lookup of the code or on an Open*Facts host. No picture
   * asked for (the suggestion was turned down) means none.
   *
   * @return string[]
   */
  private static function images_to_try( $asked, array $barcode, int $user_id ): array {
    $url = Xophz_Compass_Bazaar_Barcode_Image::accept_url( $asked );
    if ( '' === $url ) {
      return [];
    }
    $found = Xophz_Compass_Bazaar_Barcode_Image::candidates( $user_id, $barcode['normalized'] );
    if ( ! in_array( $url, $found, true ) && null === self::allowed_image_url( $url ) ) {
      return [];
    }
    return array_values( array_unique( array_merge( [ $url ], $found ) ) );
  }

  /** Attach the first picture that works, within IMAGE_BUDGET seconds. Returns its URL, '' when none did. */
  private static function attach_first_image( int $product_id, array $urls, string $title ): string {
    $deadline = time() + self::IMAGE_BUDGET;
    foreach ( $urls as $url ) {
      if ( time() >= $deadline ) {
        return '';
      }
      if ( self::sideload_image( $product_id, $url, $title ) ) {
        return $url;
      }
    }
    return '';
  }

  /** Fetch the picture and make it the product image. False leaves the product without one. */
  private static function sideload_image( int $product_id, string $url, string $title ): bool {
    $attachment_id = Xophz_Compass_Bazaar_Barcode_Image::sideload( $product_id, $url, $title );
    if ( $attachment_id < 1 ) {
      return false;
    }
    $product = self::fresh( $product_id );
    $product->set_image_id( $attachment_id );
    $product->save();
    return true;
  }

  /* ------------------------------------------------------------------ */
  /* Input                                                               */
  /* ------------------------------------------------------------------ */

  /** A price from 0.01 to 100000 with at most 2 decimals, else null. */
  public static function parse_price( $value ): ?float {
    if ( is_int( $value ) || is_float( $value ) ) {
      $value = (string) $value;
    }
    if ( ! is_string( $value ) ) {
      return null;
    }
    $value = trim( str_replace( [ '$', ',' ], '', $value ) );
    if ( ! preg_match( '/^\d{1,6}(\.\d{1,2})?$/', $value ) && ! preg_match( '/^\d{0,6}\.\d{1,2}$/', $value ) ) {
      return null;
    }
    $price = round( (float) $value, 2 );
    return ( $price >= self::PRICE_MIN && $price <= self::PRICE_MAX ) ? $price : null;
  }

  /** A whole number from $min to $max, else null. */
  public static function parse_quantity( $value, int $min, int $max ): ?float {
    if ( is_string( $value ) && preg_match( '/^\s*\d+\s*$/', $value ) ) {
      $value = (int) trim( $value );
    }
    if ( is_float( $value ) && floor( $value ) === $value ) {
      $value = (int) $value;
    }
    if ( ! is_int( $value ) || $value < $min || $value > $max ) {
      return null;
    }
    return (float) $value;
  }

  private static function parse_title( $value ): string {
    if ( ! is_scalar( $value ) ) {
      return '';
    }
    $title = trim( sanitize_text_field( (string) $value ) );
    return function_exists( 'mb_substr' ) ? mb_substr( $title, 0, self::TITLE_MAX ) : substr( $title, 0, self::TITLE_MAX );
  }

  private static function confirmed( $args ): bool {
    $value = $args->price_confirmed ?? false;
    return true === $value || 'true' === $value || '1' === $value || 1 === $value;
  }

  private static function same_price( string $current, float $price ): bool {
    return is_numeric( $current ) && abs( (float) $current - $price ) < 0.005;
  }

  private static function price_out( float $price ): string {
    return number_format( $price, 2, '.', '' );
  }

  /** An int when whole, else the float. */
  private static function number_out( float $value ) {
    return abs( $value - round( $value ) ) < 0.00005 ? (int) round( $value ) : $value;
  }

  /* ------------------------------------------------------------------ */
  /* Guard, lock, output                                                 */
  /* ------------------------------------------------------------------ */

  /**
   * The action's Bazaar caps from policy() and the wp_rest nonce. Ends the
   * request on a refusal. No WooCommerce cap is asked for: LEGACY already
   * gives manage_woocommerce and edit_products these caps, and asking would
   * refuse the bazaar_stock_clerk role the policy allows.
   */
  private function authorize( string $action ): void {
    $bazaar_caps = self::policy()[ $action ] ?? [];
    if ( [] === $bazaar_caps ) {
      wp_send_json( self::failure( 'forbidden', 'You do not have permission to do this.' ), 403 );
    }
    Xophz_Compass_Security::enforce_spa_ajax( ...$bazaar_caps );
  }

  /** @throws Xophz_Compass_Bazaar_Core_Http_Error 403 forbidden */
  private static function require_cap( string $cap ): void {
    if ( ! Xophz_Compass_Bazaar_Security_Guard::can_any( $cap ) ) {
      throw new Xophz_Compass_Bazaar_Core_Http_Error( 'forbidden', 'You do not have permission to do this.', 403 );
    }
  }

  /** The key one save (user + client_uuid) is stored and locked under; also a pile row's claim_key. */
  public static function save_hash( int $user_id, string $uuid ): string {
    return md5( $user_id . '|' . $uuid );
  }

  /**
   * What is known about the save with this hash: running (its lock row is
   * still there, so it may still write stock) and the product it saved
   * (from its stored success; 0 when there is none).
   *
   * @return array{running:bool,product_id:int}
   */
  public static function save_state( string $hash ): array {
    global $wpdb;
    $lock    = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::LOCK_PREFIX . $hash ) );
    $stored  = get_transient( self::IDEM_PREFIX . $hash );
    $product = 0;
    if ( is_array( $stored ) && ! empty( $stored['success'] ) && in_array( $stored['action'] ?? '', [ 'created', 'stock_added' ], true ) ) {
      $product = (int) ( $stored['product']['product_id'] ?? 0 );
    }
    return [ 'running' => null !== $lock, 'product_id' => $product ];
  }

  /**
   * A stored answer read past this request's option cache. WordPress remembers
   * a transient miss for the rest of the request, so a plain get_transient
   * after the first miss would not see what a twin request stored since.
   *
   * @return mixed The stored value, false when there is none.
   */
  public static function stored_answer( string $key ) {
    wp_cache_delete( '_transient_' . $key, 'options' );
    wp_cache_delete( '_transient_timeout_' . $key, 'options' );
    wp_cache_delete( 'notoptions', 'options' );
    return get_transient( $key );
  }

  /**
   * A short-lived lock row in wp_options (INSERT IGNORE is atomic on the
   * unique option_name). A lock older than LOCK_STALE_AFTER seconds is
   * taken over (its request died).
   */
  public static function acquire_lock( string $name ): bool {
    global $wpdb;
    $insert = function () use ( $wpdb, $name ) {
      return (int) $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $name, (string) time() ) );
    };
    if ( $insert() > 0 ) {
      return true;
    }
    $since = (int) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );
    if ( $since > 0 && time() - $since < self::LOCK_STALE_AFTER ) {
      return false;
    }
    $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $name, (string) $since ) );
    return $insert() > 0;
  }

  public static function release_lock( string $name ): void {
    global $wpdb;
    $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", $name ) );
    wp_cache_delete( $name, 'options' );
  }

  public static function failure( string $code, string $message ): array {
    return [ 'success' => false, 'code' => $code, 'message' => $message ];
  }

  /** Clean output buffers and send the JSON body (ends the request). */
  public static function respond( array $payload ): void {
    while ( ob_get_level() > 0 ) {
      @ob_end_clean(); // phpcs:ignore WordPress.PHP.NoSilencedErrors
    }
    Xophz_Compass::output_json( $payload );
  }
}
