<?php
/**
 * bazaar/v1 stock routes (spec 14.1): movements, balances, adjustments and
 * the ledger mode.
 *
 * Every route uses Security_Guard::rest_permission and Core_Rest::handle.
 *
 * Guarantees:
 * - POST /stock/adjustments in ledger mode goes through Stock_Service with the
 *   key adjust:{acting user}:{client_key}: a retry with the same body replays
 *   the stored batch, the same key with another body is 422
 *   idempotency_key_reused, a short balance is 409 insufficient_stock and a
 *   stale count is 409 stock_changed. The note is stored for add and
 *   subtract; set_count has no note field, so a count's note is not stored.
 * - In off and shadow modes it changes WooCommerce `_stock` with
 *   wc_update_product_stock inside a Stock_Context, at location 0 only and
 *   only for products that are not location-tracked. There, client_key is
 *   not enforced (a retry applies again), a subtract may go below zero, and
 *   a count's expected check is not atomic with the write.
 * - GET /stock/balances initializes the ledger for the product on first
 *   touch in shadow and ledger modes; in off mode it only reads.
 *
 * @package Xophz_Compass_Bazaar
 */
class Xophz_Compass_Bazaar_Rest_Stock {

  const NAMESPACE      = 'bazaar/v1';
  const DEFAULT_LIMIT  = 50;
  const MAX_LIMIT      = 200;
  const OPERATIONS     = [ 'add', 'subtract', 'count' ];
  const ADJUST_REASONS = [ 'adjust', 'count' ];

  public function register_routes(): void {
    $caps  = 'Xophz_Compass_Bazaar_Security_Capabilities';
    $guard = 'Xophz_Compass_Bazaar_Security_Guard';
    $routes = [
      '/stock/movements'                       => [ 'GET', 'movements', [ $caps::MANAGE_STOCK, $caps::VIEW_REPORTS ] ],
      '/stock/balances/(?P<product_id>[0-9]+)' => [ 'GET', 'balances', [ $caps::SELL, $caps::MANAGE_STOCK ] ],
      '/stock/adjustments'                     => [ 'POST', 'adjust', [ $caps::MANAGE_STOCK ] ],
      '/stock/mode'                            => [ 'POST', 'mode', [ $caps::MANAGE_SETTINGS ] ],
    ];
    foreach ( $routes as $route => $spec ) {
      list( $method, $handler, $needs ) = $spec;
      register_rest_route(
        self::NAMESPACE,
        $route,
        [
          'methods'             => $method,
          'callback'            => function ( WP_REST_Request $request ) use ( $handler ) {
            return Xophz_Compass_Bazaar_Core_Rest::handle(
              function () use ( $handler, $request ) {
                return $this->$handler( $request );
              }
            );
          },
          'permission_callback' => $guard::rest_permission( ...$needs ),
        ]
      );
    }
  }

  /** GET /stock/movements?product=&location=&reason=&since=&cursor=&limit= (newest first; cursor = the last id seen). */
  public function movements( WP_REST_Request $request ): array {
    $errors  = [];
    $product = self::int_param( $request->get_param( 'product' ), 'product', 1, $errors );
    $loc     = self::int_param( $request->get_param( 'location' ), 'location', 0, $errors );
    $cursor  = self::int_param( $request->get_param( 'cursor' ), 'cursor', 1, $errors );
    $limit   = self::int_param( $request->get_param( 'limit' ), 'limit', 1, $errors ) ?? self::DEFAULT_LIMIT;
    $reason  = $request->get_param( 'reason' );
    $since   = $request->get_param( 'since' );
    if ( null !== $reason && ! in_array( $reason, Xophz_Compass_Bazaar_Stock_Batch::REASONS, true ) ) {
      $errors['reason'] = 'must be a stock reason';
    }
    if ( null !== $since ) {
      $time  = is_string( $since ) ? strtotime( $since ) : false;
      $since = false === $time ? null : gmdate( 'Y-m-d H:i:s', $time );
      if ( null === $since ) {
        $errors['since'] = "must be a date, for example 'Y-m-d H:i:s' in UTC or ISO 8601";
      }
    }
    self::refuse( $errors );
    $limit = min( self::MAX_LIMIT, $limit );

    $where = [ '1 = 1' ];
    $args  = [];
    if ( null !== $cursor ) {
      $where[] = 'm.id < %d';
      $args[]  = $cursor;
    }
    if ( null !== $product ) {
      $where[] = '(m.stock_product_id = %d OR m.product_id = %d OR m.variation_id = %d)';
      array_push( $args, $product, $product, $product );
    }
    if ( null !== $loc ) {
      $where[] = 'm.location_id = %d';
      $args[]  = $loc;
    }
    if ( null !== $reason ) {
      $where[] = 'b.reason = %s';
      $args[]  = $reason;
    }
    if ( null !== $since ) {
      $where[] = 'm.created_at >= %s';
      $args[]  = $since;
    }
    $args[] = $limit + 1;
    $rows   = Xophz_Compass_Bazaar_Core_Db::get_results(
      'SELECT m.id, m.batch_id, m.line_no, m.stock_product_id, m.product_id, m.variation_id, m.location_id, m.qty_delta, m.balance_after,'
        . ' m.oversold, m.order_id, m.order_item_id, m.created_at, b.reason, b.source_type, b.source_id, b.actor_user_id, b.cashier_user_id,'
        . ' b.note, b.occurred_at FROM ' . Xophz_Compass_Bazaar_Core_Db::table( 'stock_movements' ) . ' m JOIN '
        . Xophz_Compass_Bazaar_Core_Db::table( 'stock_batches' ) . ' b ON b.id = m.batch_id WHERE ' . implode( ' AND ', $where )
        . ' ORDER BY m.id DESC LIMIT %d',
      $args
    );
    $more  = count( $rows ) > $limit;
    $items = array_map( [ self::class, 'movement_out' ], array_slice( $rows, 0, $limit ) );
    return [ 'items' => $items, 'next_cursor' => $more && $items ? $items[ count( $items ) - 1 ]['id'] : null ];
  }

