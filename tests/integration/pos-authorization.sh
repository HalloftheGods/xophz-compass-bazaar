#!/usr/bin/env bash
# Live check of the POS digital payment authorization (#6783) against the
# local Docker WordPress. Creates a throwaway product and moves it, and any
# order it creates, to the trash at the end.
#
# Run from the repo root: bash wp-content/plugins/xophz-compass-bazaar/tests/integration/pos-authorization.sh
set -u
CONTAINER="${WP_CONTAINER:-u-wordpress}"
WP_PATH="${WP_PATH:-/var/www/html}"
HERE="$(cd "$(dirname "$0")" && pwd)"
PASS=0
FAIL=0

docker cp "$HERE/call-handler.php" "$CONTAINER:/tmp/bazaar-call-handler.php" >/dev/null

wp_eval() {
  docker exec "$CONTAINER" wp --allow-root --path="$WP_PATH" eval "$1" 2>&1 | grep -v -e '^Deprecated' -e '^$' -e 'sendmail'
}

# call <method> <args-json> [extra docker -e flags...]
call() {
  local method="$1" args="$2"
  shift 2
  docker exec -e CALL_CLASS=Xophz_Compass_Bazaar_Admin_Orders -e CALL_METHOD="$method" -e CALL_ARGS="$args" "$@" \
    "$CONTAINER" wp --allow-root --path="$WP_PATH" eval-file /tmp/bazaar-call-handler.php 2>&1 \
    | grep -v -e '^Deprecated' -e '^$' -e 'sendmail'
}

expect() {
  local name="$1" output="$2" needle="$3"
  if grep -qF -- "$needle" <<<"$output"; then
    PASS=$((PASS + 1))
  else
    FAIL=$((FAIL + 1))
    printf 'FAIL %s\n  expected to contain: %s\n  got: %s\n' "$name" "$needle" "$(head -c 400 <<<"$output")"
  fi
}

PRODUCT_ID="$(wp_eval '$p = new WC_Product_Simple(); $p->set_name("POS auth integration check"); $p->set_status("private"); $p->set_regular_price("12.50"); $p->save(); echo $p->get_id();' | tail -1)"
ITEMS="[{\"product_id\":$PRODUCT_ID,\"quantity\":2}]"
ORDERS_BEFORE="$(wp_eval 'echo count(wc_get_orders(["limit" => -1, "return" => "ids", "created_via" => "bazaar_pos"]));' | tail -1)"

# Portal access
out="$(call renderGatewayPortal '{"gateway":"paypal","amount":"25.00"}' -e CALL_LOGGED_OUT=1)"
expect 'portal: logged-out user refused' "$out" '"code":"forbidden"'
out="$(call renderGatewayPortal '{"gateway":"paypal","amount":"25.00"}' -e CALL_BAD_NONCE=1)"
expect 'portal: bad nonce refused' "$out" '"code":"rest_cookie_invalid_nonce"'
out="$(call renderGatewayPortal '{"gateway":"paypal","amount":"25.00"}' -e CALL_NO_NONCE=1)"
expect 'portal: missing nonce refused' "$out" '"code":"rest_cookie_invalid_nonce"'
out="$(call renderGatewayPortal '{"gateway":"paypal","amount":"25.00"}' -e CALL_PORTAL=off)"
expect 'portal: refused when test mode is off' "$out" 'The POS test payment portal is turned off on this site.'
out="$(call renderGatewayPortal '{"gateway":"paypal","amount":"25.00"}' -e CALL_PORTAL=on)"
expect 'portal: renders in test mode' "$out" 'TEST MODE'
expect 'portal: posts to the exact site origin' "$out" 'postMessage({ status: '"'"'approved'"'"', gatewayId: GATEWAY, authorizationToken: reply.authorizationToken }, ORIGIN)'
if grep -qF "'*'" <<<"$out"; then FAIL=$((FAIL + 1)); echo "FAIL portal: still posts to '*'"; else PASS=$((PASS + 1)); fi

# Token endpoint
out="$(call authorizeTestPayment '{"gateway":"paypal","amount":"25.00"}' -e CALL_LOGGED_OUT=1)"
expect 'token: logged-out user refused' "$out" '"code":"forbidden"'
out="$(call authorizeTestPayment '{"gateway":"paypal","amount":"25.00"}' -e CALL_BAD_NONCE=1)"
expect 'token: bad nonce refused' "$out" '"code":"rest_cookie_invalid_nonce"'
out="$(call authorizeTestPayment '{"gateway":"paypal","amount":"25.00"}' -e CALL_PORTAL=off)"
expect 'token: refused when test mode is off' "$out" '"code":"test_portal_disabled"'

# Checkout
out="$(call createPosOrder "{\"items\":$ITEMS,\"paymentMethod\":\"paypal\"}")"
expect 'checkout: digital tender without a token is refused' "$out" 'Payment not authorized (missing)'
out="$(call createPosOrder "{\"items\":$ITEMS,\"paymentMethod\":\"paypal\",\"authorizationToken\":\"forged-token-forged-token-forged\"}")"
expect 'checkout: forged token is refused' "$out" 'Payment not authorized (unknown or already used)'

TOKEN="$(call authorizeTestPayment '{"gateway":"paypal","amount":"20.00"}' -e CALL_PORTAL=on | sed -n 's/.*"authorizationToken":"\([^"]*\)".*/\1/p')"
out="$(call createPosOrder "{\"items\":$ITEMS,\"paymentMethod\":\"paypal\",\"authorizationToken\":\"$TOKEN\"}")"
expect 'checkout: token for a different total is refused' "$out" 'Payment not authorized (issued for a different total)'

TOKEN="$(call authorizeTestPayment '{"gateway":"paypal","amount":"25.00"}' -e CALL_PORTAL=on | sed -n 's/.*"authorizationToken":"\([^"]*\)".*/\1/p')"
out="$(call createPosOrder "{\"items\":$ITEMS,\"paymentMethod\":\"paypal\",\"authorizationToken\":\"$TOKEN\"}")"
expect 'checkout: matching token completes the sale' "$out" '"success":true'
out="$(call createPosOrder "{\"items\":$ITEMS,\"paymentMethod\":\"paypal\",\"authorizationToken\":\"$TOKEN\"}")"
expect 'checkout: the same token cannot be reused' "$out" 'Payment not authorized (unknown or already used)'

out="$(call createPosOrder "{\"items\":$ITEMS,\"paymentMethod\":\"cash\"}")"
expect 'checkout: cash still needs no token' "$out" '"success":true'

ORDERS_AFTER="$(wp_eval 'echo count(wc_get_orders(["limit" => -1, "return" => "ids", "created_via" => "bazaar_pos"]));' | tail -1)"
if [ "$((ORDERS_AFTER - ORDERS_BEFORE))" -eq 2 ]; then PASS=$((PASS + 1)); else FAIL=$((FAIL + 1)); echo "FAIL checkout: refused attempts left orders behind ($ORDERS_BEFORE -> $ORDERS_AFTER, expected +2)"; fi

# Clean up: trash the two orders this run created and the product.
wp_eval "foreach (wc_get_orders([\"limit\" => -1, \"created_via\" => \"bazaar_pos\", \"orderby\" => \"ID\", \"order\" => \"DESC\"]) as \$i => \$o) { if (\$i < 2) \$o->delete(false); } wc_get_product($PRODUCT_ID)->delete(false);" >/dev/null

echo "$PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
