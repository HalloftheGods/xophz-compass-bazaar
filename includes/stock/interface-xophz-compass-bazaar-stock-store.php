<?php
/**
 * Persistence for the stock ledger: batches, movements and balances (spec 8.2).
 *
 * Stock_Service is the only caller. It calls every write method inside
 * transaction(), after it has locked the rows through lock_balances(). The
 * wpdb implementation (WP7) and the in-memory test double implement it.
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

  /** @return array|null ['batch' => row, 'lines' => movement rows by line_no], or null. */
  public function find_batch( string $idem_key ): ?array;

  /** True once any balance row exists for the stock product. */
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
   */
  public function order_sums( int $order_id ): array;

  /** Highest movement id for the stock product at any location, 0 when none. */
  public function last_movement_id( int $pid ): int;

  /** @return array loc => ['on_hand' => float, 'is_enabled' => bool], ascending loc, no lock. */
  public function balances( int $pid ): array;
}
