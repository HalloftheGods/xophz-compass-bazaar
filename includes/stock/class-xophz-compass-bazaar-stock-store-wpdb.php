<?php
/**
 * Stock_Store on wpdb: the bazaar_stock_batches, _movements and _balances
 * tables (spec 7.1 and 8.2), through Core_Db.
 *
 * Guarantees:
 * - transaction() is Core_Db::tx: a call inside an open transaction joins it,
 *   and only the outermost call commits, rolls back or retries a deadlock.
 * - lock_balances() inserts missing (pid, loc) rows at 0 (INSERT IGNORE), then
 *   locks every pair with SELECT ... FOR UPDATE ordered by (pid, loc).
 * - insert_batch() also writes the outbox row stock.batch.recorded in the
 *   same transaction; a taken idem_key throws Core_Duplicate_Key_Exception.
 * - No locking read on a key that may not exist yet (finding 7 of #6983).
 *   Under REPEATABLE READ such a read takes a gap lock, and two
 *   transactions holding the same gap then deadlock on their inserts, so
 *   two overlapping checkouts (new orders, new batch keys) used to deadlock
 *   every time. Instead:
 *   - find_batch() and order_sums() are plain reads unless $current is
 *     true. A plain read can miss a batch a concurrent winner committed;
 *     the unique idem_key then refuses the insert and the service re-runs
 *     with $current true, when the key exists (a record lock, no gap).
 *   - has_balances() inside a transaction inserts the location 0 row at 0
 *     when missing (INSERT IGNORE: a record lock, or an insert) and reports
 *     whether it existed, instead of a locking range read.
 *   - balances() and last_movement_id() are locking reads of a product's
 *     rows, which exist by then (has_balances() runs first) and which only a
 *     holder of that product's `_stock` lock changes.
 *
 * Not guaranteed: freedom from deadlocks. The range reads above still lock
 * the gaps next to the product's rows, so a first touch of a neighboring
 * product waits for this transaction, and three or more transactions adding
 * new locations at once can still form a cycle; MySQL then rolls one back
 * and Core_Db::tx retries it (outermost transaction only, 2 retries by
 * default).
 *
 * @package Xophz_Compass_Bazaar
 */
class Xophz_Compass_Bazaar_Stock_Store_Wpdb implements Xophz_Compass_Bazaar_Stock_Store {

  const QTY_FORMAT = '%.4F';

  public function transaction( callable $fn ) {
    return Xophz_Compass_Bazaar_Core_Db::tx( $fn );
  }

  public function find_batch( string $idem_key, bool $current = false ): ?array {
    $lock  = $current ? self::share() : '';
    $batch = Xophz_Compass_Bazaar_Core_Db::get_row(
      'SELECT * FROM ' . self::t( 'stock_batches' ) . ' WHERE idem_key = %s' . $lock,
      [ $idem_key ]
    );
    if ( ! $batch ) {
      return null;
    }
    $batch = self::batch_row( (array) $batch );
    $lines = Xophz_Compass_Bazaar_Core_Db::get_results(
      'SELECT * FROM ' . self::t( 'stock_movements' ) . ' WHERE batch_id = %d ORDER BY line_no' . $lock,
      [ $batch['id'] ]
    );
    return [ 'batch' => $batch, 'lines' => array_map( [ self::class, 'movement_row' ], array_map( 'get_object_vars', $lines ) ) ];
  }

  public function has_balances( int $pid ): bool {
    if ( ! Xophz_Compass_Bazaar_Core_Db::in_tx() ) {
      return null !== Xophz_Compass_Bazaar_Core_Db::get_var(
        'SELECT location_id FROM ' . self::t( 'stock_balances' ) . ' WHERE stock_product_id = %d ORDER BY location_id LIMIT 1',
        [ $pid ]
      );
    }
    // Every initialized product has a location 0 row (lock_balances always includes it).
    return 0 === Xophz_Compass_Bazaar_Core_Db::exec(
      'INSERT IGNORE INTO ' . self::t( 'stock_balances' ) . ' (stock_product_id, location_id, on_hand, is_enabled, last_movement_id, updated_at) VALUES (%d, 0, 0, 1, 0, %s)',
      [ $pid, Xophz_Compass_Bazaar_Core_Db::now() ]
    );
  }

