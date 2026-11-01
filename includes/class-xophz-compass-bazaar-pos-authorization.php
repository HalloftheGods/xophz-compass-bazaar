<?php

/**
 * Server-side authorization for POS digital payments.
 *
 * The register's "digital" tender (any gateway that is not cash, a card
 * terminal, or a bank transfer) used to be approved by a browser message.
 * Now createPosOrder only marks such an order paid when it carries a
 * one-time token this class issued for the same cashier, gateway and
 * total. The only issuer today is the gateway test portal, which is
 * available in local/development environments (or when explicitly turned
 * on), because no real processor is connected to it.
 *
 * @package    Xophz_Compass_Bazaar
 * @subpackage Xophz_Compass_Bazaar/includes
 */
class Xophz_Compass_Bazaar_Pos_Authorization {

  const TTL_SECONDS      = 900;
  const TRANSIENT_PREFIX = 'bazaar_pos_auth_';

  /** Must match CASH_METHODS / CARD_GATEWAY_PATTERNS / BANK_GATEWAY_PATTERNS in useBazaarPosState.ts. */
  const CASH_METHODS   = [ 'cash', 'cod' ];
  const CARD_PATTERNS  = [ 'card', 'stripe', 'credit', 'square', 'authorize', 'fastlane' ];
  const BANK_PATTERNS  = [ 'bacs', 'bank', 'cheque', 'check', 'wire' ];

  /**
   * Whether the mock gateway portal may run. On in 'local' and 'development'
   * environments, off elsewhere. The XOPHZ_BAZAAR_POS_TEST_PORTAL constant
   * and the xophz_compass_bazaar_pos_test_portal filter override it.
   */
  public static function is_test_portal_enabled(): bool {
    if ( defined( 'XOPHZ_BAZAAR_POS_TEST_PORTAL' ) ) {
      $enabled = (bool) XOPHZ_BAZAAR_POS_TEST_PORTAL;
    } else {
      $env     = function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production';
      $enabled = in_array( $env, [ 'local', 'development' ], true );
    }
    return (bool) apply_filters( 'xophz_compass_bazaar_pos_test_portal', $enabled );
  }

  /**
   * True for a payment method the register sends through the digital
   * payment dialog: not split, not cash, not a card terminal, not a bank
   * transfer. Uses the same patterns as the SPA's tender routing.
   */
  public static function requires_authorization( string $method_id, string $title = '' ): bool {
    $method = strtolower( $method_id );
    $label  = strtolower( $title );
    if ( '' === $method || 'bazaar_split' === $method || in_array( $method, self::CASH_METHODS, true ) ) {
      return false;
    }
    foreach ( self::CARD_PATTERNS as $pattern ) {
      if ( false !== strpos( $method, $pattern ) || false !== strpos( $label, $pattern ) ) {
        return false;
      }
    }
    foreach ( self::BANK_PATTERNS as $pattern ) {
      if ( false !== strpos( $method, $pattern ) ) {
        return false;
      }
    }
    return true;
  }

  public static function to_cents( $amount ): int {
    return (int) round( (float) $amount * 100 );
  }

  /**
   * Issue a one-time token for this cashier, gateway and amount.
   *
   * @return string The token (only its hash is stored).
   */
  public static function issue( int $user_id, string $gateway, $amount, ?int $now = null ): string {
    $token = wp_generate_password( 40, false, false );
    set_transient( self::key( $token ), self::record( $user_id, $gateway, $amount, $now ?? time() ), self::TTL_SECONDS );
    return $token;
  }

  /**
   * Check and spend a token. It is deleted on first use, whether or not it
   * matched, so a token can never be replayed.
   *
   * @return string '' when valid, otherwise why it was refused.
   */
  public static function consume( string $token, int $user_id, string $gateway, $amount, ?int $now = null ): string {
    if ( '' === $token ) {
      return 'missing';
    }
    $key    = self::key( $token );
    $record = get_transient( $key );
    delete_transient( $key );
    return self::check( $record, $user_id, $gateway, $amount, $now ?? time() );
  }

  /**
   * Check a token without spending it. Same answers as consume(), but the
   * token stays valid. Not a reservation: two callers can both peek the same
   * token, so the one that spends it (consume) is the real gate.
   *
   * @return string '' when it would pass, otherwise why it would be refused.
   */
  public static function peek( string $token, int $user_id, string $gateway, $amount, ?int $now = null ): string {
    if ( '' === $token ) {
      return 'missing';
    }
    return self::check( get_transient( self::key( $token ) ), $user_id, $gateway, $amount, $now ?? time() );
  }

  /** Pure comparison used by consume() and peek(). */
  public static function check( $record, int $user_id, string $gateway, $amount, int $now ): string {
    if ( ! is_array( $record ) ) {
      return 'unknown or already used';
    }
    if ( (int) ( $record['expires'] ?? 0 ) < $now ) {
      return 'expired';
    }
    if ( (int) ( $record['user_id'] ?? 0 ) !== $user_id ) {
      return 'issued to another cashier';
    }
    if ( (string) ( $record['gateway'] ?? '' ) !== $gateway ) {
      return 'issued for another payment method';
    }
    if ( (int) ( $record['cents'] ?? -1 ) !== self::to_cents( $amount ) ) {
      return 'issued for a different total';
    }
    return '';
  }

  public static function record( int $user_id, string $gateway, $amount, int $now ): array {
    return [
      'user_id' => $user_id,
      'gateway' => $gateway,
      'cents'   => self::to_cents( $amount ),
      'expires' => $now + self::TTL_SECONDS,
    ];
  }

  private static function key( string $token ): string {
    return self::TRANSIENT_PREFIX . hash( 'sha256', $token );
  }
}
