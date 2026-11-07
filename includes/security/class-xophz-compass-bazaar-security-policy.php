<?php
/**
 * Central capability policy for every Bazaar admin-ajax action.
 *
 * Spec: docs/plugins/bazaar-core-v2.md section 6.3. Keys drop the wp_ajax_
 * prefix. Values list the caps that are accepted: holding any one is enough.
 * Every action also needs a valid wp_rest nonce (Security_Guard checks it).
 *
 * tests/unit/security-policy-test.php keeps AJAX equal to the set of quoted
 * 'wp_ajax_…' keys in admin/*.php and the orchestrator, so a new action that
 * is not listed here fails the unit run (and is refused at runtime).
 *
 * @package Xophz_Compass_Bazaar
 */
class Xophz_Compass_Bazaar_Security_Policy {

  const AJAX = [
    // Admin (orchestrator, includes/class-xophz-compass-bazaar.php)
    'get_products'                       => [ 'bazaar_sell', 'bazaar_manage_catalog', 'bazaar_manage_stock' ],
    'bazaar_lookup_barcode'              => [ 'bazaar_sell', 'bazaar_manage_catalog', 'bazaar_manage_stock' ],
    'get_products_stats'                 => [ 'bazaar_view_reports', 'bazaar_manage_catalog', 'bazaar_manage_stock' ],
    'update_product_stock'               => [ 'bazaar_manage_stock' ],
    'save_product'                       => [ 'bazaar_manage_catalog' ],
    'bazaar_import_products_csv'         => [ 'bazaar_manage_catalog' ],

    // Quick Add (same caps, same order as Admin_Quick_Add::policy())
    'bazaar_quick_lookup'                => [ 'bazaar_manage_catalog', 'bazaar_manage_stock', 'bazaar_receive' ],
    'bazaar_quick_recent'                => [ 'bazaar_manage_catalog', 'bazaar_manage_stock', 'bazaar_receive' ],
    'bazaar_quick_image'                 => [ 'bazaar_manage_catalog', 'bazaar_manage_stock', 'bazaar_receive' ],
    'bazaar_quick_save'                  => [ 'bazaar_manage_stock', 'bazaar_manage_catalog' ],
    'bazaar_quick_undo'                  => [ 'bazaar_manage_stock', 'bazaar_manage_catalog' ],

    // Quick Add "Scan a pile" (same caps, same order as Admin_Quick_Pile::policy())
    'bazaar_pile_add'                    => [ 'bazaar_manage_catalog', 'bazaar_manage_stock', 'bazaar_receive' ],
    'bazaar_pile_list'                   => [ 'bazaar_manage_catalog', 'bazaar_manage_stock', 'bazaar_receive' ],
    'bazaar_pile_set_qty'                => [ 'bazaar_manage_catalog', 'bazaar_manage_stock', 'bazaar_receive' ],
    'bazaar_pile_resolve'                => [ 'bazaar_manage_catalog', 'bazaar_manage_stock', 'bazaar_receive' ],

    // Orders family
    'get_orders'                         => [ 'bazaar_manage_orders', 'bazaar_view_reports' ],
    'update_order_status'                => [ 'bazaar_manage_orders' ],
    'create_pos_order'                   => [ 'bazaar_sell' ],
    'validate_pos_coupon'                => [ 'bazaar_sell' ],
    'send_pos_receipt'                   => [ 'bazaar_sell' ],
    'get_pos_customers'                  => [ 'bazaar_sell' ],
    'email_shift_summary'                => [ 'bazaar_sell' ],
    'get_payment_gateways'               => [ 'bazaar_sell' ],
    'bazaar_pos_gateway_portal'          => [ 'bazaar_sell' ],
    'bazaar_pos_authorize_test_payment'  => [ 'bazaar_sell' ],
    'get_categories'                     => [ 'bazaar_sell', 'bazaar_manage_catalog', 'bazaar_manage_stock' ],
    'bazaar_create_category'             => [ 'bazaar_manage_catalog' ],
    'bazaar_get_coupons'                 => [ 'bazaar_manage_catalog' ],
    'bazaar_save_coupon'                 => [ 'bazaar_manage_catalog' ],
    'bazaar_delete_coupon'               => [ 'bazaar_manage_catalog' ],
    'get_pos_order_for_refund'           => [ 'bazaar_refund', 'bazaar_manage_orders' ],
    'process_pos_refund'                 => [ 'bazaar_refund' ],
    'bazaar_get_bank_details'            => [ 'bazaar_sell', 'bazaar_manage_settings' ],
    'bazaar_save_bank_details'           => [ 'bazaar_manage_settings' ],
    'get_all_payment_gateways'           => [ 'bazaar_manage_settings' ],
    'toggle_payment_gateway'             => [ 'bazaar_manage_settings' ],

    // Fulfillment
    'bazaar_get_fulfillment_orders'      => [ 'bazaar_manage_orders' ],
    'bazaar_mark_ready_for_pickup'       => [ 'bazaar_manage_orders' ],
    'bazaar_complete_fulfillment'        => [ 'bazaar_manage_orders' ],

    // Promotions
    'bazaar_search_sale_products'        => [ 'bazaar_manage_catalog' ],
    'bazaar_preview_sale_prices'         => [ 'bazaar_manage_catalog' ],
    'bazaar_apply_sale_prices'           => [ 'bazaar_manage_catalog' ],
    'bazaar_clear_sale_prices'           => [ 'bazaar_manage_catalog' ],

    // Procurement
    'bazaar_get_suppliers'               => [ 'bazaar_manage_purchasing', 'bazaar_receive' ],
    'bazaar_get_pos'                     => [ 'bazaar_manage_purchasing', 'bazaar_receive' ],
    'bazaar_create_supplier'             => [ 'bazaar_manage_purchasing' ],
    'bazaar_create_po'                   => [ 'bazaar_manage_purchasing' ],
    'bazaar_receive_po'                  => [ 'bazaar_receive' ],

    // Multi_Inventory
    'bazaar_get_inventory_locations'     => [ 'bazaar_sell', 'bazaar_manage_stock' ],
    'bazaar_get_product_inventories'     => [ 'bazaar_manage_stock', 'bazaar_view_reports' ],
    'bazaar_export_inventory_csv'        => [ 'bazaar_manage_stock', 'bazaar_view_reports' ],
    'bazaar_save_inventory_location'     => [ 'bazaar_manage_stock' ],
    'bazaar_delete_inventory_location'   => [ 'bazaar_manage_stock' ],
    'bazaar_save_product_inventories'    => [ 'bazaar_manage_stock' ],
    'bazaar_bulk_update_inventory_stock' => [ 'bazaar_manage_stock' ],
    'bazaar_import_inventory_csv'        => [ 'bazaar_manage_stock' ],

    // Reports
    'get_report'                         => [ 'bazaar_view_reports' ],
    'get_reports'                        => [ 'bazaar_view_reports' ],
    'get_sales_sparkline'                => [ 'bazaar_view_reports' ],
    'get_total_orders'                   => [ 'bazaar_view_reports' ],
    'get_total_sales'                    => [ 'bazaar_view_reports' ],
    'get_total_users'                    => [ 'bazaar_view_reports' ],
    'get_total_views'                    => [ 'bazaar_view_reports' ],

    // Sales
    'get_monthly_sales'                  => [ 'bazaar_view_reports' ],
    'export_monthly_sales_report'        => [ 'bazaar_view_reports' ],
  ];

  /**
   * Caps accepted for an action (no wp_ajax_ prefix), or null when the action
   * is not in the policy. Callers must treat null as "refuse".
   *
   * @return string[]|null
   */
  public static function caps_for( string $action ): ?array {
    return self::AJAX[ $action ] ?? null;
  }
}
