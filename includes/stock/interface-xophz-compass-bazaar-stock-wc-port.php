<?php
/**
 * The ledger's only door to WooCommerce stock (spec 8.2 and 8.5).
 *
 * Only an implementation of this interface writes WooCommerce `_stock`.
 * Stock_Service calls lock_products() and apply_delta() inside its transaction,
 * and after_commit() once its own outermost transaction has returned. When the
 * service runs inside a caller's transaction, that is before the caller's
 * COMMIT, so the wpdb port (WP7) defers its work with Core_Db::after_commit.
 *
 * @package Xophz_Compass_Bazaar
 */
interface Xophz_Compass_Bazaar_Stock_Wc_Port {

  /**
   * Ensure a `_stock` row exists for each product, then lock the rows in
   * ascending id order. The row is the per-product lock anchor.
   *
   * @param int[] $pids
   * @return array pid => float, the locked `_stock` value.
   */
  public function lock_products( array $pids ): array;

  /**
   * Opening balances for a product the ledger has not seen, from WooCommerce
   * data (for example `_compass_multi_inventory`). Location 0 may be omitted:
   * the service puts the residual ($wc_value minus the projectable sum) there.
   *
   * @return array[] List of [loc, qty, enabled].
   */
  public function initial_balances( int $pid, float $wc_value ): array;

  /**
   * @return array|null stock_product_id, product_id, variation_id,
   *   manages_stock, backorders_allowed, location_tracked, name; null when the
   *   product does not exist.
   */
  public function product_info( int $id ): ?array;

  /**
   * Move `_stock` by $projectable_delta (relative, never an absolute set) and
   * mirror the per-location balances. Called inside the transaction.
   *
   * @param array $location_balances loc => on_hand, for every location of the product.
   */
  public function apply_delta( int $pid, float $projectable_delta, array $location_balances ): void;

  /** Cache clean-up, stock status sync and third-party stock actions. */
  public function after_commit( array $pids, string $reason ): void;
}
