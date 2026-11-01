<?php
/**
 * Spec 11.3 steps 8 and 9: turn a committed checkout into a finished
 * WooCommerce order and a completed checkout row.
 *
 * finalize() is safe to run again on the same committed row (a same-key
 * retry or the sweeper):
 * - payment method and title, _pos_split_payments (two or more payments),
 *   the transaction id (one payment with a reference) and date_paid (every
 *   payment captured) are set to the same values each time;
 * - the per-payment notes and the ledger stock note are written once, guarded
 *   by order meta;
 * - the status is on-hold for a pending bacs or cheque payment, processing for
 *   a pending cod payment, else completed;
 * - the row moves to completed with the response stored.
 * Post-complete side effects then run once, guarded by
 * _bazaar_post_complete_done, which is set before they run: a crash midway
 * skips the rest rather than repeating them. They are best effort and never
 * change the response.
 *
 * Concurrency: finalize() runs under Checkout_Repository::lock_finalize(), a
 * MySQL named lock per checkout, and first renews (or, for recovery, takes
 * over) the lease. A second finalizer of the same row waits for the first,
 * then sees the row completed and changes nothing, so status emails, coupon
 * usage and total_sales fire once.
 *
 * The status changes only from pending (the status the order writer leaves).
 * An order that is already cancelled, failed, refunded or trashed (staff
 * cancelled it while the row waited in committed) is never moved back: the
 * row moves to cancelled with error order_cancelled, the payments are left
 * as they are and the stock audit lists the order as cancelled_with_payments.
 * Any other status that differs from the target (staff marked it paid) is
 * kept, with a note.
 *
 * The ledger stock note uses the _stock values the commit recorded inside
 * its transaction (record_stock_changes()), not _stock at finalize time.
 *
 * Not guaranteed: notes are written before their guard is saved, so a crash
 * between the two can repeat a note on the next run.
 *
 * @package Xophz_Compass_Bazaar
 */
class Xophz_Compass_Bazaar_Checkout_Finalizer {

  /** Order statuses finalize() never moves away from. */
  const CLOSED_STATUSES = [ 'cancelled', 'failed', 'refunded', 'trash' ];

  /** Seconds a finalizer waits for another finalizer of the same row. */
  const LOCK_WAIT = 5;

  /** @var array<int,bool> Orders whose status this class is changing right now. */
  private static $finalizing = [];

  /** True while finalize() changes this order's status (Checkout_Payment_Hooks ignores that change). */
  public static function is_finalizing( int $order_id ): bool {
    return ! empty( self::$finalizing[ $order_id ] );
  }

  /**
   * @param array $row  A committed checkout row: held under this request's lease, or with $take, one whose lease expired.
   * @param bool  $take True for recovery (same-key retry, sweeper): take over the expired lease once the finalize lock is held.
   * @return array The completed (or cancelled) row, or the row as another request left it when the lease was lost or live.
   * @throws Xophz_Compass_Bazaar_Core_Http_Error 409 checkout_in_progress when another finalizer of the row holds the lock.
   * @throws RuntimeException When the order is missing.
   */
  public function finalize( array $row, bool $take = false ): array {
    $id = (int) $row['id'];
    if ( ! Xophz_Compass_Bazaar_Checkout_Repository::lock_finalize( $id, self::LOCK_WAIT ) ) {
      throw new Xophz_Compass_Bazaar_Core_Http_Error( 'checkout_in_progress', 'This checkout is still being processed. Check its status shortly.', 409, [ 'retry_after_ms' => Xophz_Compass_Bazaar_Checkout_Service::RETRY_AFTER_MS ] );
    }
    try {
      $current = Xophz_Compass_Bazaar_Checkout_Repository::find( $id ) ?? $row;
      if ( Xophz_Compass_Bazaar_Checkout_State::COMMITTED !== $current['state'] ) {
        return $current;
      }
      $held = $take ? Xophz_Compass_Bazaar_Checkout_Repository::take_expired( $current ) : Xophz_Compass_Bazaar_Checkout_Repository::renew_lease( $row );
      if ( null === $held ) {
        return Xophz_Compass_Bazaar_Checkout_Repository::find( $id ) ?? $current;
      }
      try {
        return $this->finalize_held( $held );
      } catch ( Throwable $e ) {
        // Release the lease this call holds (take_expired gave it a new token the caller does not know), so a retry need not wait it out.
        Xophz_Compass_Bazaar_Checkout_Repository::release_lease( $held );
        throw $e;
      }
    } finally {
      Xophz_Compass_Bazaar_Checkout_Repository::unlock_finalize( $id );
    }
  }

