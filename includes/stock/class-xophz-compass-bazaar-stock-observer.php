<?php
/**
 * Records WooCommerce stock changes in the ledger (spec 8.6).
 *
 * Listens on woocommerce_product_set_stock and woocommerce_variation_set_stock
 * (priority 20), and on updated_post_meta / added_post_meta for the `_stock`
 * key only. For each event it calls Stock_Service::reconcile_from_wc(), which
 * locks the product and records the difference between `_stock` and the
 * projectable sum. Because the difference is taken under lock, a second hook
 * for the same change records nothing.
 *
 * Where the difference is booked (findings 14 and 15 of #6983, attribution()):
 * - a context whose source is an order (a sale, restore or refund restock):
 *   at the location the order's lines of that product were sold from
 *   (Stock_Order_Lifecycle::order_lines, so the item's or order's
 *   _bazaar_location_id, else the default warehouse), when that is an
 *   enabled location of the product's multi-inventory meta; else at 0;
 * - a context whose source is 'inventory' (a legacy location save, which
 *   writes _compass_multi_inventory and then `_stock`): each location to its
 *   meta quantity and flag, the rest at location 0;
 * - anything else: at location 0.
 *
 * Attribution: filters at PHP_INT_MAX push a Stock_Context and return their
 * input unchanged:
 * - woocommerce_can_reduce_order_stock (when it is still true and the order
 *   is not ledger-owned) pushes online_sale, or sale for an order carrying
 *   _bazaar_checkout_id or created via bazaar_pos; woocommerce_reduce_order_stock
 *   pops it.
 * - woocommerce_can_restore_order_stock (when still true) pushes
 *   online_restore; woocommerce_restore_order_stock pops it.
 * - woocommerce_can_restock_refunded_items (when still true and the order is
 *   not ledger-owned) pushes online_refund_restock; woocommerce_refund_created
 *   pops it. It runs after the gateway refund succeeded, so a failed refund
 *   opens nothing.
 * A change seen with no context open is recorded as external, and the
 * outbox row stock.drift.detected is written in the same transaction.
 *
 * Does nothing when the mode is off or while Stock_Wc_Port_Wpdb::writing()
 * is raised (the ledger's own projection). While a Bazaar transaction is open
 * the change is queued with Core_Db::after_commit (the context open at that
 * moment is kept) and dropped on rollback; one queued call per product per
 * transaction.
 *
 * Not guaranteed:
 * - A product with no balances yet is initialized by this call: the opening
 *   batch takes the current `_stock`, so the change that triggered it is not
 *   recorded as its own batch. The backfill exists to make that rare.
 * - A failure (database error, lock timeout) is written to the PHP error log
 *   and swallowed, so WooCommerce's own flow is never interrupted. The
 *   change then stays unrecorded until the next observed change or ledger
 *   write for that product records the difference.
 * - A context whose closing hook never fires (WooCommerce returned early)
 *   stays open until a later close of the same kind
 *   or the end of the request, and attributes changes seen meanwhile.
 *
 * @package Xophz_Compass_Bazaar
 */
final class Xophz_Compass_Bazaar_Stock_Observer {

  const PRIORITY         = 20;
  const CONTEXT_PRIORITY = PHP_INT_MAX;
  const DRIFT_EVENT      = 'stock.drift.detected';
  const EXTERNAL         = 'external';

  /** @var array kind => int[] Stock_Context depths this class pushed. */
  private static $opened = [];

  /** @var array pid => true, queued for the open transaction's commit. */
  private static $queued = [];

  public static function register(): void {
    add_action( 'woocommerce_product_set_stock', [ self::class, 'on_product_stock' ], self::PRIORITY, 1 );
    add_action( 'woocommerce_variation_set_stock', [ self::class, 'on_product_stock' ], self::PRIORITY, 1 );
    add_action( 'updated_post_meta', [ self::class, 'on_post_meta' ], self::PRIORITY, 3 );
    add_action( 'added_post_meta', [ self::class, 'on_post_meta' ], self::PRIORITY, 3 );
    add_filter( 'woocommerce_can_reduce_order_stock', [ self::class, 'open_reduce' ], self::CONTEXT_PRIORITY, 2 );
    add_action( 'woocommerce_reduce_order_stock', [ self::class, 'close_reduce' ], self::CONTEXT_PRIORITY, 0 );
    add_filter( 'woocommerce_can_restore_order_stock', [ self::class, 'open_restore' ], self::CONTEXT_PRIORITY, 2 );
    add_action( 'woocommerce_restore_order_stock', [ self::class, 'close_restore' ], self::CONTEXT_PRIORITY, 0 );
    add_filter( 'woocommerce_can_restock_refunded_items', [ self::class, 'open_refund' ], self::CONTEXT_PRIORITY, 3 );
    add_action( 'woocommerce_refund_created', [ self::class, 'close_refund' ], self::CONTEXT_PRIORITY, 0 );
  }

