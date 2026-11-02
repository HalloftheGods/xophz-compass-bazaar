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
# Review fixes (#6996): a sale whose finalize throws after the commit answers
# success with finalize_pending (one order, the sweeper finishes it); every
# combination of bazaar_pos_checkout_v2 and bazaar_checkout_pipeline sells
# (set per process with pre_option filters, so the other suites sharing the
# site never see them); an SPA-shaped split with the slip reference only in
# legs[] succeeds; the shift email under a staff session header is scoped to
# that session and mailed to its staff member and the operator only (never
# the cashierId sent), an invalid header is refused; rollback-body sales are
# counted in the email.
#
# It sets the option bazaar_checkout_pipeline and restores the value it found
# (or deletes it) at the end, also when a check fails. It creates a private
# product, a coupon, two users with a staff row and a staff session, and
# deletes them and their orders at the end. Run it only against a
# development store.
#
# Run from the repo root: bash wp-content/plugins/xophz-compass-bazaar/tests/integration/checkout-legacy.sh
set -u
HERE="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=lib.sh
source "$HERE/lib.sh"

docker cp "$HERE/call-handler.php" "$CONTAINER:/tmp/bazaar-call-handler.php" >/dev/null
TMP="$(mktemp -d)"
MAIL_PHP="$TMP/mail.php"
RUN_PHP="$TMP/legacy-run.php"
PRODUCT_ID=""
COUPON_ID=""
OPTION_BEFORE=""
OPTION_SET=0
CASHIER_ID=""
OTHER_ID=""
MANAGER_ID=""
RUN_ID="$(date +%s)"
DEVICE="legacyck-dev-$RUN_ID"

# One handler call in its own wp-cli process. Env: CALL_CLASS, CALL_METHOD,
# CALL_ARGS (JSON; arrays sent as JSON strings), CALL_USER (default the first
# administrator), CALL_V2 / CALL_PIPELINE (per-process option values),
# CALL_FAIL_AT (test failure point), CALL_STAFF / CALL_DEVICE (headers),
# CALL_MAIL=1 (print the shift mail's figures instead of sending it).
cat >"$RUN_PHP" <<'PHP'
<?php
$uid = (int) getenv( 'CALL_USER' );
if ( ! $uid ) {
  $uid = (int) get_users( [ 'role' => 'administrator', 'number' => 1, 'orderby' => 'ID', 'order' => 'ASC', 'fields' => 'ID' ] )[0];
}
wp_set_current_user( $uid );
$_SERVER['HTTP_X_WP_NONCE'] = wp_create_nonce( 'wp_rest' );
foreach ( json_decode( getenv( 'CALL_ARGS' ) ?: '{}', true ) as $key => $value ) {
  $_REQUEST[ $key ] = $_GET[ $key ] = is_array( $value ) ? wp_json_encode( $value ) : (string) $value;
}
foreach ( [ 'CALL_V2' => 'bazaar_pos_checkout_v2', 'CALL_PIPELINE' => 'bazaar_checkout_pipeline' ] as $env => $option ) {
  $value = getenv( $env );
  if ( false !== $value && '' !== $value ) {
    add_filter( 'pre_option_' . $option, function () use ( $value ) {
      return $value;
    } );
  }
}
if ( getenv( 'CALL_FAIL_AT' ) ) {
  add_filter( Xophz_Compass_Bazaar_Checkout_Service::FAIL_FILTER, function ( $hit, $point ) {
    return getenv( 'CALL_FAIL_AT' ) === $point ? $point : $hit;
  }, 10, 2 );
}
if ( false !== getenv( 'CALL_STAFF' ) ) {
  $_SERVER['HTTP_X_BAZAAR_STAFF_SESSION'] = (string) getenv( 'CALL_STAFF' );
}
if ( getenv( 'CALL_DEVICE' ) ) {
  $_SERVER['HTTP_X_BAZAAR_DEVICE'] = (string) getenv( 'CALL_DEVICE' );
}
if ( getenv( 'CALL_MAIL' ) ) {
  add_filter( 'pre_wp_mail', function ( $short, $atts ) {
    $mail = (string) $atts['message'];
    preg_match( "/Total Orders:<\/td><td[^>]*>(\d+)</", $mail, $orders );
    preg_match( '/Total Shift Sales:<\/span>\s*<span>\$([0-9.,]+)</', $mail, $gross );
    echo 'MAIL_ORDERS ' . ( $orders[1] ?? '-' ) . "\n";
    echo 'MAIL_GROSS ' . ( $gross[1] ?? '-' ) . "\n";
    echo 'MAIL_TO ' . implode( ',', (array) $atts['to'] ) . "\n";
    echo 'MAIL_LEGACY ' . ( false !== strpos( $mail, 'recorded by the rollback checkout' ) ? 'yes' : 'no' ) . "\n";
    return true;
  }, 10, 2 );
}
$class = getenv( 'CALL_CLASS' ) ?: 'Xophz_Compass_Bazaar_Admin_Pos_Sale';
( new $class( 'xophz-compass-bazaar', 'integration' ) )->{getenv( 'CALL_METHOD' ) ?: 'createPosOrder'}();
PHP

