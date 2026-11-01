#!/usr/bin/env bash
# Live check of the stock observer, the ledger-owned order lifecycle, the
# backfill, the audit and the `wp bazaar` commands (WP13) against the local
# Docker WordPress.
#
# Asserts:
# - backfill: every `_stock` row in the database is unchanged; the fixtures
#   open at their WooCommerce stock; a variable parent that does not manage
#   stock is skipped; the mode moves from off to shadow; a re-run is a no-op;
# - shadow mode: a web order's reduction is recorded as online_sale with the
#   order id, its cancellation as online_restore and a refund restock as
#   online_refund_restock; a card-vault-style wc_update_product_stock decrease
#   and a WooCommerce admin stock edit are recorded as external with a
#   stock.drift.detected row; a duplicate hook records nothing; a change made
#   inside a Bazaar transaction is recorded after the commit; the audit finds
#   nothing for the fixtures and no drift anywhere;
# - ledger mode, with a ledger-owned order (checkout row plus sync_order):
#   WooCommerce never reduces it; cancelling returns stock once; re-opening
#   sells again; an admin refund with restock returns stock once; no item
#   carries _reduced_stock; the audit finds nothing for the fixtures;
# - locations (findings 14 and 15 of #6983): in shadow mode a legacy location
#   count and a location disable are recorded per location, and a web order
#   sold from a warehouse is booked at that warehouse, so location 0 never
#   goes negative and the audit has no violation; in ledger mode a ledger
#   sale projects the counted value into the meta, and a web order is booked
#   at its warehouse, so that unit is not available there any more;
# - ledger mode: an order completed, cancelled and then fully refunded has
#   no order_target violation (the refunded target follows the last sync,
#   finding 16 of #6983); a backorders product oversold to -2 is a notice,
#   and stays one (negative_balance_untracked, no drift) once it stops
#   managing stock (finding 17);
# - CLI: `wp bazaar stock verify` exits 0 (in both modes); an unknown mode
#   exits 1 with invalid_request; reconcile of an in-step product records
#   nothing; the help lists the stock and checkouts commands.
#
# The verify exit codes cover the whole site's ledger, so another script that
# leaves drift or a violation behind at the same time makes them fail.
#
# Fixtures: private products and orders tagged with this run, moved to the
# trash at the end; their ledger, checkout and outbox rows are deleted, and
# the mode, mode history and backfill cursor options are put back.
#
# Run from the repo root: bash wp-content/plugins/xophz-compass-bazaar/tests/integration/stock-observer.sh
set -u
HERE="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=lib.sh
source "$HERE/lib.sh"

RUN="so$(date +%s)$$"

fix() {
  local action="$1"
  shift
  wp_file "$HERE/stock-observer-fixtures.php" -e FIX_ACTION="$action" -e RUN="$RUN" "$@"
}

# jget <json> <key> : one top-level value of a JSON object.
jget() {
  php -r '$d = json_decode($argv[1], true); $v = is_array($d) ? ($d[$argv[2]] ?? null) : null; echo is_array($v) ? json_encode($v) : (string) $v;' "$1" "$2"
}

# wp_cli <args...> : run wp in the container; the last line is exit=<code>.
wp_cli() {
  local out code
  out="$(docker exec "$CONTAINER" wp --allow-root --path="$WP_PATH" "$@" 2>&1)"
  code=$?
  printf '%s\nexit=%s\n' "$(bz_filter <<<"$out")" "$code"
}

setup="$(fix setup | tail -1)"
for name in A B C D V VP ADMIN; do
  printf -v "$name" '%s' "$(jget "$setup" "$name")"
done
SAVED="$(jget "$setup" saved)"
if [ -z "${D:-}" ] || [ -z "${V:-}" ]; then
  echo "setup failed: $setup"
  exit 1
