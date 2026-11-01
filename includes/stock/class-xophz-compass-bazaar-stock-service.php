<?php
/**
 * The stock ledger service (spec 8.2 and 8.3), pure PHP behind two interfaces.
 *
 * Every write runs in one Stock_Store transaction, in this order: replay check;
 * lock the products' `_stock` rows (ascending ids); replay check again; lazy
 * opening batch for a product with no balances; lock the balances (one call,
 * ascending (pid, loc)); drift reconcile at location 0; plan; refuse every
 * shortfall at once; insert the batch, its movements and the balances;
 * apply_delta per product. After the service's outermost transaction returns,
 * after_commit runs once per batch; it never runs after a rollback.
 *
 * A Core_Duplicate_Key_Exception from the store (a concurrent winner took the
 * key) re-runs the call once, which then replays the winner's batch. That
 * retry happens only at the service's outermost level.
 *
 * Reads (finding 7 of #6983): nothing is read with a lock before the
 * products' `_stock` rows are locked, and the batch-key and order lookups
 * are plain reads, because a locking read of a key that does not exist yet
 * takes a gap lock that overlapping checkouts deadlock on. A plain read can
 * miss a concurrent winner's batch; the unique key then refuses the insert,
 * and the re-run reads current. An order's movements are read current
 * (after the lock) except for a sale of an order the plain read found no
 * movement for: a concurrent sale of the same order from that state uses
 * the same key, so it ends in the duplicate-key re-run as well.
 *
 * Not done here: outbox rows (stock.batch.recorded) and cache clean-up on
 * rollback belong to the wpdb store and port (WP7).
 *
 * @package Xophz_Compass_Bazaar
 */
final class Xophz_Compass_Bazaar_Stock_Service {

  /** @var Xophz_Compass_Bazaar_Stock_Store */
  private $store;

  /** @var Xophz_Compass_Bazaar_Stock_Wc_Port */
  private $wc;

  /** @var callable */
  private $clock;

  /** @var int Depth of this service's own transactions. */
  private $depth = 0;

  /** @var array[] Queued after_commit calls: [pids, reason]. */
  private $pending = [];

  /** @var bool True during the re-run after a duplicate key: lookups are current reads. */
  private $current = false;

  /** @var Xophz_Compass_Bazaar_Stock_Resolver */
  private $keys;

  /** @param callable|null $clock Returns the UTC 'Y-m-d H:i:s' now; defaults to gmdate. */
  public function __construct( Xophz_Compass_Bazaar_Stock_Store $store, Xophz_Compass_Bazaar_Stock_Wc_Port $wc, ?callable $clock = null ) {
    $this->store = $store;
    $this->wc    = $wc;
    $this->keys  = new Xophz_Compass_Bazaar_Stock_Resolver( $wc );
    $this->clock = $clock ?: function () {
      return gmdate( 'Y-m-d H:i:s' );
    };
  }

  /** Record one batch. Lines for products that do not manage stock, and zero lines, are dropped. */
  public function apply( Xophz_Compass_Bazaar_Stock_Batch $batch ): Xophz_Compass_Bazaar_Stock_Result {
    $hash = $batch->request_hash();
    return $this->replay( $batch->idem_key(), $hash ) ?? $this->run(
      function () use ( $batch, $hash ) {
        $infos = [];
        $lines = $this->keys->lines( $batch->lines(), $infos );
        if ( ! $lines ) {
          return Xophz_Compass_Bazaar_Stock_Result::none();
        }
        $pids = Xophz_Compass_Bazaar_Stock_Planner::pids( $lines );
        $wc   = $this->lock( $pids );
        return $this->replay( $batch->idem_key(), $hash ) ?? $this->commit( $batch, $hash, $lines, $infos, $wc, $this->settle( $pids, $wc, Xophz_Compass_Bazaar_Stock_Planner::pairs( $lines ), $batch->meta(), $infos ) );
      }
    );
  }

