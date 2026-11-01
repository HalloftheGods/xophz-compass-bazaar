<?php
/**
 * One-time manager approvals for over-limit actions.
 *
 * Spec: docs/plugins/bazaar-core-v2.md section 13.4.
 * - create() needs an approver who holds bazaar_approve, passes the PIN check
 *   (lockout applies), has limits that cover the amount, and is not the
 *   requester. The token is returned once; only its sha256 is stored. It
 *   expires after 300 seconds. The outbox records approval.granted.
 * - peek() reads without changing anything and returns the approval id.
 * - consume() is one conditional UPDATE meant to run in the consuming
 *   transaction. It spends the token only when the token, scope, subject hash
 *   and requester match, it is unspent and unexpired, and its amount is at
 *   least the amount needed. Anything other than exactly one changed row is
 *   refused with 403 approval_invalid.
 * - Approval rows are never deleted.
 *
 * Not guaranteed: a refusal does not tell the client which condition failed;
 * only evaluate() (used by tests) names it.
 *
 * @package Xophz_Compass_Bazaar
 */
class Xophz_Compass_Bazaar_Staff_Approvals {

  const TTL_SECONDS = 300;

  /**
   * Why an approval row does not cover a request, or '' when it does. Pure.
   *
   * Reasons: unknown, consumed, expired, scope, subject, requester, amount.
   *
   * @param array|object|null $row
   */
  public static function evaluate( $row, string $scope, string $subject_hash, int $amount_minor, int $requester, int $now ): string {
    if ( ! $row ) {
      return 'unknown';
    }
    $row = (array) $row;
    if ( ! empty( $row['consumed_at'] ) ) {
      return 'consumed';
    }
    if ( strtotime( (string) ( $row['expires_at'] ?? '' ) . ' UTC' ) <= $now ) {
      return 'expired';
    }
    if ( (string) ( $row['scope'] ?? '' ) !== $scope ) {
      return 'scope';
    }
    if ( ! hash_equals( (string) ( $row['subject_hash'] ?? '' ), $subject_hash ) ) {
      return 'subject';
    }
    if ( (int) ( $row['requested_by'] ?? 0 ) !== $requester ) {
      return 'requester';
    }
    if ( (int) ( $row['amount_minor'] ?? 0 ) < $amount_minor ) {
      return 'amount';
    }
    return '';
  }

  public static function hash_token( string $token ): string {
    return hash( 'sha256', $token );
  }