  /** GET /stock/balances/{product_id} */
  public function balances( WP_REST_Request $request ): array {
    $info = self::stock_product( (int) $request->get_param( 'product_id' ) );
    $key  = (int) $info['stock_product_id'];
    $mode = Xophz_Compass_Bazaar_Stock_Mode::current();
    if ( Xophz_Compass_Bazaar_Stock_Mode::OFF === $mode ) {
      $balances = ( new Xophz_Compass_Bazaar_Stock_Store_Wpdb() )->balances( $key );
    } else {
      Xophz_Compass_Bazaar_Stock_Factory::port()->ensure_stock_rows( [ $key ] );
      $balances = Xophz_Compass_Bazaar_Stock_Factory::service()->balances( $key );
    }
    return self::state( (int) $request->get_param( 'product_id' ), $info, $mode, $balances );
  }

  /** POST /stock/adjustments */
  public function adjust( WP_REST_Request $request ): array {
    $in   = self::adjustment_input( $request );
    $info = self::stock_product( $in['product_id'] );
    $mode = Xophz_Compass_Bazaar_Stock_Mode::current();
    if ( Xophz_Compass_Bazaar_Stock_Mode::LEDGER !== $mode ) {
      return self::adjust_legacy( $in, $info, $mode );
    }
    if ( $in['location_id'] > 0 && ! isset( Xophz_Compass_Bazaar_Stock_Locations::inventory_entries( (int) $info['stock_product_id'] )[ $in['location_id'] ] ) ) {
      throw new Xophz_Compass_Bazaar_Core_Http_Error( 'unsupported_field', 'location_id must be 0 or one of the product\'s multi-inventory locations.', 422 );
    }
    $ctx  = Xophz_Compass_Bazaar_Security_Guard::context();
    $meta = new Xophz_Compass_Bazaar_Stock_Meta(
      [
        'actor_user_id'    => $ctx->operator_user_id,
        'cashier_user_id'  => $ctx->acting_user_id,
        'staff_session_id' => $ctx->staff_session_id > 0 ? $ctx->staff_session_id : null,
        'device_id'        => $ctx->device_id,
      ]
    );
    $key     = Xophz_Compass_Bazaar_Stock_Keys::adjust( $ctx->acting_user_id, $in['client_key'] );
    $service = Xophz_Compass_Bazaar_Stock_Factory::service();
    Xophz_Compass_Bazaar_Stock_Factory::port()->ensure_stock_rows( [ (int) $info['stock_product_id'] ] );
    if ( 'count' === $in['operation'] ) {
      $result = $service->set_count( $key, $in['product_id'], $in['location_id'], $in['quantity'], $in['expected'], $in['reason'], $meta );
    } else {
      $delta  = 'add' === $in['operation'] ? $in['quantity'] : -$in['quantity'];
      $line   = new Xophz_Compass_Bazaar_Stock_Line( $in['product_id'], $in['location_id'], $delta );
      $result = $service->apply( new Xophz_Compass_Bazaar_Stock_Batch( $key, $in['reason'], [ $line ], $meta, [ 'note' => $in['note'] ] ) );
    }
    $out = self::state( $in['product_id'], $info, $mode, ( new Xophz_Compass_Bazaar_Stock_Store_Wpdb() )->balances( (int) $info['stock_product_id'] ) );
    return array_merge( $out, [ 'operation' => $in['operation'], 'location_id' => $in['location_id'] ], $result->to_array() );
  }