  /** A signed change at one location. A zero delta is a noop. */
  public function adjust( string $key, int $pid, int $loc, float $delta, string $reason, Xophz_Compass_Bazaar_Stock_Meta $meta ): Xophz_Compass_Bazaar_Stock_Result {
    $batch = new Xophz_Compass_Bazaar_Stock_Batch( $key, $reason, [ new Xophz_Compass_Bazaar_Stock_Line( $pid, $loc, $delta ) ], $meta );
    if ( ! Xophz_Compass_Bazaar_Stock_Planner::differs( $delta, 0.0 ) ) {
      return $this->replay( $key, $batch->request_hash() ) ?? Xophz_Compass_Bazaar_Stock_Result::none();
    }
    return $this->apply( $batch );
  }

  /**
   * Set one location to a counted quantity. With $expected, a balance that no
   * longer matches it (read under lock, after drift) throws stock_changed.
   * The request hash covers pid, loc, counted and expected. A count that
   * needs no change writes no batch, so a retry of it is computed again.
   */
  public function set_count( string $key, int $pid, int $loc, float $counted, ?float $expected, string $reason, Xophz_Compass_Bazaar_Stock_Meta $meta ): Xophz_Compass_Bazaar_Stock_Result {
    if ( $counted < 0 || ! Xophz_Compass_Bazaar_Stock_Line::in_range( $counted ) || ( null !== $expected && ! Xophz_Compass_Bazaar_Stock_Line::in_range( $expected ) ) ) {
      throw new InvalidArgumentException( 'A counted or expected quantity must be finite, within range, and the count not negative.' );
    }
    $basis   = [ 'stock_product_id' => $pid, 'location_id' => $loc, 'counted' => Xophz_Compass_Bazaar_Stock_Keys::number( $counted ) ];
    $options = [ 'hash_basis' => $basis + [ 'expected' => null === $expected ? null : Xophz_Compass_Bazaar_Stock_Keys::number( $expected ) ] ];
    $hash    = ( new Xophz_Compass_Bazaar_Stock_Batch( $key, $reason, [], $meta, $options ) )->request_hash();
    return $this->replay( $key, $hash ) ?? $this->run(
      function () use ( $key, $pid, $loc, $counted, $expected, $reason, $meta, $options, $hash ) {
        $infos = [];
        $owner = $this->keys->stock_key( $pid, $infos );
        if ( ! $owner ) {
          return Xophz_Compass_Bazaar_Stock_Result::none();
        }
        $spid = $owner['key'];
        $wc   = $this->lock( [ $spid ] );
        $done = $this->replay( $key, $hash );
        if ( $done ) {
          return $done;
        }
        $ctx     = $this->settle( [ $spid ], $wc, [ [ $spid, $loc ] ], $meta, $infos );
        $current = (float) $ctx['balances'][ "$spid:$loc" ]['on_hand'];
        if ( null !== $expected && Xophz_Compass_Bazaar_Stock_Planner::differs( $current, $expected ) ) {
          throw Xophz_Compass_Bazaar_Stock_Conflict_Exception::stock_changed( $current );
        }
        $delta = Xophz_Compass_Bazaar_Stock_Line::qty( $counted - $current );
        if ( ! Xophz_Compass_Bazaar_Stock_Planner::differs( $delta, 0.0 ) ) {
          return Xophz_Compass_Bazaar_Stock_Result::none( $ctx['reconciled'] );
        }
        $line = new Xophz_Compass_Bazaar_Stock_Line( $spid, $loc, $delta, [ 'product_id' => $owner['product_id'], 'variation_id' => $owner['variation_id'] ] );
        return $this->commit( new Xophz_Compass_Bazaar_Stock_Batch( $key, $reason, [ $line ], $meta, $options ), $hash, [ $line ], $infos, $wc, $ctx );
      }
    );
  }

