<?php
/**
 * Quick Add "Scan a pile": the pile's four admin-ajax actions.
 *
 *   bazaar_pile_add      {client_uuid, barcode}                    -> item, created
 *   bazaar_pile_list     {}                                        -> items (open, oldest first), open_count
 *   bazaar_pile_set_qty  {pile_id, qty 0..10000, client_uuid}      -> item|null (qty 0 removes)
 *   bazaar_pile_resolve  {pile_id, product_id|null, title, thumb}  -> item
 *
 * PileItem = {pile_id, barcode, normalized, valid, qty, title, thumb,
 * product_id|null, status 'open'|'done', scanned_by, created_at, updated_at}.
 * Errors are {success:false, code, message}, as in the bazaar_quick_* actions.
 *
 * Guarantees:
 * - The pile is per site and shared by everyone with Quick Add permission.
 * - A scan is counted atomically (see Quick_Pile_Store::add_scan) and at most
 *   once per user and client_uuid within 7 days (scans can wait on a phone
 *   that long; the contract's minimum is 10 minutes): a retried add returns
 *   the first answer (with the row as it is now) and does not count again.
 * - A scan never waits on a product lookup and never writes a product, a
 *   price or stock. Only bazaar_quick_save with a pile_id finishes a row.
 * - resolve stores the lookup result for display only (title at most 200
 *   characters, thumb https or ''); saves never read it.
 * - All four actions are mapped for the Bazaar Security_Guard through
 *   policy() (see Security_Guard::MODULE_POLICIES).
 *
 * @package    Xophz_Compass_Bazaar
 * @subpackage Xophz_Compass_Bazaar/admin
 */
class Xophz_Compass_Bazaar_Admin_Quick_Pile {

  /** @var string */
  private $plugin_name;

  /** @var string */
  private $version;

  public $action_hooks = [
    'wp_ajax_bazaar_pile_add'     => 'pileAdd',
    'wp_ajax_bazaar_pile_list'    => 'pileList',
    'wp_ajax_bazaar_pile_set_qty' => 'pileSetQty',
    'wp_ajax_bazaar_pile_resolve' => 'pileResolve',
  ];

  /** A scan kept on a phone (localStorage) may be resent long after; it must still count once. */
  const IDEMPOTENCY_TTL = 604800; // 7 days (a literal: unit tests load this class without WordPress).

  /**
   * The Bazaar caps each action needs (any one of them), the same as Quick
   * Add's lookup. Read by Security_Guard::caps_for and by authorize().
   *
   * @return array<string,string[]> action (without wp_ajax_) => caps
   */
  public static function policy(): array {
    $c    = 'Xophz_Compass_Bazaar_Security_Capabilities';
    $caps = [ $c::MANAGE_CATALOG, $c::MANAGE_STOCK, $c::RECEIVE ];
    return [
      'bazaar_pile_add'     => $caps,
      'bazaar_pile_list'    => $caps,
      'bazaar_pile_set_qty' => $caps,
      'bazaar_pile_resolve' => $caps,
    ];
  }

  public function __construct( $plugin_name, $version ) {
    $this->plugin_name = $plugin_name;
    $this->version     = $version;
  }

  /* ------------------------------------------------------------------ */
  /* Actions                                                             */
  /* ------------------------------------------------------------------ */

