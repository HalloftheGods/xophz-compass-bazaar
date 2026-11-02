#!/usr/bin/env bash
# Live check of refunds v2 (POST /bazaar/v1/orders/{id}/refunds and the legacy
# process_pos_refund adapter) against the local Docker WordPress, through
# refunds-fixtures.php.
#
# Covers: a dry run that writes nothing; a two-rate order refunded in three
# partial refunds whose tax per rate adds up to the line tax exactly (the
# last unit takes the remainder); replaying a key returns the
# same refund and a changed body under the same key is 422; an over-refund is
# 422 invalid_line; a cashier needs a manager approval (403 approval_required
# with the subject hash, a wrong token is approval_invalid and stays unspent,
# the right token refunds and is spent, reusing it is refused); an external
# terminal refund needs the slip reference (reference_required,
# tender_total_mismatch, tender_unsupported) and records it; permissions and
# a bad body; two refunds of the last unit started at the same moment where
# exactly one succeeds; a ledger-owned order restocks once through the
# lifecycle (also when the key is replayed and the hook fires again); the
# legacy action with stringified items refunds, restocks, keeps its response
# shape and writes no payment rows for an order with none, and a cashier
# calling it without an approval is refused (finding of #6983); and the
# review fixes of #6995: a split cash + card sale refunds cash first and
# names the card reference it needs, a legacy negative-fee discount is
# spread over the lines, taxes rounded at subtotal or per line (as the
# order's own tax item shows) refund to the cent, and
# the approval id is stamped on the refund row.
#
# Taxes and ledger mode are switched on inside the wp-cli processes this
# script starts; the store's options are not changed, except the tax class
# bazaar-rf-test and its rates, which exist while it runs. Everything the
# script creates is deleted at the end, also when a check fails. Run it only
# against a development store.
#
# Run from the repo root: bash wp-content/plugins/xophz-compass-bazaar/tests/integration/refunds-v2.sh
set -u
HERE="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=lib.sh
source "$HERE/lib.sh"

FIXTURES=/tmp/bazaar-refunds-fixtures.php
docker cp "$HERE/refunds-fixtures.php" "$CONTAINER:$FIXTURES" >/dev/null

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

# jget <json> <key> : one top-level value of a JSON object.
jget() {
  php -r '$d = json_decode($argv[1], true); $v = is_array($d) ? ($d[$argv[2]] ?? null) : null; echo is_array($v) ? json_encode($v) : (is_bool($v) ? ($v ? "true" : "false") : (string) $v);' "$1" "$2"
}

# ajax <user> <args-json> : the JSON body process_pos_refund answers. The
# handler cleans the harness's output buffer and prints the body itself, so it
# comes right before the harness's own "process_pos_refund<TAB>..." line. The
# harness turns wp_die into an exception that the handler's catch-all then
# answers with {"success":false,"message":""}; that second body is cut off.
ajax() {
  local out
  out="$(wp_file "$HERE/call-ajax.php" -e CALL_ACTION=process_pos_refund -e CALL_USER="$1" -e CALL_ARGS="$2")"
  sed -e 's/{"success":false,"message":""}process_pos_refund\t.*$//' -e 's/process_pos_refund\t.*$//' <<<"$out" | grep '^{' | head -n 1
}

out="$(bz_wp setup)"
STATE="$(sed -n 's/^STATE //p' <<<"$out")"
expect 'setup: fixtures created' "$STATE" '"orders":{'
if [ -z "$STATE" ]; then
  echo "$out"
  bz_finish
  exit 1
fi

out="$(bz_wp run)"
check_line() { sed -n "s/^CHECK $1 //p" <<<"$out"; }
if grep -q -e '^ERROR' -e 'Fatal error' -e 'Warning' <<<"$out"; then
  grep -e '^ERROR' -e 'Fatal error' -e 'Warning' <<<"$out" | head -n 5
fi