  /** woocommerce_product_set_stock / woocommerce_variation_set_stock. */
  public static function on_product_stock( $product ): void {
    if ( $product instanceof WC_Product ) {
      self::observe( (int) $product->get_id() );
    }
  }

  /** updated_post_meta / added_post_meta( $meta_id, $object_id, $meta_key ). */
  public static function on_post_meta( $meta_id, $object_id = 0, $meta_key = '' ): void {
    if ( '_stock' === $meta_key ) {
      self::observe( (int) $object_id );
    }
  }

  /**
   * Record (or queue) the difference for one product.
   *
   * @return string 'skipped', 'queued' or 'recorded' (also when nothing differed).
   */
  public static function observe( int $pid ): string {
    if ( $pid <= 0 || Xophz_Compass_Bazaar_Stock_Mode::OFF === Xophz_Compass_Bazaar_Stock_Mode::current() || Xophz_Compass_Bazaar_Stock_Wc_Port_Wpdb::writing() ) {
      return 'skipped';
    }
    $context = Xophz_Compass_Bazaar_Stock_Context::current();
    if ( Xophz_Compass_Bazaar_Core_Db::in_tx() ) {
      self::queue( $pid, $context );
      return 'queued';
    }
    self::record( $pid, $context );
    return 'recorded';
  }

  /**
   * reconcile_from_wc under the context's reason (external without one), plus
   * stock.drift.detected for an external difference, in one transaction.
   * Null when nothing differed or the call failed (logged).
   */
  public static function record( int $pid, ?array $context ): ?Xophz_Compass_Bazaar_Stock_Result {
    $reason = null === $context ? self::EXTERNAL : (string) $context['reason'];
    $source = null === $context ? [] : (array) ( $context['source'] ?? [] );
    $actor  = max( 0, (int) get_current_user_id() );
    try {
      return Xophz_Compass_Bazaar_Core_Db::tx(
        function () use ( $pid, $reason, $source, $actor, $context ) {
          $result = Xophz_Compass_Bazaar_Stock_Factory::service()->reconcile_from_wc( $pid, $reason, new Xophz_Compass_Bazaar_Stock_Meta( [ 'actor_user_id' => $actor ] ), $source, self::attribution( $pid, $source ) );
          if ( $result && null === $context ) {
            self::drift_event( $pid, $result, $actor );
          }
          return $result;
        }
      );
    } catch ( Throwable $e ) {
      error_log( sprintf( 'Bazaar stock observer could not record the WooCommerce stock change of product %d (%s): %s', $pid, $reason, $e->getMessage() ) );
      return null;
    }
  }

  /** woocommerce_can_reduce_order_stock at PHP_INT_MAX; returns $allowed unchanged. */
  public static function open_reduce( $allowed, $order = null ) {
    if ( $allowed && $order instanceof WC_Order && ! Xophz_Compass_Bazaar_Stock_Order_Lifecycle::is_ledger_owned( $order ) ) {
      self::open( 'reduce', self::sale_reason( $order ), [ 'type' => 'order', 'id' => $order->get_id() ] );
    }
    return $allowed;
  }

  public static function close_reduce(): void {
    self::close( 'reduce' );
  }

  /** woocommerce_can_restore_order_stock at PHP_INT_MAX; returns $allowed unchanged. */
  public static function open_restore( $allowed, $order = null ) {
    if ( $allowed && $order instanceof WC_Order ) {
      self::open( 'restore', 'online_restore', [ 'type' => 'order', 'id' => $order->get_id() ] );
    }
    return $allowed;
  }

  public static function close_restore(): void {
    self::close( 'restore' );
  }

  /**
   * woocommerce_can_restock_refunded_items at PHP_INT_MAX; returns $allowed
   * unchanged. WooCommerce runs it inside wc_restock_refunded_items(), after
   * the gateway refund succeeded, so a failed refund never opens a context.
   */
  public static function open_refund( $allowed, $order = null ) {
    if ( $allowed && $order instanceof WC_Order && ! Xophz_Compass_Bazaar_Stock_Order_Lifecycle::is_ledger_owned( $order ) ) {
      self::open( 'refund', 'online_refund_restock', [ 'type' => 'order', 'id' => $order->get_id() ] );
    }
    return $allowed;
  }

