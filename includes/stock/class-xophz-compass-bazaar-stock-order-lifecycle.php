<?php
/**
 * Ledger-owned orders (spec 8.7): WooCommerce stock handling switched off,
 * and the ledger kept in step with the order's status and refunds.
 *
 * An order is ledger-owned when its meta _bazaar_stock_owner is 'ledger'
 * (Checkout v2 sets it in ledger mode). register() runs in every mode, so an
 * order that was sold through the ledger keeps this lifecycle after a
 * rollback to shadow mode.
 *
 * Guarantees, for ledger-owned orders only:
 * - woocommerce_payment_complete_reduce_order_stock,
 *   woocommerce_can_reduce_order_stock, woocommerce_can_restore_order_stock
 *   and woocommerce_can_restock_refunded_items answer false, and
 *   woocommerce_prevent_adjust_line_item_product_stock answers true, at
 *   priority PHP_INT_MAX - 1. WooCommerce therefore never writes `_stock` or
 *   `_reduced_stock` for them through those paths.
 * - woocommerce_order_status_changed: a new status in wc_get_is_paid_statuses()
 *   or on-hold calls Stock_Service::sync_order(committed); cancelled, failed,
 *   or pending reached from one of those statuses calls sync_order(released).
 *   sync_order keys on the order's last movement id, so a repeated hook
 *   records nothing new.
 * - woocommerce_refund_created with restock_items calls restock_refund with
 *   the refund's line quantities (key refund:{id}:restock, so a repeated
 *   hook replays), capped by the service at each line's net outflow.
 *
 * Not guaranteed:
 * - A hook that fails (for example a resale refused for insufficient stock)
 *   does not undo the status change or the refund. The failure is written to
 *   the PHP error log and as an order note, and the order's stock stays as
 *   the ledger last recorded it; Stock_Audit then lists the order.
 * - Item edits made to a ledger-owned order after the sale are not synced
 *   when they are made; the next status change that syncs brings the ledger
 *   to the order's current items.
 *
 * @package Xophz_Compass_Bazaar
 */
final class Xophz_Compass_Bazaar_Stock_Order_Lifecycle {

  const OWNER_META        = '_bazaar_stock_owner';
  const OWNER_LEDGER      = 'ledger';
  const LOCATION_META     = '_bazaar_location_id';
  const CHECKOUT_META     = '_bazaar_checkout_id';
  const RELEASED_STATUSES = [ 'cancelled', 'failed' ];
  const SUPPRESS_PRIORITY = PHP_INT_MAX - 1;

  public static function register(): void {
    add_filter( 'woocommerce_payment_complete_reduce_order_stock', [ self::class, 'filter_payment_complete' ], self::SUPPRESS_PRIORITY, 2 );
    add_filter( 'woocommerce_can_reduce_order_stock', [ self::class, 'filter_order' ], self::SUPPRESS_PRIORITY, 2 );
    add_filter( 'woocommerce_can_restore_order_stock', [ self::class, 'filter_order' ], self::SUPPRESS_PRIORITY, 2 );
    add_filter( 'woocommerce_can_restock_refunded_items', [ self::class, 'filter_order' ], self::SUPPRESS_PRIORITY, 2 );
    add_filter( 'woocommerce_prevent_adjust_line_item_product_stock', [ self::class, 'filter_line_item_adjust' ], self::SUPPRESS_PRIORITY, 2 );
    add_action( 'woocommerce_order_status_changed', [ self::class, 'on_status_changed' ], 10, 4 );
    add_action( 'woocommerce_refund_created', [ self::class, 'on_refund_created' ], 10, 2 );
  }

  /** @param mixed $order A WC_Order or an order id. */
  public static function is_ledger_owned( $order ): bool {
    if ( is_numeric( $order ) ) {
      $order = (int) $order > 0 && function_exists( 'wc_get_order' ) ? wc_get_order( (int) $order ) : null;
    }
    return $order instanceof WC_Order && self::OWNER_LEDGER === (string) $order->get_meta( self::OWNER_META, true );
  }