  /** AJAX bazaar_pile_add. */
  public function pileAdd() {
    $this->authorize( 'bazaar_pile_add' );
    $args = Xophz_Compass::get_input_json();
    $uuid = Xophz_Compass_Bazaar_Quick_Pile_Store::parse_uuid( $args->client_uuid ?? null );
    if ( '' === $uuid ) {
      self::respond( self::failure( 'invalid_request', 'client_uuid is required (1 to 64 letters, digits, _ or -).' ) );
    }
    $user_id  = (int) get_current_user_id();
    $idem_key = 'bazaar_qp_idem_' . md5( $user_id . '|' . $uuid );
    $stored   = get_transient( $idem_key );
    if ( is_array( $stored ) ) {
      self::respond( self::replay( $stored ) );
    }
    $lock = 'bazaar_qp_lock_' . md5( $user_id . '|' . $uuid );
    if ( ! Xophz_Compass_Bazaar_Admin_Quick_Add::acquire_lock( $lock ) ) {
      self::respond( self::failure( 'in_progress', 'Still saving. Try again in a moment.' ) );
    }
    // Read again under the lock: the twin request may have finished in between.
    $stored = Xophz_Compass_Bazaar_Admin_Quick_Add::stored_answer( $idem_key );
    if ( is_array( $stored ) ) {
      Xophz_Compass_Bazaar_Admin_Quick_Add::release_lock( $lock );
      self::respond( self::replay( $stored ) );
    }
    try {
      $response = $this->add( $args->barcode ?? null, $user_id );
    } catch ( \Throwable $e ) {
      error_log( 'bazaar_pile_add: ' . $e->getMessage() );
      $response = self::failure( 'failed', 'That scan did not save. Scan it again.' );
    }
    if ( ! empty( $response['success'] ) ) {
      set_transient( $idem_key, $response, self::IDEMPOTENCY_TTL );
    }
    Xophz_Compass_Bazaar_Admin_Quick_Add::release_lock( $lock );
    self::respond( $response );
  }

  /** AJAX bazaar_pile_list. */
  public function pileList() {
    $this->authorize( 'bazaar_pile_list' );
    try {
      $response = [ 'success' => true ] + Xophz_Compass_Bazaar_Quick_Pile_Store::open_items();
    } catch ( \Throwable $e ) {
      error_log( 'bazaar_pile_list: ' . $e->getMessage() );
      $response = self::failure( 'failed', 'That did not work. Try again.' );
    }
    self::respond( $response );
  }

  /** AJAX bazaar_pile_set_qty. */
  public function pileSetQty() {
    $this->authorize( 'bazaar_pile_set_qty' );
    $args = Xophz_Compass::get_input_json();
    $id   = Xophz_Compass_Bazaar_Quick_Pile_Store::parse_id( $args->pile_id ?? null );
    $qty  = Xophz_Compass_Bazaar_Quick_Pile_Store::parse_qty( $args->qty ?? null );
    if ( '' === Xophz_Compass_Bazaar_Quick_Pile_Store::parse_uuid( $args->client_uuid ?? null ) ) {
      self::respond( self::failure( 'invalid_request', 'client_uuid is required (1 to 64 letters, digits, _ or -).' ) );
    }
    if ( $id <= 0 ) {
      self::respond( self::failure( 'invalid_request', 'pile_id is required.' ) );
    }
    if ( null === $qty ) {
      self::respond( self::failure( 'invalid_quantity', 'The count must be a whole number from 0 to 10,000.' ) );
    }
    try {
      $state = Xophz_Compass_Bazaar_Quick_Pile_Store::set_qty( $id, $qty );
      if ( 'removed' === $state ) {
        $response = [ 'success' => true, 'item' => null ];
      } elseif ( 'missing' === $state ) {
        $response = self::failure( 'not_found', 'That item is not in the pile any more.' );
      } elseif ( 'done' === $state ) {
        $response = self::failure( 'pile_done', 'That item was already put on the shelf.' );
      } else {
        $response = [ 'success' => true, 'item' => Xophz_Compass_Bazaar_Quick_Pile_Store::item( $id ) ];
      }
    } catch ( \Throwable $e ) {
      error_log( 'bazaar_pile_set_qty: ' . $e->getMessage() );
      $response = self::failure( 'failed', 'That did not change. Try again.' );
    }
    self::respond( $response );
  }

