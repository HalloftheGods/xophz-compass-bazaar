# Changelog

All notable changes to the Compass Bazaar plugin. Newest first.

## 2026-11-01

### Added
Bazaar Core v2, Phase 1 (spec: `docs/plugins/bazaar-core-v2.md` in the root repo). This entry summarizes the work package commits (#6939 to #6996); it does not replace the per-commit messages, and it does not claim every Phase 1 path is exercised against a live store.
- **Core foundation** (#6939): autoloader, module manifest, `Core_Db` transactions (after-commit and after-rollback callbacks are best effort), money, canonical and request helpers, REST helpers, and a schema installer for ten tables (including the events table used by the outbox).
- **Capabilities and security** (#6940): capabilities, roles, a central admin-ajax policy, a guard, and a REST permission factory.
- **Stock ledger** (#6941, #6945, #6951, #6987): ledger domain and service, wpdb store with WooCommerce projection, locations and modes (including shadow), observer, ledger-owned order lifecycle, backfill, audit, the `wp bazaar` CLI, and REST routes for movements, balances, adjustments and mode. Product stock updates, product saves, the product CSV import and multi-inventory writes go through the ledger (#6953); multi-inventory writes use `bazaar_manage_stock`.
- **Pricing** (#6948): cart request schema, pricer using `WC_Discounts` coupons, line-level discounts, order-parity tax, tax location filter, and the quote and POS config routes.
- **Payments** (#6942, #6949): `PaymentProvider` interface, classifier, registry, four pure providers, `Pos_Authorization::peek`, and payments and refunds repositories with refund allocation and a shift summary.
- **Staff** (#6947): PINs with lockout, staff sessions, per-role limits, one-time approvals, and staff REST routes.
- **Checkout v2** (#6952): state machine, validated request with request hash, repository, order writer, service, finalizer, sweeper, payment hooks, and the REST checkouts route. Legacy `create_pos_order` runs through checkout v2 with a rollback switch (#6956).
- **Refunds v2** (#6957): service with per-rate tax, approvals, tender allocation, a REST route and a legacy adapter.

### Changed
- The shift summary email is built from server totals and follows the staff session (#6956, #6996).
- POS refund and sale paths in the SPA were adjusted alongside these services (#6994).

### Fixed
Review fixes landed after the features above; see the commit messages for detail.
- Checkout: a committed sale is never cancelled, and orders saved but never recorded are cancelled (#6985, #6952); POS sales attach only store customers, never staff accounts.
- Stock: overlapping ledger sales no longer deadlock, observed changes book at their location, and one failing after-commit listener cannot skip the rest (#6987, #6989).
- Staff: PIN and password attempts are counted before the hash check and throttled per operator and address (#6986).
- Refunds: cash before terminal, per-rate limit rounding, discount fees and an approval id on the refund row (#6995); the legacy adapter reports committed sales as success (#6996).
- Test portal token amounts follow store price decimals (#6988).

### Security
- Staff-session handling: a late `staff_session_invalid` for an old token no longer ends a newer staff session; discount limits follow the staff limits (#6994).

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