  /** Move $qty from one location to another: one batch, reason transfer_out, two lines. */
  public function transfer( string $key, int $pid, int $from, int $to, float $qty, Xophz_Compass_Bazaar_Stock_Meta $meta ): Xophz_Compass_Bazaar_Stock_Result {
    if ( $from === $to || ! ( $qty > 0 ) || ! Xophz_Compass_Bazaar_Stock_Line::in_range( $qty ) ) {
      throw new InvalidArgumentException( 'A transfer needs two different locations and a positive quantity.' );
    }
    $lines = [ new Xophz_Compass_Bazaar_Stock_Line( $pid, $from, -$qty ), new Xophz_Compass_Bazaar_Stock_Line( $pid, $to, $qty ) ];
    return $this->apply( new Xophz_Compass_Bazaar_Stock_Batch( $key, 'transfer_out', $lines, $meta, [ 'source_type' => 'transfer' ] ) );
  }

  /**
   * Bring a ledger-owned order's net outflow to its target (Stock_Order_Math).
   * The key embeds the order's last movement id, so a repeated call finds
   * nothing to do and returns a noop.
   *
   * @param array[] $lines Order lines: order_item_id, quantity, location_id and stock_product_id (or product_id / variation_id).
   */
  public function sync_order( int $order_id, bool $committed, array $lines, Xophz_Compass_Bazaar_Stock_Meta $meta ): Xophz_Compass_Bazaar_Stock_Result {
    return $this->run(
      function () use ( $order_id, $committed, $lines, $meta ) {
        $infos = [];
        list( $order_lines, $pids, $seen ) = $this->order_prep( $order_id, $lines, $infos );
        if ( ! $pids ) {
          return Xophz_Compass_Bazaar_Stock_Result::none();
        }
        $wc = $this->lock( $pids );
        if ( $committed && ! $seen['items'] && ! $this->current ) {
          $sums = $seen;
        } else {
          $sums = $this->store->order_sums( $order_id, true );
          $wc  += $this->lock_more( $sums, $pids, $infos );
        }
        $deltas = Xophz_Compass_Bazaar_Stock_Order_Math::deltas( $order_lines, $sums, $committed );
        $key    = Xophz_Compass_Bazaar_Stock_Keys::order_sync( $order_id, (int) ( $sums['last_movement_id'] ?? 0 ), $committed );
        $reason = Xophz_Compass_Bazaar_Stock_Order_Math::batch_reason( $deltas, $committed );
        return $this->commit_order( $order_id, $key, $reason, $deltas, [], $pids, $wc, $meta, $infos );
      }
    );
  }

  /**
   * Restock refunded items: each quantity capped at the item's net outflow and
   * returned to the locations it left. Key refund:{id}:restock; the request
   * hash covers the order id and the item quantities.
   *
   * @param array $item_qtys order_item_id => quantity.
   */
  public function restock_refund( int $wc_refund_id, int $order_id, array $item_qtys, array $lines, Xophz_Compass_Bazaar_Stock_Meta $meta ): Xophz_Compass_Bazaar_Stock_Result {
    $key     = Xophz_Compass_Bazaar_Stock_Keys::refund_restock( $wc_refund_id );
    $basis   = [ 'order_id' => $order_id, 'items' => Xophz_Compass_Bazaar_Stock_Order_Math::hash_items( $item_qtys ) ];
    $options = [ 'source_type' => 'refund', 'source_id' => $wc_refund_id, 'hash_basis' => $basis ];
    $hash    = ( new Xophz_Compass_Bazaar_Stock_Batch( $key, 'refund_restock', [], $meta, $options ) )->request_hash();
    return $this->replay( $key, $hash ) ?? $this->run(
      function () use ( $order_id, $item_qtys, $lines, $meta, $key, $options ) {
        $infos = [];
        list( $order_lines, $pids ) = $this->order_prep( $order_id, $lines, $infos );
        if ( ! $pids ) {
          return Xophz_Compass_Bazaar_Stock_Result::none();
        }
        $wc     = $this->lock( $pids );
        $sums   = $this->store->order_sums( $order_id, true );
        $wc    += $this->lock_more( $sums, $pids, $infos );
        $deltas = Xophz_Compass_Bazaar_Stock_Order_Math::restock( $order_lines, $sums, $item_qtys );
        return $this->commit_order( $order_id, $key, 'refund_restock', $deltas, $options, $pids, $wc, $meta, $infos );
      }
    );
  }