fi
EXTRA=""
cleanup() {
  fix cleanup -e A="$A" -e B="$B" -e C="$C" -e D="$D" -e V="$V" -e VP="$VP" -e SAVED="$SAVED" -e EXTRA="$EXTRA" >/dev/null
}
trap cleanup EXIT
IDS=(-e A="$A" -e B="$B" -e C="$C" -e D="$D" -e V="$V" -e VP="$VP" -e ADMIN="$ADMIN")

# Backfill
out="$(fix seq_backfill "${IDS[@]}")"
expect 'backfill: starts in off mode' "$out" 'backfill.mode_before=off'
expect 'backfill: the pass finishes' "$out" 'backfill.done=true'
expect 'backfill: no errors' "$out" 'backfill.errors=0'
expect 'backfill: every _stock row unchanged' "$out" 'backfill.stock_rows_changed=0'
expect 'backfill: all 5 fixtures open at their WooCommerce stock' "$out" 'backfill.fixtures_opened_at_wc=5'
expect 'backfill: a parent that does not manage stock is skipped' "$out" 'backfill.parent_skipped=true'
expect 'backfill: the mode moves from off to shadow' "$out" 'backfill.mode_after=shadow'
expect 'backfill: the cursor says done' "$out" 'backfill.cursor_done=true'
expect 'backfill: a re-run initializes nothing' "$out" 'backfill.rerun.initialized=0'
expect 'backfill: a re-run writes no batch' "$out" 'backfill.rerun.batches_unchanged=true'
expect 'backfill: a re-run changes no _stock row' "$out" 'backfill.rerun.stock_rows_changed=0'

# Shadow mode
out="$(fix seq_shadow "${IDS[@]}")"
WEB="$(sed -n 's/^web.order=//p' <<<"$out")"
REFUND_ORDER="$(sed -n 's/^refund.order=//p' <<<"$out")"
expect 'shadow: mode' "$out" 'shadow.mode=shadow'
expect 'web order: WooCommerce reduced A by 2' "$out" 'web.A.wc=8'
expect 'web order: the ledger follows A' "$out" 'web.A.bal=8'
expect 'web order: recorded as online_sale' "$out" 'web.A.reason=online_sale'
expect 'web order: with the order id' "$out" "web.A.source=order:$WEB"
expect 'web order: one movement of -2' "$out" 'web.A.qty=-2'
expect 'web order: the variation is recorded too' "$out" 'web.V.reason=online_sale'
expect 'web order: the variation balance follows' "$out" 'web.V.bal=3'
expect 'web order: WooCommerce owns this order (_reduced_stock on both items)' "$out" 'web.wc_reduced_items=2'
expect 'web order: no context left open' "$out" 'web.context_depth=0'
expect 'web cancel: A back to 10' "$out" 'restore.A.bal=10'
expect 'web cancel: recorded as online_restore' "$out" 'restore.A.reason=online_restore'
expect 'web cancel: no context left open' "$out" 'restore.context_depth=0'
expect 'web refund: created' "$out" 'refund.created=true'
expect 'web refund: restock of 1 reaches the ledger' "$out" 'refund.A.bal=9'
expect 'web refund: recorded as online_refund_restock' "$out" 'refund.A.reason=online_refund_restock'
expect 'web refund: with the order id' "$out" "refund.A.source=order:$REFUND_ORDER"
expect 'web refund: no context left open' "$out" 'refund.context_depth=0'
expect 'failed gateway refund: is an error' "$out" 'refund.failed_is_error=true'
expect 'failed gateway refund: no context left open' "$out" 'refund.failed_context_depth=0'
expect 'card-vault write: WooCommerce at 5' "$out" 'vault.B.wc=5'
expect 'card-vault write: ledger at 5' "$out" 'vault.B.bal=5'
expect 'card-vault write: recorded as external' "$out" 'vault.B.reason=external'
expect 'card-vault write: one stock.drift.detected row' "$out" 'vault.drift_events=1'
expect 'admin edit: ledger at 4' "$out" 'admin.C.bal=4'
expect 'admin edit: recorded as external' "$out" 'admin.C.reason=external'
expect 'admin edit: one batch besides the opening' "$out" 'admin.C.batches=2'
expect 'admin edit: one stock.drift.detected row' "$out" 'admin.drift_events=1'
expect 'duplicate hook: records nothing' "$out" 'duplicate.C.batches=2'
expect 'transaction: nothing recorded before the commit' "$out" 'tx.during_bal=5'
expect 'transaction: recorded after the commit' "$out" 'tx.B.bal=6'
expect 'transaction: still external' "$out" 'tx.B.reason=external'
expect 'shadow audit: nothing for the fixtures' "$out" 'shadow.audit.fixture_violations=0'
expect 'shadow audit: no drift anywhere' "$out" 'shadow.audit.drift_anywhere=0'