  public function lock_balances( array $pairs ): array {
    $pairs = self::unique_pairs( $pairs );
    if ( ! $pairs ) {
      return [];
    }
    $now    = Xophz_Compass_Bazaar_Core_Db::now();
    $values = [];
    $args   = [];
    foreach ( $pairs as $pair ) {
      $values[] = '(%d, %d, 0, 1, 0, %s)';
      array_push( $args, $pair[0], $pair[1], $now );
    }
    Xophz_Compass_Bazaar_Core_Db::exec(
      'INSERT IGNORE INTO ' . self::t( 'stock_balances' ) . ' (stock_product_id, location_id, on_hand, is_enabled, last_movement_id, updated_at) VALUES ' . implode( ', ', $values ),
      $args
    );
    list( $where, $where_args ) = self::pairs_where( $pairs );
    $rows = Xophz_Compass_Bazaar_Core_Db::get_results(
      'SELECT stock_product_id, location_id, on_hand, is_enabled, last_movement_id FROM ' . self::t( 'stock_balances' )
        . ' WHERE ' . $where . ' ORDER BY stock_product_id, location_id FOR UPDATE',
      $where_args
    );
    $out = [];
    foreach ( $rows as $row ) {
      $out[ (int) $row->stock_product_id . ':' . (int) $row->location_id ] = [
        'on_hand'          => Xophz_Compass_Bazaar_Stock_Line::qty( (float) $row->on_hand ),
        'is_enabled'       => (bool) (int) $row->is_enabled,
        'last_movement_id' => (int) $row->last_movement_id,
      ];
    }
    return $out;
  }

  public function insert_batch( array $row ): int {
    $id = Xophz_Compass_Bazaar_Core_Db::insert( self::t( 'stock_batches' ), $row );
    Xophz_Compass_Bazaar_Events_Outbox::record(
      'stock.batch.recorded',
      'stock_batch',
      $id,
      [
        'batch_id'    => $id,
        'idem_key'    => (string) ( $row['idem_key'] ?? '' ),
        'reason'      => (string) ( $row['reason'] ?? '' ),
        'source_type' => (string) ( $row['source_type'] ?? '' ),
        'source_id'   => (int) ( $row['source_id'] ?? 0 ),
      ],
      (int) ( $row['actor_user_id'] ?? 0 )
    );
    return $id;
  }

  public function insert_movement( array $row ): int {
    foreach ( [ 'qty_delta', 'balance_after' ] as $key ) {
      $row[ $key ] = sprintf( self::QTY_FORMAT, Xophz_Compass_Bazaar_Stock_Line::qty( (float) ( $row[ $key ] ?? 0 ) ) );
    }
    return Xophz_Compass_Bazaar_Core_Db::insert( self::t( 'stock_movements' ), $row );
  }

  public function update_balance( int $pid, int $loc, float $on_hand, int $movement_id ): void {
    Xophz_Compass_Bazaar_Core_Db::exec(
      'UPDATE ' . self::t( 'stock_balances' ) . ' SET on_hand = %s, last_movement_id = %d, updated_at = %s WHERE stock_product_id = %d AND location_id = %d',
      [ sprintf( self::QTY_FORMAT, Xophz_Compass_Bazaar_Stock_Line::qty( $on_hand ) ), $movement_id, Xophz_Compass_Bazaar_Core_Db::now(), $pid, $loc ]
    );
  }

  public function set_enabled( int $pid, int $loc, bool $enabled ): void {
    Xophz_Compass_Bazaar_Core_Db::exec(
      'UPDATE ' . self::t( 'stock_balances' ) . ' SET is_enabled = %d, updated_at = %s WHERE stock_product_id = %d AND location_id = %d',
      [ $enabled ? 1 : 0, Xophz_Compass_Bazaar_Core_Db::now(), $pid, $loc ]
    );
  }

  public function order_sums( int $order_id, bool $current = false ): array {
    $out = [ 'items' => [], 'last_movement_id' => 0 ];
    if ( $order_id <= 0 ) {
      return $out;
    }
    $rows = Xophz_Compass_Bazaar_Core_Db::get_results(
      'SELECT m.id, m.order_item_id, m.location_id, m.stock_product_id, m.product_id, m.variation_id, m.qty_delta, b.reason FROM '
        . self::t( 'stock_movements' ) . ' m JOIN ' . self::t( 'stock_batches' ) . ' b ON b.id = m.batch_id'
        . ' WHERE m.order_id = %d ORDER BY m.id' . ( $current ? self::share() : '' ),
      [ $order_id ]
    );
    foreach ( $rows as $row ) {
      $item = (int) $row->order_item_id;
      $loc  = (int) $row->location_id;
      $qty  = (float) $row->qty_delta;
      if ( ! isset( $out['items'][ $item ][ $loc ] ) ) {
        $out['items'][ $item ][ $loc ] = [
          'net'              => 0.0,
          'restocked'        => 0.0,
          'stock_product_id' => (int) $row->stock_product_id,
          'product_id'       => (int) $row->product_id,
          'variation_id'     => (int) $row->variation_id,
        ];
      }
      $sums = &$out['items'][ $item ][ $loc ];
      $sums['net'] = Xophz_Compass_Bazaar_Stock_Line::qty( $sums['net'] + $qty );
      if ( 'refund_restock' === (string) $row->reason ) {
        $sums['restocked'] = Xophz_Compass_Bazaar_Stock_Line::qty( $sums['restocked'] + $qty );
      }
      unset( $sums );
      $out['last_movement_id'] = max( $out['last_movement_id'], (int) $row->id );
    }
    return $out;
  }

