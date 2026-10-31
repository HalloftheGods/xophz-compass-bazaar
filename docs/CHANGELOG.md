# Changelog

All notable changes to the Compass Bazaar plugin. Newest first.

## 2026-10-30

### Security
- **POS digital payments now need server-side authorization** (#6783). Before, `renderGatewayPortal` was registered on `wp_ajax_nopriv_bazaar_pos_gateway_portal` (open to logged-out visitors), its mock form posted `{status: 'approved'}` to `window.opener` with target origin `'*'`, the register accepted that message from any window, and `createPosOrder` marked the sale completed with no server check. Pay-by-link also recorded the sale as paid as soon as the link was "sent".
- Removed the `nopriv` hook. The portal and the new `bazaar_pos_authorize_test_payment` action require `manage_woocommerce` or `edit_shop_orders` plus a valid `wp_rest` nonce.
- The portal is test-only: enabled in `local`/`development` environments, otherwise off (override with `XOPHZ_BAZAAR_POS_TEST_PORTAL` or the `xophz_compass_bazaar_pos_test_portal` filter). It no longer shows a pre-filled card form, issues a one-time token (`Xophz_Compass_Bazaar_Pos_Authorization`) bound to cashier, gateway and amount, and posts it to the site origin only.
- `createPosOrder` refuses a digital tender (anything that is not cash, a card terminal, a bank transfer or split) unless it carries a matching unused token; the refused order is deleted.
- Tests: token rules in `tests/run-tests.php`; live checks in `tests/integration/pos-authorization.sh` with `tests/integration/call-handler.php`.

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
