<?php
/**
 * Checkout v2: submit, status, resume and cancel (spec 11.3 and 11.4).
 *
 * submit() claims the client key, prices the cart, peeks approvals, prechecks
 * stock and tenders, creates the pending order, commits stock, payments and
 * approvals in one Core_Db::tx, then finalizes. Guarantees:
 * - Any refusal before the order exists leaves no order (state failed).
 * - A failure after the order exists cancels the order; no order is deleted.
 * - A failure inside the commit rolls back stock, payment rows and approval
 *   consumption together. The test-portal token is spent last, so it stays
 *   unspent unless the token itself failed.
 * - A finalize failure leaves the row committed and answers 500
 *   finalize_pending; a same-key retry or the sweeper finishes it.
 * Not guaranteed: a deadlock retry of the commit after the test-portal token
 * was spent refuses the token (it is a transient, outside the transaction).
 *
 * Test-only failure injection: apply_filters('xophz_compass_bazaar_test_fail_at',
 * '', $point) returning $point (or true) throws at after_order_created,
 * in_commit or after_commit, only when wp_get_environment_type() is local or
 * development.
 *
 * @package Xophz_Compass_Bazaar
 */
class Xophz_Compass_Bazaar_Checkout_Service {

  const OPTION         = 'bazaar_pos_checkout_v2';
  const ENABLED_FILTER = 'xophz_compass_bazaar_pos_checkout_v2';
  const FAIL_FILTER    = 'xophz_compass_bazaar_test_fail_at';
  const FAIL_ENVS      = [ 'local', 'development' ];
  const RETRY_AFTER_MS = 1500;

  /** Pre-order refusals a same-key retry with the same request hash may restart. */
  const RETRYABLE = [ 'total_changed', 'insufficient_stock', 'approval_required', 'approval_invalid', 'payment_not_authorized', 'stock_changed', 'checkout_interrupted', 'internal' ];

  /** @var Xophz_Compass_Bazaar_Pricing_Pricer */
  private $pricer;
  /** @var Xophz_Compass_Bazaar_Checkout_Order_Writer */
  private $writer;
  /** @var Xophz_Compass_Bazaar_Checkout_Finalizer */
  private $finalizer;

  public function __construct( ?Xophz_Compass_Bazaar_Pricing_Pricer $pricer = null, ?Xophz_Compass_Bazaar_Checkout_Order_Writer $writer = null, ?Xophz_Compass_Bazaar_Checkout_Finalizer $finalizer = null ) {
    $this->pricer    = $pricer ?? new Xophz_Compass_Bazaar_Pricing_Pricer();
    $this->writer    = $writer ?? new Xophz_Compass_Bazaar_Checkout_Order_Writer();
    $this->finalizer = $finalizer ?? new Xophz_Compass_Bazaar_Checkout_Finalizer();
  }

  /** Option bazaar_pos_checkout_v2 (default yes; 0, no, false, off turn it off), then the filter. */
  public static function enabled(): bool {
    $value = get_option( self::OPTION, 'yes' );
    $on    = is_string( $value ) ? ! in_array( strtolower( trim( $value ) ), [ '0', 'no', 'false', 'off' ], true ) : (bool) $value;
    return (bool) apply_filters( self::ENABLED_FILTER, $on );
  }

  /**
   * @return array{status:int,body:array} The response to send.
   * @throws Xophz_Compass_Bazaar_Core_Http_Error Every refusal, with its spec 14.3 code.
   */
  public function submit( Xophz_Compass_Bazaar_Checkout_Request $req, Xophz_Compass_Bazaar_Security_Context $ctx ): array {
    $hash  = $req->request_hash();
    $claim = Xophz_Compass_Bazaar_Checkout_Repository::claim( [
      'client_key'        => $req->client_key,
      'created_by'        => $ctx->operator_user_id,
      'request_hash'      => $hash,
      'operator_user_id'  => $ctx->operator_user_id,
      'cashier_user_id'   => $ctx->acting_user_id,
      'staff_session_id'  => $ctx->staff_session_id,
      'device_id'         => $ctx->device_id,
      'location_id'       => $req->cart->location_id,
      'stock_owner'       => self::stock_owner(),
      'currency'          => function_exists( 'get_woocommerce_currency' ) ? (string) get_woocommerce_currency() : '',
      'quote_hash'        => $req->quote_hash,
      'client_created_at' => $req->client_created_at,
    ] );
    $row = $claim['row'];
    if ( null === $row ) {
      throw new Xophz_Compass_Bazaar_Core_Http_Error( 'internal', 'The checkout could not be recorded.', 500 );
    }
    if ( ! $claim['claimed'] ) {
      $next = $this->branch( $row, $hash );
      if ( isset( $next['response'] ) ) {
        return $next['response'];
      }
      $row = $next['row'];
    }
    return $this->run( $row, $req, $ctx );
  }

