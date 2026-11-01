#!/usr/bin/env bash
# Live check of the Admin_Orders split and its handler fixes (#6946) against
# the local Docker WordPress. Calls the handlers directly as a chosen user;
# array arguments are passed as stringified JSON, the way a form post sends
# them (that is what failed before the fix).
#
# Asserts: split and coupon sales succeed; a percent custom discount takes
# that percent of the item subtotal; a cashierId that is not the logged-in
# user is ignored and noted; an unknown, refunded or checkout-draft status is
# refused; the customer search leaves staff out and masks contact details
# unless the caller holds bazaar_view_customers or typed the full email; a
# refund with stringified items refunds the right amount; a receipt never
# overwrites a different billing email; pos-authorization.sh still passes.
#
# Side effects: creates a throwaway product, coupon, three users and a few
# orders, and deletes them at the end (also when a check fails). It installs
# the Bazaar roles when they are missing (Security_Capabilities::install_roles).
#
# Run from the repo root: bash wp-content/plugins/xophz-compass-bazaar/tests/integration/orders-split.sh
set -u
HERE="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=lib.sh
source "$HERE/lib.sh"

env_type="$(wp_eval 'echo wp_get_environment_type();' | tail -1)"
case "$env_type" in
  local | development) ;;
  *)
    echo "ABORT orders-split.sh needs a local or development site; wp_get_environment_type() is '$env_type'"
    exit 1
    ;;
esac

SCRATCH="$(mktemp -d)"
RUNNER="$SCRATCH/orders-split-runner.php"
cat >"$RUNNER" <<'PHP'
<?php
// Env: CALL_CLASS, CALL_METHOD, CALL_ARGS (JSON; arrays are sent as JSON strings), CALL_USER (id, default first administrator).
$uid = (int) getenv( 'CALL_USER' );
if ( ! $uid ) {
  $admins = get_users( [ 'role' => 'administrator', 'number' => 1, 'orderby' => 'ID', 'order' => 'ASC', 'fields' => 'ID' ] );
  $uid    = (int) $admins[0];
}
wp_set_current_user( $uid );
$_SERVER['HTTP_X_WP_NONCE'] = wp_create_nonce( 'wp_rest' );
foreach ( json_decode( getenv( 'CALL_ARGS' ) ?: '{}', true ) as $key => $value ) {
  $_REQUEST[ $key ] = $_GET[ $key ] = is_array( $value ) ? wp_json_encode( $value ) : (string) $value;
}
$class  = getenv( 'CALL_CLASS' );
$method = getenv( 'CALL_METHOD' );
( new $class( 'xophz-compass-bazaar', 'integration' ) )->{$method}();
PHP

PRODUCT_ID=''
COUPON_ID=''
USERS=''
ORDERS=''

cleanup() {
  wp_eval "
foreach (array_filter(explode(',', '$ORDERS')) as \$id) { \$o = wc_get_order((int) \$id); if (\$o) { \$o->delete(true); } }
if ('$PRODUCT_ID') { \$p = wc_get_product((int) '$PRODUCT_ID'); if (\$p) { \$p->delete(true); } }
if ('$COUPON_ID') { wp_delete_post((int) '$COUPON_ID', true); }
require_once ABSPATH . 'wp-admin/includes/user.php';
foreach (array_filter(explode(',', '$USERS')) as \$id) { wp_delete_user((int) \$id); }
" >/dev/null
  rm -rf "$SCRATCH"
}
trap cleanup EXIT

# call <Class suffix> <method> <args-json> [user-id]
call() {
  local class="Xophz_Compass_Bazaar_$1" method="$2" args="$3" user="${4:-}"
  wp_file "$RUNNER" -e CALL_CLASS="$class" -e CALL_METHOD="$method" -e CALL_ARGS="$args" -e CALL_USER="$user"
}

# json_field <json> <key> : the value of a top-level key of a one-line JSON object.
json_field() {
  sed -n "s/.*\"$2\":\"\{0,1\}\([^\",}]*\)\"\{0,1\}.*/\1/p" <<<"$1" | head -1
}

# order_field <order-id> <php expression on $o> : echo one value from the order.
order_field() {
  wp_eval "\$o = wc_get_order((int) '$1'); echo $2;" | tail -1
}

