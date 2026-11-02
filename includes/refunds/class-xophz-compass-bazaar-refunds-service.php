<?php
/**
 * Refunds v2: one idempotent refund of order lines.
 *
 * Spec: docs/plugins/bazaar-core-v2.md section 12. refund() runs, in order:
 * 1. dry_run only: amounts and approvals_needed are computed and returned;
 *    nothing is claimed, locked or written.
 * 2. Claim (created_by = the operator, client_key) in Refunds_Repository. The
 *    same key with another body is 422 idempotency_key_reused; a completed key
 *    replays its stored answer with replayed true; a failed key replays its
 *    stored error.
 * 3. Take GET_LOCK('bazaar_order_{id}', 5). Not obtained: 409 order_busy.
 * 4. Read refundable quantities from WooCommerce and compute amounts with
 *    Refunds_Amounts: negative fee lines (legacy order discounts) are spread
 *    over the lines, and each tax rate and the whole refund are held to what
 *    the order still has (Refunds_Tax::settle).
 * 5. Limits: Staff_Limits decides whether a scope=refund approval is needed.
 *    Subject: Refunds_Request::subject_for(). A missing or wrong token is 403
 *    approval_required or approval_invalid.
 * 6. One Core_Db::tx: consume the approval and stamp its id on the refund
 *    row, allocate_refund on the captures Refunds_Allocation chose (the
 *    request's tenders, or else captures needing no slip reference before
 *    external terminal ones, newest first within each) and write the refund
 *    payment rows. Each row copies its capture's provider, method and kind
 *    (cash goes back as cash); the provider's refund() decides whether a
 *    reference is needed (an external terminal needs one).
 * 7. wc_create_refund with refund_payment false and restock_items = restock.
 *    Ledger-owned orders restock through Stock_Order_Lifecycle on
 *    woocommerce_refund_created. If WooCommerce fails, release_refund gives
 *    the allocations back, the payment rows are marked failed and the key is
 *    marked failed.
 * 8. Mark the refund completed, RELEASE_LOCK, answer.
 *
 * Guaranteed: two refunds of the last unit cannot both succeed (the order
 * lock serializes them and WooCommerce is read after it); allocations never
 * exceed a capture. Not guaranteed:
 * - A process that dies between the allocation commit and the end of step 8
 *   leaves the key claimed. A retry with that key then answers 409
 *   refund_interrupted when payment rows exist, and finishes the key from the
 *   WooCommerce refund tagged with _bazaar_refund_row when one exists. A
 *   crash between wc_create_refund and the tag is not detected for an order
 *   with no recorded payments.
 * - A spent approval is not given back when WooCommerce then refuses.
 * - Orders with no recorded payments (not sold through checkout v2) refund in
 *   WooCommerce only; no payment rows are written for them.
 * - Shipping and positive fee lines (tips) are not refunded; negative fee
 *   lines are refunded only as their share of each line.
 *
 * @package Xophz_Compass_Bazaar
 */
class Xophz_Compass_Bazaar_Refunds_Service {

  const LOCK_WAIT = 5;

  /** Errors that leave the key claimed so the same key can be retried. */
  const RETRYABLE = [ 'order_busy', 'approval_required', 'approval_invalid', 'refund_interrupted' ];

  /** Order statuses nothing was paid on. */
  const UNPAID = [ 'pending', 'failed', 'cancelled', 'auto-draft', 'checkout-draft', 'trash' ];

