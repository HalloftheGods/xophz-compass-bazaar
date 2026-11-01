#!/usr/bin/env bash
# Live check of the Bazaar schema and the Core_Db / Events_Outbox helpers
# against the local Docker WordPress.
#
# Asserts: the ten tables exist and are InnoDB, the installer is idempotent and
# stores its version and engine option, Core_Db transactions (rollback, nesting,
# after_commit, after_rollback, typed duplicate-key errors) and the outbox.
# Roles are checked only when Security_Capabilities exists (a later package).
#
# Leaves no rows behind: its own outbox rows (type test.schema_sh) are deleted
# at the end, and it creates no other data.
#
# Run from the repo root: bash wp-content/plugins/xophz-compass-bazaar/tests/integration/schema.sh
set -u
HERE="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=lib.sh
source "$HERE/lib.sh"

P='Xophz_Compass_Bazaar'

# Installer
out="$(wp_eval "echo ${P}_Schema_Installer::install() ? 'installed' : 'incomplete';")"
expect 'installer: install() reports every table present' "$out" 'installed'
out="$(wp_eval "echo ${P}_Schema_Installer::install() ? 'installed' : 'incomplete';")"
expect 'installer: running it twice is fine' "$out" 'installed'

out="$(wp_eval 'global $wpdb; foreach (Xophz_Compass_Bazaar_Schema_Tables::NAMES as $n) { $t = $wpdb->prefix . "bazaar_" . $n; $e = $wpdb->get_var($wpdb->prepare("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s", $t)); echo $n . "=" . ($e ?: "MISSING") . "\n"; }')"
for name in stock_batches stock_movements stock_balances checkouts payments refunds staff staff_sessions approvals events; do
  expect "table: $name exists and is InnoDB" "$out" "$name=InnoDB"
done

out="$(wp_eval "echo get_option('xophz_compass_bazaar_db_version') === ${P}_Schema_Installer::DB_VERSION ? 'current' : 'stale';")"
expect 'installer: version option equals DB_VERSION' "$out" 'current'
out="$(wp_eval "\$e = get_option('xophz_compass_bazaar_db_engines'); echo isset(\$e['postmeta'], \$e['bazaar_events']) ? 'recorded' : 'missing';")"
expect 'installer: engines option records postmeta and the Bazaar tables' "$out" 'recorded'
out="$(wp_eval "echo get_option('xophz_compass_bazaar_db_lock', 'none');")"
expect 'installer: the install mutex is released' "$out" 'none'

out="$(wp_eval "echo class_exists('${P}_Security_Capabilities') ? 'present' : 'absent';")"
if [ "$out" = "present" ]; then
  out="$(wp_eval 'foreach (["bazaar_cashier","bazaar_supervisor","bazaar_stock_clerk"] as $r) { echo $r . "=" . (get_role($r) ? "yes" : "no") . "\n"; }')"
  expect 'roles: cashier role exists' "$out" 'bazaar_cashier=yes'
  expect 'roles: supervisor role exists' "$out" 'bazaar_supervisor=yes'
  expect 'roles: stock clerk role exists' "$out" 'bazaar_stock_clerk=yes'
else
  echo "SKIP roles: ${P}_Security_Capabilities is not part of this build"
fi

# Autoloader inside WordPress
out="$(wp_eval "echo class_exists('${P}_Core_Money', false) ? 'loaded' : 'not-loaded'; echo ' '; echo ${P}_Core_Money::from_minor(1050, 2);")"
expect 'autoloader: Core_Money resolves inside WordPress' "$out" 'loaded 10.50'

# Core_Db
cleanup='global $wpdb; $wpdb->query("DELETE FROM " . Xophz_Compass_Bazaar_Core_Db::table("events") . " WHERE type = '"'"'test.schema_sh'"'"'");'
wp_eval "$cleanup" >/dev/null