  /** wc_get_is_paid_statuses() plus on-hold, without the wc- prefix. */
  public static function committed_statuses(): array {
    $paid = function_exists( 'wc_get_is_paid_statuses' ) ? (array) wc_get_is_paid_statuses() : [ 'processing', 'completed' ];
    return array_values( array_unique( array_merge( array_map( [ self::class, 'bare' ], $paid ), [ 'on-hold' ] ) ) );
  }

  /**
   * What a status change does to a ledger-owned order: 'commit', 'release'
   * or '' (nothing).
   *
   * @param string[] $committed committed_statuses().
   */
  public static function action_for( string $from, string $to, array $committed ): string {
    $from = self::bare( $from );
    $to   = self::bare( $to );
    if ( in_array( $to, $committed, true ) ) {
      return 'commit';
    }
    if ( in_array( $to, self::RELEASED_STATUSES, true ) || ( 'pending' === $to && in_array( $from, $committed, true ) ) ) {
      return 'release';
    }
    return '';
  }

  /**
   * The order's line items as Stock_Service order lines, for products that
   * exist and manage stock. location_id is Stock_Locations::sale_location()
   * for the item's _bazaar_location_id meta, else the order's.
   *
   * @return array[] order_item_id, stock_product_id, product_id, variation_id, location_id, quantity.
   */
  public static function order_lines( WC_Order $order ): array {
    $requested = (int) $order->get_meta( self::LOCATION_META, true );
    $port      = Xophz_Compass_Bazaar_Stock_Factory::port();
    $lines     = [];
    foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
      if ( ! $item instanceof WC_Order_Item_Product ) {
        continue;
      }
      $product_id   = (int) $item->get_product_id();
      $variation_id = (int) $item->get_variation_id();
      $info         = $port->product_info( $variation_id ?: $product_id );
      $key          = (int) ( $info['stock_product_id'] ?? 0 );
      $owner        = $key > 0 ? $port->product_info( $key ) : null;
      if ( ! $owner || empty( $owner['manages_stock'] ) ) {
        continue;
      }
      $own_location = (string) $item->get_meta( self::LOCATION_META, true );
      $wanted       = '' !== $own_location ? (int) $own_location : $requested;
      $lines[]      = [
        'order_item_id'    => (int) $item_id,
        'stock_product_id' => $key,
        'product_id'       => $product_id,
        'variation_id'     => $variation_id,
        'location_id'      => Xophz_Compass_Bazaar_Stock_Locations::sale_location( $key, $wanted > 0 ? $wanted : null ),
        'quantity'         => max( 0.0, (float) $item->get_quantity() ),
      ];
    }
    return $lines;
  }

  /**
   * Bring a ledger-owned order's stock to its target now. Throws what the
   * service throws (Stock_Insufficient_Exception on a refused resale), so a
   * caller inside a transaction can roll back.
   */
  public static function sync_order( WC_Order $order, bool $committed ): Xophz_Compass_Bazaar_Stock_Result {
    return Xophz_Compass_Bazaar_Stock_Factory::service()->sync_order( $order->get_id(), $committed, self::order_lines( $order ), self::meta() );
  }

  /** @param mixed $trigger @param mixed $order_id */
  public static function filter_payment_complete( $trigger, $order_id = 0 ) {
    return self::is_ledger_owned( $order_id ) ? false : $trigger;
  }

  /** @param mixed $allowed @param mixed $order */
  public static function filter_order( $allowed, $order = null ) {
    return self::is_ledger_owned( $order ) ? false : $allowed;
  }

  /** @param mixed $prevent @param mixed $item */
  public static function filter_line_item_adjust( $prevent, $item = null ) {
    if ( $item instanceof WC_Order_Item && self::is_ledger_owned( $item->get_order() ) ) {
      return true;
    }
    return $prevent;
  }

  /** woocommerce_order_status_changed( $order_id, $from, $to, $order ). */
  public static function on_status_changed( $order_id, $from = '', $to = '', $order = null ): void {
    $order = $order instanceof WC_Order ? $order : wc_get_order( (int) $order_id );
    if ( ! self::is_ledger_owned( $order ) ) {
      return;
    }
    $action = self::action_for( (string) $from, (string) $to, self::committed_statuses() );
    if ( '' === $action ) {
      return;
    }
    try {
      $result = self::sync_order( $order, 'commit' === $action );
    } catch ( Throwable $e ) {
      self::report_failure( $order, 'commit' === $action ? 'sell' : 'return', $e );
      return;
    }
    if ( ! $result->replayed() && $result->lines() ) {
      $order->add_order_note( sprintf( 'Bazaar stock ledger: stock %s for this order (batch #%d).', 'commit' === $action ? 'taken' : 'returned', $result->batch_id() ) );
    }
  }

  /** woocommerce_refund_created( $refund_id, $args ). */
  public static function on_refund_created( $refund_id, $args = [] ): void {
    $args = is_array( $args ) ? $args : [];
    if ( empty( $args['restock_items'] ) ) {
      return;
    }
    $refund = wc_get_order( (int) $refund_id );
    if ( ! $refund instanceof WC_Order_Refund ) {
      return;
    }
    $order = wc_get_order( (int) $refund->get_parent_id() );
    if ( ! self::is_ledger_owned( $order ) ) {
      return;
    }
    $qtys = self::refund_quantities( $refund, $args );
    if ( ! $qtys ) {
      return;
    }
    try {
      $result = Xophz_Compass_Bazaar_Stock_Factory::service()->restock_refund( $refund->get_id(), $order->get_id(), $qtys, self::order_lines( $order ), self::meta() );
    } catch ( Throwable $e ) {
      self::report_failure( $order, 'restock', $e );
      return;
    }
    if ( ! $result->replayed() && $result->lines() ) {
      $order->add_order_note( sprintf( 'Bazaar stock ledger: refund #%d restocked (batch #%d).', $refund->get_id(), $result->batch_id() ) );
    }
  }

  /**
   * order_item_id => quantity from the refund's items (_refunded_item_id and
   * the absolute quantity), else from $args['line_items'][id]['qty'].
   */
  public static function refund_quantities( WC_Order_Refund $refund, array $args ): array {
    $qtys = [];
    foreach ( $refund->get_items( 'line_item' ) as $item ) {
      $id  = (int) $item->get_meta( '_refunded_item_id', true );
      $qty = abs( (float) $item->get_quantity() );
      if ( $id > 0 && $qty > 0 ) {
        $qtys[ $id ] = ( $qtys[ $id ] ?? 0.0 ) + $qty;
      }
    }
    if ( $qtys ) {
      return $qtys;
    }
    foreach ( (array) ( $args['line_items'] ?? [] ) as $id => $line ) {
      $qty = is_array( $line ) ? abs( (float) ( $line['qty'] ?? 0 ) ) : 0.0;
      if ( (int) $id > 0 && $qty > 0 ) {
        $qtys[ (int) $id ] = $qty;
      }
    }
    return $qtys;
  }

  private static function meta(): Xophz_Compass_Bazaar_Stock_Meta {
    return new Xophz_Compass_Bazaar_Stock_Meta( [ 'actor_user_id' => max( 0, (int) get_current_user_id() ) ] );
  }

  private static function report_failure( WC_Order $order, string $what, Throwable $e ): void {
    error_log( sprintf( 'Bazaar stock ledger could not %s stock for order %d: %s', $what, $order->get_id(), $e->getMessage() ) );
    $order->add_order_note( sprintf( 'Bazaar stock ledger could not %s stock for this order: %s', $what, $e->getMessage() ) );
  }

  private static function bare( $status ): string {
    $status = (string) $status;
    return 0 === strpos( $status, 'wc-' ) ? substr( $status, 3 ) : $status;
  }
}
