#!/usr/bin/env bash
# Live check of the barcode lookup and the shared Barcode_Memory against the
# local Docker WordPress.
#
# Asserts: the table exists, a beauty code is found past Open Food Facts, a
# food code's second lookup comes from memory with no HTTP, a restricted code
# is never stored, the owner and provider write rules, and that an undo of a
# Quick Add create restores the memory row. Needs outbound HTTPS. Leaves no
# rows, transients or products behind.
#
# Run from the repo root: bash wp-content/plugins/xophz-compass-bazaar/tests/integration/barcode-memory.sh
set -u
HERE="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=lib.sh
source "$HERE/lib.sh"

step() { wp_file "$HERE/barcode-memory-fixtures.php" -e MEMORY_STEP="$1"; }
quick() { wp_file "$HERE/call-ajax.php" -e CALL_ACTION="$1" -e CALL_ARGS="$2"; }
token_of() { sed -n 's/.*"undo_token":"\([a-f0-9]*\)".*/\1/p' <<<"$1"; }
# draft_field <lookup output> <php path into the body>: the body sits between the first { and last }.
draft_field() { php -r '$s = $argv[1]; $a = strpos($s, "{"); $j = json_decode(substr($s, $a, strrpos($s, "}") - $a + 1), true); echo eval("return " . $argv[2] . ";");' "$1" "$2"; }

out="$(wp_eval 'global $wpdb; $t = Xophz_Compass_Bazaar_Barcode_Memory::table(); echo $wpdb->get_var($wpdb->prepare("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s", $t)), " ", get_network_option(get_main_network_id(), "bazaar_barcode_memory_db_version") === Xophz_Compass_Bazaar_Barcode_Memory::DB_VERSION ? "current" : "stale";')"
expect 'install: table is InnoDB at the current version' "$out" 'InnoDB current'

out="$(step beauty)"
echo "$out"
expect 'beauty: found' "$out" "status='found'"
expect 'beauty: stored as provider' "$out" "stored='provider'"

out="$(step food)"
echo "$out"
expect 'food: first lookup found' "$out" "first_status='found'"
expect 'food: first lookup from Open Food Facts' "$out" "first_source='openfoodfacts'"
expect 'food: first lookup makes one call' "$out" 'first_calls=1'
expect 'food: stored as provider' "$out" "stored_source='provider'"
expect 'food: second lookup from memory' "$out" "second_source='memory'"
expect 'food: second lookup makes no HTTP' "$out" 'second_calls=0'
expect 'food: same name' "$out" 'second_title_same=true'
expect 'food: a read never writes' "$out" 'read_did_not_write=true'
expect 'food: owner marker not returned' "$out" 'no_owner_in_answer=true'

out="$(step restricted)"
echo "$out"
expect 'restricted: provider answer still used' "$out" "status='found'"
expect 'restricted: provider hit not stored' "$out" "row='absent'"
expect 'restricted: store write refused' "$out" "store_write='NULL'"
expect 'restricted: not stored after a store write' "$out" "row_after_store='absent'"

out="$(step owners)"
echo "$out"
expect 'owners: money left out of a provider title' "$out" "provider_title='Provider Name'"
expect 'owners: provider write has no owner' "$out" "provider_owner=''"
expect 'owners: store replaces a provider title' "$out" "store_over_provider='Store Name'"
expect 'owners: store write sets source' "$out" "store_source='store'"
expect 'owners: marker is 16 characters' "$out" 'owner_length=16'
expect 'owners: provider keeps a store title' "$out" "provider_keeps_store_title='Store Name'"
expect 'owners: provider fills an empty brand' "$out" "provider_fills_brand='Acme'"
expect 'owners: provider fills an empty picture' "$out" "provider_fills_image='https://images.openfoodfacts.org/a.jpg'"
expect 'owners: source stays store' "$out" "source_stays_store='store'"
expect 'owners: same site may rename' "$out" "same_owner_renames='Same Owner Rename'"
expect 'owners: another site may not rename' "$out" "other_owner_kept='Other Site Name'"
expect 'owners: the marker stays too' "$out" "other_owner_marker_kept='aaaaaaaaaaaaaaaa'"
expect 'owners: restore leaves another site alone' "$out" "restore_leaves_other_owner='Other Site Name'"
expect 'off: nothing read' "$out" "off_find='NULL'"
expect 'off: nothing written' "$out" 'off_no_write=true'
expect 'filter: can hide a row' "$out" "filter_hides='NULL'"
expect 'forget: removes the row' "$out" 'forget=true'
expect 'forget: gone' "$out" "forgotten='absent'"
expect 'cache: a write drops a cached miss' "$out" "write_drops_cached_miss='Back Again'"

