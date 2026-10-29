# Changelog

All notable changes to the Compass Bazaar plugin. Newest first.

## 2026-10-29

### Added
- **Web order fulfillment** (`Xophz_Compass_Bazaar_Admin_Fulfillment`): a `wc-ready-pickup` ("Ready for pickup") order status registered for HPOS and legacy storage, listed after Processing and counted as paid. New AJAX actions `bazaar_get_fulfillment_orders` (open web orders split into Local Pickup and Delivery, POS orders excluded), `bazaar_mark_ready_for_pickup` and `bazaar_complete_fulfillment`. Marking ready texts the customer through Twilio when it is configured and the order has a phone, otherwise adds a customer note that WooCommerce emails; a failed notification never undoes the status change. (#6779)
- **Bulk sale pricing** (`Xophz_Compass_Bazaar_Admin_Promotions`, `Xophz_Compass_Bazaar_Sale_Pricing`): AJAX actions `bazaar_search_sale_products`, `bazaar_preview_sale_prices`, `bazaar_apply_sale_prices` and `bazaar_clear_sale_prices`. Selects by search, category or product ids; sets a fixed sale price or percent off the regular price on each simple product and each variation, with optional native `date_on_sale_from` / `date_on_sale_to`; batches of 25 parent products per request. (#6780)
- `Xophz_Compass_Bazaar_Twilio::is_configured()`, `pickup_ready_message()` and `send_pickup_ready_sms()`.
- `tests/run-tests.php`: plain-PHP tests for the sale price rules and the order status filters.

### Changed
- `Xophz_Compass_Bazaar_Admin_Orders::getOrders()` now builds each row with the new public `map_order_data()`, shared with the fulfillment board. Output is unchanged.

### Security
- All new actions call `Xophz_Compass_Security::enforce_spa_ajax()` (capability plus `wp_rest` nonce): `manage_woocommerce` or `edit_shop_orders` for fulfillment, `manage_woocommerce` or `edit_products` for sale pricing.