  /** POST /stock/mode {mode, force_with_drift?, reason?} */
  public function mode( WP_REST_Request $request ): array {
    $body = Xophz_Compass_Bazaar_Core_Rest::json_body( $request );
    $mode = $body['mode'] ?? null;
    if ( ! is_string( $mode ) ) {
      self::refuse( [ 'mode' => 'must be off, shadow or ledger' ] );
    }
    $reason = $body['reason'] ?? '';
    if ( ! is_string( $reason ) ) {
      self::refuse( [ 'reason' => 'must be a string' ] );
    }
    return Xophz_Compass_Bazaar_Stock_Mode::set(
      $mode,
      [
        'force_with_drift' => self::bool_value( $body['force_with_drift'] ?? false ),
        'reason'           => $reason,
        'actor'            => Xophz_Compass_Bazaar_Security_Guard::context()->acting_user_id,
      ]
    );
  }

  /** Off and shadow modes: WooCommerce stock only, inside a Stock_Context. */
  private static function adjust_legacy( array $in, array $info, string $mode ): array {
    if ( 0 !== $in['location_id'] || ! empty( $info['location_tracked'] ) ) {
      throw new Xophz_Compass_Bazaar_Core_Http_Error( 'unsupported_field', 'In off and shadow modes only location 0 of a product that is not location-tracked can be adjusted here.', 422 );
    }
    $key     = (int) $info['stock_product_id'];
    $product = wc_get_product( $key );
    $current = self::wc_stock( $key );
    if ( 'count' === $in['operation'] && null !== $in['expected'] && Xophz_Compass_Bazaar_Stock_Planner::differs( $current, $in['expected'] ) ) {
      throw Xophz_Compass_Bazaar_Stock_Conflict_Exception::stock_changed( $current );
    }
    $operations = [ 'add' => 'increase', 'subtract' => 'decrease', 'count' => 'set' ];
    Xophz_Compass_Bazaar_Stock_Context::with(
      $in['reason'],
      function () use ( $product, $in, $operations ) {
        wc_update_product_stock( $product, $in['quantity'], $operations[ $in['operation'] ] );
      },
      [ 'type' => 'adjustment' ]
    );
    $balances = ( new Xophz_Compass_Bazaar_Stock_Store_Wpdb() )->balances( $key );
    return array_merge( self::state( $in['product_id'], $info, $mode, $balances ), [ 'operation' => $in['operation'], 'location_id' => 0, 'ledger' => false, 'replay_protected' => false ] );
  }

  /** @return array client_key, product_id, location_id, operation, quantity, expected, reason, note */
  private static function adjustment_input( WP_REST_Request $request ): array {
    $body   = Xophz_Compass_Bazaar_Core_Rest::json_body( $request );
    $errors = [];
    $key    = $body['client_key'] ?? $request->get_header( 'idempotency_key' );
    if ( ! is_string( $key ) || ! preg_match( '/^[A-Za-z0-9_-]{1,64}$/', $key ) ) {
      $errors['client_key'] = 'must be 1 to 64 characters of letters, digits, _ or - (a uuid fits)';
    }
    $product   = self::int_param( $body['product_id'] ?? null, 'product_id', 1, $errors );
    $location  = self::int_param( $body['location_id'] ?? null, 'location_id', 0, $errors ) ?? 0;
    $operation = $body['operation'] ?? null;
    if ( ! in_array( $operation, self::OPERATIONS, true ) ) {
      $errors['operation'] = 'must be add, subtract or count';
    }
    $quantity = self::qty_param( $body['quantity'] ?? null, 'quantity', $errors );
    $expected = array_key_exists( 'expected', $body ) && null !== $body['expected'] ? self::qty_param( $body['expected'], 'expected', $errors ) : null;
    if ( null === $product && ! isset( $errors['product_id'] ) ) {
      $errors['product_id'] = 'is required';
    }
    if ( null === $quantity && ! isset( $errors['quantity'] ) ) {
      $errors['quantity'] = 'is required';
    }
    if ( null !== $quantity && 'count' !== $operation && ! ( $quantity > 0 ) ) {
      $errors['quantity'] = 'must be more than 0 for add and subtract';
    }
    if ( null !== $expected && 'count' !== $operation ) {
      $errors['expected'] = 'is only used with count';
    }
    $reason = $body['reason'] ?? ( 'count' === $operation ? 'count' : 'adjust' );
    if ( ! in_array( $reason, self::ADJUST_REASONS, true ) ) {
      $errors['reason'] = 'must be adjust or count';
    }
    $note = $body['note'] ?? '';
    if ( ! is_string( $note ) || strlen( $note ) > 255 ) {
      $errors['note'] = 'must be a string of 255 characters or fewer';
    }
    self::refuse( $errors );
    return [
      'client_key'  => $key,
      'product_id'  => $product,
      'location_id' => $location,
      'operation'   => $operation,
      'quantity'    => $quantity,
      'expected'    => $expected,
      'reason'      => $reason,
      'note'        => sanitize_text_field( $note ),
    ];
  }