# run <args-json> [docker -e flags...] : createPosOrder (or CALL_CLASS/CALL_METHOD) through RUN_PHP.
run() {
  local args="$1"
  shift
  wp_file "$RUN_PHP" -e CALL_ARGS="$args" "$@"
}

# checkout_state <order-id> : the state of the order's checkout row, or "none".
checkout_state() {
  wp_eval "\$r = Xophz_Compass_Bazaar_Checkout_Repository::find_by_order((int) '$1'); echo \$r ? \$r['state'] : 'none';" | tail -1
}

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
  if [ -n "$CASHIER_ID$OTHER_ID$MANAGER_ID" ]; then
    wp_eval "global \$wpdb; require_once ABSPATH . 'wp-admin/includes/user.php';
foreach (array_filter(['$CASHIER_ID', '$OTHER_ID', '$MANAGER_ID']) as \$id) {
  \$wpdb->delete(Xophz_Compass_Bazaar_Core_Db::table('staff'), ['user_id' => (int) \$id]);
  \$wpdb->query(\$wpdb->prepare('DELETE FROM ' . Xophz_Compass_Bazaar_Core_Db::table('staff_sessions') . ' WHERE staff_user_id = %d', (int) \$id));
  wp_delete_user((int) \$id);
}" >/dev/null
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
# Rollback-body sales are part of the server totals (read from their orders).
cat >"$MAIL_PHP" <<'PHP'
<?php
$admins = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
wp_set_current_user( (int) $admins[0] );
$_POST = [ 'cash' => '987654.32', 'card' => '123456.78', 'totalOrders' => '7777', 'totalTips' => '55555.55', 'coupons' => '4444.44', 'customDiscounts' => '3333.33' ];
$ctx   = Xophz_Compass_Bazaar_Security_Context::for_operator( (int) $admins[0] );
$scope = Xophz_Compass_Bazaar_Admin_Pos_Shift::scope( $ctx, true );
$sum   = Xophz_Compass_Bazaar_Admin_Pos_Shift::merge(
  Xophz_Compass_Bazaar_Rest_Checkouts::summary( $scope ),
  Xophz_Compass_Bazaar_Checkout_Legacy_Writer::shift_totals( $scope['from'], $scope['to'] ),
  [ 'Xophz_Compass_Bazaar_Payments_Classifier', 'kind' ]
);
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

# 6. A committed sale is a success, never a failure to retry (finding 9).
BEFORE="$(order_count)"
out="$(run "{\"items\":$ITEMS,\"paymentMethod\":\"cash\"}" -e CALL_FAIL_AT=after_commit -e CALL_PIPELINE=v2)"
expect 'finalize fails: the sale answers success' "$out" '"success":true'
expect 'finalize fails: the answer says finalize is pending' "$out" '"finalize_pending":true'
expect 'finalize fails: the answer carries the order key' "$out" '"order_key":"wc_order_'
ID="$(order_id_of "$out")"
expect_equal 'finalize fails: the row stays committed' "$(checkout_state "$ID")" 'committed'
expect_equal 'finalize fails: exactly one order was made' "$(( $(order_count) - BEFORE ))" '1'
expect_equal 'finalize fails: the sweeper path completes it' "$(wp_eval "\$r = Xophz_Compass_Bazaar_Checkout_Repository::find_by_order((int) '$ID'); \$d = (new Xophz_Compass_Bazaar_Checkout_Service())->resume(\$r, true); echo \$d ? \$d['state'] : 'null';" | tail -1)" 'completed'
expect_equal 'finalize fails: the order completes' "$(wp_eval "echo wc_get_order((int) '$ID')->get_status();" | tail -1)" 'completed'