  /**
   * Record the difference between `_stock` and the projectable sum, under
   * lock, at location 0. Returns null when there is none (including a first
   * touch, where the opening batch absorbs the WooCommerce value).
   *
   * @param array $source Optional ['type' => string, 'id' => int] for the batch.
   */
  public function reconcile_from_wc( int $pid, string $reason, Xophz_Compass_Bazaar_Stock_Meta $meta, array $source = [] ): ?Xophz_Compass_Bazaar_Stock_Result {
    return $this->run(
      function () use ( $pid, $reason, $meta, $source ) {
        $infos = [];
        $owner = $this->keys->stock_key( $pid, $infos );
        if ( ! $owner ) {
          return null;
        }
        $wc  = $this->lock( [ $owner['key'] ] );
        $ctx = $this->settle( [ $owner['key'] ], $wc, [], $meta, $infos, $reason, $source );
        if ( ! $ctx['reconciled'] ) {
          return null;
        }
        return new Xophz_Compass_Bazaar_Stock_Result( (int) $ctx['reconciled'][0]['batch_id'], false, false, $ctx['reconciled'] );
      }
    );
  }

  /** Write the opening batch when the product has no balances. Records no drift. */
  public function ensure_initialized( int $pid ): void {
    $this->run(
      function () use ( $pid ) {
        $infos = [];
        $owner = $this->keys->stock_key( $pid, $infos );
        if ( $owner ) {
          $this->settle( [ $owner['key'] ], $this->lock( [ $owner['key'] ] ), [], new Xophz_Compass_Bazaar_Stock_Meta(), $infos, null );
        }
      }
    );
  }

  /**
   * Enable or disable a location (never location 0). on_hand is unchanged and
   * no movement is written; `_stock` moves by the location's balance so it
   * stays equal to the projectable sum. The result has batch_id 0.
   */
  public function set_enabled( int $pid, int $loc, bool $enabled, Xophz_Compass_Bazaar_Stock_Meta $meta ): Xophz_Compass_Bazaar_Stock_Result {
    if ( $loc <= 0 ) {
      throw new InvalidArgumentException( 'Location 0 is always enabled.' );
    }
    return $this->run(
      function () use ( $pid, $loc, $enabled, $meta ) {
        $infos = [];
        $owner = $this->keys->stock_key( $pid, $infos );
        if ( ! $owner ) {
          return Xophz_Compass_Bazaar_Stock_Result::none();
        }
        $spid = $owner['key'];
        $ctx  = $this->settle( [ $spid ], $this->lock( [ $spid ] ), [ [ $spid, $loc ] ], $meta, $infos );
        $row  = $ctx['balances'][ "$spid:$loc" ];
        if ( (bool) $row['is_enabled'] === $enabled ) {
          return Xophz_Compass_Bazaar_Stock_Result::none( $ctx['reconciled'] );
        }
        $this->store->set_enabled( $spid, $loc, $enabled );
        $balances                           = $ctx['balances'];
        $balances[ "$spid:$loc" ]['is_enabled'] = $enabled;
        $on_hand                            = (float) $row['on_hand'];
        $this->wc->apply_delta( $spid, $enabled ? $on_hand : -$on_hand, Xophz_Compass_Bazaar_Stock_Planner::locations( $spid, $balances ) );
        $this->pending[] = [ [ $spid ], 'adjust' ];
        return new Xophz_Compass_Bazaar_Stock_Result( 0, false, false, [], $ctx['reconciled'] );
      }
    );
  }

  /** loc => ['on_hand', 'is_enabled'] for the product's stock key. Initializes the product on first touch. */
  public function balances( int $pid ): array {
    $infos = [];
    $owner = $this->keys->stock_key( $pid, $infos );
    if ( ! $owner ) {
      return [];
    }
    $this->ensure_initialized( $owner['key'] );
    return $this->store->balances( $owner['key'] );
  }