# Create then undo over the real ajax actions, with and without an earlier row.
save='{"client_uuid":"memory-sh-UUID","mode":"create","barcode":"4006381333931","title":"Store Pencil $2.50","regular_price":"3.00","quantity":1}'

step clear >/dev/null
out="$(quick bazaar_quick_save "${save/UUID/$RANDOM$RANDOM}")"
expect 'create: saved' "$out" '"action":"created"'
row="$(step row)"
expect 'create: new row is the store title, without its price' "$row" 'row=["Store Pencil","","store","owner",1]'
out="$(quick bazaar_quick_undo "{\"undo_token\":\"$(token_of "$out")\"}")"
expect 'create: undone' "$out" '"action":"undone"'
expect 'undo: a new row is deleted' "$(step row)" "row='absent'"

step clear >/dev/null
step seed-provider >/dev/null
before="$(step row)"
expect 'seed: provider row' "$before" 'row=["Provider Pencil","Acme","provider","no-owner",1]'
out="$(quick bazaar_quick_save "${save/UUID/$RANDOM$RANDOM}")"
expect 'create over a provider row: saved' "$out" '"action":"created"'
expect 'create over a provider row: store title' "$(step row)" 'row=["Store Pencil","Acme","store","owner",2]'
out="$(quick bazaar_quick_undo "{\"undo_token\":\"$(token_of "$out")\"}")"
expect 'create over a provider row: undone' "$out" '"action":"undone"'
expect_equal 'undo: the snapshot is back' "$(step row)" "$before"

step clear >/dev/null

# Pictures: a UPCitemdb item shows through the proxy and attaches on save.
step picture-clear >/dev/null
code=885909950805
out="$(quick bazaar_quick_lookup "{\"barcode\":\"$code\"}")"
expect 'picture: UPCitemdb found it' "$out" '"source":"upcitemdb"'
expect 'picture: an outlier high never leaves the server' "$out" '"high":null'
url="$(draft_field "$out" '$j["draft"]["image_urls"][1] ?? ""')"
first="$(draft_field "$out" '$j["draft"]["image_url"] ?? ""')"
out="$(quick bazaar_quick_image "{\"url\":\"$url\"}")"
expect 'proxy: answers with a picture' "$out" '"data_base64":"'
expect 'proxy: says its type' "$out" '"mime":"image\/'
out="$(quick bazaar_quick_image '{"url":"file:///etc/passwd"}')"
expect 'proxy: refuses file:' "$out" '"code":"no_picture"'

picture_save='{"client_uuid":"memory-sh-UUID","mode":"create","barcode":"885909950805","title":"Phone","regular_price":"30.00","quantity":1,"image_url":"IMAGE"}'
save_body="${picture_save/IMAGE/https://evil.example/not-from-the-lookup.jpg}"
out="$(quick bazaar_quick_save "${save_body/UUID/$RANDOM$RANDOM}")"
expect 'save: a URL the lookup never offered still saves' "$out" '"action":"created"'
expect 'save: but gets no picture' "$(step picture-state)" 'has_picture=false'
step picture-clear >/dev/null
quick bazaar_quick_lookup "{\"barcode\":\"$code\"}" >/dev/null
save_body="${picture_save/IMAGE/$first}"
out="$(quick bazaar_quick_save "${save_body/UUID/$RANDOM$RANDOM}")"
expect 'save: from the lookup' "$out" '"action":"created"'
state="$(step picture-state)"
expect 'save: the picture is attached' "$state" 'has_picture=true'
expect 'memory: keeps the found picture' "$state" 'memory_has_image=true'
expect 'memory: never a store upload' "$state" 'memory_image_uploads=false'
step picture-clear >/dev/null

out="$(step url-rules)"
expect 'urls: loopback, private, file, metadata and logins all refused' "$(grep -c '=true' <<<"$out")" '7'

bz_finish