  /**
   * @return array{status:int,body:array}
   * @throws Xophz_Compass_Bazaar_Core_Http_Error
   */
  public function refund( Xophz_Compass_Bazaar_Refunds_Request $req, Xophz_Compass_Bazaar_Security_Context $ctx ): array {
    $order = self::load_order( $req->order_id );
    if ( $req->dry_run ) {
      return [ 'status' => 200, 'body' => $this->preview( $req, $order, $ctx ) ];
    }
    $claim = Xophz_Compass_Bazaar_Refunds_Repository::claim( $ctx->operator_user_id, $req->client_key, [
      'order_id'         => $req->order_id,
      'request_hash'     => $req->request_hash(),
      'restock'          => $req->restock,
      'reason'           => $req->reason,
      'cashier_user_id'  => $ctx->acting_user_id,
      'staff_session_id' => $ctx->staff_session_id > 0 ? $ctx->staff_session_id : null,
    ] );
    if ( $claim['conflict'] ) {
      throw new Xophz_Compass_Bazaar_Core_Http_Error( 'idempotency_key_reused', 'This client_key was used for a different refund.', 422 );
    }
    $done = self::answer( $claim['row'] );
    if ( null !== $done ) {
      return $done;
    }
    $lock = 'bazaar_order_' . $req->order_id;
    if ( '1' !== (string) Xophz_Compass_Bazaar_Core_Db::get_var( 'SELECT GET_LOCK(%s, %d)', [ $lock, self::LOCK_WAIT ] ) ) {
      throw new Xophz_Compass_Bazaar_Core_Http_Error( 'order_busy', 'Another refund of this order is running. Try again in a moment.', 409 );
    }
    try {
      $row  = Xophz_Compass_Bazaar_Refunds_Repository::find( (int) $claim['row']->id );
      $done = $row ? self::answer( $row ) : null;
      if ( null !== $done ) {
        return $done;
      }
      return $this->run( $row ? $row : $claim['row'], $req, $ctx, self::load_order( $req->order_id ) );
    } finally {
      Xophz_Compass_Bazaar_Core_Db::get_var( 'SELECT RELEASE_LOCK(%s)', [ $lock ] );
    }
  }

  /**
   * The answer a finished key gives again, or null while the key is claimed.
   *
   * @return array{status:int,body:array}|null
   * @throws Xophz_Compass_Bazaar_Core_Http_Error The stored error of a failed key.
   */
  private static function answer( $row ): ?array {
    $stored = Xophz_Compass_Bazaar_Refunds_Repository::stored_response( $row );
    if ( Xophz_Compass_Bazaar_Refunds_Repository::STATE_COMPLETED === $row->state ) {
      return [ 'status' => (int) ( $stored['status'] ?? 200 ), 'body' => array_merge( (array) ( $stored['body'] ?? [] ), [ 'replayed' => true ] ) ];
    }
    if ( Xophz_Compass_Bazaar_Refunds_Repository::STATE_FAILED === $row->state ) {
      throw new Xophz_Compass_Bazaar_Core_Http_Error(
        (string) ( $stored['code'] ?? ( $row->error_code ?: 'refund_failed' ) ),
        (string) ( $stored['message'] ?? 'This refund failed earlier.' ),
        (int) ( $stored['status'] ?? 422 ),
        (array) ( $stored['data'] ?? [] )
      );
    }
    return null;
  }

  /** Steps 4 to 8 for a claimed row, with the order lock held. */
  private function run( $row, Xophz_Compass_Bazaar_Refunds_Request $req, Xophz_Compass_Bazaar_Security_Context $ctx, WC_Order $order ): array {
    $recovered = $this->recover( $row, $order );
    if ( null !== $recovered ) {
      return $recovered;
    }
    try {
      $calc  = $this->amounts( $order, $req );
      $needs = $this->approvals( $req, $ctx, $calc['total_minor'] );
      $this->verify_approvals( $needs, $req, $ctx );
      $plan = Xophz_Compass_Bazaar_Refunds_Allocation::plan( (int) $order->get_id(), $req, $calc['total_minor'] );
      Xophz_Compass_Bazaar_Refunds_Repository::set_amounts( (int) $row->id, $calc['total_minor'], $calc['tax_minor'] );
      $rows = $this->commit_allocations( $row, $req, $ctx, $needs, $plan );
    } catch ( Xophz_Compass_Bazaar_Core_Http_Error $e ) {
      if ( ! in_array( $e->error_code(), self::RETRYABLE, true ) ) {
        Xophz_Compass_Bazaar_Refunds_Repository::fail( (int) $row->id, $e->error_code(), self::error_body( $e ) );
      }
      throw $e;
    }
    $refund = $this->create_wc_refund( $row, $req, $order, $calc, $plan, $rows );
    $body   = $this->body( $req, $order, $calc, $plan, (int) $row->id, $refund->get_id() );
    Xophz_Compass_Bazaar_Refunds_Repository::complete( (int) $row->id, (int) $refund->get_id(), [ 'status' => 200, 'body' => $body ] );
    try {
      Xophz_Compass_Bazaar_Events_Outbox::record( 'refund.completed', 'refund', (int) $row->id, [
        'order_id' => $req->order_id, 'wc_refund_id' => (int) $refund->get_id(), 'amount_minor' => $calc['total_minor'],
      ], $ctx->acting_user_id );
    } catch ( Throwable $e ) {
      error_log( 'Bazaar refund ' . (int) $row->id . ': outbox record failed: ' . $e->getMessage() );
    }
    return [ 'status' => 200, 'body' => array_merge( $body, [ 'replayed' => false ] ) ];
  }