  /** on_hand at one location (0.0 when the location has no balance). Initializes on first touch. */
  public function available( int $pid, int $loc ): float {
    $balances = $this->balances( $pid );
    return isset( $balances[ $loc ] ) ? (float) $balances[ $loc ]['on_hand'] : 0.0;
  }

  /**
   * Steps 4 to 6: opening batches, one lock_balances call, drift reconcile.
   * $drift_reason null skips the drift step.
   *
   * @return array ['balances' => "pid:loc" => row, 'reconciled' => result lines]
   */
  private function settle( array $pids, array $wc, array $pairs, Xophz_Compass_Bazaar_Stock_Meta $meta, array $infos, ?string $drift_reason = 'reconcile', array $source = [] ): array {
    $want     = $pairs;
    $openings = [];
    foreach ( $pids as $pid ) {
      $want[] = [ $pid, 0 ];
      $locs   = $this->store->has_balances( $pid ) ? array_keys( $this->store->balances( $pid ) ) : null;
      if ( null === $locs ) {
        $openings[ $pid ] = Xophz_Compass_Bazaar_Stock_Planner::opening_entries( $this->wc->initial_balances( $pid, $wc[ $pid ] ), $wc[ $pid ] );
        $locs             = array_keys( $openings[ $pid ] );
      }
      foreach ( $locs as $loc ) {
        $want[] = [ $pid, (int) $loc ];
      }
    }
    $balances = [];
    foreach ( $this->store->lock_balances( Xophz_Compass_Bazaar_Stock_Planner::sorted_pairs( $want ) ) as $key => $row ) {
      $balances[ $key ] = [ 'on_hand' => (float) $row['on_hand'], 'is_enabled' => (bool) $row['is_enabled'], 'last_movement_id' => (int) $row['last_movement_id'] ];
    }
    foreach ( $openings as $pid => $entries ) {
      $balances = $this->open( $pid, $entries, $wc, $balances, $meta, $infos );
    }
    $reconciled = [];
    foreach ( null === $drift_reason ? [] : $pids as $pid ) {
      $diff = Xophz_Compass_Bazaar_Stock_Line::qty( $wc[ $pid ] - Xophz_Compass_Bazaar_Stock_Planner::projectable_sum( $pid, $balances ) );
      if ( ! Xophz_Compass_Bazaar_Stock_Planner::differs( $diff, 0.0 ) ) {
        continue;
      }
      $line  = new Xophz_Compass_Bazaar_Stock_Line( $pid, 0, $diff, Xophz_Compass_Bazaar_Stock_Resolver::product_ids( $infos, $pid ) );
      $batch = new Xophz_Compass_Bazaar_Stock_Batch(
        Xophz_Compass_Bazaar_Stock_Keys::reconcile( $pid, $this->store->last_movement_id( $pid ), $wc[ $pid ] ),
        $drift_reason,
        [ $line ],
        $meta,
        [ 'source_type' => (string) ( $source['type'] ?? '' ), 'source_id' => (int) ( $source['id'] ?? 0 ) ]
      );
      list( $balances, $batch_id, $lines ) = $this->write( $batch, $batch->request_hash(), Xophz_Compass_Bazaar_Stock_Planner::plan( [ $line ], $balances, $wc, [], true ) );
      foreach ( $lines as $result_line ) {
        $reconciled[] = $result_line + [ 'batch_id' => $batch_id, 'reason' => $drift_reason ];
      }
    }
    return [ 'balances' => $balances, 'reconciled' => $reconciled ];
  }

