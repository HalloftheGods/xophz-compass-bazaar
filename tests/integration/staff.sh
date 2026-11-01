#!/usr/bin/env bash
# Live check of staff PINs, staff sessions, per-role limits and one-time
# manager approvals against the local Docker WordPress (#6947).
#
# Asserts: PIN format rules, setting PINs (manager only), the staff list
# (no email), session start/resolve/replace/end/expiry and device binding,
# the fifth wrong PIN locking for 15 minutes (a right PIN is refused while
# locked), approvals (requester and approver rules, PIN check, one use,
# subject/requester/amount binding, expiry), the cashier default limits, and
# that no staff route answers 401 for a logged-in caller.
#
# Creates five throwaway users (login prefix bzrstaff_) and their staff,
# session, approval and event rows. A trap deletes them, also on failure.
# Wrong-PIN counts use unique X-Bazaar-Device values, and their throttle
# transients are deleted at the end.
#
# Run from the repo root: bash wp-content/plugins/xophz-compass-bazaar/tests/integration/staff.sh
set -u
HERE="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=lib.sh
source "$HERE/lib.sh"

P='Xophz_Compass_Bazaar'
RUN="$(date +%s)"

# PHP helper prepended to every call: run one REST request as a user, with headers.
PRE='function bzcall($m,$r,$b,$u,$h=[]){ wp_set_current_user($u); $q=new WP_REST_Request($m,$r); foreach($h as $k=>$v){$q->set_header($k,$v);} if($b!==""){$q->set_header("Content-Type","application/json");$q->set_body($b);} $res=rest_do_request($q); return ["status"=>$res->get_status(),"data"=>rest_get_server()->response_to_data($res,false)]; }'

# call <METHOD> <route> <body-json or ""> <user-id> [php-array-literal-of-headers]
call() {
  local m="$1" r="$2" b="$3" u="$4" h="${5:-[]}"
  wp_eval "$PRE echo wp_json_encode(bzcall('$m','$r','$b',$u,$h));"
}

