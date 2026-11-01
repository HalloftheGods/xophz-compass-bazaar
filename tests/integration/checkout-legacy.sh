#!/usr/bin/env bash
# Live check of the legacy create_pos_order action on checkout v2 (WP18)
# against the local Docker WordPress.
#
# Covers: pos-authorization.sh run unchanged with the option
# bazaar_checkout_pipeline set to v2, then to legacy (both must pass, which
# includes its "+2 orders" check); through the adapter: a plain cash sale, a
# coupon, a percent custom discount, a split sale with a card reference and
# a custom line; refused attempts (empty cart, split sum mismatch, a card
# terminal tender without a reference, a forged digital token) that create no
# order and no checkout row; the rollback switch (legacy sale still works);
# and email_shift_summary built from server totals, not from the totals the
# request carries.
#
# It sets the option bazaar_checkout_pipeline and restores the value it found
# (or deletes it) at the end, also when a check fails. It creates a private
# product and a coupon and deletes them and their orders at the end. Run it
# only against a development store.
#
# Run from the repo root: bash wp-content/plugins/xophz-compass-bazaar/tests/integration/checkout-legacy.sh
set -u
HERE="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=lib.sh
source "$HERE/lib.sh"

docker cp "$HERE/call-handler.php" "$CONTAINER:/tmp/bazaar-call-handler.php" >/dev/null
TMP="$(mktemp -d)"
MAIL_PHP="$TMP/mail.php"
PRODUCT_ID=""
COUPON_ID=""
OPTION_BEFORE=""
OPTION_SET=0

# call <method> <args-json> [docker -e flags...] : run Admin_Pos_Sale::<method>.
call() {
  local method="$1" args="$2"
  shift 2
  docker exec -e CALL_CLASS=Xophz_Compass_Bazaar_Admin_Pos_Sale -e CALL_METHOD="$method" -e CALL_ARGS="$args" "$@" \
    "$CONTAINER" wp --allow-root --path="$WP_PATH" eval-file /tmp/bazaar-call-handler.php 2>&1 | bz_filter
}

set_pipeline() {
  wp_eval "update_option('bazaar_checkout_pipeline', '$1');" >/dev/null
}

# Orders created through the POS that hold this run's product.
order_count() {
  wp_eval '$n = 0; foreach (wc_get_orders(["limit" => -1, "created_via" => "bazaar_pos"]) as $o) { foreach ($o->get_items() as $it) { if ((int) $it->get_product_id() === '"$PRODUCT_ID"') { $n++; break; } } } echo $n;' | tail -1
}

checkout_rows() {
  wp_eval 'global $wpdb; echo (int) $wpdb->get_var("SELECT COUNT(*) FROM " . Xophz_Compass_Bazaar_Core_Db::table("checkouts"));' | tail -1
}

order_total() {
  wp_eval "echo wc_get_order($1)->get_total();" | tail -1
}

payment_rows() {
  wp_eval 'global $wpdb; echo (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . Xophz_Compass_Bazaar_Core_Db::table("payments") . " WHERE order_id = %d", '"$1"'));' | tail -1
}

order_id_of() {
  sed -n 's/.*"order_id":\([0-9]*\).*/\1/p' <<<"$1"
}

teardown() {
  if [ "$OPTION_SET" -eq 1 ]; then
    if [ -n "$OPTION_BEFORE" ]; then
      wp_eval "update_option('bazaar_checkout_pipeline', '$OPTION_BEFORE');" >/dev/null
    else
      wp_eval "delete_option('bazaar_checkout_pipeline');" >/dev/null
    fi
  fi
  if [ -n "$PRODUCT_ID" ]; then
    wp_eval '$ids = []; foreach (wc_get_orders(["limit" => -1, "created_via" => "bazaar_pos"]) as $o) { foreach ($o->get_items() as $it) { if ((int) $it->get_product_id() === '"$PRODUCT_ID"') { $ids[] = $o->get_id(); break; } } } foreach ($ids as $id) { wc_get_order($id)->delete(false); } $p = wc_get_product('"$PRODUCT_ID"'); if ($p) { $p->delete(false); }' >/dev/null
  fi
  if [ -n "$COUPON_ID" ]; then
    wp_eval "wp_delete_post($COUPON_ID, true);" >/dev/null
  fi
  rm -rf "$TMP"
}
trap teardown EXIT

OPTION_BEFORE="$(wp_eval "echo get_option('bazaar_checkout_pipeline', '');" | tail -1)"
OPTION_SET=1

PRODUCT_ID="$(wp_eval '$p = new WC_Product_Simple(); $p->set_name("Legacy checkout integration check"); $p->set_status("private"); $p->set_regular_price("12.50"); $p->save(); echo $p->get_id();' | tail -1)"
COUPON_CODE="legacy$(date +%s)"
COUPON_ID="$(wp_eval '$c = new WC_Coupon(); $c->set_code("'"$COUPON_CODE"'"); $c->set_discount_type("percent"); $c->set_amount("10"); $c->save(); echo $c->get_id();' | tail -1)"
if ! [[ "$PRODUCT_ID" =~ ^[0-9]+$ ]]; then
  echo "setup failed: $PRODUCT_ID"
  PRODUCT_ID=""
  bz_finish
  exit 1
