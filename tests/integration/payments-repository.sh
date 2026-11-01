#!/usr/bin/env bash
# Live check of Payments_Repository, Refunds_Repository and
# Payments_Shift_Summary against the local Docker WordPress and MySQL.
#
# Asserts: capture and refund-row inserts, typed duplicate errors, the
# allocate_refund conditional UPDATE (bounds, release, non-captured rows,
# rollback inside Core_Db::tx), 8 parallel allocations of the last 500 minor
# units (exactly one wins), refund claim replay and conflict, and the shift
# summary totals.
#
# Leaves no rows behind: it only writes payments with order_id 990000061 and
# refunds created_by 990000062/990000063, and deletes them at start and end.
#
# Run from the repo root: bash wp-content/plugins/xophz-compass-bazaar/tests/integration/payments-repository.sh
set -u
HERE="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=lib.sh
source "$HERE/lib.sh"

FIXTURE="$HERE/payments-repository-fixtures.php"
step() { # step <MODE> [VAR=value ...]
  local mode="$1"
  shift
  local args=(-e "MODE=$mode")
  local kv
  for kv in "$@"; do args+=(-e "$kv"); done
  wp_file "$FIXTURE" "${args[@]}"
}

step cleanup >/dev/null
trap 'step cleanup >/dev/null' EXIT

# Basics: inserts, duplicates, bounds, release
out="$(step basics)"
expect 'insert: a capture row stores direction capture, amount and refunded 0' "$out" 'insert=ok'
expect 'insert: find_by_uuid returns the row' "$out" 'by_uuid=ok'
expect 'insert: a repeated payment_uuid raises the typed duplicate error' "$out" 'dup_uuid=typed'
expect 'insert: missing fields are refused' "$out" 'invalid=refused'
expect 'insert: a refund row without a parent is refused' "$out" 'refund_no_parent=refused'
expect 'allocate: amount 0 is refused' "$out" 'zero=false'
expect 'allocate: more than the capture is refused' "$out" 'over=false'
expect 'allocate: part, then the remainder, then nothing more' "$out" 'part=true,rest=true,full=false'
expect 'release: more than was allocated is refused' "$out" 'release_too_much=false'
expect 'release: returns the amount and refunded_minor drops' "$out" 'release=true,after_release=200'
expect 'allocate: a capture no longer captured is refused' "$out" 'not_captured=false'

# Rollback of the surrounding transaction takes the allocation with it
out="$(step rollback)"
expect 'rollback: a thrown tx undoes the allocation, which can then be taken' "$out" 'after_rollback=0,then=true'

# Parallel allocation: 8 processes each ask for 500 of a 500 capture
pid="$(step new_capture AMOUNT=500 | tail -1)"
tmp="$(mktemp -d)"
for i in 1 2 3 4 5 6 7 8; do
  docker exec -e MODE=alloc -e "PAYMENT_ID=$pid" -e AMOUNT=500 "$CONTAINER" wp --allow-root --path="$WP_PATH" eval-file /tmp/bazaar-payments-repository-fixtures.php 2>&1 | bz_filter >"$tmp/$i.out" &
done
wait
won="$(cat "$tmp"/*.out | grep -c '^won$')"
lost="$(cat "$tmp"/*.out | grep -c '^lost$')"
rm -rf "$tmp"
expect_equal 'parallel: exactly one of 8 allocations of the last 500 wins' "$won/$lost" '1/7'
expect_equal 'parallel: refunded_minor equals the capture amount, not more' "$(step refunded PAYMENT_ID="$pid" | tail -1)" '500'

# Refund claim: replay and conflict
out="$(step claim KEY=test-pr-claim-1)"
expect 'claim: the first claim stores a claimed row' "$out" 'first=claimed'
expect 'claim: the same key and hash returns the same row, replayed' "$out" 'replay=same-row'
expect 'claim: the same key with a different hash is flagged as a conflict' "$out" 'conflict=flagged'
expect 'claim: another user can use the same key' "$out" 'other_user=own-row'
expect 'claim: amounts, then complete, move a claimed row once' "$out" 'amounts=true,complete=true,complete_twice=false,fail_after_complete=false,amounts_after=false'
expect 'claim: the completed row keeps its state, wc id, amounts and response' "$out" 'state=completed,wc=990000064,amount=300,tax=20,response=990000064'
expect 'claim: for_order lists the refunds of the order' "$out" 'for_order=1'
expect 'claim: fail marks a claimed row failed' "$out" 'fail=true,failed'
expect 'claim: a missing user is refused' "$out" 'invalid=refused'

# Shift summary
out="$(step shift)"
expect 'shift: captured, pending, refunded, net and count per session' "$out" 'totals=3900/300/600/3300/'
expect 'shift: cash totals include change given' "$out" 'cash=1400/100/2'
expect 'shift: card totals subtract the refund row' "$out" 'card=2500/600/1900'
expect 'shift: void_required rows are listed, not summed' "$out" 'void=1:VOID-1:900'
expect 'shift: a time window with a cashier finds the same rows' "$out" 'window_has_session_rows=yes'
expect 'shift: an unknown session is empty' "$out" 'empty=0/0'
expect 'shift: no scope is refused' "$out" 'noscope=refused'

out="$(step cleanup)"
expect 'cleanup: fixture rows removed' "$out" 'cleaned'

bz_finish