  /**
   * Grant an approval. $in keys: scope, subject_hash, amount_minor,
   * basis_points, approver_user_id, approver_pin, reason, device_id,
   * staff_session_id.
   *
   * @return array{token:string,id:int,expires_at:string,scope:string}
   * @throws Xophz_Compass_Bazaar_Core_Http_Error
   */
  public static function create( int $requester, array $in ): array {
    $scope    = (string) ( $in['scope'] ?? '' );
    $subject  = (string) ( $in['subject_hash'] ?? '' );
    $amount   = (int) ( $in['amount_minor'] ?? 0 );
    $bp       = (int) ( $in['basis_points'] ?? 0 );
    $approver = (int) ( $in['approver_user_id'] ?? 0 );
    $errors   = [];
    if ( ! in_array( $scope, Xophz_Compass_Bazaar_Staff_Limits::SCOPES, true ) ) {
      $errors[] = 'scope';
    }
    if ( ! preg_match( '/^[0-9a-f]{64}$/', $subject ) ) {
      $errors[] = 'subject_hash';
    }
    if ( $amount < 0 ) {
      $errors[] = 'amount_minor';
    }
    if ( $approver <= 0 ) {
      $errors[] = 'approver_user_id';
    }
    if ( $errors ) {
      throw new Xophz_Compass_Bazaar_Core_Http_Error( 'invalid_request', 'The approval request is incomplete.', 400, [ 'errors' => $errors ] );
    }
    if ( $approver === $requester ) {
      throw self::invalid( 'You cannot approve your own action.' );
    }
    if ( ! user_can( $approver, Xophz_Compass_Bazaar_Security_Capabilities::APPROVE ) ) {
      throw self::invalid( 'That person cannot approve.' );
    }
    $device = (string) ( $in['device_id'] ?? '' );
    Xophz_Compass_Bazaar_Staff_Pins::verify( $approver, (string) ( $in['approver_pin'] ?? '' ), $device );
    if ( ! Xophz_Compass_Bazaar_Staff_Limits::covers( Xophz_Compass_Bazaar_Staff_Limits::for_user( $approver ), $scope, $amount, $bp ) ) {
      throw self::invalid( 'That amount is above this approver\'s limit.' );
    }
    $token   = bin2hex( random_bytes( 32 ) );
    $now     = time();
    $expires = gmdate( 'Y-m-d H:i:s', $now + self::TTL_SECONDS );
    $session = (int) ( $in['staff_session_id'] ?? 0 );
    $id      = Xophz_Compass_Bazaar_Core_Db::tx( function () use ( $token, $scope, $subject, $amount, $bp, $requester, $approver, $session, $device, $in, $now, $expires ) {
      $new = Xophz_Compass_Bazaar_Core_Db::insert( Xophz_Compass_Bazaar_Core_Db::table( 'approvals' ), [
        'token_hash'       => self::hash_token( $token ),
        'scope'            => $scope,
        'subject_hash'     => $subject,
        'amount_minor'     => $amount,
        'basis_points'     => $bp,
        'requested_by'     => $requester,
        'approved_by'      => $approver,
        'staff_session_id' => $session > 0 ? $session : null,
        'device_id'        => substr( $device, 0, 64 ),
        'reason'           => substr( sanitize_text_field( (string) ( $in['reason'] ?? '' ) ), 0, 255 ),
        'created_at'       => gmdate( 'Y-m-d H:i:s', $now ),
        'expires_at'       => $expires,
        'consumed_ref'     => '',
      ] );
      Xophz_Compass_Bazaar_Events_Outbox::record( 'approval.granted', 'approval', $new, [ 'scope' => $scope, 'amount_minor' => $amount, 'requested_by' => $requester, 'approved_by' => $approver ], $approver );
      return $new;
    } );
    return [ 'token' => $token, 'id' => (int) $id, 'expires_at' => $expires, 'scope' => $scope ];
  }

  /**
   * Check a token without spending it. Returns the approval id.
   *
   * @throws Xophz_Compass_Bazaar_Core_Http_Error 403 approval_invalid
   */
  public static function peek( string $token, string $scope, string $subject_hash, int $amount_minor, int $requester ): int {
    $row    = '' === $token ? null : self::find( $token );
    $reason = self::evaluate( $row, $scope, $subject_hash, $amount_minor, $requester, time() );
    if ( '' !== $reason ) {
      throw self::invalid( 'The approval is not valid for this action.', $reason );
    }
    return (int) $row->id;
  }

  /**
   * Spend a token. Run it inside the consuming transaction. Returns the
   * approval id.
   *
   * @throws Xophz_Compass_Bazaar_Core_Http_Error 403 approval_invalid
   */
  public static function consume( string $token, string $scope, string $subject_hash, int $amount_minor, int $requester, string $ref ): int {
    if ( '' === $token ) {
      throw self::invalid( 'The approval is not valid for this action.', 'unknown' );
    }
    $table = Xophz_Compass_Bazaar_Core_Db::table( 'approvals' );
    $now   = Xophz_Compass_Bazaar_Core_Db::now();
    $n     = Xophz_Compass_Bazaar_Core_Db::exec(
      "UPDATE $table SET consumed_at = %s, consumed_ref = %s
       WHERE token_hash = %s AND scope = %s AND subject_hash = %s AND requested_by = %d
         AND consumed_at IS NULL AND expires_at > %s AND amount_minor >= %d",
      [ $now, substr( $ref, 0, 64 ), self::hash_token( $token ), $scope, $subject_hash, $requester, $now, $amount_minor ]
    );
    if ( 1 !== $n ) {
      throw self::invalid( 'The approval is not valid for this action.' );
    }
    $row = self::find( $token );
    return $row ? (int) $row->id : 0;
  }

  private static function find( string $token ) {
    $table = Xophz_Compass_Bazaar_Core_Db::table( 'approvals' );
    return Xophz_Compass_Bazaar_Core_Db::get_row( "SELECT * FROM $table WHERE token_hash = %s", [ self::hash_token( $token ) ] );
  }

  private static function invalid( string $message, string $reason = '' ): Xophz_Compass_Bazaar_Core_Http_Error {
    return new Xophz_Compass_Bazaar_Core_Http_Error( 'approval_invalid', $message, 403 );
  }
}
