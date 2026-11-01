<?php
/**
 * Per-role staff limits and the approvals a request needs.
 *
 * Spec: docs/plugins/bazaar-core-v2.md section 13.3.
 * - Limits are stored per role in the option bazaar_staff_limits and pass
 *   through the filter xophz_compass_bazaar_staff_limits.
 * - A user's effective limit is the maximum over their roles, key by key.
 *   A role with no entry (and no default) contributes zero.
 * - Holders of bazaar_approve have no limits of their own.
 * - A user without bazaar_discount needs approval for every discount.
 * - A price override always needs approval in Phase 1.
 *
 * Amounts are integers in minor units. Basis points: 100 = 1%.
 *
 * Guaranteed: the pure methods (from_roles, approvals_needed, covers,
 * sanitize) read no database. Not guaranteed: for_user() trusts WordPress
 * roles and capabilities as they are at the moment of the call.
 *
 * @package Xophz_Compass_Bazaar
 */
class Xophz_Compass_Bazaar_Staff_Limits {

  const OPTION = 'bazaar_staff_limits';
  const FILTER = 'xophz_compass_bazaar_staff_limits';

  const KEYS = [ 'discount_bp', 'discount_minor', 'refund_minor', 'custom_item_minor' ];

  const SCOPE_DISCOUNT       = 'discount';
  const SCOPE_REFUND         = 'refund';
  const SCOPE_CUSTOM_ITEM    = 'custom_item';
  const SCOPE_PRICE_OVERRIDE = 'price_override';

  const SCOPES = [ self::SCOPE_DISCOUNT, self::SCOPE_REFUND, self::SCOPE_CUSTOM_ITEM, self::SCOPE_PRICE_OVERRIDE ];

  /** @return array<string,array<string,int>> role => limits */
  public static function defaults(): array {
    return [
      'bazaar_cashier' => [
        'discount_bp'       => 1000,
        'discount_minor'    => 2000,
        'refund_minor'      => 0,
        'custom_item_minor' => 5000,
      ],
    ];
  }

  /**
   * Keep only well-formed roles and the four known keys, as non-negative
   * integers. discount_bp is capped at 10000 (100%). Missing keys become 0.
   *
   * @param mixed $raw
   * @return array<string,array<string,int>>
   */
  public static function sanitize( $raw ): array {
    $out = [];
    if ( ! is_array( $raw ) ) {
      return $out;
    }
    foreach ( $raw as $role => $limits ) {
      if ( ! is_string( $role ) || ! preg_match( '/^[a-z0-9_\-]{1,64}$/', $role ) || ! is_array( $limits ) ) {
        continue;
      }
      $clean = [];
      foreach ( self::KEYS as $key ) {
        $value         = $limits[ $key ] ?? 0;
        $clean[ $key ] = is_numeric( $value ) ? max( 0, (int) $value ) : 0;
      }
      $clean['discount_bp'] = min( 10000, $clean['discount_bp'] );
      $out[ $role ]         = $clean;
    }
    return $out;
  }

  /**
   * The role map: defaults, replaced role by role by the stored option, then
   * passed through the filter. A stored role replaces that role's default
   * entirely.
   *
   * @return array<string,array<string,int>>
   */
  public static function role_map(): array {
    $stored = function_exists( 'get_option' ) ? get_option( self::OPTION, [] ) : [];
    $map    = array_merge( self::defaults(), self::sanitize( $stored ) );
    $map    = apply_filters( self::FILTER, $map );
    return self::sanitize( $map );
  }

  /** Persist a role map (sanitized). Returns the map as stored. */
  public static function save( $raw ): array {
    $clean = self::sanitize( $raw );
    update_option( self::OPTION, $clean, false );
    return $clean;
  }

  /** The marker for a user who needs no approvals. */
  public static function unlimited(): array {
    return [ 'discount_bp' => 0, 'discount_minor' => 0, 'refund_minor' => 0, 'custom_item_minor' => 0, 'unlimited' => true ];
  }

  /**
   * Maximum over $roles, key by key.
   *
   * @param string[]                          $roles
   * @param array<string,array<string,int>>|null $map Defaults to role_map().
   */
  public static function from_roles( array $roles, ?array $map = null ): array {
    $map    = null === $map ? self::role_map() : $map;
    $limits = [ 'discount_bp' => 0, 'discount_minor' => 0, 'refund_minor' => 0, 'custom_item_minor' => 0, 'unlimited' => false ];
    foreach ( $roles as $role ) {
      if ( ! is_string( $role ) || ! isset( $map[ $role ] ) ) {
        continue;
      }
      foreach ( self::KEYS as $key ) {
        $limits[ $key ] = max( $limits[ $key ], (int) ( $map[ $role ][ $key ] ?? 0 ) );
      }
    }
    return $limits;
  }