expect_equal 'dry run: 200, amounts only, no approval for an administrator, nothing written' "$(check_line dry_run)" '200 true 1098 0 0 0 cash'
expect_equal 'multi-rate: first unit refunds both rates (83 + 15 tax)' "$(check_line multi_first)" '200 completed 1098 98 83 15'
expect_equal 'replay: same key and body is the same refund, replayed, no new refund or row' "$(check_line replay)" '200 true same-refund 1 1'
expect_equal 'conflict: same key, other body is 422' "$(check_line conflict)" '422 idempotency_key_reused'
expect_equal 'multi-rate: two more refunds, each over two lines' "$(check_line multi_next)" '200 1975 200 1974'
expect_equal 'multi-rate: the last unit takes the remainder (82, not 83)' "$(check_line multi_last_unit_a)" '82 15 1000'
expect_equal 'multi-rate: item A tax per rate and total add up exactly' "$(check_line multi_item_a)" '248/3000 45/3000'
expect_equal 'multi-rate: item B tax per rate and total add up exactly' "$(check_line multi_item_b)" '132/1598 24/1598'
expect_equal 'payments: the cash capture is fully refunded in 3 cash rows' "$(check_line multi_payments)" '5047/5047 3 cash refunded parent'
expect_equal 'over-refund: 422 invalid_line, no fourth refund' "$(check_line over_refund)" '422 invalid_line 3'
expect_equal 'cashier dry run: the refund scope needs an approval with a subject hash' "$(check_line cashier_dry)" '200 refund 2165 64'
expect_equal 'cashier: 403 approval_required with the subject, no refund, key left claimed' "$(check_line approval_required)" '403 approval_required refund same-subject 0 claimed'
expect_equal 'cashier: a token for another subject is approval_invalid and stays unspent' "$(check_line approval_wrong_subject)" '403 approval_invalid unspent 0'
expect_equal 'cashier: the right token refunds on the same key, is spent and recorded' "$(check_line approved)" '200 completed spent 1 completed approval-recorded'
expect_equal 'cashier: a spent token is refused' "$(check_line approval_reuse)" '403 approval_invalid 1'
expect_equal 'cashier: a new approval refunds the second unit' "$(check_line approval_second_unit)" '200 2165 2'
expect_equal 'terminal: no reference, short tenders, short reference and a cash tender are refused, nothing refunded' "$(check_line card_refused)" '422/reference_required 422/tender_total_mismatch 422/reference_required 422/tender_unsupported 0 0'
expect_equal 'terminal: with a reference the refund is recorded on the terminal capture' "$(check_line card_refund)" '200 SLIP-0002 external_terminal SLIP-0002 1881 2165 1'
expect_equal 'permissions: subscriber 403, logged out 401, unknown order 404, empty body 400' "$(check_line permissions)" '403 401 404/order_not_found 400/invalid_request'
expect_equal 'ledger: the sale took 3, the refund returns 1 once, a replay and a repeated hook add nothing' "$(check_line ledger_restock)" '1:-3 200 2:-2 200/true 2:-2'
# Review fixes of #6995.
expect_equal 'split sale: without tenders the cash leg is refunded before the card leg' "$(check_line split_cash_first)" '200 cash no-error 200 cash 1624'
expect_equal 'split sale: a refund the cash leg cannot cover names the card payment and the tender it needs' "$(check_line split_needs_reference)" '200 reference_required card-named 1248 tender 422/reference_required'
expect_equal 'split sale: explicit tenders with the slip reference refund both legs' "$(check_line split_tenders)" '200 cash:2000/2000,external_terminal:1248/1248 2'
expect_equal 'legacy discount fee: each line refunds its discounted price and the two add up to the order total' "$(check_line fee_discount)" '200 4330 330 200/4331 8661 8661'
expect_equal 'round at subtotal: the last line takes the rate remainder, the order refunds to the cent' "$(check_line round_at_subtotal)" '200 1083 200/1082 2165 2165 2165'
expect_equal 'round per line (sold with the option no, refunded with yes): each line refunds its 0.83, the order is fully refunded' "$(check_line round_per_line)" '200 1083 200/1083 2166 2166 2166 refunded'
expect_equal 'approval id: stamped on the refund row of an order with no payments' "$(check_line nopay_approval)" '200 0 stamped'
expect_equal 'approval id: stamped on the refund row of an order with payments' "$(check_line approved_row)" 'stamped'