# 7. Both rollback switches, every combination (finding 10).
for combo in yes:v2:checkout no:v2:none no:legacy:none yes:legacy:none; do
  IFS=':' read -r v2 pipe want <<<"$combo"
  out="$(run "{\"items\":$ITEMS,\"paymentMethod\":\"cash\"}" -e CALL_V2="$v2" -e CALL_PIPELINE="$pipe")"
  expect "switches checkout_v2=$v2 pipeline=$pipe: the sale succeeds" "$out" '"success":true'
  ID="$(order_id_of "$out")"
  got="$(checkout_state "$ID")"
  [ "$got" != 'none' ] && got='checkout'
  expect_equal "switches checkout_v2=$v2 pipeline=$pipe: $want row" "$got" "$want"
done

# 8. SPA-shaped split: the slip reference only in legs[] (finding 13).
SPA_SPLIT="[{\"method\":\"cash\",\"amount\":\"$SPLIT_CASH\"},{\"method\":\"bazaar_card_terminal\",\"amount\":\"$SPLIT_CARD\"}]"
SPA_LEGS="[{\"method\":\"cash\",\"amount\":$SPLIT_CASH,\"kind\":\"cash\"},{\"method\":\"bazaar_card_terminal\",\"amount\":$SPLIT_CARD,\"kind\":\"external_terminal\",\"reference\":\"SLIP-90299\"}]"
out="$(run "{\"items\":$ITEMS,\"paymentMethod\":\"bazaar_split\",\"splitPayments\":$SPA_SPLIT,\"legs\":$SPA_LEGS}" -e CALL_PIPELINE=v2)"
expect 'legs: a split with the reference only in legs[] succeeds' "$out" '"success":true'
ID="$(order_id_of "$out")"
expect_equal 'legs: the card capture holds the slip reference' "$(wp_eval "global \$wpdb; echo \$wpdb->get_var(\$wpdb->prepare('SELECT reference FROM ' . Xophz_Compass_Bazaar_Core_Db::table('payments') . ' WHERE order_id = %d AND method_id = %s', (int) '$ID', 'bazaar_card_terminal'));" | tail -1)" 'SLIP-90299'

# 9. Shift email under a staff session (finding 11) and rollback sales (finding 12).
setup="$(wp_eval '
$c = wp_insert_user(["user_login" => "legacyck_cashier_'"$RUN_ID"'", "user_pass" => wp_generate_password(24), "user_email" => "legacyck.cashier.'"$RUN_ID"'@example.invalid", "role" => "bazaar_cashier"]);
$o = wp_insert_user(["user_login" => "legacyck_other_'"$RUN_ID"'", "user_pass" => wp_generate_password(24), "user_email" => "legacyck.other.'"$RUN_ID"'@example.invalid", "role" => "customer"]);
$admin = (int) get_users(["role" => "administrator", "number" => 1, "orderby" => "ID", "order" => "ASC", "fields" => "ID"])[0];
$now = gmdate("Y-m-d H:i:s"); $tok = bin2hex(random_bytes(16));
Xophz_Compass_Bazaar_Core_Db::insert(Xophz_Compass_Bazaar_Core_Db::table("staff"), ["user_id" => $c, "active" => 1, "updated_at" => $now]);
$sid = Xophz_Compass_Bazaar_Core_Db::insert(Xophz_Compass_Bazaar_Core_Db::table("staff_sessions"), ["token_hash" => Xophz_Compass_Bazaar_Staff_Sessions::hash_token($tok), "staff_user_id" => $c, "operator_user_id" => $admin, "device_id" => "'"$DEVICE"'", "location_id" => 0, "started_at" => $now, "last_seen_at" => $now, "idle_expires_at" => gmdate("Y-m-d H:i:s", time() + 900), "expires_at" => gmdate("Y-m-d H:i:s", time() + 3600), "end_reason" => ""]);
echo "staff=$c,$o,$admin,$sid,$tok";' | tail -1)"
IFS=',' read -r CASHIER_ID OTHER_ID ADMIN_ID SESSION_ID TOKEN <<<"${setup#staff=}"
if ! [[ "${SESSION_ID:-}" =~ ^[0-9]+$ ]]; then
  echo "staff session setup failed: $setup"
  FAIL=$((FAIL + 1))