  /**
   * The stored response of a finished checkout, or {state, …} while it is in
   * flight. Null when there is no such key ($any_creator false limits it to
   * the operator's own keys).
   */
  public function status( string $client_key, Xophz_Compass_Bazaar_Security_Context $ctx, bool $any_creator ): ?array {
    $row = $any_creator
      ? Xophz_Compass_Bazaar_Checkout_Repository::find_any_by_key( $client_key )
      : Xophz_Compass_Bazaar_Checkout_Repository::find_by_key( $ctx->operator_user_id, $client_key );
    if ( null === $row ) {
      return null;
    }
    $body = Xophz_Compass_Bazaar_Checkout_Repository::response_body( $row );
    if ( Xophz_Compass_Bazaar_Checkout_State::COMPLETED === $row['state'] && $body ) {
      return $body;
    }
    return [
      'client_key'  => (string) $row['client_key'],
      'checkout_id' => (int) $row['id'],
      'state'       => (string) $row['state'],
      'order_id'    => null === $row['order_id'] ? null : (int) $row['order_id'],
      'retryable'   => (bool) $row['retryable'],
      'error'       => '' === (string) $row['error_code'] ? null : [ 'code' => (string) $row['error_code'], 'message' => (string) $row['error_message'], 'status' => (int) $row['response_status'] ],
    ];
  }

  /** Finalize a committed row held under this request's lease. Returns the completed row, or null after logging a failure (the lease is released). */
  public function resume( array $row ): ?array {
    try {
      return $this->finalizer->finalize( $row );
    } catch ( Throwable $e ) {
      error_log( 'Bazaar checkout ' . (int) $row['id'] . ' finalize failed: ' . $e->getMessage() );
      Xophz_Compass_Bazaar_Checkout_Repository::release_lease( $row );
      return null;
    }
  }

  /**
   * Cancel a row held under this request's lease: order_created, or committed
   * (admin or recovery: ledger stock is released and every payment voided
   * through its provider first). The order is cancelled with a note and
   * _bazaar_failure_code; it is never deleted.
   */
  public function cancel( array $row, Xophz_Compass_Bazaar_Core_Http_Error $err ): ?array {
    $order = empty( $row['order_id'] ) ? null : wc_get_order( (int) $row['order_id'] );
    if ( Xophz_Compass_Bazaar_Checkout_State::COMMITTED === $row['state'] ) {
      $this->reverse( $row, $order instanceof WC_Order ? $order : null );
    }
    if ( $order instanceof WC_Order && 'cancelled' !== $order->get_status() ) {
      $order->update_meta_data( '_bazaar_failure_code', $err->error_code() );
      $order->update_status( 'cancelled', sprintf( 'Bazaar POS checkout %d failed (%s): %s The order was cancelled, not deleted.', (int) $row['id'], $err->error_code(), $err->getMessage() ) );
    }
    return Xophz_Compass_Bazaar_Checkout_Repository::transition( $row, Xophz_Compass_Bazaar_Checkout_State::CANCELLED, self::error_changes( $err, false ) );
  }

  /** Throw at a test failure point (local and development only). */
  public static function fail_at( string $point ): void {
    $env = function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production';
    if ( ! in_array( $env, self::FAIL_ENVS, true ) ) {
      return;
    }
    $hit = apply_filters( self::FAIL_FILTER, '', $point );
    if ( $hit === $point || true === $hit ) {
      throw new RuntimeException( 'Injected test failure at ' . $point . '.' );
    }
  }

  /** 'ledger' in ledger mode, else 'wc'. */
  public static function stock_owner(): string {
    return class_exists( 'Xophz_Compass_Bazaar_Stock_Mode' ) && Xophz_Compass_Bazaar_Stock_Mode::is_ledger() ? 'ledger' : 'wc';
  }

