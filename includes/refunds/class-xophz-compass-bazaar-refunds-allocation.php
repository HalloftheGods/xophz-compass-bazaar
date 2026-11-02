<?php
/**
 * Where the money of one refund goes back: which recorded captures of the
 * order, how much on each, and the provider's answer for each.
 *
 * Spec: docs/plugins/bazaar-core-v2.md section 12, step 5.
 *
 * - With `tenders`, each tender names its capture by payment_uuid or by
 *   method_id (newest first); the tenders are authoritative. Their sum must
 *   equal the refund (422 tender_total_mismatch) and each must match a capture
 *   with room left (422 tender_unsupported, refund_exceeds_payments).
 * - Without `tenders`, captures whose refund needs no slip reference (cash,
 *   manual, test portal) are used first and external terminal captures last,
 *   newest first within each group. When a terminal capture still has to take
 *   part, the refusal is 422 reference_required naming that payment, the
 *   amount on it and the tender to send ({payment_uuid, amount_minor,
 *   reference}).
 * - Every planned entry is first put to the provider's refund(); a refusal
 *   refuses the whole plan.
 *
 * Writes nothing: Refunds_Service commits the plan.
 *
 * @package Xophz_Compass_Bazaar
 */
class Xophz_Compass_Bazaar_Refunds_Allocation {

  /**
   * Each entry: capture (row), amount_minor, reference, result (the
   * provider's refund() answer). Empty for an order with no recorded captures.
   *
   * @return array[]
   * @throws Xophz_Compass_Bazaar_Core_Http_Error 422 tender_unsupported, tender_total_mismatch, refund_exceeds_payments, reference_required, or another provider refusal.
   */
  public static function plan( int $order_id, Xophz_Compass_Bazaar_Refunds_Request $req, int $total ): array {
    $all = Xophz_Compass_Bazaar_Payments_Repository::for_order( $order_id, 'capture' );
    if ( ! $all ) {
      if ( $req->tenders ) {
        throw new Xophz_Compass_Bazaar_Core_Http_Error( 'tender_unsupported', 'This order has no recorded payments to refund to.', 422 );
      }
      return [];
    }
    $left     = [];
    $captures = [];
    foreach ( $all as $capture ) {
      $room = (int) $capture->amount_minor - (int) $capture->refunded_minor;
      if ( 'captured' === $capture->status && $room > 0 ) {
        $left[ (int) $capture->id ] = $room;
        $captures[]                 = $capture;
      }
    }
    $plan = [];
    $take = function ( array $pool, int $amount, string $reference, int $index ) use ( &$plan, &$left ) {
      foreach ( $pool as $capture ) {
        $id   = (int) $capture->id;
        $part = min( $amount, $left[ $id ] ?? 0 );
        if ( $part <= 0 ) {
          continue;
        }
        $left[ $id ] -= $part;
        $amount      -= $part;
        if ( isset( $plan[ $id ] ) ) {
          $plan[ $id ]['amount_minor'] += $part;
          $plan[ $id ]['reference']     = '' !== $plan[ $id ]['reference'] ? $plan[ $id ]['reference'] : $reference;
        } else {
          $plan[ $id ] = [ 'capture' => $capture, 'amount_minor' => $part, 'reference' => $reference ];
        }
        if ( 0 === $amount ) {
          break;
        }
      }
      if ( $amount > 0 ) {
        throw new Xophz_Compass_Bazaar_Core_Http_Error( 'refund_exceeds_payments', 'The payments chosen cannot be refunded by this much.', 422, [ 'tender' => $index, 'short_minor' => $amount ] );
      }
    };
    if ( $req->tenders ) {
      $sum = array_sum( array_column( $req->tenders, 'amount_minor' ) );
      if ( $sum !== $total ) {
        throw new Xophz_Compass_Bazaar_Core_Http_Error( 'tender_total_mismatch', 'The tenders add up to ' . $sum . ', not the refund of ' . $total . '.', 422, [ 'refund_minor' => $total, 'tender_minor' => $sum ] );
      }
      foreach ( $req->tenders as $i => $tender ) {
        $pool = array_values( array_filter( $captures, function ( $capture ) use ( $tender ) {
          return '' !== $tender['payment_uuid'] ? $capture->payment_uuid === $tender['payment_uuid'] : $capture->method_id === $tender['method_id'];
        } ) );
        if ( ! $pool ) {
          throw new Xophz_Compass_Bazaar_Core_Http_Error( 'tender_unsupported', 'No payment of this order with room left matches this tender.', 422, [ 'tender' => $i ] );
        }
        $take( $pool, (int) $tender['amount_minor'], (string) $tender['reference'], $i );
      }
    } else {
      $take( self::default_order( $captures ), $total, '', 0 );
    }
    $providers = Xophz_Compass_Bazaar_Payments_Registry::providers();
    foreach ( $plan as $id => $entry ) {
      $capture  = $entry['capture'];
      $provider = $providers[ (string) $capture->provider ] ?? null;
      if ( null === $provider ) {
        throw new Xophz_Compass_Bazaar_Core_Http_Error( 'tender_unsupported', 'No payment provider "' . $capture->provider . '" is available to refund this payment.', 422, [ 'payment_uuid' => (string) $capture->payment_uuid ] );
      }
      $result = $provider->refund( (array) $capture, $entry['amount_minor'], [ 'reference' => $entry['reference'] ] );
      if ( $result->is_refused() && ! $req->tenders && 'reference_required' === $result->code ) {
        throw self::reference_needed( $capture, (int) $entry['amount_minor'], (int) $result->http_status );
      }
      if ( $result->is_refused() ) {
        throw new Xophz_Compass_Bazaar_Core_Http_Error( $result->code, $result->message, $result->http_status, [ 'payment_uuid' => (string) $capture->payment_uuid ] );
      }
      $plan[ $id ]['result'] = $result;
    }
    return array_values( $plan );
  }