  /** Step 4: the opening batch. Disabled locations keep their quantity and stay disabled. */
  private function open( int $pid, array $entries, array $wc, array $balances, Xophz_Compass_Bazaar_Stock_Meta $meta, array $infos ): array {
    $lines = [];
    foreach ( $entries as $loc => $entry ) {
      if ( 0 !== $loc && ! $entry['enabled'] ) {
        $this->store->set_enabled( $pid, $loc, false );
        $balances[ "$pid:$loc" ]['is_enabled'] = false;
      }
      if ( Xophz_Compass_Bazaar_Stock_Planner::differs( $entry['qty'], 0.0 ) ) {
        $lines[] = new Xophz_Compass_Bazaar_Stock_Line( $pid, $loc, $entry['qty'], Xophz_Compass_Bazaar_Stock_Resolver::product_ids( $infos, $pid ) );
      }
    }
    if ( ! $lines ) {
      return $balances;
    }
    $batch = new Xophz_Compass_Bazaar_Stock_Batch( Xophz_Compass_Bazaar_Stock_Keys::opening( $pid ), 'opening', $lines, $meta, [ 'source_type' => 'product', 'source_id' => $pid ] );
    list( $balances ) = $this->write( $batch, $batch->request_hash(), Xophz_Compass_Bazaar_Stock_Planner::plan( $lines, $balances, $wc, [], true ) );
    return $balances;
  }

  /** Steps 7 to 10 for a resolved batch. */
  private function commit( Xophz_Compass_Bazaar_Stock_Batch $batch, string $hash, array $lines, array $infos, array $wc, array $ctx ): Xophz_Compass_Bazaar_Stock_Result {
    $plan = Xophz_Compass_Bazaar_Stock_Planner::plan( $lines, $ctx['balances'], $wc, $infos );
    if ( $plan['shortfalls'] ) {
      throw new Xophz_Compass_Bazaar_Stock_Insufficient_Exception( $plan['shortfalls'] );
    }
    list( $balances, $batch_id, $result_lines ) = $this->write( $batch, $hash, $plan );
    $pids = Xophz_Compass_Bazaar_Stock_Planner::pids( $lines );
    foreach ( $pids as $pid ) {
      $this->wc->apply_delta( $pid, (float) ( $plan['deltas'][ $pid ] ?? 0.0 ), Xophz_Compass_Bazaar_Stock_Planner::locations( $pid, $balances ) );
    }
    $this->pending[] = [ $pids, $batch->reason() ];
    return new Xophz_Compass_Bazaar_Stock_Result( $batch_id, false, false, $result_lines, $ctx['reconciled'] );
  }

  /** Shared tail of sync_order and restock_refund, once the products are locked. */
  private function commit_order( int $order_id, string $key, string $reason, array $deltas, array $options, array $pids, array $wc, Xophz_Compass_Bazaar_Stock_Meta $meta, array $infos ): Xophz_Compass_Bazaar_Stock_Result {
    $lines = Xophz_Compass_Bazaar_Stock_Order_Math::stock_lines( $order_id, $deltas, $infos );
    if ( ! $lines ) {
      return Xophz_Compass_Bazaar_Stock_Result::none();
    }
    $batch = new Xophz_Compass_Bazaar_Stock_Batch( $key, $reason, $lines, $meta, $options + [ 'source_type' => 'order', 'source_id' => $order_id ] );
    $hash  = $batch->request_hash();
    return $this->replay( $key, $hash ) ?? $this->commit( $batch, $hash, $lines, $infos, $wc, $this->settle( $pids, $wc, Xophz_Compass_Bazaar_Stock_Planner::pairs( $lines ), $meta, $infos ) );
  }

  /** Insert the batch, its movements and the touched balances. @return array [balances, batch_id, result lines] */
  private function write( Xophz_Compass_Bazaar_Stock_Batch $batch, string $hash, array $plan ): array {
    $now      = (string) call_user_func( $this->clock );
    $batch_id = $this->store->insert_batch( $batch->to_row( $hash, $now ) );
    $balances = $plan['balances'];
    $lines    = [];
    $last     = [];
    foreach ( $plan['movements'] as $index => $movement ) {
      $line        = $movement['line'];
      $row         = $line->to_movement_row( $batch_id, $index + 1, $movement['balance_after'], $movement['oversold'], $now );
      $movement_id = $this->store->insert_movement( $row );
      $last[ $line->stock_product_id() . ':' . $line->location_id() ] = [ $line->stock_product_id(), $line->location_id(), $movement_id ];
      $lines[] = Xophz_Compass_Bazaar_Stock_Result::line_from_row( $row );
    }
    foreach ( $last as $key => $target ) {
      list( $pid, $loc, $movement_id ) = $target;
      $this->store->update_balance( $pid, $loc, (float) $balances[ $key ]['on_hand'], $movement_id );
      $balances[ $key ]['last_movement_id'] = $movement_id;
    }
    return [ $balances, $batch_id, $lines ];
  }