  /** Spec 11.3 step 1 for an existing row: a response to send, or the row to run. */
  private function branch( array $row, string $hash ): array {
    $S = 'Xophz_Compass_Bazaar_Checkout_State';
    if ( ! hash_equals( (string) $row['request_hash'], $hash ) ) {
      throw new Xophz_Compass_Bazaar_Core_Http_Error( 'idempotency_key_reused', 'This client key was already used for a different checkout.', 422 );
    }
    $state = (string) $row['state'];
    if ( $S::is_terminal( $state ) || ( $S::FAILED === $state && ! $row['retryable'] ) ) {
      return [ 'response' => self::replay( $row ) ];
    }
    if ( $S::FAILED === $state ) {
      return [ 'row' => Xophz_Compass_Bazaar_Checkout_Order_Writer::restart_clean( $row ) ?? self::throw_in_progress() ];
    }
    if ( Xophz_Compass_Bazaar_Checkout_Repository::lease_live( $row ) ) {
      self::throw_in_progress();
    }
    if ( $S::COMMITTED === $state ) {
      $taken = Xophz_Compass_Bazaar_Checkout_Repository::take_expired( $row ) ?? self::throw_in_progress();
      return [ 'response' => $this->finish( $taken ) ];
    }
    if ( $S::ORDER_CREATED === $state ) {
      $taken = Xophz_Compass_Bazaar_Checkout_Repository::take_expired( $row ) ?? self::throw_in_progress();
      $err   = self::interrupted();
      $this->cancel( $taken, $err );
      throw $err;
    }
    if ( $S::RECEIVED === $state || $S::PRICED === $state ) {
      return [ 'row' => Xophz_Compass_Bazaar_Checkout_Order_Writer::restart_clean( $row ) ?? self::throw_in_progress() ];
    }
    self::throw_in_progress();
  }

  /** Steps 2 to 8 for a received row held under this request's lease. */
  private function run( array $row, Xophz_Compass_Bazaar_Checkout_Request $req, Xophz_Compass_Bazaar_Security_Context $ctx ): array {
    $owner = self::stock_owner();
    try {
      $priced = $this->pricer->price( $req->cart, $ctx );
      $this->check_coupons( $priced );
      if ( null !== $req->expected_total_minor && $req->expected_total_minor !== $priced->total_minor() ) {
        throw new Xophz_Compass_Bazaar_Core_Http_Error( 'total_changed', 'The total changed since the quote. Review the new total and try again.', 409, [ 'quote' => $priced->to_quote_array() ] );
      }
      $approvals = $this->peek_approvals( $priced, $req, $ctx );
      $this->writer->precheck_stock( $priced, $owner );
      $plan = $this->tender_plan( $req, $priced, $row, $ctx );
    } catch ( Throwable $e ) {
      $err = self::as_http_error( $e );
      Xophz_Compass_Bazaar_Checkout_Repository::transition( $row, Xophz_Compass_Bazaar_Checkout_State::FAILED, self::error_changes( $err, in_array( $err->error_code(), self::RETRYABLE, true ) ) );
      throw $err;
    }
    $totals = $priced->totals();
    $row    = Xophz_Compass_Bazaar_Checkout_Repository::transition( $row, Xophz_Compass_Bazaar_Checkout_State::PRICED, [
      'stock_owner'    => $owner,
      'subtotal_minor' => $totals['subtotal_minor'],
      'discount_minor' => $totals['discount_minor'],
      'tax_minor'      => $totals['tax_minor'],
      'tip_minor'      => $totals['tip_minor'],
      'total_minor'    => $totals['total_minor'],
    ] ) ?? self::throw_in_progress();

    $order = null;
    try {
      $order = $this->writer->open( $priced, $row, $ctx );
      $row   = Xophz_Compass_Bazaar_Checkout_Repository::transition( $row, Xophz_Compass_Bazaar_Checkout_State::ORDER_CREATED, [ 'order_id' => $order->get_id() ] ) ?? self::throw_in_progress( true );
      $this->writer->fill( $order, $priced );
      self::fail_at( 'after_order_created' );
      $row = Xophz_Compass_Bazaar_Core_Db::tx( function () use ( $row, $order, $plan, $approvals, $ctx ) {
        return $this->commit( $row, $order, $plan, $approvals, $ctx );
      } );
    } catch ( Throwable $e ) {
      $this->abort( $row, $order, $e );
    }
    return $this->finish( $row );
  }