  /**
   * Record, inside the commit transaction, each ledger line's _stock after
   * the sale (read under the commit's row locks) in _bazaar_stock_changes.
   * _stock is read from the product that holds the stock
   * (get_stock_managed_by_id: the parent for a variation whose parent
   * manages stock), as the ledger applies it. Saved with save_meta_data(),
   * so no order save hooks run in the transaction.
   */
  public static function record_stock_changes( WC_Order $order ): void {
    global $wpdb;
    $lines = [];
    $after = [];
    foreach ( Xophz_Compass_Bazaar_Checkout_Order_Writer::stock_lines( $order ) as $line ) {
      $pid     = (int) ( $line['variation_id'] ?: $line['product_id'] );
      $product = wc_get_product( $pid );
      $owner   = $product ? (int) $product->get_stock_managed_by_id() : $pid;
      if ( ! array_key_exists( $owner, $after ) ) {
        $after[ $owner ] = Xophz_Compass_Bazaar_Core_Db::get_var( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_stock' LIMIT 1", [ $owner ] );
      }
      $lines[] = [ 'product_id' => $pid, 'stock_id' => $owner, 'quantity' => (float) $line['quantity'] ];
    }
    $order->update_meta_data( '_bazaar_stock_changes', wp_json_encode( self::stock_steps( $lines, $after ) ) );
    $order->save_meta_data();
  }

  /**
   * Pure: from/to per line, given each stock holder's _stock after the whole
   * sale. Lines that share a holder step through consecutive values in order
   * (the first starts at after + the holder's total quantity). A holder whose
   * _stock is null or '' (not managed) gives no entry.
   *
   * @param array[] $lines {product_id, stock_id, quantity} in order line order.
   * @param array   $after stock_id => _stock after the sale.
   * @return array[] {product_id, stock_id, from, to}
   */
  public static function stock_steps( array $lines, array $after ): array {
    $level = [];
    foreach ( $lines as $line ) {
      $sid = (int) $line['stock_id'];
      if ( isset( $after[ $sid ] ) && '' !== (string) $after[ $sid ] ) {
        $level[ $sid ] = ( $level[ $sid ] ?? (float) $after[ $sid ] ) + (float) $line['quantity'];
      }
    }
    $changes = [];
    foreach ( $lines as $line ) {
      $sid = (int) $line['stock_id'];
      if ( ! isset( $level[ $sid ] ) ) {
        continue;
      }
      $from          = $level[ $sid ];
      $level[ $sid ] = $from - (float) $line['quantity'];
      $changes[]     = [ 'product_id' => (int) $line['product_id'], 'stock_id' => $sid, 'from' => $from, 'to' => $level[ $sid ] ];
    }
    return $changes;
  }

  /** finalize() once the lock and the lease are held. */
  private function finalize_held( array $row ): array {
    $order = wc_get_order( (int) $row['order_id'] );
    if ( ! $order instanceof WC_Order ) {
      throw new RuntimeException( 'The order of checkout ' . (int) $row['id'] . ' is missing.' );
    }
    $payments = Xophz_Compass_Bazaar_Payments_Repository::for_checkout( (int) $row['id'] );
    $current  = (string) $order->get_status();
    if ( in_array( $current, self::CLOSED_STATUSES, true ) ) {
      return $this->closed( $row, $order, $payments, $current );
    }
    $this->set_payment_fields( $order, $payments );
    $this->add_payment_notes( $order, $payments );
    if ( 'ledger' === (string) $row['stock_owner'] ) {
      $this->stock_notes( $order );
    }
    $order->save();

    $status = self::target_status( $payments );
    if ( 'pending' === $current && $status !== $current ) {
      self::$finalizing[ $order->get_id() ] = true;
      try {
        $order->update_status( $status, sprintf( 'Bazaar POS checkout %d completed.', (int) $row['id'] ) );
      } finally {
        unset( self::$finalizing[ $order->get_id() ] );
      }
    } elseif ( $status !== $current && 'yes' !== $order->get_meta( '_bazaar_status_kept' ) ) {
      $order->update_meta_data( '_bazaar_status_kept', 'yes' );
      $order->add_order_note( sprintf( 'Bazaar POS checkout %d completed. The order status was already %s, so it was kept (the sale would have set %s).', (int) $row['id'], $current, $status ) );
      $order->save();
    }

    $body = self::response( $row, $order, $payments );
    $done = Xophz_Compass_Bazaar_Checkout_Repository::transition( $row, Xophz_Compass_Bazaar_Checkout_State::COMPLETED, [
      'completed_at'    => Xophz_Compass_Bazaar_Core_Db::now(),
      'response_status' => 200,
      'response_json'   => wp_json_encode( $body ),
    ] );
    if ( null === $done ) {
      return Xophz_Compass_Bazaar_Checkout_Repository::find( (int) $row['id'] ) ?? $row;
    }
    $this->post_complete( $order, $done );
    return $done;
  }

  /**
   * The order was closed (cancelled, failed, refunded, trashed) before the
   * sale finished: keep that status, note the payments, and cancel the row
   * with order_cancelled. Payments are not voided (spec 11.4: an admin cancel
   * leaves payments to the audit's cancelled_with_payments).
   */
  private function closed( array $row, WC_Order $order, array $payments, string $status ): array {
    $this->add_payment_notes( $order, $payments );
    $order->update_meta_data( '_bazaar_failure_code', 'order_cancelled' );
    $order->add_order_note( sprintf( 'Bazaar POS checkout %d was paid, but this order was already %s before the sale finished. The status was kept; void or refund the %d payment(s) by hand.', (int) $row['id'], $status, count( $payments ) ) );
    $order->save();
    error_log( sprintf( 'Bazaar checkout %d: order %d is %s with %d committed payment(s); the row is cancelled, payments left for review.', (int) $row['id'], (int) $order->get_id(), $status, count( $payments ) ) );
    $err  = new Xophz_Compass_Bazaar_Core_Http_Error( 'order_cancelled', 'The sale was paid, but its order was ' . $status . ' before it finished. The order was left ' . $status . '; void or refund the payments.', 409, [ 'order_id' => (int) $order->get_id(), 'order_status' => $status ] );
    $done = Xophz_Compass_Bazaar_Checkout_Repository::transition( $row, Xophz_Compass_Bazaar_Checkout_State::CANCELLED, Xophz_Compass_Bazaar_Checkout_Service::error_changes( $err, false ) );
    return $done ?? ( Xophz_Compass_Bazaar_Checkout_Repository::find( (int) $row['id'] ) ?? $row );
  }

  /**
   * on-hold for a pending bacs or cheque payment, processing for a pending
   * cod payment, else completed.
   *
   * @param object[] $payments Capture rows.
   */
  public static function target_status( array $payments ): string {
    $status = 'completed';
    foreach ( $payments as $p ) {
      if ( 'pending' !== (string) $p->status || 'manual' !== (string) $p->kind ) {
        continue;
      }
      if ( 'cod' === (string) $p->method_id ) {
        $status = 'on-hold' === $status ? $status : 'processing';
      } else {
        $status = 'on-hold';
      }
    }
    return $status;
  }

  /** The spec 11.1 success body (replayed false). */
  public static function response( array $row, WC_Order $order, array $payments ): array {
    $decimals = function_exists( 'wc_get_price_decimals' ) ? (int) wc_get_price_decimals() : 2;
    $cashier  = get_user_by( 'id', (int) $row['cashier_user_id'] );
    return [
      'client_key'  => (string) $row['client_key'],
      'replayed'    => false,
      'state'       => Xophz_Compass_Bazaar_Checkout_State::COMPLETED,
      'checkout_id' => (int) $row['id'],
      'order'       => [
        'id'          => (int) $order->get_id(),
        'key'         => (string) $order->get_order_key(),
        'status'      => (string) $order->get_status(),
        'receipt_url' => home_url( '/?xophz_bazaar_receipt=' . (int) $order->get_id() . '&key=' . rawurlencode( (string) $order->get_order_key() ) ),
      ],
      'totals'      => [
        'currency'       => (string) $row['currency'],
        'decimals'       => $decimals,
        'subtotal_minor' => (int) $row['subtotal_minor'],
        'discount_minor' => (int) $row['discount_minor'],
        'tax_minor'      => (int) $row['tax_minor'],
        'tip_minor'      => (int) $row['tip_minor'],
        'total_minor'    => (int) $row['total_minor'],
      ],
      'payments'    => array_map( [ self::class, 'payment_array' ], $payments ),
      'cashier'     => [ 'id' => (int) $row['cashier_user_id'], 'name' => $cashier ? (string) $cashier->display_name : 'Unknown' ],
    ];
  }

  /** @param object $p A payment row. */
  public static function payment_array( $p ): array {
    return [
      'payment_uuid'   => (string) $p->payment_uuid,
      'seq'            => (int) $p->seq,
      'method_id'      => (string) $p->method_id,
      'kind'           => (string) $p->kind,
      'status'         => (string) $p->status,
      'verified'       => (bool) (int) $p->verified,
      'amount_minor'   => (int) $p->amount_minor,
      'tendered_minor' => null === $p->tendered_minor ? null : (int) $p->tendered_minor,
      'change_minor'   => null === $p->change_minor ? null : (int) $p->change_minor,
      'reference'      => (string) $p->reference,
    ];
  }

  /** The WooCommerce gateway title when one exists, else the provider's label. */
  public static function method_title( string $method_id ): string {
    if ( function_exists( 'WC' ) && WC() && WC()->payment_gateways() ) {
      $gateways = WC()->payment_gateways()->payment_gateways();
      if ( isset( $gateways[ $method_id ] ) ) {
        $title = trim( wp_strip_all_tags( (string) $gateways[ $method_id ]->get_title() ) );
        if ( '' !== $title ) {
          return $title;
        }
      }
    }
    $provider = Xophz_Compass_Bazaar_Payments_Registry::for_method( $method_id );
    return $provider ? $provider->label( $method_id ) : $method_id;
  }

  private function set_payment_fields( WC_Order $order, array $payments ): void {
    $decimals = function_exists( 'wc_get_price_decimals' ) ? (int) wc_get_price_decimals() : 2;
    if ( ! $payments ) {
      // A zero-total sale: no money moved.
      $order->set_payment_method( '' );
      $order->set_payment_method_title( 'No charge' );
      return;
    }
    if ( 1 === count( $payments ) ) {
      $only = $payments[0];
      $order->set_payment_method( (string) $only->method_id );
      $order->set_payment_method_title( self::method_title( (string) $only->method_id ) );
      if ( '' !== (string) $only->reference ) {
        $order->set_transaction_id( (string) $only->reference );
      }
    } else {
      $order->set_payment_method( 'bazaar_split' );
      $order->set_payment_method_title( 'Split payment' );
      $split = [];
      foreach ( $payments as $p ) {
        $split[] = [
          'method'    => (string) $p->method_id,
          'amount'    => Xophz_Compass_Bazaar_Core_Money::from_minor( (int) $p->amount_minor, $decimals ),
          'reference' => (string) $p->reference,
          'kind'      => (string) $p->kind,
          'status'    => (string) $p->status,
        ];
      }
      $order->update_meta_data( '_pos_split_payments', wp_json_encode( $split ) );
    }
    $all_captured = (bool) $payments;
    foreach ( $payments as $p ) {
      $all_captured = $all_captured && 'captured' === (string) $p->status;
    }
    if ( $all_captured && ! $order->get_date_paid() ) {
      $order->set_date_paid( time() );
    }
  }

  private function add_payment_notes( WC_Order $order, array $payments ): void {
    if ( 'yes' === $order->get_meta( '_bazaar_payment_notes' ) ) {
      return;
    }
    $decimals = function_exists( 'wc_get_price_decimals' ) ? (int) wc_get_price_decimals() : 2;
    $count    = count( $payments );
    if ( ! $count ) {
      $order->add_order_note( 'No payment taken: the sale total is 0.' );
    }
    foreach ( $payments as $p ) {
      $payload = json_decode( (string) $p->provider_payload, true );
      $note    = is_array( $payload ) ? (string) ( $payload['note'] ?? '' ) : '';
      $order->add_order_note( sprintf(
        'Payment %d of %d: %s %s (%s). %s',
        (int) $p->seq,
        $count,
        self::method_title( (string) $p->method_id ),
        Xophz_Compass_Bazaar_Core_Money::from_minor( (int) $p->amount_minor, $decimals ),
        (string) $p->status,
        $note
      ) );
    }
    $order->update_meta_data( '_bazaar_payment_notes', 'yes' );
  }

  /** The "Stock levels reduced" note and low/no-stock actions WooCommerce gives its own sales. */
  private function stock_notes( WC_Order $order ): void {
    if ( 'yes' === $order->get_meta( '_bazaar_stock_notified' ) || ! function_exists( 'wc_trigger_stock_change_notifications' ) ) {
      return;
    }
    $changes  = [];
    $recorded = json_decode( (string) $order->get_meta( '_bazaar_stock_changes' ), true );
    if ( is_array( $recorded ) ) {
      foreach ( $recorded as $c ) {
        $product = is_array( $c ) ? wc_get_product( (int) ( $c['product_id'] ?? 0 ) ) : null;
        if ( $product && $product->managing_stock() ) {
          $changes[] = [ 'product' => $product, 'from' => (float) $c['from'], 'to' => (float) $c['to'] ];
        }
      }
    } else {
      // Rows committed before _bazaar_stock_changes existed: _stock now is the best guess.
      foreach ( Xophz_Compass_Bazaar_Checkout_Order_Writer::stock_lines( $order ) as $line ) {
        $product = wc_get_product( $line['variation_id'] ?: $line['product_id'] );
        if ( ! $product || ! $product->managing_stock() ) {
          continue;
        }
        $to        = (float) $product->get_stock_quantity();
        $changes[] = [ 'product' => $product, 'from' => $to + (float) $line['quantity'], 'to' => $to ];
      }
    }
    if ( $changes ) {
      wc_trigger_stock_change_notifications( $order, $changes );
    }
    $order->update_meta_data( '_bazaar_stock_notified', 'yes' );
  }

  /** Spec 11.3 step 9. Best effort; failures are logged. */
  private function post_complete( WC_Order $order, array $row ): void {
    if ( $order->get_meta( '_bazaar_post_complete_done' ) ) {
      return;
    }
    $order->update_meta_data( '_bazaar_post_complete_done', Xophz_Compass_Bazaar_Core_Db::now() );
    $order->save();
    try {
      $flags       = json_decode( (string) $order->get_meta( '_bazaar_customer_flags' ), true );
      $flags       = is_array( $flags ) ? $flags : [];
      $customer_id = (int) $order->get_customer_id();
      if ( ! $customer_id && ! empty( $flags['create_account'] ) ) {
        $customer_id = $this->create_customer( $order );
      }
      $this->opt_ins( $order, $customer_id, $flags );
      $order->save();
      if ( $customer_id ) {
        do_action( 'xophz_compass_record_action', 'bazaar_pos_purchase', $customer_id, [
          'order_id'       => $order->get_id(),
          'total'          => $order->get_total(),
          'payment_method' => $order->get_payment_method(),
        ] );
      }
      Xophz_Compass_Bazaar_Events_Outbox::record( 'checkout.completed', 'checkout', (int) $row['id'], [
        'order_id'    => (int) $order->get_id(),
        'total_minor' => (int) $row['total_minor'],
        'currency'    => (string) $row['currency'],
        'cashier_id'  => (int) $row['cashier_user_id'],
      ], (int) $row['cashier_user_id'] );
      do_action( 'xophz_compass_bazaar_pos_checkout_completed', $order, $row );
    } catch ( Throwable $e ) {
      error_log( 'Bazaar checkout post-complete failed for order ' . $order->get_id() . ': ' . $e->getMessage() );
    }
  }

  /**
   * Attach the customer account with the billing email, or create one.
   * Returns 0 when neither works, or when the email belongs to a user who is
   * not a customer (a staff account is never attached).
   */
  private function create_customer( WC_Order $order ): int {
    $email = (string) $order->get_billing_email();
    if ( '' === $email || ! function_exists( 'wc_create_new_customer' ) ) {
      return 0;
    }
    $existing = get_user_by( 'email', $email );
    $id       = $existing ? (int) $existing->ID : 0;
    if ( $id && ! Xophz_Compass_Bazaar_Checkout_Order_Writer::is_customer( $id ) ) {
      return 0;
    }
    if ( ! $id ) {
      $new = wc_create_new_customer( $email, wc_create_new_customer_username( $email ), wp_generate_password( 24, true ) );
      if ( is_wp_error( $new ) ) {
        return 0;
      }
      $id = (int) $new;
      foreach ( [ 'first_name' => $order->get_billing_first_name(), 'last_name' => $order->get_billing_last_name() ] as $key => $value ) {
        if ( '' !== (string) $value ) {
          update_user_meta( $id, $key, $value );
          update_user_meta( $id, 'billing_' . $key, $value );
        }
      }
      if ( '' !== (string) $order->get_billing_phone() ) {
        update_user_meta( $id, 'billing_phone', $order->get_billing_phone() );
      }
    }
    $order->set_customer_id( $id );
    return $id;
  }

  /** Legacy opt-in meta: _pos_{marketing|sms}_optin on the order, _marketing_{email|sms}_optin on the customer. */
  private function opt_ins( WC_Order $order, int $customer_id, array $flags ): void {
    foreach ( [ 'marketing' => 'marketing_opt_in', 'sms' => 'sms_opt_in' ] as $key => $flag ) {
      if ( empty( $flags[ $flag ] ) ) {
        continue;
      }
      $at = current_time( 'mysql' );
      $order->update_meta_data( "_pos_{$key}_optin", 'yes' );
      $order->update_meta_data( "_pos_{$key}_optin_at", $at );
      if ( $customer_id ) {
        $channel = 'marketing' === $key ? 'email' : $key;
        update_user_meta( $customer_id, "_marketing_{$channel}_optin", 'yes' );
        update_user_meta( $customer_id, "_marketing_{$channel}_optin_at", $at );
      }
    }
  }
}