fi
ITEMS="[{\"product_id\":$PRODUCT_ID,\"quantity\":2}]"

# 1. The authorization script, unchanged, on each pipeline.
for pipeline in v2 legacy; do
  set_pipeline "$pipeline"
  out="$(bash "$HERE/pos-authorization.sh" 2>&1)"
  expect "pos-authorization.sh passes on $pipeline" "$out" ', 0 failed'
  if ! grep -qF ', 0 failed' <<<"$out"; then
    printf '%s\n' "$out" | head -20
  fi
done

# 2. Through the adapter.
set_pipeline v2
BEFORE="$(order_count)"
ROWS_BEFORE="$(checkout_rows)"

out="$(call createPosOrder "{\"items\":$ITEMS,\"paymentMethod\":\"cash\"}")"
expect 'v2: cash sale answers success with an order key' "$out" '"order_key":"wc_order_'
BASE_ID="$(order_id_of "$out")"
BASE_TOTAL="$(order_total "$BASE_ID")"
expect 'v2: cash sale is created_via bazaar_pos and completed' "$(wp_eval "\$o = wc_get_order($BASE_ID); echo \$o->get_created_via() . ':' . \$o->get_status();" | tail -1)" 'bazaar_pos:completed'
expect_equal 'v2: the sale has one payment row' "$(payment_rows "$BASE_ID")" '1'

out="$(call createPosOrder "{\"items\":$ITEMS,\"paymentMethod\":\"cash\",\"appliedCoupons\":[\"$COUPON_CODE\"]}")"
expect 'v2: coupon sale succeeds' "$out" '"success":true'
ID="$(order_id_of "$out")"
expect_equal 'v2: coupon takes 10% off' "$(wp_eval "echo abs((float) wc_get_order($ID)->get_total() - $BASE_TOTAL * 0.9) <= 0.02 ? 'yes' : 'no';" | tail -1)" 'yes'

out="$(call createPosOrder "{\"items\":\"$(sed 's/"/\\"/g' <<<"$ITEMS")\",\"paymentMethod\":\"cash\",\"customDiscounts\":\"[{\\\"name\\\":\\\"Staff\\\",\\\"type\\\":\\\"percent\\\",\\\"amount\\\":10}]\"}")"
expect 'v2: stringified arrays and a percent custom discount succeed' "$out" '"success":true'
ID="$(order_id_of "$out")"
expect_equal 'v2: percent custom discount takes 10% off' "$(wp_eval "echo abs((float) wc_get_order($ID)->get_total() - $BASE_TOTAL * 0.9) <= 0.02 ? 'yes' : 'no';" | tail -1)" 'yes'

SPLIT_CARD="5.00"
SPLIT_CASH="$(wp_eval "echo number_format((float) $BASE_TOTAL - $SPLIT_CARD, 2, '.', '');" | tail -1)"
SPLIT="[{\"method\":\"cash\",\"amount\":\"$SPLIT_CASH\"},{\"method\":\"bazaar_card_terminal\",\"amount\":\"$SPLIT_CARD\",\"reference\":\"SLIP-90210\",\"cardLast4\":\"4242\"}]"
out="$(call createPosOrder "{\"items\":$ITEMS,\"paymentMethod\":\"bazaar_split\",\"splitPayments\":$SPLIT}")"
expect 'v2: split sale with a card reference succeeds' "$out" '"success":true'
ID="$(order_id_of "$out")"
expect_equal 'v2: split sale has two payment rows' "$(payment_rows "$ID")" '2'

out="$(call createPosOrder "{\"items\":[{\"product_id\":-1,\"title\":\"Gift wrap\",\"price\":\"3.00\",\"quantity\":1}],\"paymentMethod\":\"cash\"}")"
expect 'v2: a custom line (negative id, title, price) is sold' "$out" '"success":true'

AFTER_OK="$(order_count)"
expect_equal 'v2: base, coupon, percent and split sales made 4 orders with this product' "$((AFTER_OK - BEFORE))" '4'

# 3. Refused attempts leave no order and no checkout row where promised.
ORDERS_OK="$(order_count)"
ROWS_OK="$(checkout_rows)"

out="$(call createPosOrder '{"items":[],"paymentMethod":"cash"}')"
expect 'refused: empty cart text' "$out" '"message":"Cart is empty."'
expect_equal 'refused: an empty cart claims no checkout row' "$(( $(checkout_rows) > ROWS_OK ? 1 : 0 ))" '0'

out="$(call createPosOrder "{\"items\":[{\"product_id\":0,\"quantity\":1},{\"product_id\":$PRODUCT_ID,\"quantity\":0}],\"paymentMethod\":\"cash\"}")"
expect 'refused: a cart whose lines are all skipped says Cart is empty.' "$out" '"message":"Cart is empty."'
# Other sales run against the same store, so rows can only be counted as "not more".
expect_equal 'refused: skipped lines claim no checkout row' "$(( $(checkout_rows) > ROWS_OK ? 1 : 0 ))" '0'