  /** Step 7, inside Core_Db::tx. Returns the committed row. */
  private function commit( array $row, WC_Order $order, array $plan, array $approvals, Xophz_Compass_Bazaar_Security_Context $ctx ): array {
    $locked = Xophz_Compass_Bazaar_Checkout_Repository::lock( (int) $row['id'] );
    if ( ! $locked || Xophz_Compass_Bazaar_Checkout_State::ORDER_CREATED !== $locked['state'] || (string) $locked['lease_token'] !== (string) $row['lease_token'] ) {
      self::throw_in_progress( true );
    }
    if ( 'ledger' === $row['stock_owner'] ) {
      Xophz_Compass_Bazaar_Stock_Factory::service()->sync_order( (int) $order->get_id(), true, Xophz_Compass_Bazaar_Checkout_Order_Writer::stock_lines( $order ), new Xophz_Compass_Bazaar_Stock_Meta( [
        'actor_user_id'    => $ctx->operator_user_id,
        'cashier_user_id'  => $ctx->acting_user_id,
        'staff_session_id' => $ctx->staff_session_id > 0 ? $ctx->staff_session_id : null,
        'approval_id'      => $approvals ? (int) $approvals[0]['approval_id'] : null,
        'device_id'        => $ctx->device_id,
      ] ) );
    }
    $deferred = [];
    foreach ( $plan as $seq => $leg ) {
      if ( 'test_portal' === $leg['provider']->id() ) {
        $deferred[ $seq ] = $leg;
        continue;
      }
      $this->capture( $leg, (int) $order->get_id() );
    }
    foreach ( $approvals as $a ) {
      Xophz_Compass_Bazaar_Staff_Approvals::consume( $a['token'], $a['scope'], $a['subject_hash'], (int) $a['amount_minor'], $ctx->acting_user_id, 'checkout:' . (int) $row['id'] );
    }
    self::fail_at( 'in_commit' );
    foreach ( $deferred as $leg ) {
      $this->capture( $leg, (int) $order->get_id() );
    }
    $counted = Xophz_Compass_Bazaar_Payments_Repository::counted_total( (int) $row['id'] );
    if ( $counted !== (int) $row['total_minor'] ) {
      throw new RuntimeException( sprintf( 'Checkout %d payments add up to %d, not %d (I4).', (int) $row['id'], $counted, (int) $row['total_minor'] ) );
    }
    Xophz_Compass_Bazaar_Events_Outbox::record( 'checkout.committed', 'checkout', (int) $row['id'], [
      'order_id'    => (int) $order->get_id(),
      'total_minor' => (int) $row['total_minor'],
      'stock_owner' => (string) $row['stock_owner'],
    ], $ctx->acting_user_id );
    return Xophz_Compass_Bazaar_Checkout_Repository::transition( $locked, Xophz_Compass_Bazaar_Checkout_State::COMMITTED, [ 'committed_at' => Xophz_Compass_Bazaar_Core_Db::now() ] ) ?? self::throw_in_progress( true );
  }

  /** One provider capture and its payment row. @throws Xophz_Compass_Bazaar_Core_Http_Error on a refusal. */
  private function capture( array $leg, int $order_id ): void {
    $tender = $leg['tender'];
    $intent = $leg['intent'];
    $intent->order_id = $order_id;
    $result = $leg['provider']->capture( $tender, $intent );
    if ( $result->is_refused() ) {
      throw new Xophz_Compass_Bazaar_Core_Http_Error( $result->code, $result->message, $result->http_status, array_merge( [ 'tender' => $intent->seq - 1 ], isset( $result->payload['reason'] ) ? [ 'reason' => $result->payload['reason'] ] : [] ) );
    }
    if ( ! in_array( $result->status, [ 'captured', 'pending' ], true ) ) {
      throw new RuntimeException( 'Provider ' . $leg['provider']->id() . ' returned ' . $result->status . ' from capture.' );
    }
    Xophz_Compass_Bazaar_Payments_Repository::insert_capture( [
      'payment_uuid'     => $tender->payment_uuid,
      'checkout_id'      => $intent->checkout_id,
      'order_id'         => $order_id,
      'seq'              => $intent->seq,
      'provider'         => $leg['provider']->id(),
      'method_id'        => $tender->method_id,
      'kind'             => $leg['provider']->kind(),
      'status'           => $result->status,
      'verified'         => (bool) $result->verified,
      'amount_minor'     => $tender->amount_minor,
      'tendered_minor'   => $result->payload['tendered_minor'] ?? $tender->tendered_minor,
      'change_minor'     => $result->payload['change_minor'] ?? null,
      'currency'         => $intent->currency,
      'reference'        => '' !== $result->reference ? $result->reference : $tender->reference,
      'provider_ref'     => $result->provider_ref,
      'card_brand'       => '' !== $result->card_brand ? $result->card_brand : $tender->card_brand,
      'card_last4'       => '' !== $result->card_last4 ? $result->card_last4 : $tender->card_last4,
      'operator_user_id' => $intent->operator_user_id,
      'cashier_user_id'  => $intent->cashier_user_id,
      'staff_session_id' => $intent->staff_session_id > 0 ? $intent->staff_session_id : null,
      'provider_payload' => array_merge( (array) $result->payload, [ 'note' => $result->note ] ),
    ] );
  }