cleanup() {
  wp_eval "
global \$wpdb; require_once ABSPATH . 'wp-admin/includes/user.php';
\$ids = get_users(['search' => 'bzrstaff_*', 'search_columns' => ['user_login'], 'fields' => 'ID']);
foreach (\$ids as \$id) {
  \$wpdb->delete(${P}_Core_Db::table('staff'), ['user_id' => \$id]);
  \$wpdb->query(\$wpdb->prepare('DELETE FROM ' . ${P}_Core_Db::table('staff_sessions') . ' WHERE staff_user_id = %d OR operator_user_id = %d', \$id, \$id));
  \$wpdb->query(\$wpdb->prepare('DELETE FROM ' . ${P}_Core_Db::table('approvals') . ' WHERE requested_by = %d OR approved_by = %d', \$id, \$id));
  \$wpdb->query(\$wpdb->prepare('DELETE FROM ' . ${P}_Core_Db::table('events') . ' WHERE actor_user_id = %d', \$id));
  wp_delete_user(\$id);
}
\$wpdb->query('DELETE FROM ' . ${P}_Core_Db::table('events') . \" WHERE type IN ('staff.pin.set','staff.pin.locked','staff.session.started','approval.granted') AND aggregate_type IN ('staff','staff_session','approval') AND actor_user_id = 0\");
foreach (['dev-a-$RUN','dev-b-$RUN','dev-lock-$RUN','dev-x-$RUN'] as \$d) { delete_transient(${P}_Staff_Pins::DEVICE_KEY_PREFIX . md5(\$d)); }
" >/dev/null 2>&1
}
trap cleanup EXIT
cleanup

out="$(wp_eval "echo ${P}_Schema_Installer::install() ? 'installed' : 'incomplete';")"
expect 'schema: tables present' "$out" 'installed'

# Users: operator (cashier role, the device login), staff A and B (cashiers), manager (supervisor), plain (no sell).
mk() { wp_eval "
\$role = '$2';
\$id = wp_insert_user(['user_login' => 'bzrstaff_$1_$RUN', 'user_pass' => wp_generate_password(24), 'user_email' => 'bzrstaff_$1_$RUN@example.invalid', 'display_name' => 'Bzr $1', 'role' => \$role]);
echo is_wp_error(\$id) ? 'ERR' : \$id;"; }
OP="$(mk op bazaar_cashier)"; A="$(mk a bazaar_cashier)"; B="$(mk b bazaar_cashier)"; MGR="$(mk mgr bazaar_supervisor)"; PLAIN="$(mk plain subscriber)"
ADMIN="$(wp_eval "echo get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID'])[0];")"
for v in "$OP" "$A" "$B" "$MGR" "$PLAIN" "$ADMIN"; do
  case "$v" in ''|*[!0-9]*) echo "FAIL setup: could not create users (got '$v')"; exit 1;; esac
done

DA="['X-Bazaar-Device'=>'dev-a-$RUN']"
DB="['X-Bazaar-Device'=>'dev-b-$RUN']"

# PIN rules and who may set them
out="$(call PUT "/bazaar/v1/pos/staff/$A/pin" '{"pin":"1234"}' "$ADMIN")"
expect 'pin: a common PIN is refused' "$out" '"code":"pin_format"'
out="$(call PUT "/bazaar/v1/pos/staff/$A/pin" '{"pin":"12"}' "$ADMIN")"
expect 'pin: too short is refused with 422' "$out" '"status":422'
out="$(call PUT "/bazaar/v1/pos/staff/$A/pin" '{"pin":"2947"}' "$OP")"
expect 'pin: a cashier cannot set another PIN (403 forbidden)' "$out" '"code":"forbidden"'
out="$(call PUT "/bazaar/v1/pos/staff/$A/pin" '{"pin":"2947"}' 0)"
expect 'pin: logged out is the only 401' "$out" '"status":401'
for pair in "$A:2947" "$B:5821" "$MGR:7309" "$PLAIN:4063"; do
  out="$(call PUT "/bazaar/v1/pos/staff/${pair%%:*}/pin" "{\"pin\":\"${pair##*:}\"}" "$ADMIN")"
  expect "pin: manager sets PIN for ${pair%%:*}" "$out" '"updated":true'
done
out="$(wp_eval "global \$wpdb; echo (string) \$wpdb->get_var(\$wpdb->prepare('SELECT pin_hash FROM ' . ${P}_Core_Db::table('staff') . ' WHERE user_id = %d', $A));")"
expect_equal 'pin: the stored value is a hash, not the PIN' "$([ "$out" != "2947" ] && [ ${#out} -gt 20 ] && echo hashed)" 'hashed'

# Staff list
out="$(call GET /bazaar/v1/pos/staff '' "$OP")"
expect 'list: includes staff A' "$out" "\"id\":$A"
expect 'list: includes the display name' "$out" '"display_name":"Bzr a"'
expect 'list: the manager is listed' "$out" "\"id\":$MGR"
case "$out" in *'@'*|*'email'*|*'phone'*) expect 'list: no email or phone' 'leaked' 'clean';; *) expect 'list: no email or phone' 'clean' 'clean';; esac
case "$out" in *"\"id\":$PLAIN"*) expect 'list: a user who cannot sell is not listed' 'listed' 'absent';; *) expect 'list: a user who cannot sell is not listed' 'absent' 'absent';; esac