SHORT="[{\"method\":\"cash\",\"amount\":\"1.00\"},{\"method\":\"bazaar_card_terminal\",\"amount\":\"1.00\",\"reference\":\"SLIP-90211\"}]"
out="$(call createPosOrder "{\"items\":$ITEMS,\"paymentMethod\":\"bazaar_split\",\"splitPayments\":$SHORT}")"
expect 'refused: split sum mismatch text' "$out" '"message":"Split payment sum does not match order total."'

out="$(call createPosOrder "{\"items\":$ITEMS,\"paymentMethod\":\"bazaar_card_terminal\"}")"
expect 'refused: card terminal tender without a reference' "$out" '"success":false'
expect 'refused: card terminal tender without a reference says why' "$out" 'reference from the terminal slip'

out="$(call createPosOrder "{\"items\":$ITEMS,\"paymentMethod\":\"bazaar_card_terminal\",\"terminalReference\":\"SLIP-90212\",\"cardBrand\":\"visa\",\"cardLast4\":\"4242\"}")"
expect 'accepted: the same card terminal tender with a reference' "$out" '"success":true'

out="$(call createPosOrder "{\"items\":$ITEMS,\"paymentMethod\":\"paypal\",\"authorizationToken\":\"forged-token-forged-token-forged\"}")"
expect 'refused: forged digital token keeps the legacy text' "$out" 'Payment not authorized (unknown or already used). Paypal was not charged and the sale was not recorded.'

expect_equal 'refused: attempts created no order (1 accepted card sale since)' "$(( $(order_count) - ORDERS_OK ))" '1'

# 4. The rollback switch.
set_pipeline legacy
out="$(call createPosOrder "{\"items\":$ITEMS,\"paymentMethod\":\"cash\"}")"
expect 'legacy: the old body still sells' "$out" '"order_key":"wc_order_'
out="$(call createPosOrder '{"items":[],"paymentMethod":"cash"}')"
expect 'legacy: empty cart text is the same' "$out" '"message":"Cart is empty."'
set_pipeline garbage
out="$(call createPosOrder "{\"items\":$ITEMS,\"paymentMethod\":\"cash\"}")"
expect 'unknown option value runs v2' "$out" '"success":true'
expect_equal 'unknown option value recorded a checkout row (v2)' "$(( $(checkout_rows) > ROWS_OK ? 1 : 0 ))" '1'
set_pipeline v2

# 5. email_shift_summary uses server totals, not the totals in the request.
cat >"$MAIL_PHP" <<'PHP'
<?php
$admins = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
wp_set_current_user( (int) $admins[0] );
$_POST = [ 'cash' => '987654.32', 'card' => '123456.78', 'totalOrders' => '7777', 'totalTips' => '55555.55', 'coupons' => '4444.44', 'customDiscounts' => '3333.33' ];
$ctx = Xophz_Compass_Bazaar_Security_Context::for_operator( (int) $admins[0] );
$sum = Xophz_Compass_Bazaar_Rest_Checkouts::summary( Xophz_Compass_Bazaar_Admin_Pos_Shift::scope( $ctx, true ) );
echo 'ORDERS ' . (int) $sum['orders'] . "\n";
// The handler ends the request after it answers, so the mail is checked inside the filter.
add_filter( 'pre_wp_mail', function ( $short, $atts ) use ( $sum ) {
  $mail = (string) $atts['message'];
  echo 'HAS_ORDERS ' . ( false !== strpos( $mail, "Total Orders:</td><td style='text-align: right; font-weight: bold;'>" . (int) $sum['orders'] . '<' ) ? 'yes' : 'no' ) . "\n";
  echo 'HAS_CLIENT_ORDERS ' . ( false !== strpos( $mail, '7777' ) ? 'yes' : 'no' ) . "\n";
  echo 'HAS_CLIENT_CASH ' . ( false !== strpos( $mail, '987,654.32' ) || false !== strpos( $mail, '987654' ) ? 'yes' : 'no' ) . "\n";
  echo 'HAS_GROSS ' . ( false !== strpos( $mail, number_format( (float) Xophz_Compass_Bazaar_Core_Money::from_minor( (int) $sum['gross_minor'] ), 2 ) ) ? 'yes' : 'no' ) . "\n";
  return true;
}, 10, 2 );
( new Xophz_Compass_Bazaar_Admin_Pos_Shift( 'xophz-compass-bazaar', 'integration' ) )->emailShiftSummary();
PHP
out="$(wp_file "$MAIL_PHP")"
expect 'email: the handler answers success' "$out" '"success":true'
expect 'email: the order count is the server count' "$out" 'HAS_ORDERS yes'
expect 'email: the client order count is not used' "$out" 'HAS_CLIENT_ORDERS no'
expect 'email: the client cash figure is not used' "$out" 'HAS_CLIENT_CASH no'
expect 'email: the server gross figure is in the mail' "$out" 'HAS_GROSS yes'

bz_finish