  /** Step 8, or 500 finalize_pending with the lease released. */
  private function finish( array $row ): array {
    try {
      self::fail_at( 'after_commit' );
      $done = $this->finalizer->finalize( $row );
    } catch ( Throwable $e ) {
      error_log( 'Bazaar checkout ' . (int) $row['id'] . ' finalize failed: ' . $e->getMessage() );
      Xophz_Compass_Bazaar_Checkout_Repository::release_lease( $row );
      throw new Xophz_Compass_Bazaar_Core_Http_Error( 'finalize_pending', 'The sale is recorded and paid, but finishing the order failed. Retry with the same client key.', 500, [ 'checkout_id' => (int) $row['id'], 'order_id' => (int) $row['order_id'] ] );
    }
    if ( Xophz_Compass_Bazaar_Checkout_State::COMPLETED !== $done['state'] ) {
      self::throw_in_progress();
    }
    $body = Xophz_Compass_Bazaar_Checkout_Repository::response_body( $done ) ?? [];
    return [ 'status' => 200, 'body' => $body ];
  }

  /** A failure once the order may exist: cancel the order (never delete it) and the row, then throw. */
  private function abort( array $row, ?WC_Order $order, Throwable $e ): void {
    $err     = self::as_http_error( $e );
    $current = Xophz_Compass_Bazaar_Checkout_Repository::find( (int) $row['id'] ) ?? $row;
    if ( ! empty( $err->data()['lease_lost'] ) ) {
      // Another request owns the row now; only an order the row never recorded is ours to cancel.
      if ( $order instanceof WC_Order && $order->get_id() && (int) ( $current['order_id'] ?? 0 ) !== (int) $order->get_id() ) {
        $order->update_meta_data( '_bazaar_failure_code', 'checkout_in_progress' );
        $order->update_status( 'cancelled', 'Bazaar POS checkout lost its lease before recording this order; the order was cancelled, not deleted.' );
      }
      throw $err;
    }
    if ( Xophz_Compass_Bazaar_Checkout_State::ORDER_CREATED === $current['state'] && (string) $current['lease_token'] === (string) $row['lease_token'] ) {
      $this->cancel( $current, $err );
    } elseif ( $order instanceof WC_Order && $order->get_id() && 'cancelled' !== $order->get_status() ) {
      $order->update_meta_data( '_bazaar_failure_code', $err->error_code() );
      $order->update_status( 'cancelled', 'Bazaar POS checkout failed before the order was recorded: ' . $err->getMessage() );
      if ( Xophz_Compass_Bazaar_Checkout_State::PRICED === $current['state'] ) {
        Xophz_Compass_Bazaar_Checkout_Repository::transition( $current, Xophz_Compass_Bazaar_Checkout_State::FAILED, self::error_changes( $err, false ) );
      }
    } elseif ( Xophz_Compass_Bazaar_Checkout_State::PRICED === $current['state'] ) {
      Xophz_Compass_Bazaar_Checkout_Order_Writer::cancel_unrecorded( $current, $err->error_code(), $err->getMessage() );
      Xophz_Compass_Bazaar_Checkout_Repository::transition( $current, Xophz_Compass_Bazaar_Checkout_State::FAILED, self::error_changes( $err, true ) );
    }
    throw $err;
  }

