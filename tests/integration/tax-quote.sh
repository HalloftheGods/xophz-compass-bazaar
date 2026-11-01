#!/usr/bin/env bash
# Live check of POST /bazaar/v1/pos/quote against WooCommerce's own order
# totals, plus the quote's refusals and GET /bazaar/v1/pos/config.
#
# For prices exclusive and inclusive of tax, and rounding per line and at
# subtotal, every combination of {no coupon, 15% coupon, 5.00 cart coupon} x
# {no discount, 12.5% register discount} x {no custom line, a taxable custom
# line} x {no tip, a 2.50 tip} is quoted and compared with a reference order
# priced the WooCommerce way (add_product, calculate_totals, apply_coupon,
# calculate_totals): total, tax, discount, tax per rate and discount per
# coupon must be equal (96 combinations).
#
# The tax settings are changed only inside the wp-cli processes this script
# starts (pre_option hooks set up by tax-quote-fixtures.php through
# --require); the options table is never written. Fixtures (a tax class
# bazaar-test with two rates, one standard-class rate, three private
# products, two coupons and a subscriber) are created first and deleted at
# the end, also when a check fails; reference orders are trashed, then
# deleted. While it runs, the standard-class rate "BZ Standard" exists in
# the store, so run it only against a development store.
#
# Run from the repo root: bash wp-content/plugins/xophz-compass-bazaar/tests/integration/tax-quote.sh
set -u
HERE="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=lib.sh
source "$HERE/lib.sh"

FIXTURES=/tmp/bazaar-tax-quote-fixtures.php
docker cp "$HERE/tax-quote-fixtures.php" "$CONTAINER:$FIXTURES" >/dev/null

# bz_wp <action> [prices_include_tax yes|no] [round_at_subtotal yes|no]
bz_wp() {
  local action="$1" include="${2:-no}" round="${3:-no}"
  docker exec -e BZ_ACTION="$action" -e BZ_STATE="${STATE:-}" -e BZ_PRICES_INCLUDE_TAX="$include" -e BZ_ROUND_AT_SUBTOTAL="$round" \
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
expect 'setup: fixtures created' "$STATE" '"products":['
if [ -z "$STATE" ]; then
  echo "$out"
  bz_finish
  exit 1
fi

for include in no yes; do
  for round in no yes; do
    out="$(bz_wp run "$include" "$round")"
    count="$(grep -c '^OK ' <<<"$out")"
    expect_equal "parity: prices_include_tax=$include round_at_subtotal=$round, 24 combinations equal WooCommerce" "$count" "24"
    grep -v '^OK ' <<<"$out" | head -n 8
  done
done

out="$(bz_wp checks)"
check_line() { sed -n "s/^CHECK $1 //p" <<<"$out"; }
expect 'quote: an unknown coupon is listed invalid with WooCommerce message' "$(check_line invalid_coupon)" '200 [false,"Coupon \"bz-tq-no-such-coupon\" cannot be applied because it does not exist."]'
expect 'quote: a guest email at the per-customer coupon limit is refused with WooCommerce message' "$(check_line guest_limit_used)" '200 [false,0,"Usage limit for coupon '
expect_equal 'quote: a guest email under the limit is accepted' "$(check_line guest_limit_fresh)" '200 [true]'
expect_equal 'quote: price_override_minor is 422 unsupported_field' "$(check_line price_override)" '422 unsupported_field'
expect_equal 'quote: no lines is 400 invalid_request with the path' "$(check_line bad_request)" '400 invalid_request ["lines"]'
expect_equal 'quote: malformed JSON is refused by WordPress with 400' "$(check_line malformed_json)" '400 rest_invalid_json'
expect_equal 'quote: a missing product is 422 invalid_line with its index' "$(check_line missing_product)" '422 invalid_line line=1'
expect_equal 'quote: logged out is 401' "$(check_line logged_out)" '401 rest_not_logged_in'
expect_equal 'quote: a subscriber is 403' "$(check_line subscriber)" '403 forbidden'
expect_equal 'quote: writes no orders and leaves coupon usage alone' "$(check_line no_writes)" '200 unchanged'
expect_equal 'quote: spec 9.3 keys' "$(check_line quote_shape)" 'currency,decimals,tax_enabled,prices_include_tax,tax_location,lines,coupons,discounts,tax_lines,totals,approvals_needed,warnings,quote_hash,expires_at'
expect_equal 'quote: spec 9.3 line keys' "$(check_line line_shape)" 'key,type,product_id,variation_id,name,sku,quantity,unit_price_minor,subtotal_minor,discount_minor,total_minor,tax_minor,taxes,stock'
expect_equal 'quote: hash and store-base location' "$(check_line quote_hash)" '64 store_base'
expect_equal 'config: spec 14.1 keys' "$(check_line config_shape)" '200 features,currency,decimals,prices_include_tax,tax_enabled,locations,default_location_id,tenders,limits,actor'
expect_equal 'config: features' "$(check_line config_features)" 'checkout_v2,staff_pin,ledger_mode,require_terminal_reference,test_portal'
expect 'config: actor holds bazaar_sell by login, tenders listed' "$(check_line config_actor)" 'sell login tenders='
expect_equal 'config: a subscriber is 403' "$(check_line config_subscriber)" '403 forbidden'
expect_equal 'tax location: a POS order stays at the store base' "$(check_line tax_location_pos)" 'store_base'
expect_equal 'tax location: other orders are untouched' "$(check_line tax_location_web)" 'CA'
expect_equal 'tax location: the order filter is hooked' "$(check_line tax_location_hooked)" 'yes'

teardown
trap - EXIT
out="$(wp_eval 'global $wpdb; echo in_array("bazaar-test", WC_Tax::get_tax_class_slugs(), true) ? "class-left" : "class-gone"; echo " rates=" . (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}woocommerce_tax_rates WHERE tax_rate_name IN (\"BZ State\",\"BZ City\",\"BZ Standard\")"); echo " products=" . (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = \"product\" AND post_title LIKE \"Bazaar tax-quote %\"");')"
expect_equal 'teardown: class, rates and products are gone' "$out" 'class-gone rates=0 products=0'

bz_finish
