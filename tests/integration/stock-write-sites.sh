#!/usr/bin/env bash
# Live check of every Bazaar stock write site (WP15, spec 8.9) against the
# local Docker WordPress, once in shadow mode and once in ledger mode.
#
# Write sites: update_product_stock, save_product (manage_stock and quantity),
# the product CSV import, receive_po, multi-inventory save / bulk / CSV import,
# delete location and the dead reduceLocationOrderStock.
#
# Asserts:
# - ledger mode: `_stock` and the ledger agree after each write, the batch
#   reasons are right (adjust, count, product_form, import, receive), a replayed
#   key changes stock once, a stale expected is stock_changed and an oversell is
#   insufficient_stock;
# - shadow mode: `_stock` is right and WooCommerce changed it inside the right
#   Stock_Context reason (it writes no ledger rows here: the shadow observer is WP13);
# - bool_param: the string 'false' turns manage_stock off;
# - a user with a PRO flag and no Bazaar caps is refused at every site, also
#   through the guard (admin-ajax); a catalog user without bazaar_manage_stock
#   cannot change stock in save_product or the product import;
# - receive_po: needs bazaar_receive and a bazaar_po post, receives once,
#   the second call is already_received, and two parallel processes add stock once;
# - delete location is refused while stock remains; reduceLocationOrderStock
#   does nothing unless the mode is off.
#
# Side effects: private throwaway products, warehouses, purchase orders and two
# users; at the end their ledger and outbox rows are deleted, the posts are
# trashed, the users deleted and the mode options put back.
#
# Run from the repo root: bash wp-content/plugins/xophz-compass-bazaar/tests/integration/stock-write-sites.sh
set -u
HERE="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=lib.sh
source "$HERE/lib.sh"

SCRATCH="${BAZAAR_SCRATCH:-$(mktemp -d)}"
mkdir -p "$SCRATCH"
RUN="sw$(date +%s)$$"

fix() {
  local action="$1"
  shift
  wp_file "$HERE/stock-write-sites-fixtures.php" -e FIX_ACTION="$action" -e RUN="$RUN" "$@"
}

# jget <json> <key> : one top-level value of a JSON object.
jget() {
  php -r '$d = json_decode($argv[1], true); $v = is_array($d) ? ($d[$argv[2]] ?? null) : null; echo is_array($v) ? json_encode($v) : (string) $v;' "$1" "$2"
}

# receive_bg <outfile> : receive_po on PO2 in its own process, in ledger mode (safe in parallel).
receive_bg() {
  docker exec -e FIX_ACTION=receive_one -e RUN="$RUN" "${IDS[@]}" "$CONTAINER" \
    wp --allow-root --path="$WP_PATH" eval-file /tmp/bazaar-stock-write-sites-fixtures.php 2>&1 | bz_filter | tr '\n' ' ' >"$1"
}

setup="$(fix setup | tail -1)"
PO2="$(jget "$setup" PO2)"
PRO="$(jget "$setup" PRO)"
if [ -z "$PO2" ] || [ -z "$PRO" ]; then
  echo "setup failed: $setup"
  exit 1
fi
IDS=(-e IDS="$setup")
cleanup() {
  fix cleanup "${IDS[@]}" >/dev/null
  rm -rf "$SCRATCH"
}
trap cleanup EXIT

