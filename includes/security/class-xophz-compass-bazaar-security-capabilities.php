<?php
/**
 * Bazaar capabilities, the three Bazaar roles and the WooCommerce legacy map.
 *
 * Spec: docs/plugins/bazaar-core-v2.md section 6.1 and 6.2.
 *
 * Guarantees:
 * - register() adds one user_has_cap filter. It only ever sets a requested
 *   bazaar_* cap to true, and only when the user holds a mapped WooCommerce
 *   cap. It never removes a cap, never touches a cap outside ALL, and keeps an
 *   explicit false (a deny written by a role editor) as it is.
 * - install_roles() creates the three bazaar_* roles when missing and adds
 *   missing caps to them. It never removes a cap and never edits any role
 *   outside ROLES (administrator and shop_manager are never touched).
 *
 * Not guaranteed: anything about limits or approvals (Staff_Limits, WP9).
 *
 * @package Xophz_Compass_Bazaar
 */
class Xophz_Compass_Bazaar_Security_Capabilities {

  const SELL              = 'bazaar_sell';
  const DISCOUNT          = 'bazaar_discount';
  const REFUND            = 'bazaar_refund';
  const APPROVE           = 'bazaar_approve';
  const VIEW_CUSTOMERS    = 'bazaar_view_customers';
  const MANAGE_ORDERS     = 'bazaar_manage_orders';
  const MANAGE_CATALOG    = 'bazaar_manage_catalog';
  const MANAGE_STOCK      = 'bazaar_manage_stock';
  const RECEIVE           = 'bazaar_receive';
  const MANAGE_PURCHASING = 'bazaar_manage_purchasing';
  const VIEW_REPORTS      = 'bazaar_view_reports';
  const MANAGE_SETTINGS   = 'bazaar_manage_settings';
  const MANAGE_STAFF      = 'bazaar_manage_staff';

  /** Every Bazaar capability (13). */
  const ALL = [
    self::SELL, self::DISCOUNT, self::REFUND, self::APPROVE, self::VIEW_CUSTOMERS,
    self::MANAGE_ORDERS, self::MANAGE_CATALOG, self::MANAGE_STOCK, self::RECEIVE,
    self::MANAGE_PURCHASING, self::VIEW_REPORTS, self::MANAGE_SETTINGS, self::MANAGE_STAFF,
  ];

  /** Role slug => caps the role holds (all granted true). */
  const ROLES = [
    'bazaar_cashier'     => [ 'read', self::SELL, self::DISCOUNT, self::REFUND ],
    'bazaar_supervisor'  => [
      'read', self::SELL, self::DISCOUNT, self::REFUND, self::APPROVE, self::VIEW_CUSTOMERS,
      self::MANAGE_ORDERS, self::VIEW_REPORTS, self::RECEIVE,
    ],
    'bazaar_stock_clerk' => [ 'read', self::MANAGE_STOCK, self::RECEIVE, self::MANAGE_PURCHASING ],
  ];

  /** Role slug => display name used when the role is created. */
  const ROLE_LABELS = [
    'bazaar_cashier'     => 'Bazaar Cashier',
    'bazaar_supervisor'  => 'Bazaar Supervisor',
    'bazaar_stock_clerk' => 'Bazaar Stock Clerk',
  ];

  /** WooCommerce cap => Bazaar caps it implies. */
  const LEGACY = [
    'manage_woocommerce' => self::ALL,
    'edit_shop_orders'   => [
      self::SELL, self::DISCOUNT, self::REFUND, self::APPROVE, self::VIEW_CUSTOMERS,
      self::MANAGE_ORDERS, self::VIEW_REPORTS,
    ],
    'edit_products'      => [ self::MANAGE_CATALOG, self::MANAGE_STOCK, self::RECEIVE, self::MANAGE_PURCHASING ],
  ];

  /** Add the user_has_cap filter (priority 10, 4 arguments). */
  public static function register(): void {
    add_filter( 'user_has_cap', [ __CLASS__, 'filter_user_has_cap' ], 10, 4 );
  }

  /**
   * Bazaar caps implied by the WooCommerce caps held in $allcaps (truthy only).
   * Pure. Returned in ALL order, without duplicates.
   *
   * @param array $allcaps cap => bool, as WordPress passes it.
   * @return string[]
   */
  public static function map_legacy( array $allcaps ): array {
    $granted = [];
    foreach ( self::LEGACY as $woo_cap => $bazaar_caps ) {
      if ( ! empty( $allcaps[ $woo_cap ] ) ) {
        foreach ( $bazaar_caps as $cap ) {
          $granted[ $cap ] = true;
        }
      }
    }
    return array_values( array_filter( self::ALL, function ( $cap ) use ( $granted ) {
      return isset( $granted[ $cap ] );
    } ) );
  }

  /**
   * user_has_cap filter. Sets each requested bazaar_* cap to true when the
   * legacy map grants it, unless $allcaps already holds an explicit false for
   * it. Everything else in $allcaps is returned unchanged.
   *
   * @param array $allcaps Caps the user holds.
   * @param array $caps    Primitive caps being checked.
   * @param array $args    Original has_cap() arguments (unused).
   * @param mixed $user    WP_User (unused).
   * @return array
   */
  public static function filter_user_has_cap( $allcaps, $caps, $args = [], $user = null ) {
    if ( ! is_array( $allcaps ) || ! is_array( $caps ) ) {
      return $allcaps;
    }
    $mapped = null;
    foreach ( $caps as $cap ) {
      if ( ! is_string( $cap ) || ! in_array( $cap, self::ALL, true ) ) {
        continue;
      }
      if ( array_key_exists( $cap, $allcaps ) ) {
        continue; // Already granted, or explicitly denied: leave it.
      }
      if ( null === $mapped ) {
        $mapped = self::map_legacy( $allcaps );
      }
      if ( in_array( $cap, $mapped, true ) ) {
        $allcaps[ $cap ] = true;
      }
    }
    return $allcaps;
  }

  /**
   * Create missing Bazaar roles and add missing caps to existing ones.
   * Never edits a role outside ROLES and never removes a cap. A cap that an
   * existing Bazaar role holds as an explicit false is left false.
   *
   * @return array<string,string[]> role => caps added in this call (empty when nothing changed).
   */
  public static function install_roles(): array {
    $changes = [];
    if ( ! function_exists( 'get_role' ) || ! function_exists( 'add_role' ) ) {
      return $changes;
    }
    foreach ( self::ROLES as $slug => $role_caps ) {
      $role = get_role( $slug );
      if ( ! $role ) {
        add_role( $slug, self::ROLE_LABELS[ $slug ], array_fill_keys( $role_caps, true ) );
        $changes[ $slug ] = $role_caps;
        continue;
      }
      foreach ( $role_caps as $cap ) {
        if ( ! is_array( $role->capabilities ) || ! array_key_exists( $cap, $role->capabilities ) ) {
          $role->add_cap( $cap, true );
          $changes[ $slug ][] = $cap;
        }
      }
    }
    return $changes;
  }
}