  /**
   * Highest movement id for the product, read from the balances: every
   * movement updates its (pid, loc) balance to its own id, and ids ascend,
   * so the highest last_movement_id is the highest movement id.
   */
  public function last_movement_id( int $pid ): int {
    $rows = Xophz_Compass_Bazaar_Core_Db::get_results(
      'SELECT last_movement_id FROM ' . self::t( 'stock_balances' ) . ' WHERE stock_product_id = %d ORDER BY location_id' . self::exclusive(),
      [ $pid ]
    );
    $last = 0;
    foreach ( $rows as $row ) {
      $last = max( $last, (int) $row->last_movement_id );
    }
    return $last;
  }

  public function balances( int $pid ): array {
    $rows = Xophz_Compass_Bazaar_Core_Db::get_results(
      'SELECT location_id, on_hand, is_enabled FROM ' . self::t( 'stock_balances' ) . ' WHERE stock_product_id = %d ORDER BY location_id' . self::exclusive(),
      [ $pid ]
    );
    $out = [];
    foreach ( $rows as $row ) {
      $out[ (int) $row->location_id ] = [
        'on_hand'    => Xophz_Compass_Bazaar_Stock_Line::qty( (float) $row->on_hand ),
        'is_enabled' => (bool) (int) $row->is_enabled,
      ];
    }
    return $out;
  }

  private static function t( string $name ): string {
    return Xophz_Compass_Bazaar_Core_Db::table( $name );
  }

  /** ' LOCK IN SHARE MODE' inside a transaction (a current read), '' outside. */
  private static function share(): string {
    return Xophz_Compass_Bazaar_Core_Db::in_tx() ? ' LOCK IN SHARE MODE' : '';
  }

  /** ' FOR UPDATE' inside a transaction (balance rows the service locks anyway), '' outside. */
  private static function exclusive(): string {
    return Xophz_Compass_Bazaar_Core_Db::in_tx() ? ' FOR UPDATE' : '';
  }

  /** @return array[] Distinct [pid, loc] int pairs, ascending. */
  private static function unique_pairs( array $pairs ): array {
    $out = [];
    foreach ( $pairs as $pair ) {
      $pid = (int) ( $pair[0] ?? 0 );
      $loc = (int) ( $pair[1] ?? -1 );
      if ( $pid > 0 && $loc >= 0 ) {
        $out[ "$pid:$loc" ] = [ $pid, $loc ];
      }
    }
    usort(
      $out,
      function ( $a, $b ) {
        return [ $a[0], $a[1] ] <=> [ $b[0], $b[1] ];
      }
    );
    return $out;
  }

  /** @return array [sql, args]: (stock_product_id = %d AND location_id IN (...)) OR ... */
  private static function pairs_where( array $pairs ): array {
    $by_pid = [];
    foreach ( $pairs as $pair ) {
      $by_pid[ $pair[0] ][] = $pair[1];
    }
    $parts = [];
    $args  = [];
    foreach ( $by_pid as $pid => $locs ) {
      $parts[] = '(stock_product_id = %d AND location_id IN (' . implode( ', ', array_fill( 0, count( $locs ), '%d' ) ) . '))';
      $args[]  = $pid;
      array_push( $args, ...$locs );
    }
    return [ implode( ' OR ', $parts ), $args ];
  }

  private static function batch_row( array $row ): array {
    foreach ( [ 'id', 'source_id', 'actor_user_id', 'cashier_user_id' ] as $key ) {
      $row[ $key ] = (int) ( $row[ $key ] ?? 0 );
    }
    foreach ( [ 'reverses_batch_id', 'staff_session_id', 'approval_id', 'register_id' ] as $key ) {
      $row[ $key ] = isset( $row[ $key ] ) ? (int) $row[ $key ] : null;
    }
    return $row;
  }

  private static function movement_row( array $row ): array {
    foreach ( [ 'id', 'batch_id', 'line_no', 'stock_product_id', 'product_id', 'variation_id', 'location_id', 'order_id', 'order_item_id' ] as $key ) {
      $row[ $key ] = (int) ( $row[ $key ] ?? 0 );
    }
    $row['qty_delta']       = Xophz_Compass_Bazaar_Stock_Line::qty( (float) ( $row['qty_delta'] ?? 0 ) );
    $row['balance_after']   = Xophz_Compass_Bazaar_Stock_Line::qty( (float) ( $row['balance_after'] ?? 0 ) );
    $row['oversold']        = (int) ( $row['oversold'] ?? 0 );
    $row['unit_cost_minor'] = isset( $row['unit_cost_minor'] ) ? (int) $row['unit_cost_minor'] : null;
    return $row;
  }
}
