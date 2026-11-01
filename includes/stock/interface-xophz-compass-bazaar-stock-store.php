<?php
/**
 * Persistence for the stock ledger: batches, movements and balances (spec 8.2).
 *
 * Stock_Service is the only caller. It calls every write method inside
 * transaction(), after it has locked the rows through lock_balances(). The
 * wpdb implementation (WP7) and the in-memory test double implement it.
 *
 * Reads and locks (finding 7 of #6983): a read with $current false is a
 * plain read, which inside a transaction may miss rows another transaction
 * committed after this one's snapshot, and takes no lock. A read with
 * $current true is a current (locking) read. The service makes no current
 * read before it has locked the products, and none on a key that may not
 * exist yet in the normal path, because a locking read of a missing key
 * takes a gap lock that a concurrent insert of a neighboring key then
 * deadlocks on. It relies on the unique idem_key instead: a stale miss ends
 * in Core_Duplicate_Key_Exception on insert_batch, and the re-run reads
 * with $current true, when the key exists.
 *
 * Row shapes (keys match the bazaar_stock_* columns of spec 7.1):
 * - batch row: id, idem_key, request_hash, reason, source_type, source_id,
 *   reverses_batch_id, actor_user_id, cashier_user_id, staff_session_id,
 *   approval_id, register_id, device_id, note, occurred_at, created_at.
 * - movement row: id, batch_id, line_no, stock_product_id, product_id,
 *   variation_id, location_id, qty_delta, balance_after, oversold, order_id,
 *   order_item_id, unit_cost_minor, cost_currency, cost_source, created_at.
 *
 * @package Xophz_Compass_Bazaar
 */
interface Xophz_Compass_Bazaar_Stock_Store {

  /**
   * Run $fn in one transaction and return its result. A call made while a
   * transaction is open joins it. Any Throwable rolls the outermost one back
   * and is rethrown.
   *
   * @return mixed
   */
  public function transaction( callable $fn );

  /**
   * @param bool $current A current (locking) read inside a transaction; otherwise a plain read.
   * @return array|null ['batch' => row, 'lines' => movement rows by line_no], or null.
   */
  public function find_batch( string $idem_key, bool $current = false ): ?array;

  /**
   * True once any balance row exists for the stock product; current inside a
   * transaction. The wpdb store answers it by inserting the location 0 row
   * at 0 when it is missing (which lock_balances would do anyway), so it
   * takes no gap lock.
   */
  public function has_balances( int $pid ): bool;

  /**
   * Insert missing (pid, loc) rows at 0 and enabled, then lock every pair,
   * ordered by (pid, loc).
   *
   * @param array[] $pairs List of [pid, loc].
   * @return array "pid:loc" => ['on_hand' => float, 'is_enabled' => bool, 'last_movement_id' => int]
   */
  public function lock_balances( array $pairs ): array;

  /**
   * @return int The new batch id.
   * @throws Xophz_Compass_Bazaar_Core_Duplicate_Key_Exception When idem_key is taken.
   */
  public function insert_batch( array $row ): int;

  /** @return int The new movement id. */
  public function insert_movement( array $row ): int;

  public function update_balance( int $pid, int $loc, float $on_hand, int $movement_id ): void;

  public function set_enabled( int $pid, int $loc, bool $enabled ): void;

  /**
   * Movement sums for one order.
   *
   * @return array [
   *   'items' => [order_item_id => [location_id => [
   *     'net' => SUM(qty_delta), negative while stock is out,
   *     'restocked' => SUM(qty_delta) of refund_restock batches,
   *     'stock_product_id' => int, 'product_id' => int, 'variation_id' => int,
   *   ]]],
   *   'last_movement_id' => highest movement id of the order, 0 when none,
   * ]
   *
   * @param bool $current A current (locking) read inside a transaction; otherwise a plain read.
   */
  public function order_sums( int $order_id, bool $current = false ): array;

  /** Highest movement id for the stock product at any location, 0 when none. */
  public function last_movement_id( int $pid ): int;

  /** @return array loc => ['on_hand' => float, 'is_enabled' => bool], ascending loc, no lock. */
  public function balances( int $pid ): array;
}
