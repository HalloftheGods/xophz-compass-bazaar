#!/usr/bin/env bash
# Live check of the stock ledger persistence (WP7) against the local Docker
# WordPress: the wpdb store, the WooCommerce port, modes and the stock REST.
#
# Asserts:
# - modes: shadow and ledger are accepted while the audit is clean; an unknown
#   mode is 400; logged out is 401;
# - lazy init: the first touch writes one opening batch equal to WooCommerce stock;
# - REST adjustments (add, subtract, count, replay, reused key, stale count,
#   oversell, fractional quantity) change the ledger and `_stock` by the same
#   amount, and refused ones change neither; movements page by cursor;
# - 8 parallel processes subtracting 1 from stock 5 with distinct keys give
#   exactly 5 successes and 3 insufficient_stock, ending at `_stock` 0 and balance 0;
# - 5 parallel calls with one key write one batch and subtract once;
# - a multi-inventory product keeps disabled-location stock out of `_stock`
#   and mirrors its balances into _compass_multi_inventory;
# - ledger mode is refused while there is drift, unless forced with a reason;
# - shadow mode adjusts WooCommerce stock without the ledger.
#
# The sequential checks run in-process (stock-ledger-fixtures.php) because
# every WordPress boot in the Docker stack takes seconds; the parallel checks
# use one docker exec per call.
#
# Fixtures: private throwaway products and warehouses, moved to the trash at
# the end; their ledger and outbox rows are deleted, and the two mode options
# are put back as they were. Other products' ledger rows are not touched.
#
# Env: BAZAAR_SCRATCH, a directory for the parallel outputs (default: mktemp -d);
# it is deleted at the end.
#
# Run from the repo root: bash wp-content/plugins/xophz-compass-bazaar/tests/integration/stock-ledger.sh
set -u
HERE="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=lib.sh
source "$HERE/lib.sh"

SCRATCH="${BAZAAR_SCRATCH:-$(mktemp -d)}"
mkdir -p "$SCRATCH"
RUN="sl$(date +%s)$$"

fix() {
  local action="$1"
  shift
  wp_file "$HERE/stock-ledger-fixtures.php" -e FIX_ACTION="$action" -e RUN="$RUN" "$@"
}

# jget <json> <key> : one top-level value of a JSON object.
jget() {
  php -r '$d = json_decode($argv[1], true); $v = is_array($d) ? ($d[$argv[2]] ?? null) : null; echo is_array($v) ? json_encode($v) : (string) $v;' "$1" "$2"
}

# rest_bg <outfile> <METHOD> <route> <body> : one REST call as the first administrator (safe in parallel).
rest_bg() {
  docker exec -e REST_METHOD="$2" -e REST_ROUTE="$3" -e REST_BODY="$4" -e REST_USER="" "$CONTAINER" \
    wp --allow-root --path="$WP_PATH" eval-file /tmp/bazaar-call-rest.php 2>&1 | bz_filter | tail -1 >"$1"
}

setup="$(fix setup | tail -1)"
for name in P1 P2 P3 P4 P5 W1 W2 ADMIN; do
  printf -v "$name" '%s' "$(jget "$setup" "$name")"
done
SAVED="$(jget "$setup" saved)"
if [ -z "${P5:-}" ] || [ -z "${W2:-}" ]; then
  echo "setup failed: $setup"
  exit 1
fi
cleanup() {
  fix cleanup -e PIDS="$P1,$P2,$P3,$P4,$P5" -e WIDS="$W1,$W2" -e SAVED="$SAVED" >/dev/null
  rm -rf "$SCRATCH"
}
trap cleanup EXIT
IDS=(-e P1="$P1" -e P2="$P2" -e P3="$P3" -e P4="$P4" -e P5="$P5" -e W1="$W1" -e W2="$W2" -e ADMIN="$ADMIN")