# Shadow mode, then ledger mode: the same write sites
for MODE in shadow ledger; do
  out="$(fix seq "${IDS[@]}" -e MODE="$MODE")"
  m="$MODE"
  expect "$m: add: answers success" "$out" "$m.add.success=true"
  expect "$m: add: _stock +3" "$out" "$m.add.wc=13"
  expect "$m: add: reason adjust" "$out" "$m.add.reason=adjust"
  expect "$m: subtract: _stock -4" "$out" "$m.subtract.wc=9"
  expect "$m: subtract: reason adjust" "$out" "$m.subtract.reason=adjust"
  expect "$m: an unknown operation is refused" "$out" "$m.bogus.success=false"
  expect "$m: a refused operation changes nothing" "$out" "$m.bogus.wc=9"
  expect "$m: set (the default operation) sets 20" "$out" "$m.set.wc=20"
  expect "$m: set: reason count" "$out" "$m.set.reason=count"
  expect "$m: a stale expected is stock_changed" "$out" "$m.stale.code=stock_changed"
  expect "$m: stock_changed carries the current stock" "$out" "$m.stale.current=20"
  expect "$m: a stale count changes nothing" "$out" "$m.stale.wc=20"
  expect "$m: count with the right expected sets 15" "$out" "$m.count.wc=15"
  expect "$m: PRO flag without Bazaar caps: update_product_stock is forbidden" "$out" "$m.pro_update.code=forbidden"
  expect "$m: PRO flag without Bazaar caps: stock unchanged" "$out" "$m.pro_update.unchanged=true"

  expect "$m: save_product: the string 'false' turns manage_stock off" "$out" "$m.save.string_false.managing=false"
  expect "$m: save_product: manage_stock and quantity saved" "$out" "$m.save.stock.success=true"
  expect "$m: save_product: managing again" "$out" "$m.save.stock.managing=true"
  expect "$m: save_product: quantity 8" "$out" "$m.save.stock.wc=8"
  expect "$m: save_product: reason product_form" "$out" "$m.save.stock.reason=product_form"
  expect "$m: save_product: a catalog user can save without changing stock" "$out" "$m.save.cat_same.success=true"
  expect "$m: save_product: a catalog user cannot change stock" "$out" "$m.save.cat_change.refused=true"
  expect "$m: save_product: a refused stock change changes nothing" "$out" "$m.save.cat_change.wc=8"
  expect "$m: save_product: PRO flag without Bazaar caps is refused" "$out" "$m.save.pro.success=false"
  expect "$m: save_product: PRO attempt changes nothing" "$out" "$m.save.pro.wc=8"

  expect "$m: product import creates the product" "$out" "$m.import.created=1"
  expect "$m: product import: stock 12" "$out" "$m.import.wc=12"
  expect "$m: product import: reason import" "$out" "$m.import.reason=import"
  expect "$m: product import: a second import updates it" "$out" "$m.import.again.updated=1"
  expect "$m: product import: a catalog user's stock column is ignored and said so" "$out" "$m.import.cat.noted=true"

  expect "$m: receive_po: PRO flag without Bazaar caps is forbidden" "$out" "$m.po.pro.code=forbidden"
  expect "$m: receive_po: refused call adds nothing" "$out" "$m.po_pro.wc=3"
  expect "$m: receive_po: a post that is not a PO is po_not_found" "$out" "$m.po.not_a_po.code=po_not_found"
  expect "$m: receive_po: first call succeeds" "$out" "$m.po.first.success=true"
  expect "$m: receive_po: stock +5" "$out" "$m.po.wc=8"
  expect "$m: receive_po: reason receive" "$out" "$m.po.reason=receive"
  expect "$m: receive_po: status received" "$out" "$m.po.status=received"
  expect "$m: receive_po: second call is already_received" "$out" "$m.po.second.code=already_received"
  expect "$m: receive_po: second call adds nothing" "$out" "$m.po_again.wc=8"

  expect "$m: multi save: PRO flag without Bazaar caps is forbidden" "$out" "$m.inv.pro.code=forbidden"
  expect "$m: multi save: refused call changes nothing" "$out" "$m.inv.pro.unchanged=true"
  expect "$m: multi save: succeeds" "$out" "$m.inv.save.success=true"
  expect "$m: multi save: _stock is the enabled total" "$out" "$m.inv_save.wc=7"
  expect "$m: multi save: response total" "$out" "$m.inv.save.total=7"
  expect "$m: multi save: location quantities in the meta" "$out" "$m.inv.save.meta=true"
  expect "$m: multi save: sku, threshold and price kept in the meta" "$out" "$m.inv.save.kept=KEEP/7/4.5"
  expect "$m: multi bulk: one update" "$out" "$m.inv.bulk.updated=1"
  expect "$m: multi bulk: _stock 5" "$out" "$m.inv_bulk.wc=5"
  expect "$m: multi csv: PRO flag without Bazaar caps is forbidden" "$out" "$m.inv.csv.pro.code=forbidden"
  expect "$m: multi csv: one row" "$out" "$m.inv.csv.rows=1"
  expect "$m: multi csv: _stock 9" "$out" "$m.inv_csv.wc=9"
  expect "$m: multi csv: sku and threshold from the file" "$out" "$m.inv.csv.kept=CSVSKU/4"

  expect "$m: delete location: PRO flag without Bazaar caps is forbidden" "$out" "$m.del.pro.code=forbidden"
  expect "$m: delete location: refused while stock remains" "$out" "$m.del.stock.code=location_has_stock"
  expect "$m: delete location: the location is still there" "$out" "$m.del.stock.still_there=true"
  expect "$m: delete location: an empty location is deleted" "$out" "$m.del.empty.success=true"
  expect "$m: reduceLocationOrderStock does nothing outside off mode" "$out" "$m.reduce.unchanged=true"

  if [ "$MODE" = shadow ]; then
    expect 'off: reduceLocationOrderStock still reduces the location meta (9 - 2)' "$out" 'off.reduce.meta=7'
  else
    for step in add subtract set count replay po po_again save.stock import inv_save inv_bulk inv_csv; do
      expect "ledger: $step: the ledger and _stock agree" "$out" "ledger.$step.agree=true"
    done
    expect 'ledger: a replayed key adds once (15 + 1)' "$out" 'ledger.replay.wc=16'
    expect 'ledger: an oversell is insufficient_stock' "$out" 'ledger.oversell.code=insufficient_stock'
    expect 'ledger: an oversell changes nothing' "$out" 'ledger.oversell.wc=16'
    expect 'ledger: the same product import replays (the ledger keeps 15)' "$out" 'ledger.import_again.wc=15'
    expect 'ledger: a catalog user import leaves stock at 15' "$out" 'ledger.import_cat.wc=15'
    expect 'ledger: receive_po records the unit cost from the PO' "$out" 'ledger.po.unit_cost_minor=250'
    expect 'ledger: multi save: the disabled location stays disabled' "$out" 'ledger.inv.save.w2_enabled=false'
    expect 'ledger: multi save: reason count' "$out" 'ledger.inv_save.reason=count'
    expect 'ledger: multi csv: reason count' "$out" 'ledger.inv_csv.reason=count'
  fi