  /** Reversal for a committed row: release ledger stock and void each payment, in one transaction. */
  private function reverse( array $row, ?WC_Order $order ): void {
    Xophz_Compass_Bazaar_Core_Db::tx( function () use ( $row, $order ) {
      if ( $order && 'ledger' === $row['stock_owner'] ) {
        Xophz_Compass_Bazaar_Stock_Factory::service()->sync_order( (int) $order->get_id(), false, Xophz_Compass_Bazaar_Checkout_Order_Writer::stock_lines( $order ), new Xophz_Compass_Bazaar_Stock_Meta( [ 'actor_user_id' => (int) get_current_user_id(), 'cashier_user_id' => (int) $row['cashier_user_id'] ] ) );
      }
      $providers = Xophz_Compass_Bazaar_Payments_Registry::providers();
      foreach ( Xophz_Compass_Bazaar_Payments_Repository::for_checkout( (int) $row['id'] ) as $p ) {
        if ( ! in_array( (string) $p->status, Xophz_Compass_Bazaar_Payments_Repository::COUNTED_STATUSES, true ) || ! isset( $providers[ (string) $p->provider ] ) ) {
          continue;
        }
        $result = $providers[ (string) $p->provider ]->void( (array) $p, 'checkout_cancelled' );
        $status = in_array( $result->status, [ 'voided', 'void_required' ], true ) ? $result->status : 'void_required';
        Xophz_Compass_Bazaar_Payments_Repository::update_status( (int) $p->id, $status );
      }
    } );
  }

  /**
   * Step 5: tenders sum to the total, each is offered on this register, has
   * an available provider, and passes its precheck (read-only).
   *
   * @return array[] seq => {tender, provider, intent}
   */
  private function tender_plan( Xophz_Compass_Bazaar_Checkout_Request $req, Xophz_Compass_Bazaar_Pricing_Priced_Cart $priced, array $row, Xophz_Compass_Bazaar_Security_Context $ctx ): array {
    $total = $priced->total_minor();
    if ( $req->tender_total_minor() !== $total ) {
      throw new Xophz_Compass_Bazaar_Core_Http_Error( 'tender_total_mismatch', 'The tenders do not add up to the total.', 422, [ 'total_minor' => $total, 'tendered_minor' => $req->tender_total_minor() ] );
    }
    $offered = array_column( Xophz_Compass_Bazaar_Payments_Registry::pos_tenders(), 'method_id' );
    $plan    = [];
    foreach ( $req->tenders as $i => $tender ) {
      $title    = Xophz_Compass_Bazaar_Checkout_Finalizer::method_title( $tender->method_id );
      $provider = in_array( $tender->method_id, $offered, true ) ? Xophz_Compass_Bazaar_Payments_Registry::for_method( $tender->method_id, $title ) : null;
      if ( null === $provider ) {
        throw new Xophz_Compass_Bazaar_Core_Http_Error( 'tender_unsupported', 'This payment method is not available on the register.', 422, [ 'tender' => $i, 'method_id' => $tender->method_id ] );
      }
      $intent = new Xophz_Compass_Bazaar_Payments_Intent( [
        'checkout_id'      => (int) $row['id'],
        'seq'              => $i + 1,
        'amount_minor'     => $tender->amount_minor,
        'currency'         => (string) $row['currency'],
        'operator_user_id' => $ctx->operator_user_id,
        'cashier_user_id'  => $ctx->acting_user_id,
        'staff_session_id' => $ctx->staff_session_id,
        'method_title'     => $title,
      ] );
      $check = $provider->precheck( $tender, $intent );
      if ( $check->is_refused() ) {
        throw new Xophz_Compass_Bazaar_Core_Http_Error( $check->code, $check->message, $check->http_status, array_merge( [ 'tender' => $i ], isset( $check->payload['reason'] ) ? [ 'reason' => $check->payload['reason'] ] : [] ) );
      }
      $plan[ $i + 1 ] = [ 'tender' => $tender, 'provider' => $provider, 'intent' => $intent ];
    }
    return $plan;
  }

  private function check_coupons( Xophz_Compass_Bazaar_Pricing_Priced_Cart $priced ): void {
    $invalid = $priced->invalid_coupons();
    if ( $invalid ) {
      $list = array_map( function ( $c ) { return [ 'code' => $c['code'], 'message' => $c['message'] ]; }, $invalid );
      throw new Xophz_Compass_Bazaar_Core_Http_Error( 'coupon_invalid', (string) ( $invalid[0]['message'] ?? 'A coupon cannot be applied.' ), 422, [ 'coupons' => $list ] );
    }
  }