# Two refunds of the last unit at the same moment: exactly one succeeds.
LAST="$(php -r '$s = json_decode($argv[1], true); echo $s["orders"]["last"];' "$STATE")"
AT="$(php -r 'echo sprintf("%.3f", microtime(true) + 4);')"
ONCE_A="$(mktemp)"
ONCE_B="$(mktemp)"
bz_wp once -e BZ_ORDER="$LAST" -e BZ_AT="$AT" >"$ONCE_A" &
PID_A=$!
bz_wp once -e BZ_ORDER="$LAST" -e BZ_AT="$AT" >"$ONCE_B" &
PID_B=$!
wait "$PID_A" "$PID_B"
RESULTS="$(sed -n 's/^RESULT //p' "$ONCE_A" "$ONCE_B" | awk '{print $1 " " $2}' | sort | tr '\n' ',')"
rm -f "$ONCE_A" "$ONCE_B"
case "$RESULTS" in
  '200 -,409 order_busy,' | '200 -,422 invalid_line,') expect_equal 'parallel: one refund succeeds, the other is refused' 'one-wins' 'one-wins' ;;
  *) expect_equal 'parallel: one refund succeeds, the other is refused' "$RESULTS" '200 -,422 invalid_line,' ;;
esac
expect_equal 'parallel: one WooCommerce refund, the capture refunded once, one payment row' "$(sed -n 's/^CHECK parallel_state //p' <<<"$(bz_wp parallel_state)")" '1 1083 1 2'

# The legacy action: stringified items, the old response shape, no payment rows for an order with none.
LEGACY="$(php -r '$s = json_decode($argv[1], true); echo $s["orders"]["legacy"];' "$STATE")"
CASHIER="$(php -r '$s = json_decode($argv[1], true); echo $s["cashier"];' "$STATE")"
legacy_state() { sed -n 's/^CHECK legacy_state //p' <<<"$(bz_wp legacy_state)"; }
before="$(legacy_state)"
ITEM="$(sed -n 's/.*item=\([0-9]*\).*/\1/p' <<<"$before")"
ARGS="$(php -r 'echo json_encode(["order_id" => (int) $argv[1], "items" => json_encode([["item_id" => (int) $argv[2], "qty" => 1]]), "reason" => "legacy"]);' "$LEGACY" "$ITEM")"
expect_equal 'legacy: before, stock 18 after the sale, no refunds' "${before#item=* }" 'stock=18 refunds=0 payments=0/0 rows=0'
body="$(ajax admin "$ARGS")"
expect_equal 'legacy: admin refund with stringified items answers the old shape' "$(php -r '$d = json_decode($argv[1], true); echo is_array($d) ? implode(",", array_keys($d)) . " " . var_export($d["success"] ?? null, true) . " " . ($d["refund_id"] > 0 ? "id" : "no-id") . " " . $d["amount"] : "bad";' "$body")" 'success,message,refund_id,amount true id 13.53'
expect_equal 'legacy: stock restocked once, one refund, no payment rows, one key row' "$(legacy_state | sed 's/^item=[0-9]* //')" 'stock=19 refunds=1 payments=0/0 rows=1'
cashier_body="$(ajax "id:$CASHIER" "$ARGS")"
expect_equal 'legacy: a cashier without an approval is refused (approval_required)' "$(jget "$cashier_body" success) $(jget "$cashier_body" code)" 'false approval_required'
expect_equal 'legacy: the refused call changed nothing' "$(legacy_state | sed 's/^item=[0-9]* //')" 'stock=19 refunds=1 payments=0/0 rows=2'
expect_equal 'rest: restock false leaves the stock alone' "$(sed -n 's/^CHECK no_restock //p' <<<"$(bz_wp legacy_rest)")" '200 restock-off stock-unchanged 0'

bz_finish