out="$(wp_cli bazaar stock verify)"
expect 'cli verify (shadow): exits 0' "$out" 'exit=0'
expect 'cli verify (shadow): no violations' "$out" 'Stock audit: no violations'

# Locations: shadow then ledger
out="$(fix seq_locations "${IDS[@]}")"
EXTRA="$EXTRA,$(sed -n 's/^loc.L=//p' <<<"$out"),$(sed -n 's/^loc.WA=//p' <<<"$out"),$(sed -n 's/^loc.WB=//p' <<<"$out")"
expect 'locations: opens at WA 5' "$out" 'loc.open.WA=5'
expect 'locations: opens at WB 5' "$out" 'loc.open.WB=5'
expect 'locations: opens at location 0 with 0' "$out" 'loc.open.L0=0'
expect 'shadow count: _stock is the enabled total 13' "$out" 'loc.count.wc=13'
expect 'shadow count: the ledger books it at WA' "$out" 'loc.count.WA=8'
expect 'shadow count: location 0 untouched' "$out" 'loc.count.L0=0'
expect 'shadow disable: _stock drops to 8' "$out" 'loc.disable.wc=8'
expect 'shadow disable: WB keeps 5, disabled' "$out" 'loc.disable.WB=5 off'
expect 'shadow disable: location 0 untouched' "$out" 'loc.disable.L0=0'
expect 'shadow web order: WooCommerce reduces _stock' "$out" 'loc.web.wc=7'
expect 'shadow web order: booked at WA' "$out" 'loc.web.WA=7'
expect 'shadow web order: location 0 never goes negative' "$out" 'loc.web.L0=0'
expect 'shadow audit: no violation for the product' "$out" 'loc.shadow.violations=[]'
expect 'shadow audit: the meta WA that legacy never decremented is a notice' "$out" 'loc.shadow.notices=["location_meta_drift"]'
expect 'ledger sale: WA 6' "$out" 'loc.ledger_sale.WA=6'
expect 'ledger sale: the meta gets the counted value minus 1, not a stale balance' "$out" 'loc.ledger_sale.meta.WA=6'
expect 'ledger sale: _stock 6' "$out" 'loc.ledger_sale.wc=6'
expect 'ledger web order: booked at WA' "$out" 'loc.ledger_web.WA=5'
expect 'ledger web order: location 0 untouched' "$out" 'loc.ledger_web.L0=0'
expect 'ledger web order: that unit is not available at WA any more' "$out" 'loc.ledger_web.available_WA=5'
expect 'ledger audit: no violation for the product' "$out" 'loc.ledger.violations=[]'