done

# Three parallel receive_po processes (ledger mode): one receives, the others are already_received
docker cp "$HERE/stock-write-sites-fixtures.php" "$CONTAINER:/tmp/bazaar-stock-write-sites-fixtures.php" >/dev/null
for i in 1 2 3; do
  receive_bg "$SCRATCH/par-$i" &
done
wait
expect_equal 'parallel receive_po: exactly one success' "$(cat "$SCRATCH"/par-* | grep -o 'recv.success=true' | wc -l | tr -d ' ')" '1'
expect_equal 'parallel receive_po: the others are already_received' "$(cat "$SCRATCH"/par-* | grep -o 'recv.code=already_received' | wc -l | tr -d ' ')" '2'
out="$(fix after_parallel "${IDS[@]}")"
expect 'parallel receive_po: stock added once (10 + 4)' "$out" 'par.wc=14'
expect 'parallel receive_po: the balance agrees' "$out" 'par.bal0=14'
expect 'parallel receive_po: ledger and _stock agree' "$out" 'par.agree=true'
expect 'parallel receive_po: one batch for the PO' "$out" 'par.batches=1'
expect 'parallel receive_po: status received' "$out" 'par.status=received'

# The guard refuses a PRO-flag user at every write site before the handler runs
docker cp "$HERE/call-ajax.php" "$CONTAINER:/tmp/bazaar-call-ajax.php" >/dev/null
sites="bazaar_receive_po update_product_stock bazaar_save_product_inventories bazaar_bulk_update_inventory_stock bazaar_delete_inventory_location bazaar_import_inventory_csv save_product bazaar_import_products_csv"
out="$(docker exec -e CALL_ACTION="${sites// /,}" -e CALL_ARGS='{}' -e CALL_USER="id:$PRO" "$CONTAINER" \
  wp --allow-root --path="$WP_PATH" eval-file /tmp/bazaar-call-ajax.php 2>&1 | bz_filter)"
for a in $sites; do
  expect "guard: PRO flag without Bazaar caps is refused at $a" "$(awk -F'\t' -v a="$a" '$1 == a { print $2 }' <<<"$out")" '"code":"forbidden"'
done

bz_finish
