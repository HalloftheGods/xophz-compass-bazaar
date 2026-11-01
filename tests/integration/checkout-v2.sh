#!/usr/bin/env bash
# Live check of checkout v2 (POST/GET /bazaar/v1/pos/checkouts, the sweeper,
# the payment hooks and GET /pos/shift-summary) against the local Docker
# WordPress, through checkout-fixtures.php.
#
# Covers: a cash sale and its replay; a conflicting key; a checkout in
# progress; total_changed, reference_required, tender_total_mismatch,
# insufficient stock in ledger mode and approval_required, each with no order;
# an exact split; bank transfer left on-hold, then captured when marked paid;
# cod left pending; a custom line, a percent discount and a coupon equal to the
# quote; a ledger sale moving stock once; the three failure points
# (after_order_created and in_commit cancel the order and replay on retry
# with no movements or payments and the test-portal token unspent;
# after_commit answers finalize_pending and the same-key retry completes it
# with no duplicate movement or payment); a crashed order_created row on
# same-key retry (checkout_interrupted) and in the sweeper; the sweeper
# finalizing a committed row and failing a stuck received row; staff never
# attached as the customer and create_account; reads, permissions, the
# disabled switch and the shift summary.
#
# Taxes, ledger mode and failure points are switched on inside the wp-cli
# processes this script starts; the store's options are not changed, except
# the tax class bazaar-co-test and its rate, which exist while it runs.
# Everything the script creates is deleted at the end, also when a check
# fails. Run it only against a development store.
#
# Run from the repo root: bash wp-content/plugins/xophz-compass-bazaar/tests/integration/checkout-v2.sh
set -u
HERE="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=lib.sh
source "$HERE/lib.sh"

FIXTURES=/tmp/bazaar-checkout-fixtures.php
docker cp "$HERE/checkout-fixtures.php" "$CONTAINER:$FIXTURES" >/dev/null

# bz_wp <action> [extra docker -e flags...]
bz_wp() {
  local action="$1"
  shift
  docker exec -e BZ_ACTION="$action" -e BZ_STATE="${STATE:-}" "$@" \
    "$CONTAINER" wp --allow-root --path="$WP_PATH" --require="$FIXTURES" eval-file "$FIXTURES" 2>&1 | bz_filter
}

STATE=""
teardown() {
  if [ -n "$STATE" ]; then
    bz_wp teardown >/dev/null
    STATE=""
  fi
  docker exec "$CONTAINER" rm -f "$FIXTURES" >/dev/null 2>&1
}
trap teardown EXIT

out="$(bz_wp setup)"
STATE="$(sed -n 's/^STATE //p' <<<"$out")"
expect 'setup: fixtures created' "$STATE" '"products":{'
if [ -z "$STATE" ]; then
  echo "$out"
  bz_finish
  exit 1
fi

KEY_RETRY="$(cat /proc/sys/kernel/random/uuid)"
KEY_SWEEP="$(cat /proc/sys/kernel/random/uuid)"
expect 'crash: process exits after the order is created (retry key)' "$(bz_wp crash -e BZ_KEY="$KEY_RETRY")" 'CRASHED '
expect 'crash: process exits after the order is created (sweeper key)' "$(bz_wp crash -e BZ_KEY="$KEY_SWEEP")" 'CRASHED '

out="$(bz_wp run -e BZ_CRASH_RETRY="$KEY_RETRY" -e BZ_CRASH_SWEEP="$KEY_SWEEP")"
check_line() { sed -n "s/^CHECK $1 //p" <<<"$out"; }
if grep -q -e '^ERROR' -e 'Fatal error' <<<"$out"; then
  grep -e '^ERROR' -e 'Fatal error' <<<"$out" | head -n 5
fi