  /**
   * A claimed row that an earlier process got part of the way: finish it when
   * its WooCommerce refund exists, refuse when payment rows exist without one.
   *
   * @return array{status:int,body:array}|null
   */
  private function recover( $row, WC_Order $order ): ?array {
    foreach ( $order->get_refunds() as $refund ) {
      if ( (int) $refund->get_meta( '_bazaar_refund_row', true ) === (int) $row->id ) {
        $body = [
          'client_key' => (string) $row->client_key, 'order_id' => (int) $row->order_id, 'refund_id' => (int) $row->id,
          'wc_refund_id' => (int) $refund->get_id(), 'state' => 'completed', 'amount_minor' => (int) $row->amount_minor,
          'tax_minor' => (int) $row->tax_minor, 'recovered' => true,
        ];
        Xophz_Compass_Bazaar_Refunds_Repository::complete( (int) $row->id, (int) $refund->get_id(), [ 'status' => 200, 'body' => $body ] );
        return [ 'status' => 200, 'body' => array_merge( $body, [ 'replayed' => true ] ) ];
      }
    }
    if ( Xophz_Compass_Bazaar_Payments_Repository::for_refund( (int) $row->id ) ) {
      throw new Xophz_Compass_Bazaar_Core_Http_Error( 'refund_interrupted', 'An earlier attempt with this key stopped part way. Check the order before refunding again.', 409 );
    }
    return null;
  }

  /** dry_run: amounts, approvals_needed and the planned allocations. Writes nothing. */
  private function preview( Xophz_Compass_Bazaar_Refunds_Request $req, WC_Order $order, Xophz_Compass_Bazaar_Security_Context $ctx ): array {
    $calc = $this->amounts( $order, $req );
    $body = [
      'dry_run' => true, 'order_id' => $req->order_id, 'currency' => (string) $order->get_currency(),
      'amount_minor' => $calc['total_minor'], 'tax_minor' => $calc['tax_minor'], 'subtotal_minor' => $calc['subtotal_minor'],
      'lines' => $calc['lines'], 'approvals_needed' => $this->approvals( $req, $ctx, $calc['total_minor'] ),
    ];
    try {
      $body['allocations'] = Xophz_Compass_Bazaar_Refunds_Allocation::view( Xophz_Compass_Bazaar_Refunds_Allocation::plan( (int) $order->get_id(), $req, $calc['total_minor'] ) );
    } catch ( Xophz_Compass_Bazaar_Core_Http_Error $e ) {
      $body['allocations']      = [];
      $body['allocation_error'] = self::error_body( $e );
    }
    return $body;
  }

  /** @throws Xophz_Compass_Bazaar_Core_Http_Error 404 order_not_found, 409 order_not_refundable. */
  private static function load_order( int $order_id ): WC_Order {
    $order = wc_get_order( $order_id );
    if ( ! $order instanceof WC_Order || $order instanceof WC_Order_Refund ) {
      throw new Xophz_Compass_Bazaar_Core_Http_Error( 'order_not_found', 'No order with this id.', 404 );
    }
    if ( in_array( $order->get_status(), self::UNPAID, true ) ) {
      throw new Xophz_Compass_Bazaar_Core_Http_Error( 'order_not_refundable', 'An order with status ' . $order->get_status() . ' cannot be refunded.', 409 );
    }
    return $order;
  }

  /**
   * @return array Refunds_Tax::compute() output, after negative fees are spread and the order limits settled.
   * @throws Xophz_Compass_Bazaar_Core_Http_Error 422 invalid_line, refund_empty, refund_exceeds_order.
   */
  private function amounts( WC_Order $order, Xophz_Compass_Bazaar_Refunds_Request $req ): array {
    return Xophz_Compass_Bazaar_Refunds_Amounts::for_lines( $order, $req->lines );
  }