else
  # One cash sale rung by the cashier under the session, as the v2 REST route records it.
  STAFF_SALE="$(wp_eval 'wp_set_current_user('"$ADMIN_ID"'); $A = "Xophz_Compass_Bazaar_Checkout_Legacy_Adapter";
$ctx = Xophz_Compass_Bazaar_Security_Context::for_staff('"$ADMIN_ID"', '"$CASHIER_ID"', '"$SESSION_ID"', "'"$DEVICE"'");
$cart = $A::cart((object) ["items" => [["product_id" => '"$PRODUCT_ID"', "quantity" => 1]]]);
$total = (new Xophz_Compass_Bazaar_Pricing_Pricer())->price(Xophz_Compass_Bazaar_Pricing_Cart_Request::from_array($cart), $ctx)->total_minor();
$req = Xophz_Compass_Bazaar_Checkout_Request::from_array(["client_key" => $A::key(), "cart" => $cart, "tenders" => $A::tenders((object) [], $total)]);
$res = (new Xophz_Compass_Bazaar_Checkout_Service())->submit($req, $ctx);
echo "sale=" . (int) ($res["body"]["order"]["id"] ?? 0) . "," . $total;' | tail -1)"
  IFS=',' read -r STAFF_ORDER STAFF_TOTAL <<<"${STAFF_SALE#sale=}"
  expect_equal 'staff: the session sale was recorded' "$( [[ "${STAFF_ORDER:-}" =~ ^[1-9][0-9]*$ ]] && echo yes || echo "no: $STAFF_SALE" )" 'yes'
  STAFF_MONEY="$(wp_eval "echo number_format((float) Xophz_Compass_Bazaar_Core_Money::from_minor((int) '$STAFF_TOTAL'), 2);" | tail -1)"

  out="$(run "{\"cashierId\":\"$OTHER_ID\",\"totalOrders\":\"7777\"}" -e CALL_CLASS=Xophz_Compass_Bazaar_Admin_Pos_Shift -e CALL_METHOD=emailShiftSummary -e CALL_MAIL=1 -e CALL_STAFF="$TOKEN" -e CALL_DEVICE="$DEVICE")"
  expect_equal 'staff email: scoped to the session (its one sale)' "$(sed -n 's/^MAIL_ORDERS //p' <<<"$out")" '1'
  expect_equal 'staff email: the session gross' "$(sed -n 's/^MAIL_GROSS //p' <<<"$out")" "$STAFF_MONEY"
  expect_equal 'staff email: sent to the staff member and the operator only' "$(sed -n 's/^MAIL_TO //p' <<<"$out" | tr ',' '\n' | sed 's/.*legacyck\.cashier.*/cashier/;t;s/.*legacyck\.other.*/other/;t;s/.*/operator/' | sort | tr '\n' ' ')" 'cashier operator '
  out="$(run '{}' -e CALL_CLASS=Xophz_Compass_Bazaar_Admin_Pos_Shift -e CALL_METHOD=emailShiftSummary -e CALL_MAIL=1 -e CALL_STAFF=not-a-token -e CALL_DEVICE="$DEVICE")"
  expect 'staff email: an invalid session header is refused' "$out" '"code":"staff_session_invalid"'
  expect_equal 'staff email: nothing is mailed for an invalid header' "$(grep -c '^MAIL_ORDERS' <<<"$out")" '0'

  # A rollback-body sale rung by the cashier, then the cashier's own email.
  out="$(run "{\"items\":[{\"product_id\":$PRODUCT_ID,\"quantity\":1}],\"paymentMethod\":\"cash\"}" -e CALL_USER="$CASHIER_ID" -e CALL_PIPELINE=legacy)"
  expect 'rollback: the cashier sells through the old body' "$out" '"success":true'
  ID="$(order_id_of "$out")"
  expect_equal 'rollback: the sale has no checkout row' "$(checkout_state "$ID")" 'none'
  ROLL_MONEY="$(wp_eval "echo number_format((float) Xophz_Compass_Bazaar_Core_Money::from_minor((int) '$STAFF_TOTAL' + Xophz_Compass_Bazaar_Core_Money::to_minor((float) wc_get_order((int) '$ID')->get_total())), 2);" | tail -1)"
  out="$(run '{}' -e CALL_CLASS=Xophz_Compass_Bazaar_Admin_Pos_Shift -e CALL_METHOD=emailShiftSummary -e CALL_MAIL=1 -e CALL_USER="$CASHIER_ID")"
  expect_equal 'rollback email: counts the v2 and the rollback sale' "$(sed -n 's/^MAIL_ORDERS //p' <<<"$out")" '2'
  expect_equal 'rollback email: gross includes the rollback sale' "$(sed -n 's/^MAIL_GROSS //p' <<<"$out")" "$ROLL_MONEY"
  expect_equal 'rollback email: says rollback sales are included' "$(sed -n 's/^MAIL_LEGACY //p' <<<"$out")" 'yes'

  # rf4 review: a view_reports staff member's session on a register whose
  # operator only sells. A rollback sale rung by another user on another
  # register during the session is not the session's: it is not counted.
  msetup="$(wp_eval '
