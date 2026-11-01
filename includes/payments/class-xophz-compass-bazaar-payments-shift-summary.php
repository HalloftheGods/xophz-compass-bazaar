<?php
/**
 * Server-side shift totals from the bazaar_payments table.
 *
 * A shift is chosen by staff session id or by a time window (created_at,
 * UTC 'Y-m-d H:i:s', from inclusive, to exclusive), optionally narrowed to one
 * cashier. It reports what the rows say; it does not reconcile a cash drawer.
 *
 * @package Xophz_Compass_Bazaar
 */
class Xophz_Compass_Bazaar_Payments_Shift_Summary {

  /**
   * Totals per method, plus the rows that need a manual void.
   *
   * Capture rows count by status: captured amounts go to `captured_minor`,
   * pending and authorized ones to `pending_minor`. `refunded_minor` sums the
   * refund rows (direction refund) with status refunded. `net_minor` is
   * captured minus refunded. Rows with void_required status are not in any
   * total; they are listed in `void_required`.
   *
   * @param array $scope Either staff_session_id (int) or from and to (strings); optional cashier_user_id (int).
   * @return array{
   *   scope: array, methods: array<string, array>, totals: array<string, int>, void_required: array<int, array>
   * }
   * @throws InvalidArgumentException When the scope has neither a session nor a complete time window.
   */
  public static function summarize( array $scope ): array {
    list( $where, $args ) = self::where( $scope );
    $table                 = Xophz_Compass_Bazaar_Core_Db::table( 'payments' );

    $rows = Xophz_Compass_Bazaar_Core_Db::get_results(
      "SELECT method_id, kind, direction, status, COUNT(*) AS n, COALESCE(SUM(amount_minor), 0) AS amount, COALESCE(SUM(change_minor), 0) AS change_given"
      . " FROM $table WHERE $where GROUP BY method_id, kind, direction, status",
      $args
    );

    $methods = [];
    $totals  = [ 'captured_minor' => 0, 'pending_minor' => 0, 'refunded_minor' => 0, 'net_minor' => 0, 'count' => 0 ];
    foreach ( $rows as $row ) {
      $id = (string) $row->method_id;
      if ( ! isset( $methods[ $id ] ) ) {
        $methods[ $id ] = [ 'kind' => (string) $row->kind, 'captured_minor' => 0, 'pending_minor' => 0, 'refunded_minor' => 0, 'net_minor' => 0, 'change_minor' => 0, 'count' => 0 ];
      }
      $amount = (int) $row->amount;
      $count  = (int) $row->n;
      $bucket = self::bucket( (string) $row->direction, (string) $row->status );
      if ( null === $bucket ) {
        continue;
      }
      $methods[ $id ][ $bucket ] += $amount;
      $methods[ $id ]['count']   += $count;
      if ( 'captured_minor' === $bucket ) {
        $methods[ $id ]['change_minor'] += (int) $row->change_given;
      }
      $totals[ $bucket ] += $amount;
      $totals['count']   += $count;
    }
    foreach ( $methods as $id => $m ) {
      $methods[ $id ]['net_minor'] = $m['captured_minor'] - $m['refunded_minor'];
    }
    $totals['net_minor'] = $totals['captured_minor'] - $totals['refunded_minor'];
    ksort( $methods );

    return [
      'scope'         => $scope,
      'methods'       => $methods,
      'totals'        => $totals,
      'void_required' => self::void_required( $where, $args ),
    ];
  }

  /** @return string|null The total a row adds to, or null when it adds to none. */
  private static function bucket( string $direction, string $status ): ?string {
    if ( 'refund' === $direction ) {
      return 'refunded' === $status ? 'refunded_minor' : null;
    }
    if ( 'captured' === $status ) {
      return 'captured_minor';
    }
    return in_array( $status, [ 'pending', 'authorized' ], true ) ? 'pending_minor' : null;
  }

  /** @return array<int, array> Rows with status void_required, oldest first. */
  private static function void_required( string $where, array $args ): array {
    $table = Xophz_Compass_Bazaar_Core_Db::table( 'payments' );
    $rows  = Xophz_Compass_Bazaar_Core_Db::get_results(
      "SELECT payment_uuid, order_id, method_id, kind, amount_minor, reference, provider_ref, created_at FROM $table"
      . " WHERE $where AND status = 'void_required' ORDER BY id ASC",
      $args
    );
    $out = [];
    foreach ( $rows as $row ) {
      $out[] = [
        'payment_uuid' => (string) $row->payment_uuid,
        'order_id'     => (int) $row->order_id,
        'method_id'    => (string) $row->method_id,
        'kind'         => (string) $row->kind,
        'amount_minor' => (int) $row->amount_minor,
        'reference'    => (string) $row->reference,
        'provider_ref' => (string) $row->provider_ref,
        'created_at'   => (string) $row->created_at,
      ];
    }
    return $out;
  }

  /** @return array{0: string, 1: array} A WHERE clause (no keyword) and its arguments. */
  private static function where( array $scope ): array {
    $parts = [];
    $args  = [];
    if ( ! empty( $scope['staff_session_id'] ) ) {
      $parts[] = 'staff_session_id = %d';
      $args[]  = (int) $scope['staff_session_id'];
    } elseif ( ! empty( $scope['from'] ) && ! empty( $scope['to'] ) ) {
      $parts[] = 'created_at >= %s AND created_at < %s';
      $args[]  = (string) $scope['from'];
      $args[]  = (string) $scope['to'];
    } else {
      throw new InvalidArgumentException( 'A shift summary needs staff_session_id, or both from and to.' );
    }
    if ( ! empty( $scope['cashier_user_id'] ) ) {
      $parts[] = 'cashier_user_id = %d';
      $args[]  = (int) $scope['cashier_user_id'];
    }
    return [ implode( ' AND ', $parts ), $args ];
  }
}