  public static function close_refund(): void {
    self::close( 'refund' );
  }

  /**
   * Stock_Service::reconcile_from_wc attribution for a change seen under a
   * context with this source: ['locations' => true] for an inventory save,
   * ['location' => L] when every line of the order for this product's stock
   * key was sold from L, else [] (location 0).
   */
  public static function attribution( int $pid, array $source ): array {
    $type = (string) ( $source['type'] ?? '' );
    if ( 'inventory' === $type ) {
      return [ 'locations' => true ];
    }
    $order = 'order' === $type && (int) ( $source['id'] ?? 0 ) > 0 && function_exists( 'wc_get_order' ) ? wc_get_order( (int) $source['id'] ) : null;
    if ( ! $order instanceof WC_Order ) {
      return [];
    }
    $key  = Xophz_Compass_Bazaar_Stock_Locations::stock_key( $pid );
    $locs = [];
    foreach ( Xophz_Compass_Bazaar_Stock_Order_Lifecycle::order_lines( $order ) as $line ) {
      if ( (int) $line['stock_product_id'] === $key ) {
        $locs[ (int) $line['location_id'] ] = true;
      }
    }
    return 1 === count( $locs ) ? [ 'location' => (int) array_key_first( $locs ) ] : [];
  }

  /** sale for v2 checkouts and legacy POS orders, online_sale otherwise. */
  public static function sale_reason( WC_Order $order ): string {
    $checkout = (string) $order->get_meta( Xophz_Compass_Bazaar_Stock_Order_Lifecycle::CHECKOUT_META, true );
    return '' !== $checkout || 'bazaar_pos' === $order->get_created_via() ? 'sale' : 'online_sale';
  }

  /** Drop the queue and this class's open contexts (tests). */
  public static function reset(): void {
    self::$opened = [];
    self::$queued = [];
  }

  private static function queue( int $pid, ?array $context ): void {
    if ( isset( self::$queued[ $pid ] ) ) {
      return;
    }
    self::$queued[ $pid ] = true;
    Xophz_Compass_Bazaar_Core_Db::after_rollback(
      function () use ( $pid ) {
        unset( self::$queued[ $pid ] );
      }
    );
    Xophz_Compass_Bazaar_Core_Db::after_commit(
      function () use ( $pid, $context ) {
        unset( self::$queued[ $pid ] );
        if ( Xophz_Compass_Bazaar_Stock_Mode::OFF !== Xophz_Compass_Bazaar_Stock_Mode::current() ) {
          self::record( $pid, $context );
        }
      }
    );
  }

  private static function drift_event( int $pid, Xophz_Compass_Bazaar_Stock_Result $result, int $actor ): void {
    $lines = $result->lines();
    $key   = (int) ( $lines[0]['stock_product_id'] ?? $pid );
    $delta = 0.0;
    foreach ( $lines as $line ) {
      $delta += (float) $line['qty_delta'];
    }
    Xophz_Compass_Bazaar_Events_Outbox::record(
      self::DRIFT_EVENT,
      'product',
      $key,
      [
        'stock_product_id' => $key,
        'observed_id'      => $pid,
        'batch_id'         => $result->batch_id(),
        'qty_delta'        => Xophz_Compass_Bazaar_Stock_Keys::number( $delta ),
        'balance_after'    => Xophz_Compass_Bazaar_Stock_Keys::number( (float) ( $lines[ count( $lines ) - 1 ]['balance_after'] ?? 0 ) ),
      ],
      $actor
    );
  }

  private static function open( string $kind, string $reason, array $source ): void {
    Xophz_Compass_Bazaar_Stock_Context::push( $reason, $source );
    self::$opened[ $kind ][] = Xophz_Compass_Bazaar_Stock_Context::depth();
  }

  /** Pop the newest context this class opened for $kind, and anything left open above it. */
  private static function close( string $kind ): void {
    if ( empty( self::$opened[ $kind ] ) ) {
      return;
    }
    $depth = (int) array_pop( self::$opened[ $kind ] );
    while ( Xophz_Compass_Bazaar_Stock_Context::depth() >= $depth && Xophz_Compass_Bazaar_Stock_Context::depth() > 0 ) {
      Xophz_Compass_Bazaar_Stock_Context::pop();
    }
  }
}