  /**
   * Step 3: every approval the cart needs must be covered by a distinct token
   * that passes Staff_Approvals::peek for the acting user.
   *
   * @return array[] Each need plus token and approval_id.
   */
  private function peek_approvals( Xophz_Compass_Bazaar_Pricing_Priced_Cart $priced, Xophz_Compass_Bazaar_Checkout_Request $req, Xophz_Compass_Bazaar_Security_Context $ctx ): array {
    $out  = [];
    $used = [];
    foreach ( $priced->approvals_needed() as $need ) {
      $found = null;
      foreach ( $req->approval_tokens as $k => $token ) {
        if ( isset( $used[ $k ] ) || ! class_exists( 'Xophz_Compass_Bazaar_Staff_Approvals' ) ) {
          continue;
        }
        try {
          $id = Xophz_Compass_Bazaar_Staff_Approvals::peek( $token, (string) $need['scope'], (string) $need['subject_hash'], (int) $need['amount_minor'], $ctx->acting_user_id );
        } catch ( Xophz_Compass_Bazaar_Core_Http_Error $e ) {
          continue;
        }
        $used[ $k ] = true;
        $found      = array_merge( $need, [ 'token' => $token, 'approval_id' => $id ] );
        break;
      }
      if ( null === $found ) {
        $data = [ 'scope' => (string) $need['scope'], 'amount_minor' => (int) $need['amount_minor'], 'basis_points' => (int) ( $need['basis_points'] ?? 0 ), 'subject_hash' => (string) $need['subject_hash'] ];
        throw new Xophz_Compass_Bazaar_Core_Http_Error( 'approval_required', 'A manager approval is needed for this sale.', 403, $data );
      }
      $out[] = $found;
    }
    return $out;
  }

  /** The stored response, marked replayed. */
  private static function replay( array $row ): array {
    $body   = Xophz_Compass_Bazaar_Checkout_Repository::response_body( $row ) ?? [];
    $status = (int) $row['response_status'] ?: 500;
    if ( 200 === $status ) {
      $body['replayed'] = true;
    } else {
      $body['data'] = array_merge( (array) ( $body['data'] ?? [] ), [ 'replayed' => true ] );
    }
    return [ 'status' => $status, 'body' => $body ];
  }

  /** Row changes for a failed or cancelled row, with the error body stored as its response. */
  public static function error_changes( Xophz_Compass_Bazaar_Core_Http_Error $err, bool $retryable ): array {
    return [
      'retryable'       => $retryable,
      'error_code'      => substr( $err->error_code(), 0, 48 ),
      'error_message'   => $err->getMessage(),
      'response_status' => $err->status(),
      'response_json'   => wp_json_encode( [ 'code' => $err->error_code(), 'message' => $err->getMessage(), 'data' => $err->data() ] ),
    ];
  }

  private static function as_http_error( Throwable $e ): Xophz_Compass_Bazaar_Core_Http_Error {
    if ( $e instanceof Xophz_Compass_Bazaar_Core_Http_Error ) {
      return $e;
    }
    error_log( 'Bazaar checkout error: ' . get_class( $e ) . ': ' . $e->getMessage() );
    return new Xophz_Compass_Bazaar_Core_Http_Error( 'internal', 'The sale could not be recorded. Nothing was charged.', 500 );
  }

  public static function interrupted(): Xophz_Compass_Bazaar_Core_Http_Error {
    return new Xophz_Compass_Bazaar_Core_Http_Error( 'checkout_interrupted', 'This checkout was interrupted after its order was created. The order was cancelled and nothing was charged. Start the sale again with a new key.', 409 );
  }

  /**
   * @param bool $lease_lost True when this request lost its lease mid-way: the caller must not touch the order.
   * @throws Xophz_Compass_Bazaar_Core_Http_Error 409 checkout_in_progress
   */
  private static function throw_in_progress( bool $lease_lost = false ) {
    throw new Xophz_Compass_Bazaar_Core_Http_Error( 'checkout_in_progress', 'This checkout is still being processed. Check its status shortly.', 409, array_merge( [ 'retry_after_ms' => self::RETRY_AFTER_MS ], $lease_lost ? [ 'lease_lost' => true ] : [] ) );
  }
}