# Modes, lazy init, adjustments, movements, multi-inventory (ledger mode)
out="$(fix seq_ledger "${IDS[@]}")"
expect 'mode: shadow is accepted' "$out" 'mode.shadow.status=200'
expect 'mode: ledger is accepted while the audit is clean' "$out" 'mode.ledger.mode=ledger'
expect 'mode: ledger change reports engines_ok' "$out" 'mode.ledger.engines_ok=true'
expect 'mode: ledger change reports zero violations' "$out" 'mode.ledger.violations=0'
expect 'mode: an unknown mode is 400 invalid_request' "$out" 'mode.unknown.code=invalid_request'
expect 'auth: logged out is 401' "$out" 'auth.logged_out.status=401'
expect 'lazy init: balances answer 200' "$out" 'lazy.status=200'
expect 'lazy init: initialized' "$out" 'lazy.initialized=true'
expect 'lazy init: location 0 holds the WooCommerce stock' "$out" 'lazy.balances=[{"location_id":0,"on_hand":7,"is_enabled":true}]'
expect 'lazy init: one opening batch' "$out" 'lazy.batches=1'
expect 'lazy init: _stock unchanged' "$out" 'lazy.wc=7'
expect 'adjust add: 200' "$out" 'add.status=200'
expect 'adjust add: fresh batch' "$out" 'add.replayed=false'
expect 'adjust add: _stock +3' "$out" 'add.wc=10'
expect 'adjust add: balance +3' "$out" 'add.bal0=10'
expect 'adjust replay: same key and body replays' "$out" 'replay.replayed=true'
expect 'adjust replay: _stock unchanged' "$out" 'replay.wc=10'
expect 'adjust reuse: same key, other body is 422' "$out" 'reuse.code=idempotency_key_reused'
expect 'adjust subtract: 200' "$out" 'subtract.status=200'
expect 'adjust subtract: _stock -4' "$out" 'subtract.wc=6'
expect 'adjust subtract: balance -4' "$out" 'subtract.bal0=6'
expect 'adjust count: 200' "$out" 'count.status=200'
expect 'adjust count: _stock set to 9' "$out" 'count.wc=9'
expect 'adjust count: balance set to 9' "$out" 'count.bal0=9'
expect 'adjust count: a stale expected is 409 stock_changed' "$out" 'stale.code=stock_changed'
expect 'adjust count: stock_changed carries the current balance' "$out" 'stale.current=9'
expect 'adjust oversell: 409 insufficient_stock' "$out" 'oversell.code=insufficient_stock'
expect 'adjust: a quantity WooCommerce would round is 400' "$out" 'fraction.code=invalid_request'
expect 'adjust: refused calls leave _stock at 9' "$out" 'refused.wc=9'
expect 'adjust: refused calls leave the balance at 9' "$out" 'refused.bal0=9'
expect 'movements: newest first, limit 2' "$out" 'movements.page1.reasons=["count","adjust"]'
expect 'movements: a cursor while more exist' "$out" 'movements.page1.has_cursor=true'
expect 'movements: the cursor page ends at the opening batch' "$out" 'movements.page2.reasons=["adjust","opening"]'
expect 'movements: the last page has no cursor' "$out" 'movements.page2.next_cursor=null'
expect 'movements: a bad since is 400' "$out" 'movements.bad_since.code=invalid_request'
expect 'multi: location-tracked' "$out" 'multi.tracked=true'
expect 'multi: location 0 holds the residual 0' "$out" '{"location_id":0,"on_hand":0,"is_enabled":true}'
expect 'multi: the enabled warehouse opens at 4' "$out" "{\"location_id\":$W1,\"on_hand\":4,\"is_enabled\":true}"
expect 'multi: the disabled warehouse keeps its 3, disabled' "$out" "{\"location_id\":$W2,\"on_hand\":3,\"is_enabled\":false}"
expect 'multi: add at the disabled warehouse answers 200' "$out" 'multi.disabled.status=200'
expect 'multi: disabled stock stays out of _stock' "$out" 'multi.disabled.wc=4'
expect 'multi: disabled balance is 5' "$out" 'multi.disabled.bal=5'
expect 'multi: disabled balance mirrored into the meta' "$out" 'multi.disabled.meta=5'
expect 'multi: subtract at the enabled warehouse answers 200' "$out" 'multi.enabled.status=200'
expect 'multi: enabled change reaches _stock' "$out" 'multi.enabled.wc=3'
expect 'multi: enabled balance is 3' "$out" 'multi.enabled.bal=3'
expect 'multi: enabled balance mirrored into the meta' "$out" 'multi.enabled.meta=3'
expect 'multi: the disabled warehouse stays disabled' "$out" 'multi.disabled.still=false'
expect 'parallel setup: P2 initialized at 5' "$out" 'init.P2=5'
expect 'parallel setup: P3 initialized at 5' "$out" 'init.P3=5'