  /**
   * Approvals the acting user needs for this amount, each with its subject_hash.
   *
   * @return array[] {scope, amount_minor, basis_points, subject_hash}
   */
  private function approvals( Xophz_Compass_Bazaar_Refunds_Request $req, Xophz_Compass_Bazaar_Security_Context $ctx, int $total ): array {
    $needed = Xophz_Compass_Bazaar_Staff_Limits::approvals_needed( Xophz_Compass_Bazaar_Staff_Limits::for_user( $ctx->acting_user_id ), [ 'refund_minor' => $total ] );
    foreach ( $needed as $i => $need ) {
      $needed[ $i ]['subject_hash'] = $req->subject_hash( $total );
    }
    return array_values( $needed );
  }

  /** @throws Xophz_Compass_Bazaar_Core_Http_Error 403 approval_required, or the peek's 403 approval_invalid. */
  private function verify_approvals( array $needs, Xophz_Compass_Bazaar_Refunds_Request $req, Xophz_Compass_Bazaar_Security_Context $ctx ): void {
    foreach ( $needs as $need ) {
      if ( '' === $req->approval_token ) {
        throw new Xophz_Compass_Bazaar_Core_Http_Error( 'approval_required', 'A manager approval is needed for this refund.', 403, $need );
      }
      Xophz_Compass_Bazaar_Staff_Approvals::peek( $req->approval_token, (string) $need['scope'], (string) $need['subject_hash'], (int) $need['amount_minor'], $ctx->acting_user_id );
    }
  }

  /**
   * Step 6: spend the approval, stamp it on the refund row, take the capacity
   * and write the refund rows. The stamp is written also for an order with no
   * recorded payments, where no payment row carries it (section 13.4).
   *
   * @return int[] Payment row ids, in plan order.
   */
  private function commit_allocations( $row, Xophz_Compass_Bazaar_Refunds_Request $req, Xophz_Compass_Bazaar_Security_Context $ctx, array $needs, array $plan ): array {
    return Xophz_Compass_Bazaar_Core_Db::tx( function () use ( $row, $req, $ctx, $needs, $plan ) {
      $approval_id = null;
      foreach ( $needs as $need ) {
        $approval_id = Xophz_Compass_Bazaar_Staff_Approvals::consume( $req->approval_token, (string) $need['scope'], (string) $need['subject_hash'], (int) $need['amount_minor'], $ctx->acting_user_id, 'refund:' . (int) $row->id );
      }
      if ( null !== $approval_id ) {
        Xophz_Compass_Bazaar_Refunds_Repository::set_approval( (int) $row->id, (int) $approval_id );
      }
      $ids = [];
      foreach ( $plan as $seq => $entry ) {
        $capture = $entry['capture'];
        $result  = $entry['result'];
        if ( ! Xophz_Compass_Bazaar_Payments_Repository::allocate_refund( (int) $capture->id, $entry['amount_minor'] ) ) {
          throw new Xophz_Compass_Bazaar_Core_Http_Error( 'refund_conflict', 'The payment no longer has room for this refund.', 409, [ 'payment_uuid' => (string) $capture->payment_uuid ] );
        }
        $ids[] = Xophz_Compass_Bazaar_Payments_Repository::insert_refund_row( [
          'refund_id'         => (int) $row->id,
          'parent_payment_id' => (int) $capture->id,
          'order_id'          => (int) $capture->order_id,
          'seq'               => $seq + 1,
          'provider'          => (string) $capture->provider,
          'method_id'         => (string) $capture->method_id,
          'kind'              => (string) $capture->kind,
          'status'            => 'refunded',
          'verified'          => (bool) $result->verified,
          'amount_minor'      => $entry['amount_minor'],
          'currency'          => (string) $capture->currency,
          'reference'         => '' !== $result->reference ? $result->reference : $entry['reference'],
          'provider_ref'      => $result->provider_ref,
          'card_brand'        => '' !== $result->card_brand ? $result->card_brand : (string) $capture->card_brand,
          'card_last4'        => '' !== $result->card_last4 ? $result->card_last4 : (string) $capture->card_last4,
          'operator_user_id'  => $ctx->operator_user_id,
          'cashier_user_id'   => $ctx->acting_user_id,
          'staff_session_id'  => $ctx->staff_session_id > 0 ? $ctx->staff_session_id : null,
          'approval_id'       => $approval_id,
          'provider_payload'  => array_merge( (array) $result->payload, [ 'note' => $result->note ] ),
        ] );
      }
      return $ids;
    } );
  }

