<?php
/**
 * Staff password proof: the current WordPress password as proof for
 * PUT /pos/staff/me/pin, counted and locked like a PIN (review fix #6986).
 *
 * Spec: docs/plugins/bazaar-core-v2.md section 13.1.
 * - Five wrong passwords per user per 15-minute window. Once they are used,
 *   every try gets 403 proof_locked (data.retry_after, seconds) without the
 *   password being checked, even when it is right. Never 401: a 401 tells the
 *   SPA the login is gone.
 * - The attempt is claimed before the hash check (Staff_Throttle), so
 *   parallel guesses cannot get past the limit. A right password gives its
 *   slot back.
 * - A wrong password fires wp_login_failed with the user's login, so login
 *   limiters see guesses made through this route too.
 *
 * @package Xophz_Compass_Bazaar
 */
class Xophz_Compass_Bazaar_Staff_Password_Proof {

  const MAX_FAILURES = 5;

  /** @return array<string,int> */
  public static function buckets( int $user_id ): array {
    return [ 'password:user:' . $user_id => self::MAX_FAILURES ];
  }

  /**
   * Returns normally when $password is the user's current password.
   *
   * @throws Xophz_Compass_Bazaar_Core_Http_Error 403 proof_locked, or 403 proof_invalid.
   */
  public static function verify( int $user_id, string $password ): void {
    $now     = time();
    $window  = Xophz_Compass_Bazaar_Staff_Throttle::window( $now );
    $buckets = self::buckets( $user_id );
    if ( ! Xophz_Compass_Bazaar_Staff_Throttle::claim( $buckets, $window ) ) {
      throw new Xophz_Compass_Bazaar_Core_Http_Error( 'proof_locked', 'Too many wrong passwords. Try again in a few minutes.', 403, [ 'retry_after' => Xophz_Compass_Bazaar_Staff_Throttle::seconds_left( $now ) ] );
    }
    $user = get_userdata( $user_id );
    if ( $user && wp_check_password( $password, $user->user_pass, $user_id ) ) {
      Xophz_Compass_Bazaar_Staff_Throttle::release( $buckets, $window );
      return;
    }
    if ( $user ) {
      do_action( 'wp_login_failed', $user->user_login, new WP_Error( 'incorrect_password', 'Wrong current password on PUT /bazaar/v1/pos/staff/me/pin.', [ 'source' => 'bazaar_staff_me_pin' ] ) );
    }
    throw new Xophz_Compass_Bazaar_Core_Http_Error( 'proof_invalid', 'The current password is not correct.', 403 );
  }
}
