<?php
/**
 * Spec 11.3 step 5: check the tenders of a priced sale and plan one provider
 * capture per tender. Read-only: nothing is captured or written here.
 *
 * Guarantees, each refused before any order exists:
 * - SUM(tender amount_minor) equals the priced total, else 422
 *   tender_total_mismatch. A zero total takes no tenders, or one tender of 0
 *   (Checkout_Request allows a 0 amount only as the only tender); such a
 *   tender is not planned, so the sale records no capture row.
 * - A client payment_uuid is used once: a repeat within the request, or one
 *   already stored, is 422 tender_invalid with data.field payment_uuid. Two
 *   concurrent requests with the same new UUID can still both pass; the
 *   second then fails on the unique key inside the commit and rolls back.
 * - Each tender is offered on this register and has an available provider
 *   (else 422 tender_unsupported), and passes the provider's precheck
 *   (refusals keep their own codes).
 *
 * @package Xophz_Compass_Bazaar
 */
final class Xophz_Compass_Bazaar_Checkout_Tender_Plan {

  /**
   * @return array[] seq => {tender, provider, intent}; empty for a zero-total sale.
   * @throws Xophz_Compass_Bazaar_Core_Http_Error On the first refusal.
   */
  public static function build( Xophz_Compass_Bazaar_Checkout_Request $req, int $total, array $row, Xophz_Compass_Bazaar_Security_Context $ctx ): array {
    if ( $req->tender_total_minor() !== $total ) {
      throw new Xophz_Compass_Bazaar_Core_Http_Error( 'tender_total_mismatch', 'The tenders do not add up to the total.', 422, [ 'total_minor' => $total, 'tendered_minor' => $req->tender_total_minor() ] );
    }
    self::check_uuids( $req->tenders );
    $offered = array_column( Xophz_Compass_Bazaar_Payments_Registry::pos_tenders(), 'method_id' );
    $plan    = [];
    foreach ( $req->tenders as $i => $tender ) {
      if ( 0 === $tender->amount_minor ) {
        continue;
      }
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

  /**
   * The index of the first tender whose non-empty payment_uuid repeats an
   * earlier one (case-insensitive), or null. Pure.
   *
   * @param Xophz_Compass_Bazaar_Payments_Tender[] $tenders
   */
  public static function repeated_uuid( array $tenders ): ?int {
    $seen = [];
    foreach ( array_values( $tenders ) as $i => $tender ) {
      $uuid = strtolower( (string) $tender->payment_uuid );
      if ( '' === $uuid ) {
        continue;
      }
      if ( isset( $seen[ $uuid ] ) ) {
        return $i;
      }
      $seen[ $uuid ] = true;
    }
    return null;
  }

  /** @param Xophz_Compass_Bazaar_Payments_Tender[] $tenders */
  private static function check_uuids( array $tenders ): void {
    $repeat = self::repeated_uuid( $tenders );
    if ( null !== $repeat ) {
      throw self::uuid_refusal( $repeat, 'Each tender needs its own payment_uuid.' );
    }
    foreach ( array_values( $tenders ) as $i => $tender ) {
      if ( '' !== (string) $tender->payment_uuid && null !== Xophz_Compass_Bazaar_Payments_Repository::find_by_uuid( (string) $tender->payment_uuid ) ) {
        throw self::uuid_refusal( $i, 'This payment_uuid already belongs to a recorded payment.' );
      }
    }
  }

  private static function uuid_refusal( int $i, string $message ): Xophz_Compass_Bazaar_Core_Http_Error {
    return new Xophz_Compass_Bazaar_Core_Http_Error( 'tender_invalid', $message, 422, [ 'tender' => $i, 'field' => 'payment_uuid' ] );
  }
}