expect_equal 'cash: 200 completed, order completed, one payment, change 500' "$(check_line cash)" '200 completed completed 1 500'
expect_equal 'cash: created_via, checkout id, cashier, method, total' "$(check_line cash_meta)" 'bazaar_pos checkout cashier cash total'
expect_equal 'replay: same body, same order, replayed, no new order or payment' "$(check_line replay)" '200 true same-order 0 1'
expect_equal 'conflict: same key, other body is 422' "$(check_line conflict)" '422 idempotency_key_reused'
expect_equal 'in progress: live lease is 409 with retry_after_ms, no order' "$(check_line in_progress)" '409 checkout_in_progress 1500 0'
expect_equal 'total_changed: 409 with the quote, no order, failed and retryable' "$(check_line total_changed)" '409 total_changed quote 0 failed 1'
expect_equal 'reference_required: card without a reference, no order' "$(check_line reference_required)" '422 reference_required 0'
expect_equal 'tender_total_mismatch: tenders short of the total, no order' "$(check_line tender_mismatch)" '422 tender_total_mismatch 0'
expect_equal 'insufficient stock (ledger): 409 with the short line, no order' "$(check_line insufficient_ledger)" '409 insufficient_stock 1 0'
expect_equal 'approval_required: cashier over the discount limit, no order' "$(check_line approval_required)" '403 approval_required discount 0'
expect_equal 'split: exact cash and card, bazaar_split, two payments, legacy JSON' "$(check_line split)" '200 completed bazaar_split 2 2 completed'
expect_equal 'bank transfer: order on-hold, payment pending and unpaid' "$(check_line bacs)" '200 on-hold pending manual unpaid'
expect_equal 'bank transfer: marking the order paid captures the payment' "$(check_line bacs_paid)" 'captured'
expect_equal 'cod: order processing, payment stays pending' "$(check_line cod)" '200 processing pending'
expect_equal 'parity: custom line, percent discount and coupon equal the quote' "$(check_line parity)" '200 equal'
expect_equal 'parity: the case has a discount and tax' "$(check_line parity_discounted)" 'yes'
expect_equal 'ledger sale: stock down by 1 once, one movement, ledger-owned' "$(check_line ledger_sale)" '200 completed 1 1 ledger'
expect_equal 'after_order_created: 500, order cancelled not deleted, nothing moved' "$(check_line fail_after_order_created)" '500 internal cancelled cancelled internal 0 0 0'
expect_equal 'after_order_created retry: replayed, no new order, movement or payment, token unspent' "$(check_line retry_after_order_created)" '500 true 0 0 0 token-unspent'
expect_equal 'in_commit: 500, rolled back, order cancelled not deleted' "$(check_line fail_in_commit)" '500 internal cancelled cancelled internal 0 0 0'
expect_equal 'in_commit retry: replayed, no new order, movement or payment, token unspent' "$(check_line retry_in_commit)" '500 true 0 0 0 token-unspent'
expect_equal 'order saved then open() throws: 500, order cancelled, retry completes on one live order' "$(check_line orphan_open)" '500 failed cancelled 200 completed cancelled,completed'
expect_equal 'after_commit: 500 finalize_pending, committed, paid and moved once' "$(check_line fail_after_commit)" '500 finalize_pending committed pending 1 1 1'
expect_equal 'after_commit retry: completed, still one movement and one payment' "$(check_line retry_after_commit)" '200 completed completed 1 1 1'
expect_equal 'crash: live lease is in progress; expired retry is checkout_interrupted and cancels' "$(check_line crash_retry)" 'checkout_in_progress 409 checkout_interrupted cancelled cancelled'
expect_equal 'sweeper: finalizes committed, cancels order_created, fails received (retryable)' "$(check_line sweeper)" 'completed cancelled failed 1 cancelled completed counted'
expect_equal 'sweeper: recurring action is scheduled' "$(check_line sweeper_scheduled)" 'action_scheduler'
expect_equal 'customer: a staff account id is not attached to the sale' "$(check_line customer_staff)" '200 0'
expect_equal 'customer: create_account attaches a new customer with the opt-in' "$(check_line customer_create)" '200 attached customer yes done'
expect_equal 'GET: own key, other cashier 404, subscriber 403' "$(check_line status_get)" '200 completed 404 checkout_not_found 403'
expect_equal 'GET: a failed row shows its state and error' "$(check_line status_failed)" '200 failed checkout_interrupted'
expect_equal 'POST: logged out 401, subscriber 403' "$(check_line permissions)" '401 rest_not_logged_in 403 forbidden'
expect_equal 'POST: refused while checkout v2 is off' "$(check_line disabled)" '403 checkout_disabled'
expect_equal 'shift summary: spec 14.1 keys, sell-only user narrowed to self' "$(check_line shift)" '200 window,orders,gross_minor,tips_minor,discounts_minor,refunds_minor,by_kind,void_required,totals cashier=self'
expect_equal 'shift summary: admin sees the sales' "$(check_line shift_admin)" '200 counted'

KEYS="$(wp_eval 'echo implode(",", (array) get_option("bazaar_checkout_v2_test_keys", []));')"
ORDER_IDS="$(wp_eval 'global $wpdb; $keys = (array) get_option("bazaar_checkout_v2_test_keys", []); $ids = []; foreach ($keys as $k) { $ids = array_merge($ids, $wpdb->get_col($wpdb->prepare("SELECT order_id FROM {$wpdb->prefix}bazaar_checkouts WHERE client_key = %s AND order_id IS NOT NULL", $k))); } echo implode(",", array_map("intval", $ids));')"
teardown
trap - EXIT
out="$(wp_eval 'global $wpdb; $keys = array_filter(explode(",", "'"$KEYS"'")); $in = $keys ? "\"" . implode("\",\"", array_map("esc_sql", $keys)) . "\"" : "\"\""; echo "checkouts=" . (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}bazaar_checkouts WHERE client_key IN ($in)"); echo " products=" . (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = \"product\" AND post_title LIKE \"Bazaar checkout-v2 %\""); echo in_array("bazaar-co-test", WC_Tax::get_tax_class_slugs(), true) ? " class-left" : " class-gone";')"
expect_equal 'teardown: checkouts, products and tax class are gone' "$out" 'checkouts=0 products=0 class-gone'
out="$(wp_eval 'global $wpdb; echo (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->users} WHERE user_email LIKE \"bz-co-new-%@example.test\" OR user_login LIKE \"bz-co-%\"");')"
expect_equal 'teardown: test users are gone' "$out" '0'
out="$(wp_eval '$left = 0; foreach (array_filter(explode(",", "'"$ORDER_IDS"'")) as $id) { $left += wc_get_order((int) $id) ? 1 : 0; } echo "orders-left=$left";')"
expect_equal 'teardown: every order this run created is deleted, not left in the trash' "$out" 'orders-left=0'

bz_finish