# Fixtures
setup="$(wp_eval '
Xophz_Compass_Bazaar_Security_Capabilities::install_roles();
$p = new WC_Product_Simple(); $p->set_name("OrdSplit product"); $p->set_status("private"); $p->set_regular_price("10.00"); $p->save();
$c = new WC_Coupon(); $c->set_code("ordsplit5"); $c->set_discount_type("fixed_cart"); $c->set_amount(5); $c->save();
$cust = wp_insert_user(["user_login" => "ordsplit_customer", "user_pass" => wp_generate_password(), "user_email" => "ordsplit.customer@example.test", "display_name" => "OrdSplit Customer", "role" => "customer"]);
update_user_meta($cust, "billing_phone", "5550100123");
$staff = wp_insert_user(["user_login" => "ordsplit_staff", "user_pass" => wp_generate_password(), "user_email" => "ordsplit.staff@example.test", "display_name" => "OrdSplit Staff", "role" => "shop_manager"]);
$cash = wp_insert_user(["user_login" => "ordsplit_cashier", "user_pass" => wp_generate_password(), "user_email" => "ordsplit.cashier@example.test", "display_name" => "OrdSplit Cashier", "role" => "bazaar_cashier"]);
echo "fixtures=" . $p->get_id() . "," . $c->get_id() . "," . $cust . "," . $staff . "," . $cash;
' | tail -1)"
IFS=',' read -r PRODUCT_ID COUPON_ID CUSTOMER_ID STAFF_ID CASHIER_ID <<<"${setup#fixtures=}"
USERS="$CUSTOMER_ID,$STAFF_ID,$CASHIER_ID"
if [ -z "${CASHIER_ID:-}" ]; then
  echo "ABORT fixtures were not created: $setup"
  exit 1
fi
ADMIN_ID="$(wp_eval 'echo get_users(["role" => "administrator", "number" => 1, "orderby" => "ID", "order" => "ASC", "fields" => "ID"])[0];' | tail -1)"
ITEMS="[{\"product_id\":$PRODUCT_ID,\"quantity\":2}]"

# Sales with stringified arrays
out="$(call Admin_Orders createPosOrder "{\"items\":$ITEMS,\"paymentMethod\":\"bazaar_split\",\"splitPayments\":[{\"method\":\"cash\",\"amount\":12},{\"method\":\"card\",\"amount\":8}]}")"
expect 'split sale: succeeds with stringified splitPayments' "$out" '"success":true'
id="$(json_field "$out" order_id)"; ORDERS="$ORDERS,$id"
expect_equal 'split sale: both tenders are stored' "$(order_field "$id" 'count(json_decode($o->get_meta("_pos_split_payments"), true))')" '2'

out="$(call Admin_Pos_Sale createPosOrder "{\"items\":$ITEMS,\"paymentMethod\":\"cash\",\"appliedCoupons\":[\"ordsplit5\"]}")"
expect 'coupon sale: succeeds with stringified appliedCoupons' "$out" '"success":true'
id="$(json_field "$out" order_id)"; ORDERS="$ORDERS,$id"
expect_equal 'coupon sale: the coupon is applied (20.00 less 5.00)' "$(order_field "$id" '$o->get_total()')" '15.00'
expect_equal 'coupon sale: the coupon code is on the order' "$(order_field "$id" 'implode(",", $o->get_coupon_codes())')" 'ordsplit5'

out="$(call Admin_Pos_Sale createPosOrder "{\"items\":$ITEMS,\"paymentMethod\":\"cash\",\"customDiscounts\":[{\"type\":\"percent\",\"amount\":10,\"name\":\"Ten off\"}]}")"
expect 'percent discount: sale succeeds' "$out" '"success":true'
id="$(json_field "$out" order_id)"; ORDERS="$ORDERS,$id"
expect_equal 'percent discount: 10 percent of 20.00 is 2.00 off' "$(order_field "$id" '$o->get_total()')" '18.00'

out="$(call Admin_Pos_Sale createPosOrder "{\"items\":$ITEMS,\"paymentMethod\":\"cash\",\"cashierId\":\"$STAFF_ID\"}")"
id="$(json_field "$out" order_id)"; ORDERS="$ORDERS,$id"
expect_equal 'cashier: the logged-in user is recorded, not the id sent' "$(order_field "$id" '$o->get_meta("_pos_cashier_id")')" "$ADMIN_ID"
expect_equal 'cashier: the order author is not rewritten' "$(order_field "$id" 'get_post_field("post_author", $o->get_id()) == '"$STAFF_ID"' ? "rewritten" : "kept"')" 'kept'
expect_equal 'cashier: an order note records the ignored id' "$(order_field "$id" 'count(array_filter(wc_get_order_notes(["order_id" => $o->get_id()]), function ($n) { return false !== strpos($n->content, "was ignored"); }))')" '1'

# Order status whitelist
sale_id="$id"
for status in bogus refunded checkout-draft wc-refunded; do
  out="$(call Admin_Orders updateOrderStatus "{\"order_id\":\"$sale_id\",\"status\":\"$status\"}")"
  expect "status: $status is refused" "$out" '"message":"Invalid order status."'
done
expect_equal 'status: a refused status leaves the order alone' "$(order_field "$sale_id" '$o->get_status()')" 'completed'
out="$(call Admin_Orders updateOrderStatus "{\"order_id\":\"$sale_id\",\"status\":\"on-hold\"}")"
expect 'status: on-hold is accepted' "$out" '"new_status":"on-hold"'
out="$(call Admin_Orders updateOrderStatus "{\"order_id\":\"$sale_id\",\"status\":\"processing\"}" "$CASHIER_ID")"
expect 'status: a cashier cannot change statuses' "$out" 'Permission denied.'