# Sessions
out="$(call POST /bazaar/v1/pos/staff/sessions "{\"staff_user_id\":$A,\"pin\":\"1111\"}" "$OP" "$DA")"
expect 'session: wrong PIN is 403 pin_invalid' "$out" '"code":"pin_invalid"'
out="$(call POST /bazaar/v1/pos/staff/sessions "{\"staff_user_id\":$PLAIN,\"pin\":\"4063\"}" "$OP" "$DA")"
expect 'session: a user who cannot sell cannot start one' "$out" '"code":"forbidden"'
out="$(call POST /bazaar/v1/pos/staff/sessions "{\"staff_user_id\":$A,\"pin\":\"2947\"}" "$OP" "$DA")"
expect 'session: right PIN starts one' "$out" '"status":200'
TOK1="$(grep -o '"token":"[0-9a-f]*"' <<<"$out" | head -1 | cut -d'"' -f4)"
expect_equal 'session: token is 64 hex characters' "${#TOK1}" '64'
case "$out" in *token_hash*) expect 'session: response never carries the token hash' 'leaked' 'clean';; *) expect 'session: response never carries the token hash' 'clean' 'clean';; esac
out="$(wp_eval "global \$wpdb; echo \$wpdb->get_var(\$wpdb->prepare('SELECT COUNT(*) FROM ' . ${P}_Core_Db::table('staff_sessions') . ' WHERE token_hash = %s', '$TOK1')), ',', \$wpdb->get_var(\$wpdb->prepare('SELECT COUNT(*) FROM ' . ${P}_Core_Db::table('staff_sessions') . ' WHERE token_hash = %s', hash('sha256', '$TOK1')));")"
expect_equal 'session: only the sha256 of the token is stored' "$out" '0,1'

out="$(call GET /bazaar/v1/pos/staff/sessions/current '' "$OP" "['X-Bazaar-Device'=>'dev-a-$RUN','X-Bazaar-Staff-Session'=>'$TOK1']")"
expect 'session: current resolves for the same operator and device' "$out" "\"staff_user_id\":$A"
out="$(call GET /bazaar/v1/pos/staff/sessions/current '' "$OP" "['X-Bazaar-Device'=>'dev-b-$RUN','X-Bazaar-Staff-Session'=>'$TOK1']")"
expect 'session: another device is refused' "$out" '"code":"staff_session_invalid"'
out="$(call GET /bazaar/v1/pos/staff/sessions/current '' "$ADMIN" "['X-Bazaar-Device'=>'dev-a-$RUN','X-Bazaar-Staff-Session'=>'$TOK1']")"
expect 'session: another operator is refused' "$out" '"code":"staff_session_invalid"'
out="$(call GET /bazaar/v1/pos/staff/sessions/current '' "$OP" "['X-Bazaar-Device'=>'dev-a-$RUN','X-Bazaar-Staff-Session'=>'nope']")"
expect 'session: a made-up token is refused' "$out" '"code":"staff_session_invalid"'
out="$(call GET /bazaar/v1/pos/staff/sessions/current '' "$OP" "$DA")"
expect 'session: current without a token is 404' "$out" '"code":"no_staff_session"'

out="$(call POST /bazaar/v1/pos/staff/sessions "{\"staff_user_id\":$MGR,\"pin\":\"7309\"}" "$OP" "$DA")"
TOK2="$(grep -o '"token":"[0-9a-f]*"' <<<"$out" | head -1 | cut -d'"' -f4)"
expect 'session: a second start on the same device works' "$out" '"status":200'
out="$(call GET /bazaar/v1/pos/staff/sessions/current '' "$OP" "['X-Bazaar-Device'=>'dev-a-$RUN','X-Bazaar-Staff-Session'=>'$TOK1']")"
expect 'session: starting a new one ended the old one' "$out" '"code":"staff_session_invalid"'