# Ledger mode, a ledger-owned order
out="$(fix seq_ledger "${IDS[@]}")"
expect 'ledger: mode' "$out" 'ledger.mode=ledger'
expect 'ledger: the order is ledger-owned' "$out" 'ledger.owned=true'
expect 'ledger sale: sync_order takes 3' "$out" 'sold.D.wc=6'
expect 'ledger sale: balance 6' "$out" 'sold.D.bal=6'
expect 'ledger sale: reason sale' "$out" 'sold.D.reason=sale'
expect 'ledger completed: WooCommerce did not reduce' "$out" 'completed.D.wc=6'
expect 'ledger completed: no new batch' "$out" 'completed.D.batches=2'
expect 'ledger: payment-complete reduction is suppressed' "$out" 'filter.payment_complete=false'
expect 'ledger: refund restock by WooCommerce is suppressed' "$out" 'filter.can_restock_refunded=false'
expect 'ledger cancel: stock returns' "$out" 'cancelled.D.wc=9'
expect 'ledger cancel: as sale_reversal' "$out" 'cancelled.D.reason=sale_reversal'
expect 'ledger cancel: repeated hooks return nothing more' "$out" 'cancelled_again.D.wc=9'
expect 'ledger cancel: no extra batch' "$out" 'cancelled_again.D.batches=3'
expect 'ledger re-open: sells again' "$out" 'reopened.D.wc=6'
expect 'ledger re-open: as resale' "$out" 'reopened.D.reason=resale'
expect 'ledger refund: created' "$out" 'ledger_refund.created=true'
expect 'ledger refund: restock returns 1' "$out" 'refunded.D.wc=7'
expect 'ledger refund: as refund_restock' "$out" 'refunded.D.reason=refund_restock'
expect 'ledger refund: a repeated hook returns nothing more' "$out" 'refunded_again.D.wc=7'
expect 'ledger refund: no extra batch' "$out" 'refunded_again.D.batches=5'
expect 'ledger: no item carries _reduced_stock' "$out" 'ledger.wc_reduced_items=0'
expect 'ledger: only ledger batches, none observed' "$out" 'ledger.D.reasons=["opening","sale","sale_reversal","resale","refund_restock"]'
expect 'ledger audit: nothing for the fixtures' "$out" 'ledger.audit.fixture_violations=0'
EXTRA="$EXTRA,$(sed -n 's/^ledger.F=//p' <<<"$out")"
expect 'cancel then refund: the refund is created' "$out" 'cancel_refund.created=true'
expect 'cancel then refund: the order is refunded' "$out" 'cancel_refund.status=refunded'
expect 'cancel then refund: the stock stays returned' "$out" 'cancel_refund.net_outflow=0'
expect 'cancel then refund: no order_target violation' "$out" 'cancel_refund.audit.violations=0'
expect 'backorder: oversold to -2' "$out" 'backorder.balance=-2'
expect 'backorder: no violation' "$out" 'backorder.violations=[]'
expect 'backorder: a backorder notice' "$out" 'backorder.notices=["negative_balance_backorder"]'
expect 'unmanaged: still no violation (no drift either)' "$out" 'unmanaged.violations=[]'
expect 'unmanaged: an untracked notice' "$out" 'unmanaged.notices=["negative_balance_untracked"]'

out="$(wp_cli bazaar stock verify)"
expect 'cli verify (ledger): exits 0' "$out" 'exit=0'
out="$(wp_cli bazaar stock verify --format=json)"
expect 'cli verify json: ok' "$out" '"ok":true'
out="$(wp_cli bazaar stock mode sideways)"
expect 'cli mode: an unknown mode exits 1' "$out" 'exit=1'
expect 'cli mode: with invalid_request' "$out" 'invalid_request'
out="$(wp_cli bazaar stock mode ledger)"
expect 'cli mode: the current mode changes nothing' "$out" 'Mode already ledger'
out="$(wp_cli bazaar stock reconcile --pid="$B")"
expect 'cli reconcile: an in-step product records nothing' "$out" "Product $B: no difference recorded."
expect 'cli reconcile: exits 0' "$out" 'exit=0'
out="$(wp_cli help bazaar stock)"
for sub in backfill verify reconcile mode; do
  expect "cli help: stock $sub" "$out" "$sub"
done
out="$(wp_cli help bazaar checkouts)"
expect 'cli help: checkouts sweep' "$out" 'sweep'

bz_finish