# Customer search
out="$(call Admin_Pos_Customers getPosCustomers '{"search":"OrdSplit"}')"
expect 'customers: the customer is found' "$out" '"name":"OrdSplit Customer"'
if grep -qF 'OrdSplit Staff' <<<"$out" || grep -qF 'OrdSplit Cashier' <<<"$out"; then FAIL=$((FAIL + 1)); echo "FAIL customers: staff appear in the search: $out"; else PASS=$((PASS + 1)); fi
expect 'customers: a caller with bazaar_view_customers sees the email' "$out" 'ordsplit.customer@example.test'
out="$(call Admin_Pos_Customers getPosCustomers '{"search":"OrdSplit"}' "$CASHIER_ID")"
expect 'customers: a cashier still finds the customer' "$out" '"name":"OrdSplit Customer"'
expect 'customers: the email is masked for a cashier' "$out" '"email":"o***@e***.test"'
expect 'customers: the phone is masked for a cashier' "$out" '"phone":"***-0123"'
out="$(call Admin_Pos_Customers getPosCustomers '{"search":"ordsplit.customer@example.test"}' "$CASHIER_ID")"
expect 'customers: typing the full email shows it' "$out" '"email":"ordsplit.customer@example.test"'
out="$(call Admin_Pos_Customers getPosCustomers '{"search":"5550100123"}' "$CASHIER_ID")"
expect 'customers: typing the full phone finds the customer and shows it' "$out" '"phone":"5550100123"'

# Refund with stringified items
out="$(call Admin_Pos_Sale createPosOrder "{\"items\":$ITEMS,\"paymentMethod\":\"cash\"}")"
id="$(json_field "$out" order_id)"; ORDERS="$ORDERS,$id"
item_id="$(order_field "$id" 'array_key_first($o->get_items())')"
out="$(call Admin_Pos_Refunds processPosRefund "{\"order_id\":\"$id\",\"items\":[{\"item_id\":$item_id,\"qty\":1}],\"reason\":\"orders-split check\"}")"
expect 'refund: stringified items refund something' "$out" '"message":"Refund processed successfully."'
expect_equal 'refund: one of two units (10.00) is refunded' "$(order_field "$id" '$o->get_total_refunded()')" '10'
out="$(call Admin_Pos_Refunds processPosRefund "{\"order_id\":\"$id\",\"items\":[{\"item_id\":$item_id,\"qty\":1}]}" "$CASHIER_ID")"
expect 'refund: a cashier holds bazaar_refund and may refund' "$out" '"success":true'
out="$(call Admin_Pos_Refunds processPosRefund "{\"order_id\":\"$id\",\"items\":[{\"item_id\":$item_id,\"qty\":1}]}" "$CUSTOMER_ID")"
expect 'refund: a customer account is refused' "$out" 'Permission denied.'

# Receipt email
out="$(call Admin_Pos_Sale createPosOrder "{\"items\":$ITEMS,\"paymentMethod\":\"cash\",\"customerEmail\":\"first.address@example.test\"}")"
id="$(json_field "$out" order_id)"; ORDERS="$ORDERS,$id"
out="$(call Admin_Pos_Customers sendPosReceipt "{\"order_id\":\"$id\",\"recipient\":\"other.address@example.test\"}")"
expect 'receipt: sent to a different address' "$out" '"type":"email"'
expect_equal 'receipt: the billing email is not overwritten' "$(order_field "$id" '$o->get_billing_email()')" 'first.address@example.test'
out="$(call Admin_Pos_Sale createPosOrder "{\"items\":$ITEMS,\"paymentMethod\":\"cash\"}")"
id="$(json_field "$out" order_id)"; ORDERS="$ORDERS,$id"
call Admin_Pos_Customers sendPosReceipt "{\"order_id\":\"$id\",\"recipient\":\"fills.empty@example.test\"}" >/dev/null
expect_equal 'receipt: an empty billing email is filled' "$(order_field "$id" '$o->get_billing_email()')" 'fills.empty@example.test'

# Permission checks for the moved handlers
out="$(call Admin_Payment_Settings getAllPaymentGateways '{}' "$CASHIER_ID")"
expect 'gateways: settings list refused for a cashier' "$out" 'Permission denied.'
out="$(call Admin_Payment_Settings getBankDetails '{}' "$CASHIER_ID")"
expect 'bank: a cashier may read bank details' "$out" '"success":true'
out="$(call Admin_Payment_Settings getBankDetails '{}' "$CUSTOMER_ID")"
expect 'bank: a customer account may not' "$out" 'Permission denied.'
out="$(call Admin_Coupons getCoupons '{}' "$CASHIER_ID")"
expect 'coupons: a cashier cannot manage coupons' "$out" 'Permission denied.'
out="$(call Admin_Categories getCategories '{}')"
expect 'categories: the administrator gets the list' "$out" '"categories":'

# The older checks still pass, calling the delegates on Admin_Orders
out="$(bash "$HERE/pos-authorization.sh" 2>&1 | tail -3)"
expect 'pos-authorization.sh passes unchanged' "$out" ' 0 failed'

bz_finish