out="$(wp_eval "global \$wpdb; \$t = ${P}_Core_Db::table('staff_sessions'); \$h = hash('sha256', '$TOK2');
\$wpdb->query(\$wpdb->prepare(\"UPDATE \$t SET last_seen_at = '2000-01-01 00:00:00' WHERE token_hash = %s\", \$h));
\$s = ${P}_Staff_Sessions::resolve('$TOK2', $OP, 'dev-a-$RUN');
\$row = \$wpdb->get_row(\$wpdb->prepare(\"SELECT last_seen_at, idle_expires_at, expires_at FROM \$t WHERE token_hash = %s\", \$h));
echo \$s ? 'ok' : 'null', ',', strtotime(\$row->last_seen_at . ' UTC') > time() - 5 ? 'slid' : 'stuck', ',', strtotime(\$row->idle_expires_at . ' UTC') - time() <= 900 ? 'idle<=15m' : 'idle-too-long', ',', strtotime(\$row->expires_at . ' UTC') - time() <= 43200 ? 'abs<=12h' : 'abs-too-long';")"
expect_equal 'session: resolve slides last_seen and keeps the 15 minute and 12 hour limits' "$out" 'ok,slid,idle<=15m,abs<=12h'

out="$(wp_eval "global \$wpdb; \$t = ${P}_Core_Db::table('staff_sessions'); \$h = hash('sha256', '$TOK2');
\$wpdb->query(\$wpdb->prepare(\"UPDATE \$t SET idle_expires_at = '2000-01-01 00:00:00' WHERE token_hash = %s\", \$h));
echo ${P}_Staff_Sessions::resolve('$TOK2', $OP, 'dev-a-$RUN') === null ? 'expired' : 'still-live';")"
expect_equal 'session: idle expiry ends it' "$out" 'expired'

out="$(call POST /bazaar/v1/pos/staff/sessions "{\"staff_user_id\":$A,\"pin\":\"2947\"}" "$OP" "$DB")"
TOK3="$(grep -o '"token":"[0-9a-f]*"' <<<"$out" | head -1 | cut -d'"' -f4)"
out="$(call DELETE /bazaar/v1/pos/staff/sessions/current '' "$OP" "['X-Bazaar-Device'=>'dev-b-$RUN','X-Bazaar-Staff-Session'=>'$TOK3']")"
expect 'session: DELETE ends the current one' "$out" '"ended":true'
out="$(call GET /bazaar/v1/pos/staff/sessions/current '' "$OP" "['X-Bazaar-Device'=>'dev-b-$RUN','X-Bazaar-Staff-Session'=>'$TOK3']")"
expect 'session: an ended session is refused' "$out" '"code":"staff_session_invalid"'

# Lockout: five wrong PINs lock for 15 minutes; a right PIN is refused while locked.
DL="['X-Bazaar-Device'=>'dev-lock-$RUN']"
for n in 1 2 3 4; do
  out="$(call POST /bazaar/v1/pos/staff/sessions "{\"staff_user_id\":$B,\"pin\":\"0001\"}" "$OP" "$DL")"
  expect "lockout: wrong PIN $n is 403 pin_invalid" "$out" '"code":"pin_invalid"'
done
out="$(call POST /bazaar/v1/pos/staff/sessions "{\"staff_user_id\":$B,\"pin\":\"0001\"}" "$OP" "$DL")"
expect 'lockout: the fifth wrong PIN answers 423 pin_locked' "$out" '"status":423'
expect 'lockout: the answer carries locked_until' "$out" '"locked_until":"'
out="$(call POST /bazaar/v1/pos/staff/sessions "{\"staff_user_id\":$B,\"pin\":\"5821\"}" "$OP" "$DL")"
expect 'lockout: the right PIN is refused while locked' "$out" '"code":"pin_locked"'
out="$(wp_eval "global \$wpdb; \$r = \$wpdb->get_row(\$wpdb->prepare('SELECT failed_attempts, locked_until FROM ' . ${P}_Core_Db::table('staff') . ' WHERE user_id = %d', $B)); \$secs = strtotime(\$r->locked_until . ' UTC') - time(); echo (\$secs > 800 && \$secs <= 900) ? 'about-15m' : 'secs=' . \$secs, ',attempts=', \$r->failed_attempts;")"
expect_equal 'lockout: locks for about 15 minutes and restarts the count' "$out" 'about-15m,attempts=0'
out="$(wp_eval "global \$wpdb; echo \$wpdb->get_var('SELECT COUNT(*) FROM ' . ${P}_Core_Db::table('events') . \" WHERE type = 'staff.pin.locked' AND aggregate_id = $B\");")"
expect_equal 'lockout: the outbox recorded staff.pin.locked once' "$out" '1'
out="$(wp_eval "global \$wpdb; \$wpdb->query(\$wpdb->prepare('UPDATE ' . ${P}_Core_Db::table('staff') . \" SET locked_until = '2000-01-01 00:00:00' WHERE user_id = %d\", $B));")"
out="$(call POST /bazaar/v1/pos/staff/sessions "{\"staff_user_id\":$B,\"pin\":\"5821\"}" "$OP" "$DL")"
expect 'lockout: the right PIN works once the lock has passed' "$out" '"status":200'

# Per-device throttle: 20 failures across staff block the device.
DX="dev-x-$RUN"
out="$(wp_eval "
for (\$i = 0; \$i < 20; \$i++) { try { ${P}_Staff_Pins::verify($MGR, '0002', '$DX'); } catch (${P}_Core_Http_Error \$e) { \$last = \$e->error_code(); } if (\$i % 4 === 3) { global \$wpdb; \$wpdb->query(\$wpdb->prepare('UPDATE ' . ${P}_Core_Db::table('staff') . ' SET failed_attempts = 0, locked_until = NULL WHERE user_id = %d', $MGR)); } }
try { ${P}_Staff_Pins::verify($MGR, '7309', '$DX'); echo 'allowed'; } catch (${P}_Core_Http_Error \$e) { echo \$e->error_code(), ',', \$e->status(); }")"
expect_equal 'throttle: after 20 failures a device is blocked even with the right PIN (429)' "$out" 'pin_throttled,429'

# Own PIN
PW="bzr-pass-$RUN"
wp_eval "wp_set_password('$PW', $OP);" >/dev/null
out="$(call PUT /bazaar/v1/pos/staff/me/pin '{"pin":"6150","current_password":"wrong"}' "$OP")"
expect 'own pin: a wrong password is refused' "$out" '"code":"proof_invalid"'
out="$(call PUT /bazaar/v1/pos/staff/me/pin '{"pin":"6150"}' "$OP")"
expect 'own pin: no proof is a 400' "$out" '"code":"invalid_request"'
out="$(call PUT /bazaar/v1/pos/staff/me/pin "{\"pin\":\"6150\",\"current_password\":\"$PW\"}" "$OP")"
expect 'own pin: the right password sets it' "$out" '"updated":true'
out="$(call PUT /bazaar/v1/pos/staff/me/pin '{"pin":"8372","current_pin":"6150"}' "$OP" "['X-Bazaar-Device'=>'dev-b-$RUN']")"
expect 'own pin: the current PIN also works as proof' "$out" '"updated":true'

# Approvals
SUBJ="$(printf 'a%.0s' $(seq 1 64))"
SUBJ2="$(printf 'b%.0s' $(seq 1 64))"
ask() { # ask <requester> <approver> <pin> <scope> <amount> [subject]
  call POST /bazaar/v1/pos/approvals "{\"scope\":\"$4\",\"subject_hash\":\"${6:-$SUBJ}\",\"amount_minor\":$5,\"basis_points\":0,\"approver_user_id\":$2,\"approver_pin\":\"$3\",\"reason\":\"test\"}" "$1" "['X-Bazaar-Device'=>'dev-a-$RUN']"
}
out="$(ask "$MGR" "$MGR" 7309 refund 1500)"
expect 'approval: you cannot approve your own action' "$out" '"code":"approval_invalid"'
out="$(ask "$OP" "$A" 2947 refund 1500)"
expect 'approval: someone without bazaar_approve cannot approve' "$out" '"code":"approval_invalid"'
out="$(ask "$OP" "$MGR" 1111 refund 1500)"
expect 'approval: a wrong approver PIN is refused' "$out" '"code":"pin_invalid"'
out="$(ask "$OP" "$MGR" 7309 refund 1500 'not-a-hash')"
expect 'approval: a malformed subject hash is a 400' "$out" '"code":"invalid_request"'
out="$(ask "$OP" "$MGR" 7309 refund 1500)"
expect 'approval: a manager with the right PIN grants one' "$out" '"status":200'
TOKA="$(grep -o '"token":"[0-9a-f]*"' <<<"$out" | head -1 | cut -d'"' -f4)"
expect_equal 'approval: the token is 64 hex characters' "${#TOKA}" '64'
out="$(wp_eval "global \$wpdb; echo \$wpdb->get_var(\$wpdb->prepare('SELECT COUNT(*) FROM ' . ${P}_Core_Db::table('approvals') . ' WHERE token_hash = %s', hash('sha256', '$TOKA'))), ',', \$wpdb->get_var(\$wpdb->prepare('SELECT COUNT(*) FROM ' . ${P}_Core_Db::table('approvals') . ' WHERE token_hash = %s', '$TOKA'));")"
expect_equal 'approval: only the sha256 of the token is stored' "$out" '1,0'

A_CLS="${P}_Staff_Approvals"
tryit() { # tryit <php expression returning value>, prints the value or the error code
  wp_eval "try { \$v = $1; echo 'ok:' . \$v; } catch (${P}_Core_Http_Error \$e) { echo \$e->error_code() . ',' . \$e->status(); }"
}
out="$(tryit "$A_CLS::peek('$TOKA', 'refund', '$SUBJ', 1500, $OP) > 0 ? 1 : 0")"
expect_equal 'approval: peek accepts the matching request' "$out" 'ok:1'
out="$(tryit "$A_CLS::peek('$TOKA', 'refund', '$SUBJ', 1500, $OP) > 0 ? 1 : 0")"
expect_equal 'approval: peek does not spend it' "$out" 'ok:1'
out="$(tryit "$A_CLS::peek('$TOKA', 'discount', '$SUBJ', 1500, $OP)")"
expect_equal 'approval: another scope is refused (403 approval_invalid)' "$out" 'approval_invalid,403'
out="$(tryit "$A_CLS::peek('$TOKA', 'refund', '$SUBJ2', 1500, $OP)")"
expect_equal 'approval: another subject is refused' "$out" 'approval_invalid,403'
out="$(tryit "$A_CLS::peek('$TOKA', 'refund', '$SUBJ', 1500, $A)")"
expect_equal 'approval: another requester is refused' "$out" 'approval_invalid,403'
out="$(tryit "$A_CLS::peek('$TOKA', 'refund', '$SUBJ', 1501, $OP)")"
expect_equal 'approval: a larger amount is refused' "$out" 'approval_invalid,403'
out="$(tryit "$A_CLS::peek('', 'refund', '$SUBJ', 1500, $OP)")"
expect_equal 'approval: an empty token is refused' "$out" 'approval_invalid,403'
out="$(tryit "$A_CLS::consume('$TOKA', 'refund', '$SUBJ2', 1500, $OP, 'x')")"
expect_equal 'approval: a refused consume does not spend the token' "$out" 'approval_invalid,403'
out="$(tryit "${P}_Core_Db::tx(function () { return $A_CLS::consume('$TOKA', 'refund', '$SUBJ', 1000, $OP, 'order:1') > 0 ? 'spent' : 'zero'; })")"
expect_equal 'approval: consume spends it (a smaller amount is covered)' "$out" 'ok:spent'
out="$(tryit "$A_CLS::consume('$TOKA', 'refund', '$SUBJ', 1000, $OP, 'order:2')")"
expect_equal 'approval: a second consume is refused' "$out" 'approval_invalid,403'
out="$(tryit "$A_CLS::peek('$TOKA', 'refund', '$SUBJ', 1000, $OP)")"
expect_equal 'approval: peek refuses a spent token' "$out" 'approval_invalid,403'
out="$(wp_eval "global \$wpdb; \$r = \$wpdb->get_row(\$wpdb->prepare('SELECT consumed_ref, approved_by, requested_by FROM ' . ${P}_Core_Db::table('approvals') . ' WHERE token_hash = %s', hash('sha256', '$TOKA'))); echo \$r->consumed_ref, ',', \$r->approved_by === '$MGR' ? 'mgr' : 'other', ',', \$r->requested_by === '$OP' ? 'op' : 'other';")"
expect_equal 'approval: the row stays as an audit trail with the consuming reference' "$out" 'order:1,mgr,op'

out="$(ask "$OP" "$MGR" 7309 refund 800)"
TOKB="$(grep -o '"token":"[0-9a-f]*"' <<<"$out" | head -1 | cut -d'"' -f4)"
out="$(wp_eval "global \$wpdb; \$wpdb->query(\$wpdb->prepare('UPDATE ' . ${P}_Core_Db::table('approvals') . \" SET expires_at = '2000-01-01 00:00:00' WHERE token_hash = %s\", hash('sha256', '$TOKB')));")"
out="$(tryit "$A_CLS::peek('$TOKB', 'refund', '$SUBJ', 800, $OP)")"
expect_equal 'approval: an expired token is refused by peek' "$out" 'approval_invalid,403'
out="$(tryit "$A_CLS::consume('$TOKB', 'refund', '$SUBJ', 800, $OP, 'x')")"
expect_equal 'approval: an expired token is refused by consume' "$out" 'approval_invalid,403'

out="$(wp_eval "global \$wpdb; echo \$wpdb->get_var('SELECT COUNT(*) FROM ' . ${P}_Core_Db::table('events') . \" WHERE type = 'approval.granted' AND actor_user_id = $MGR\");")"
expect_equal 'approval: the outbox recorded approval.granted for each grant' "$out" '2'

# Limits against real users
out="$(wp_eval "echo wp_json_encode(${P}_Staff_Limits::for_user($OP));")"
expect 'limits: a cashier gets the default discount limit' "$out" '"discount_bp":1000,"discount_minor":2000,"refund_minor":0,"custom_item_minor":5000,"unlimited":false'
out="$(wp_eval "echo wp_json_encode(${P}_Staff_Limits::for_user($MGR));")"
expect 'limits: a bazaar_approve holder is unlimited' "$out" '"unlimited":true'
out="$(wp_eval "echo wp_json_encode(${P}_Staff_Limits::approvals_needed(${P}_Staff_Limits::for_user($OP), ['refund_minor' => 1]));")"
expect 'limits: every cashier refund needs approval' "$out" '"scope":"refund"'

# Nothing under /pos/staff or /pos/approvals answers 401 for a logged-in caller
for r in "GET /bazaar/v1/pos/staff" "GET /bazaar/v1/pos/staff/sessions/current" "DELETE /bazaar/v1/pos/staff/sessions/current" "POST /bazaar/v1/pos/approvals" "POST /bazaar/v1/pos/staff/sessions" "PUT /bazaar/v1/pos/staff/me/pin"; do
  out="$(call "${r%% *}" "${r#* }" '{}' "$OP")"
  case "$out" in *'"status":401'*) expect "no 401 for a logged-in caller: $r" 'got 401' 'not 401';; *) expect "no 401 for a logged-in caller: $r" 'ok' 'ok';; esac
done
out="$(call GET /bazaar/v1/pos/staff '' "$PLAIN")"
expect 'a user without bazaar_sell cannot list staff (403)' "$out" '"status":403'

bz_finish