  /**
   * The order captures are tried in when the request names no tenders:
   * captures whose refund needs no slip reference first, external terminal
   * captures last, keeping the given (newest first) order within each group.
   * Pure.
   *
   * @param object[] $captures Each with kind and provider.
   * @return object[]
   */
  public static function default_order( array $captures ): array {
    $plain    = [];
    $terminal = [];
    foreach ( $captures as $capture ) {
      if ( 'external_terminal' === (string) ( $capture->kind ?? '' ) || 'external_terminal' === (string) ( $capture->provider ?? '' ) ) {
        $terminal[] = $capture;
      } else {
        $plain[] = $capture;
      }
    }
    return array_merge( $plain, $terminal );
  }

  /**
   * The refusal for a refund without tenders that has to go back to an
   * external terminal capture. Pure.
   */
  public static function reference_needed( $capture, int $amount_minor, int $status = 422 ): Xophz_Compass_Bazaar_Core_Http_Error {
    $last4 = (string) ( $capture->card_last4 ?? '' );
    $card  = '' !== $last4 ? ' (card ending ' . $last4 . ')' : '';
    return new Xophz_Compass_Bazaar_Core_Http_Error(
      'reference_required',
      Xophz_Compass_Bazaar_Core_Money::from_minor( $amount_minor ) . ' of this refund goes back to the card terminal payment' . $card . '. Enter the reference from the terminal refund slip.',
      $status > 0 ? $status : 422,
      [
        'payment_uuid' => (string) $capture->payment_uuid,
        'method_id'    => (string) $capture->method_id,
        'kind'         => (string) $capture->kind,
        'amount_minor' => $amount_minor,
        'card_last4'   => $last4,
        'tender'       => [ 'payment_uuid' => (string) $capture->payment_uuid, 'amount_minor' => $amount_minor, 'reference' => '' ],
      ]
    );
  }

  /** The plan as the response shows it. */
  public static function view( array $plan ): array {
    $out = [];
    foreach ( $plan as $entry ) {
      $capture = $entry['capture'];
      $out[]   = [
        'payment_uuid' => (string) $capture->payment_uuid,
        'method_id'    => (string) $capture->method_id,
        'kind'         => (string) $capture->kind,
        'amount_minor' => (int) $entry['amount_minor'],
        'reference'    => isset( $entry['result'] ) && '' !== $entry['result']->reference ? $entry['result']->reference : (string) $entry['reference'],
      ];
    }
    return $out;
  }
}