  /**
   * Run in the service's transaction; one re-run after a duplicate key at the
   * outermost level, with current reads (inside a caller's transaction the
   * snapshot that missed the winner is still the same).
   */
  private function run( callable $fn ) {
    try {
      return $this->tx( $fn );
    } catch ( Xophz_Compass_Bazaar_Core_Duplicate_Key_Exception $e ) {
      if ( $this->depth > 0 ) {
        throw $e;
      }
      $was           = $this->current;
      $this->current = true;
      try {
        return $this->tx( $fn );
      } finally {
        $this->current = $was;
      }
    }
  }

  /** store->transaction, then the queued after_commit calls once the outermost one returns. */
  private function tx( callable $fn ) {
    $outer = 0 === $this->depth++;
    try {
      $result = $this->store->transaction( $fn );
    } finally {
      $this->depth--;
      $queued = $outer ? $this->pending : [];
      if ( $outer ) {
        $this->pending = [];
      }
    }
    foreach ( $queued as $call ) {
      $this->wc->after_commit( $call[0], $call[1] );
    }
    return $result;
  }

  /** The stored batch as a replay, null when the key is unused; idempotency_key_reused when the hash differs. */
  private function replay( string $key, string $hash ): ?Xophz_Compass_Bazaar_Stock_Result {
    $found = $this->store->find_batch( $key, $this->current );
    if ( null === $found ) {
      return null;
    }
    if ( (string) ( $found['batch']['request_hash'] ?? '' ) !== $hash ) {
      throw Xophz_Compass_Bazaar_Stock_Conflict_Exception::key_reused();
    }
    return Xophz_Compass_Bazaar_Stock_Result::replay( $found );
  }

  /** Lock `_stock` rows in ascending order. @return array pid => float */
  private function lock( array $pids ): array {
    sort( $pids );
    $values = $this->wc->lock_products( $pids );
    $out    = [];
    foreach ( $pids as $pid ) {
      $out[ $pid ] = Xophz_Compass_Bazaar_Stock_Line::qty( (float) ( $values[ $pid ] ?? 0.0 ) );
    }
    return $out;
  }

  /**
   * Lock the stock keys of $sums (an order's movements, read after the lock)
   * that $pids does not hold yet, adding them to $pids. Only a concurrent
   * change to the order's items brings any.
   *
   * @return array pid => float for the newly locked keys.
   */
  private function lock_more( array $sums, array &$pids, array &$infos ): array {
    $more = array_values( array_diff( $this->keys->order_keys( $sums, $infos ), $pids ) );
    if ( ! $more ) {
      return [];
    }
    $pids = array_merge( $pids, $more );
    sort( $pids );
    return $this->lock( $more );
  }

  /**
   * Normalized order lines on stock keys, every stock key the order touches
   * (lines plus earlier movements), and the order's movement sums from a
   * plain read (no lock is held yet).
   *
   * @return array [order lines, pids ascending, sums]
   */
  private function order_prep( int $order_id, array $lines, array &$infos ): array {
    list( $order_lines, $pids ) = $this->keys->order_lines( $lines, $infos );
    $seen                       = $this->store->order_sums( $order_id, false );
    $pids                       = array_values( array_unique( array_merge( $pids, $this->keys->order_keys( $seen, $infos ) ) ) );
    sort( $pids );
    return [ $order_lines, $pids, $seen ];
  }
}