  /** Effective limits for a WordPress user. Approvers are unlimited. */
  public static function for_user( int $user_id ): array {
    if ( $user_id <= 0 ) {
      return self::from_roles( [] );
    }
    if ( user_can( $user_id, Xophz_Compass_Bazaar_Security_Capabilities::APPROVE ) ) {
      return self::unlimited();
    }
    $user = get_userdata( $user_id );
    return self::from_roles( $user && is_array( $user->roles ) ? $user->roles : [] );
  }

  /** Whether the user holds bazaar_discount (without it every discount needs approval). */
  public static function can_discount( int $user_id ): bool {
    return $user_id > 0 && user_can( $user_id, Xophz_Compass_Bazaar_Security_Capabilities::DISCOUNT );
  }

  /**
   * Rounded up, so a discount is never reported smaller than it is.
   * Zero when the base is not positive.
   */
  public static function basis_points( int $discount_minor, int $base_minor ): int {
    if ( $discount_minor <= 0 || $base_minor <= 0 ) {
      return 0;
    }
    return (int) ceil( ( $discount_minor * 10000 ) / $base_minor );
  }

  /**
   * Approvals a request needs.
   *
   * $req keys (all optional): can_discount (bool, default true),
   * discount_minor, discount_base_minor, custom_item_minor (the largest custom
   * item price), refund_minor, price_override (bool).
   *
   * @return array<int,array{scope:string,amount_minor:int,basis_points:int}>
   */
  public static function approvals_needed( array $limits, array $req ): array {
    if ( ! empty( $limits['unlimited'] ) ) {
      return [];
    }
    $needed   = [];
    $discount = max( 0, (int) ( $req['discount_minor'] ?? 0 ) );
    if ( $discount > 0 ) {
      $bp = self::basis_points( $discount, (int) ( $req['discount_base_minor'] ?? 0 ) );
      if ( ! ( $req['can_discount'] ?? true ) || ! self::within( $limits, self::SCOPE_DISCOUNT, $discount, $bp ) ) {
        $needed[] = [ 'scope' => self::SCOPE_DISCOUNT, 'amount_minor' => $discount, 'basis_points' => $bp ];
      }
    }
    $custom = max( 0, (int) ( $req['custom_item_minor'] ?? 0 ) );
    if ( $custom > 0 && ! self::within( $limits, self::SCOPE_CUSTOM_ITEM, $custom, 0 ) ) {
      $needed[] = [ 'scope' => self::SCOPE_CUSTOM_ITEM, 'amount_minor' => $custom, 'basis_points' => 0 ];
    }
    $refund = max( 0, (int) ( $req['refund_minor'] ?? 0 ) );
    if ( $refund > 0 && ! self::within( $limits, self::SCOPE_REFUND, $refund, 0 ) ) {
      $needed[] = [ 'scope' => self::SCOPE_REFUND, 'amount_minor' => $refund, 'basis_points' => 0 ];
    }
    if ( ! empty( $req['price_override'] ) ) {
      $needed[] = [ 'scope' => self::SCOPE_PRICE_OVERRIDE, 'amount_minor' => 0, 'basis_points' => 0 ];
    }
    return $needed;
  }

  /** Whether an approver with $limits may approve this scope and amount. */
  public static function covers( array $limits, string $scope, int $amount_minor, int $basis_points = 0 ): bool {
    if ( ! in_array( $scope, self::SCOPES, true ) ) {
      return false;
    }
    if ( ! empty( $limits['unlimited'] ) ) {
      return true;
    }
    if ( self::SCOPE_PRICE_OVERRIDE === $scope ) {
      return false;
    }
    return self::within( $limits, $scope, max( 0, $amount_minor ), max( 0, $basis_points ) );
  }

  private static function within( array $limits, string $scope, int $amount, int $bp ): bool {
    switch ( $scope ) {
      case self::SCOPE_DISCOUNT:
        return $amount <= (int) ( $limits['discount_minor'] ?? 0 ) || ( $bp > 0 && $bp <= (int) ( $limits['discount_bp'] ?? 0 ) );
      case self::SCOPE_REFUND:
        return $amount <= (int) ( $limits['refund_minor'] ?? 0 );
      case self::SCOPE_CUSTOM_ITEM:
        return $amount <= (int) ( $limits['custom_item_minor'] ?? 0 );
    }
    return false;
  }
}