  /** AJAX bazaar_pile_resolve. */
  public function pileResolve() {
    $this->authorize( 'bazaar_pile_resolve' );
    $args = Xophz_Compass::get_input_json();
    $id   = Xophz_Compass_Bazaar_Quick_Pile_Store::parse_id( $args->pile_id ?? null );
    if ( $id <= 0 ) {
      self::respond( self::failure( 'invalid_request', 'pile_id is required.' ) );
    }
    $raw_pid    = $args->product_id ?? null;
    $product_id = null === $raw_pid ? null : Xophz_Compass_Bazaar_Quick_Pile_Store::parse_id( $raw_pid );
    if ( 0 === $product_id ) {
      self::respond( self::failure( 'invalid_request', 'product_id must be a number or null.' ) );
    }
    $title = is_scalar( $args->title ?? null ) ? Xophz_Compass_Bazaar_Quick_Pile_Store::clip_title( sanitize_text_field( (string) $args->title ) ) : '';
    $thumb = Xophz_Compass_Bazaar_Quick_Pile_Store::clean_thumb( $args->thumb ?? null );
    if ( '' !== $thumb ) {
      // esc_url_raw may encode characters and grow the URL: check it again.
      $thumb = Xophz_Compass_Bazaar_Quick_Pile_Store::clean_thumb( esc_url_raw( $thumb, [ 'https' ] ) );
    }
    try {
      $response = Xophz_Compass_Bazaar_Quick_Pile_Store::resolve( $id, $product_id, $title, $thumb )
        ? [ 'success' => true, 'item' => Xophz_Compass_Bazaar_Quick_Pile_Store::item( $id ) ]
        : self::failure( 'not_found', 'That item is not in the pile any more.' );
    } catch ( \Throwable $e ) {
      error_log( 'bazaar_pile_resolve: ' . $e->getMessage() );
      $response = self::failure( 'failed', 'That did not work. Try again.' );
    }
    self::respond( $response );
  }

  /* ------------------------------------------------------------------ */
  /* Helpers                                                             */
  /* ------------------------------------------------------------------ */

  /** Count one scan and answer {success, item, created}. */
  private function add( $raw, int $user_id ): array {
    $code = Xophz_Compass_Bazaar_Barcode::normalize( is_scalar( $raw ) ? sanitize_text_field( (string) $raw ) : '' );
    $key  = Xophz_Compass_Bazaar_Quick_Pile_Store::key_for( $code );
    if ( '' === $key ) {
      return self::failure( 'invalid_barcode', 'Scan or type the numbers under the bars.' );
    }
    $added = Xophz_Compass_Bazaar_Quick_Pile_Store::add_scan( $code, $key, $user_id );
    $item  = $added ? Xophz_Compass_Bazaar_Quick_Pile_Store::item( $added['id'] ) : null;
    if ( ! $added || ! $item ) {
      return self::failure( 'failed', 'That scan did not save. Scan it again.' );
    }
    return [ 'success' => true, 'item' => $item, 'created' => $added['created'] ];
  }

  /** A stored add answer with the row as it is now (the stored copy when the row is gone). */
  private static function replay( array $stored ): array {
    $id = (int) ( $stored['item']['pile_id'] ?? 0 );
    if ( $id > 0 ) {
      $now = Xophz_Compass_Bazaar_Quick_Pile_Store::item( $id );
      if ( $now ) {
        $stored['item'] = $now;
      }
    }
    return $stored;
  }

  /** Same as Quick Add: this action's Bazaar caps and the wp_rest nonce, no WooCommerce cap. Ends the request on a refusal. */
  private function authorize( string $action ): void {
    $bazaar_caps = self::policy()[ $action ] ?? [];
    if ( [] === $bazaar_caps ) {
      wp_send_json( self::failure( 'forbidden', 'You do not have permission to do this.' ), 403 );
    }
    Xophz_Compass_Security::enforce_spa_ajax( ...$bazaar_caps );
  }

  private static function failure( string $code, string $message ): array {
    return Xophz_Compass_Bazaar_Admin_Quick_Add::failure( $code, $message );
  }

  private static function respond( array $payload ): void {
    Xophz_Compass_Bazaar_Admin_Quick_Add::respond( $payload );
  }
}