# 8 parallel decrements with distinct keys, then 5 parallel calls with one key
docker cp "$LIB_DIR/call-rest.php" "$CONTAINER:/tmp/bazaar-call-rest.php" >/dev/null
for i in 1 2 3 4 5 6 7 8; do
  rest_bg "$SCRATCH/par-$i" POST /bazaar/v1/stock/adjustments "{\"client_key\":\"$RUN-p$i\",\"product_id\":$P2,\"operation\":\"subtract\",\"quantity\":1}" &
done
wait
expect_equal 'parallel: exactly 5 successes' "$(cat "$SCRATCH"/par-* | grep -c '"status":200')" '5'
expect_equal 'parallel: exactly 3 insufficient_stock' "$(cat "$SCRATCH"/par-* | grep -c '"code":"insufficient_stock"')" '3'
for i in 1 2 3 4 5; do
  rest_bg "$SCRATCH/same-$i" POST /bazaar/v1/stock/adjustments "{\"client_key\":\"$RUN-same\",\"product_id\":$P3,\"operation\":\"subtract\",\"quantity\":1}" &
done
wait
expect_equal 'same key: all 5 calls answer 200' "$(cat "$SCRATCH"/same-* | grep -c '"status":200')" '5'
expect_equal 'same key: one fresh result, four replays' "$(cat "$SCRATCH"/same-* | grep -c '"replayed":true')" '4'

# Parallel results, drift, shadow mode
out="$(fix seq_drift "${IDS[@]}")"
expect 'parallel: _stock ends at 0' "$out" 'par.P2.wc=0'
expect 'parallel: balance ends at 0' "$out" 'par.P2.bal0=0'
expect 'parallel: opening plus 5 batches' "$out" 'par.P2.batches=6'
expect 'same key: _stock -1 once' "$out" 'par.P3.wc=4'
expect 'same key: balance -1 once' "$out" 'par.P3.bal0=4'
expect 'same key: one batch for the key' "$out" 'par.same.key_batches=1'
expect 'drift: back to shadow' "$out" 'drift.shadow.previous=ledger'
expect 'drift: ledger mode is refused with 409' "$out" 'drift.refused.status=409'
expect 'drift: the code is ledger_mode_refused' "$out" 'drift.refused.code=ledger_mode_refused'
expect 'drift: the refusal counts the drifted product' "$out" 'drift.refused.violations=1'
expect 'drift: force without a reason is still refused' "$out" 'drift.no_reason.code=ledger_mode_refused'
expect 'drift: force with a reason is accepted' "$out" 'drift.forced.status=200'
expect 'drift: the change is marked forced' "$out" 'drift.forced.forced=true'
expect 'drift: the option now says ledger' "$out" 'drift.forced.current=ledger'
expect 'drift: the history keeps the reason' "$out" 'drift.forced.history_reason=integration test'
expect 'ledger: an unknown location is 422' "$out" 'ledger.phantom.status=422'
expect 'ledger: the code is unsupported_field' "$out" 'ledger.phantom.code=unsupported_field'
expect 'ledger: no balance row opened for it' "$out" 'ledger.phantom.rows=0'
expect 'shadow: adjustment answers 200' "$out" 'shadow.status=200'
expect 'shadow: no ledger involved' "$out" 'shadow.ledger=false'
expect 'shadow: _stock +2' "$out" 'shadow.wc=11'
expect 'shadow: ledger balance untouched' "$out" 'shadow.bal0=9'
expect 'shadow: a warehouse adjustment is 422 unsupported_field' "$out" 'shadow.warehouse.code=unsupported_field'

bz_finish