T="${P}_Core_Db"
out="$(wp_eval "
\$ev = ${P}_Core_Db::table('events');
\$count = function () use (\$ev) { return (int) ${P}_Core_Db::get_var(\"SELECT COUNT(*) FROM \$ev WHERE type = %s\", ['test.schema_sh']); };
\$row = function () { return [
  'event_uuid' => wp_generate_uuid4(), 'type' => 'test.schema_sh', 'aggregate_type' => 't', 'aggregate_id' => 1,
  'actor_user_id' => 0, 'payload' => '{}', 'created_at' => ${P}_Core_Db::now(),
]; };
\$log = [];

echo 'in_tx_outside=' . var_export(${T}::in_tx(), true) . \"\n\";

\$n = ${T}::tx(function () use (\$ev, \$row) { \$id = ${T}::insert(\$ev, \$row()); return \$id > 0 ? 'ok' : 'noid'; });
echo 'commit=' . \$n . ',count=' . \$count() . \"\n\";

try { ${T}::tx(function () use (\$ev, \$row, &\$log) { ${T}::insert(\$ev, \$row()); ${T}::after_rollback(function () use (&\$log) { \$log[] = 'rolled-back'; }); ${T}::after_commit(function () use (&\$log) { \$log[] = 'WRONG-commit'; }); throw new RuntimeException('boom'); }); } catch (RuntimeException \$e) { \$log[] = 'caught'; }
echo 'rollback_count=' . \$count() . ',log=' . implode('|', \$log) . \"\n\";

\$log = [];
${T}::tx(function () use (\$ev, \$row, &\$log) {
  ${T}::after_commit(function () use (&\$log) { \$log[] = 'after-commit'; });
  ${T}::tx(function () use (\$ev, \$row, &\$log) { \$log[] = 'inner-in_tx=' . var_export(${T}::in_tx(), true); ${T}::insert(\$ev, \$row()); });
  \$log[] = 'outer-still-open=' . var_export(${T}::in_tx(), true);
});
echo 'nested=' . implode('|', \$log) . ',count=' . \$count() . \"\n\";

\$log = [];
${T}::after_commit(function () use (&\$log) { \$log[] = 'immediate'; });
echo 'after_commit_outside=' . implode('|', \$log) . \"\n\";

try { ${T}::exec(\"INSERT INTO \$ev (event_uuid, type, aggregate_type, aggregate_id, actor_user_id, payload, created_at) SELECT event_uuid, type, aggregate_type, aggregate_id, actor_user_id, payload, created_at FROM \$ev WHERE type = %s LIMIT 1\", ['test.schema_sh']); echo \"duplicate=none\n\"; } catch (${P}_Core_Duplicate_Key_Exception \$e) { echo \"duplicate=typed\n\"; }

\$calls = 0;
try { ${T}::tx(function () use (&\$calls) { \$calls++; throw new ${P}_Core_Deadlock_Exception('simulated', 1213); }, 2); } catch (${P}_Core_Deadlock_Exception \$e) { echo 'deadlock_attempts=' . \$calls . \"\n\"; }
\$calls = 0;
\$r = ${T}::tx(function () use (&\$calls) { \$calls++; if (\$calls < 2) { throw new ${P}_Core_Deadlock_Exception('simulated', 1213); } return 'recovered'; }, 2);
echo 'deadlock_retry=' . \$r . ',attempts=' . \$calls . \"\n\";
echo 'in_tx_after=' . var_export(${T}::in_tx(), true) . \"\n\";
echo 'now=' . (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\$/', ${T}::now()) ? 'ok' : 'bad') . \"\n\";
")"
expect 'db: in_tx is false outside a transaction' "$out" 'in_tx_outside=false'
expect 'db: a committed tx keeps its row and returns the result' "$out" 'commit=ok,count=1'
expect 'db: a thrown tx rolls back, runs after_rollback, drops after_commit' "$out" 'rollback_count=1,log=rolled-back|caught'
expect 'db: nested tx joins; after_commit runs after the outer commit' "$out" 'nested=inner-in_tx=true|outer-still-open=true|after-commit,count=2'
expect 'db: after_commit outside a tx runs at once' "$out" 'after_commit_outside=immediate'
expect 'db: a unique-key violation raises the typed exception' "$out" 'duplicate=typed'
expect 'db: a deadlock is retried twice, then rethrown' "$out" 'deadlock_attempts=3'
expect 'db: a deadlock that clears is retried to success' "$out" 'deadlock_retry=recovered,attempts=2'
expect 'db: depth is reset after failures' "$out" 'in_tx_after=false'
expect 'db: now() is a UTC datetime string' "$out" 'now=ok'

# Events_Outbox
out="$(wp_eval "
\$fired = [];
add_action('xophz_compass_bazaar_event', function (\$type, \$agg, \$id, \$payload, \$uuid, \$actor) use (&\$fired) { if (\$type === 'test.schema_sh') { \$fired[] = \$type . ':' . \$id . ':' . json_encode(\$payload) . ':' . \$actor; } }, 10, 6);
\$ev = ${P}_Core_Db::table('events');
\$before = (int) ${P}_Core_Db::get_var(\"SELECT COUNT(*) FROM \$ev WHERE type = %s\", ['test.schema_sh']);

${T}::tx(function () use (&\$fired) {
  ${P}_Events_Outbox::record('test.schema_sh', 'thing', 7, ['b' => 2, 'a' => 1], 3);
  echo 'fired_inside_tx=' . count(\$fired) . \"\n\";
});
echo 'fired_after_commit=' . implode('|', \$fired) . \"\n\";

\$fired = [];
\$before = (int) ${P}_Core_Db::get_var(\"SELECT COUNT(*) FROM \$ev WHERE type = %s\", ['test.schema_sh']);
try { ${T}::tx(function () { ${P}_Events_Outbox::record('test.schema_sh', 'thing', 8, [], 0); throw new RuntimeException('boom'); }); } catch (RuntimeException \$e) {}
\$after = (int) ${P}_Core_Db::get_var(\"SELECT COUNT(*) FROM \$ev WHERE type = %s\", ['test.schema_sh']);
echo 'rolled_back_rows=' . (\$after - \$before) . ',fired_on_rollback=' . count(\$fired) . \"\n\";

\$row = ${P}_Core_Db::get_row(\"SELECT * FROM \$ev WHERE type = %s AND aggregate_id = %d\", ['test.schema_sh', 7]);
echo 'payload=' . \$row->payload . ',actor=' . \$row->actor_user_id . ',dispatched=' . var_export(\$row->dispatched_at, true) . ',uuid=' . (strlen(\$row->event_uuid) === 36 ? 'ok' : 'bad') . \"\n\";
")"
expect 'outbox: the action does not fire inside the transaction' "$out" 'fired_inside_tx=0'
expect 'outbox: the action fires after commit with the event data' "$out" 'fired_after_commit=test.schema_sh:7:{"b":2,"a":1}:3'
expect 'outbox: a rolled-back event leaves no row and fires nothing' "$out" 'rolled_back_rows=0,fired_on_rollback=0'
expect 'outbox: the row stores canonical payload, actor, a uuid, and is undispatched' "$out" 'payload={"a":1,"b":2},actor=3,dispatched=NULL,uuid=ok'

wp_eval "$cleanup" >/dev/null
out="$(wp_eval 'echo (int) Xophz_Compass_Bazaar_Core_Db::get_var("SELECT COUNT(*) FROM " . Xophz_Compass_Bazaar_Core_Db::table("events") . " WHERE type = %s", ["test.schema_sh"]);')"
expect_equal 'cleanup: no test events remain' "$out" '0'

bz_finish