  /** Product info for a product that manages stock, else 422 invalid_line. */
  private static function stock_product( int $id ): array {
    $info = $id > 0 ? Xophz_Compass_Bazaar_Stock_Factory::port()->product_info( $id ) : null;
    if ( ! $info || empty( $info['manages_stock'] ) ) {
      throw new Xophz_Compass_Bazaar_Core_Http_Error( 'invalid_line', 'This product does not exist or does not manage stock.', 422, [ 'product_id' => $id ] );
    }
    return $info;
  }

  private static function state( int $product_id, array $info, string $mode, array $balances ): array {
    $key  = (int) $info['stock_product_id'];
    $rows = [];
    $sum  = 0.0;
    foreach ( $balances as $loc => $row ) {
      $rows[] = [ 'location_id' => (int) $loc, 'on_hand' => (float) $row['on_hand'], 'is_enabled' => (bool) $row['is_enabled'] ];
      $sum   += ( 0 === (int) $loc || $row['is_enabled'] ) ? (float) $row['on_hand'] : 0.0;
    }
    return [
      'product_id'       => $product_id,
      'stock_product_id' => $key,
      'mode'             => $mode,
      'initialized'      => (bool) $rows,
      'location_tracked' => (bool) $info['location_tracked'],
      'wc_stock'         => self::wc_stock( $key ),
      'projectable'      => $rows ? Xophz_Compass_Bazaar_Stock_Line::qty( $sum ) : null,
      'balances'         => $rows,
    ];
  }

  /** The `_stock` value as WooCommerce has it now (cache cleared first). */
  private static function wc_stock( int $pid ): float {
    wp_cache_delete( $pid, 'post_meta' );
    $value = get_post_meta( $pid, '_stock', true );
    return is_numeric( $value ) ? (float) $value : 0.0;
  }

  private static function movement_out( $row ): array {
    $out = [];
    foreach ( [ 'id', 'batch_id', 'line_no', 'stock_product_id', 'product_id', 'variation_id', 'location_id', 'order_id', 'order_item_id', 'source_id', 'actor_user_id', 'cashier_user_id' ] as $key ) {
      $out[ $key ] = (int) $row->$key;
    }
    $out['qty_delta']     = (float) $row->qty_delta;
    $out['balance_after'] = (float) $row->balance_after;
    $out['oversold']      = (bool) (int) $row->oversold;
    foreach ( [ 'reason', 'source_type', 'note', 'occurred_at', 'created_at' ] as $key ) {
      $out[ $key ] = (string) $row->$key;
    }
    return $out;
  }

  /** null when absent; an int >= $min from an int or digit string; else an error. */
  private static function int_param( $value, string $name, int $min, array &$errors ): ?int {
    if ( null === $value || '' === $value ) {
      return null;
    }
    if ( is_int( $value ) || ( is_string( $value ) && preg_match( '/^-?\d{1,19}$/', $value ) ) ) {
      $int = (int) $value;
      if ( $int >= $min ) {
        return $int;
      }
    }
    $errors[ $name ] = "must be a whole number of $min or more";
    return null;
  }

  /** A finite, non-negative quantity WooCommerce can store unrounded (wc_stock_amount), else an error. */
  private static function qty_param( $value, string $name, array &$errors ): ?float {
    if ( null === $value ) {
      return null;
    }
    $qty = ( is_int( $value ) || is_float( $value ) || ( is_string( $value ) && is_numeric( $value ) ) ) ? (float) $value : null;
    if ( null === $qty || $qty < 0 || ! Xophz_Compass_Bazaar_Stock_Line::in_range( $qty ) ) {
      $errors[ $name ] = 'must be a number of 0 or more';
      return null;
    }
    if ( Xophz_Compass_Bazaar_Stock_Planner::differs( (float) wc_stock_amount( $qty ), $qty ) ) {
      $errors[ $name ] = 'must be a quantity WooCommerce stores without rounding (a whole number by default)';
      return null;
    }
    return Xophz_Compass_Bazaar_Stock_Line::qty( $qty );
  }

  private static function bool_value( $value ): bool {
    return true === $value || 1 === $value || '1' === $value || 'true' === $value;
  }

  /** 400 invalid_request with data.errors when $errors is not empty. */
  private static function refuse( array $errors ): void {
    if ( $errors ) {
      throw new Xophz_Compass_Bazaar_Core_Http_Error( 'invalid_request', 'The request has invalid fields.', 400, [ 'errors' => $errors ] );
    }
  }
}