  /**
   * Step 7. On failure the allocations are given back and the key is failed.
   *
   * @throws Xophz_Compass_Bazaar_Core_Http_Error 422 refund_rejected, or 500 refund_failed.
   */
  private function create_wc_refund( $row, Xophz_Compass_Bazaar_Refunds_Request $req, WC_Order $order, array $calc, array $plan, array $rows ): WC_Order_Refund {
    $line_items = [];
    foreach ( $calc['lines'] as $line ) {
      $line_items[ $line['order_item_id'] ] = [
        'qty'          => $line['qty'],
        'refund_total' => Xophz_Compass_Bazaar_Core_Money::from_minor( $line['refund_total_minor'] ),
        'refund_tax'   => array_map( function ( $minor ) {
          return Xophz_Compass_Bazaar_Core_Money::from_minor( $minor );
        }, $line['refund_tax'] ),
      ];
    }
    $message = '';
    try {
      $refund = wc_create_refund( [
        'amount'         => Xophz_Compass_Bazaar_Core_Money::from_minor( $calc['total_minor'] ),
        'reason'         => $req->reason,
        'order_id'       => $req->order_id,
        'line_items'     => $line_items,
        'refund_payment' => false,
        'restock_items'  => $req->restock,
      ] );
      if ( $refund instanceof WC_Order_Refund ) {
        $refund->update_meta_data( '_bazaar_refund_row', (int) $row->id );
        $refund->save();
        return $refund;
      }
      $message = is_wp_error( $refund ) ? $refund->get_error_message() : 'WooCommerce did not create the refund.';
    } catch ( Throwable $e ) {
      $message = $e->getMessage();
      error_log( 'Bazaar refund ' . (int) $row->id . ': WooCommerce threw ' . get_class( $e ) . ': ' . $message );
    }
    $this->compensate( $plan, $rows );
    $error = new Xophz_Compass_Bazaar_Core_Http_Error( 'refund_rejected', 'WooCommerce refused the refund: ' . $message, 422 );
    Xophz_Compass_Bazaar_Refunds_Repository::fail( (int) $row->id, 'refund_rejected', self::error_body( $error ) );
    throw $error;
  }

  /** Give the allocations back and mark the refund rows failed. */
  private function compensate( array $plan, array $rows ): void {
    foreach ( $plan as $i => $entry ) {
      Xophz_Compass_Bazaar_Payments_Repository::release_refund( (int) $entry['capture']->id, $entry['amount_minor'] );
      if ( isset( $rows[ $i ] ) ) {
        Xophz_Compass_Bazaar_Payments_Repository::update_status( (int) $rows[ $i ], 'failed' );
      }
    }
  }

  private function body( Xophz_Compass_Bazaar_Refunds_Request $req, WC_Order $order, array $calc, array $plan, int $refund_id, int $wc_refund_id ): array {
    return [
      'client_key'     => $req->client_key,
      'order_id'       => $req->order_id,
      'refund_id'      => $refund_id,
      'wc_refund_id'   => $wc_refund_id,
      'state'          => 'completed',
      'currency'       => (string) $order->get_currency(),
      'amount_minor'   => $calc['total_minor'],
      'tax_minor'      => $calc['tax_minor'],
      'subtotal_minor' => $calc['subtotal_minor'],
      'restock'        => $req->restock,
      'reason'         => $req->reason,
      'lines'          => $calc['lines'],
      'allocations'    => Xophz_Compass_Bazaar_Refunds_Allocation::view( $plan ),
    ];
  }

  /** The stored form of an error: replayed by answer(). */
  private static function error_body( Xophz_Compass_Bazaar_Core_Http_Error $e ): array {
    return [ 'code' => $e->error_code(), 'message' => $e->getMessage(), 'status' => $e->status(), 'data' => array_diff_key( $e->data(), [ 'status' => 1 ] ) ];
  }
}
