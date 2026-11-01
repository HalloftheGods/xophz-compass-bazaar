<?php
/**
 * A way of taking one tender. Phase 1 providers are pure: no network, no
 * database. Persistence is the caller's job (Payments_Repository, WP11).
 *
 * @package Xophz_Compass_Bazaar
 */
interface Xophz_Compass_Bazaar_Payments_Provider {

  /** cash | external_terminal | manual | test_portal. */
  public function id(): string;

  /** cash | external_terminal | manual | digital. */
  public function kind(): string;

  public function label( string $method_id ): string;

  /** @return array{tendered:bool,reference:bool,authorization_token:bool} */
  public function requirements(): array;

  /** Features: refund_api, void_api, async, verifies_funds. */
  public function supports( string $feature ): bool;

  public function available(): bool;

  /** Read-only. Runs before any order exists. */
  public function precheck( Xophz_Compass_Bazaar_Payments_Tender $t, Xophz_Compass_Bazaar_Payments_Intent $i ): Xophz_Compass_Bazaar_Payments_Result;

  /** Runs inside the commit transaction. No network in Phase 1. */
  public function capture( Xophz_Compass_Bazaar_Payments_Tender $t, Xophz_Compass_Bazaar_Payments_Intent $i ): Xophz_Compass_Bazaar_Payments_Result;

  /** Async completion. Phase 1 providers refuse it. */
  public function confirm( array $payment_row, array $input ): Xophz_Compass_Bazaar_Payments_Result;

  /** Result status is voided or void_required. */
  public function void( array $payment_row, string $reason ): Xophz_Compass_Bazaar_Payments_Result;

  public function refund( array $payment_row, int $amount_minor, array $input ): Xophz_Compass_Bazaar_Payments_Result;

  /** Phase 2. Phase 1 providers return null. */
  public function handle_webhook( WP_REST_Request $request ): ?Xophz_Compass_Bazaar_Payments_Result;
}