$m = wp_insert_user(["user_login" => "legacyck_manager_'"$RUN_ID"'", "user_pass" => wp_generate_password(24), "user_email" => "legacyck.manager.'"$RUN_ID"'@example.invalid", "role" => "bazaar_supervisor"]);
$now = gmdate("Y-m-d H:i:s"); $tok = bin2hex(random_bytes(16));
Xophz_Compass_Bazaar_Core_Db::insert(Xophz_Compass_Bazaar_Core_Db::table("staff"), ["user_id" => $m, "active" => 1, "updated_at" => $now]);
$sid = Xophz_Compass_Bazaar_Core_Db::insert(Xophz_Compass_Bazaar_Core_Db::table("staff_sessions"), ["token_hash" => Xophz_Compass_Bazaar_Staff_Sessions::hash_token($tok), "staff_user_id" => $m, "operator_user_id" => '"$CASHIER_ID"', "device_id" => "'"$DEVICE"'-m", "location_id" => 0, "started_at" => $now, "last_seen_at" => $now, "idle_expires_at" => gmdate("Y-m-d H:i:s", time() + 900), "expires_at" => gmdate("Y-m-d H:i:s", time() + 3600), "end_reason" => ""]);
echo "mgr=$m," . (user_can($m, "bazaar_view_reports") ? 1 : 0) . ",$sid,$tok";' | tail -1)"
  IFS=',' read -r MANAGER_ID MANAGER_REPORTS MSESSION_ID MTOKEN <<<"${msetup#mgr=}"
  expect_equal 'manager: the staff member holds bazaar_view_reports' "${MANAGER_REPORTS:-}" '1'
  out="$(run "{\"items\":[{\"product_id\":$PRODUCT_ID,\"quantity\":1}],\"paymentMethod\":\"cash\"}" -e CALL_PIPELINE=legacy)"
  expect 'manager: another user sells through the old body during the session' "$out" '"success":true'
  ID="$(order_id_of "$out")"
  expect_equal 'manager: that sale has no checkout row' "$(checkout_state "$ID")" 'none'
  out="$(run '{}' -e CALL_CLASS=Xophz_Compass_Bazaar_Admin_Pos_Shift -e CALL_METHOD=emailShiftSummary -e CALL_MAIL=1 -e CALL_USER="$CASHIER_ID" -e CALL_STAFF="$MTOKEN" -e CALL_DEVICE="$DEVICE-m")"
  expect_equal 'manager email: the other register rollback sale is not counted' "$(sed -n 's/^MAIL_ORDERS //p' <<<"$out")" '0'
  expect_equal 'manager email: no rollback sales are included' "$(sed -n 's/^MAIL_LEGACY //p' <<<"$out")" 'no'
  expect_equal 'manager email: sent to the staff member and the operator' "$(sed -n 's/^MAIL_TO //p' <<<"$out" | tr ',' '\n' | sed 's/.*legacyck\.manager.*/manager/;t;s/.*legacyck\.cashier.*/operator/;t;s/.*/other/' | sort | tr '\n' ' ')" 'manager operator '
fi

bz_finish
