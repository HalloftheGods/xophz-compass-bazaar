#!/usr/bin/env bash
# Live check of the Bazaar capability model and guard (#6940) against the
# local Docker WordPress. Every admin-ajax call runs in the guard's dry run,
# so no handler runs: the guard answers {success:true, guard:'allowed'} or a
# refusal. call-ajax.php also refuses to fire a hook whose first callback is
# not the guard.
#
# Asserts: the user_has_cap filter and the three roles; for all policy
# actions, logged-out and a throwaway subscriber are refused, an administrator
# is allowed and a throwaway bazaar_cashier matches its matrix; bad and
# missing nonces are refused; the `security` field is accepted; report-only
# mode; an unmapped action fails closed; rest_permission() answers.
#
# Side effects: install_roles() adds the three bazaar_* roles (or their
# missing caps) when they are missing. Two throwaway users are created and
# deleted at the end, also when a check fails.
#
# Run from the repo root: bash wp-content/plugins/xophz-compass-bazaar/tests/integration/guard.sh
set -u
HERE="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=lib.sh
source "$HERE/lib.sh"

P='Xophz_Compass_Bazaar'

# The dry run only exists on local and development sites. Anywhere else the
# handlers would run for real, so stop before calling anything.
env_type="$(wp_eval 'echo wp_get_environment_type();' | tail -1)"
case "$env_type" in
  local | development) ;;
  *)
    echo "ABORT guard.sh needs a local or development site; wp_get_environment_type() is '$env_type'"
    exit 1
    ;;
esac

# Capabilities and roles
out="$(wp_eval "
\$c = '${P}_Security_Capabilities';
echo 'guard=' . (class_exists('${P}_Security_Guard') ? 'yes' : 'no') . \"\n\";
echo 'filter=' . var_export(has_filter('user_has_cap', [\$c, 'filter_user_has_cap']), true) . \"\n\";
\$c::install_roles();
foreach (\$c::ROLES as \$slug => \$caps) {
  \$role = get_role(\$slug);
  \$held = \$role ? array_keys(array_filter(\$role->capabilities)) : [];
  echo \$slug . '=' . (\$role ? (array_diff(\$caps, \$held) ? 'missing:' . implode(',', array_diff(\$caps, \$held)) : 'ok') : 'absent') . \"\n\";
}
echo 'second_install=' . wp_json_encode(\$c::install_roles()) . \"\n\";
foreach (['administrator', 'shop_manager'] as \$slug) {
  \$role = get_role(\$slug);
  \$stored = \$role ? array_filter(array_keys(\$role->capabilities), function (\$k) { return 0 === strpos(\$k, 'bazaar_'); }) : [];
  echo \$slug . '_stored_bazaar_caps=' . count(\$stored) . \"\n\";
}
\$admins = get_users(['role' => 'administrator', 'number' => 1, 'orderby' => 'ID', 'order' => 'ASC', 'fields' => 'ID']);
echo 'admin_settings=' . (user_can((int) \$admins[0], 'bazaar_manage_settings') ? 'yes' : 'no') . \"\n\";
")"
expect 'guard: class loads' "$out" 'guard=yes'
expect 'capabilities: user_has_cap filter at priority 10' "$out" 'filter=10'
expect 'roles: bazaar_cashier has its caps' "$out" 'bazaar_cashier=ok'
expect 'roles: bazaar_supervisor has its caps' "$out" 'bazaar_supervisor=ok'
expect 'roles: bazaar_stock_clerk has its caps' "$out" 'bazaar_stock_clerk=ok'
expect 'roles: install_roles() is idempotent' "$out" 'second_install=[]'
expect 'roles: administrator is not edited' "$out" 'administrator_stored_bazaar_caps=0'
expect 'roles: shop_manager is not edited' "$out" 'shop_manager_stored_bazaar_caps=0'
expect 'legacy map: administrator gets bazaar_manage_settings from manage_woocommerce' "$out" 'admin_settings=yes'

ACTIONS="$(wp_eval "echo implode(',', array_keys(${P}_Security_Policy::AJAX));" | tail -1)"
IFS=',' read -r -a ACTION_LIST <<<"$ACTIONS"
expect_equal 'policy: 71 actions' "${#ACTION_LIST[@]}" '71'

# Throwaway users, deleted on exit.
SUFFIX="$(date +%s)$RANDOM"
ids="$(wp_eval "
foreach (['subscriber' => 'sub', 'bazaar_cashier' => 'cash'] as \$role => \$tag) {
  \$id = wp_insert_user(['user_login' => 'bazaar_guard_' . \$tag . '_$SUFFIX', 'user_pass' => wp_generate_password(32), 'role' => \$role]);
  echo \$tag . '=' . (is_wp_error(\$id) ? 'error:' . \$id->get_error_code() : \$id) . \"\n\";
}
")"
SUB_ID="$(sed -n 's/^sub=\([0-9][0-9]*\)$/\1/p' <<<"$ids")"
CASH_ID="$(sed -n 's/^cash=\([0-9][0-9]*\)$/\1/p' <<<"$ids")"

cleanup() {
  local list=""
  [ -n "${SUB_ID:-}" ] && list="$SUB_ID"
  [ -n "${CASH_ID:-}" ] && list="$list,$CASH_ID"
  list="${list#,}"
  [ -z "$list" ] && return
  wp_eval "require_once ABSPATH . 'wp-admin/includes/user.php'; foreach ([$list] as \$id) { wp_delete_user(\$id); }" >/dev/null
}
trap cleanup EXIT

if [ -z "$SUB_ID" ] || [ -z "$CASH_ID" ]; then
  echo "ABORT could not create the throwaway users: $ids"
  exit 1
fi

# ajax <docker -e flags...> : dry-run call of every policy action.
ajax_all() {
  wp_file "$LIB_DIR/call-ajax.php" -e CALL_ACTION="$ACTIONS" -e CALL_DRY_RUN=1 "$@"
}
# ajax_some <actions> <docker -e flags...>
ajax_some() {
  local actions="$1"
  shift
  wp_file "$LIB_DIR/call-ajax.php" -e CALL_ACTION="$actions" -e CALL_DRY_RUN=1 "$@"
}
# body <output> <action> : the response body call-ajax printed for one action.
body() {
  awk -F'\t' -v a="$2" '$1 == a { print $2 }' <<<"$1"
}

ALLOWED='"guard":"allowed"'
FORBIDDEN='"code":"forbidden"'
BAD_NONCE='"code":"rest_cookie_invalid_nonce"'

# Who may call what
out="$(ajax_all -e CALL_USER=logged-out)"
for a in "${ACTION_LIST[@]}"; do expect "logged-out: $a refused" "$(body "$out" "$a")" "$FORBIDDEN"; done

out="$(ajax_all -e CALL_USER="id:$SUB_ID")"
for a in "${ACTION_LIST[@]}"; do expect "subscriber: $a refused" "$(body "$out" "$a")" "$FORBIDDEN"; done

out="$(ajax_all -e CALL_USER=admin)"
for a in "${ACTION_LIST[@]}"; do expect "administrator: $a allowed" "$(body "$out" "$a")" "$ALLOWED"; done

# bazaar_cashier holds read, bazaar_sell, bazaar_discount and bazaar_refund (spec 6.2, 6.3).
CASHIER_ALLOWED=" get_products bazaar_lookup_barcode bazaar_pos_lookup create_pos_order validate_pos_coupon send_pos_receipt get_pos_customers email_shift_summary get_payment_gateways bazaar_pos_gateway_portal bazaar_pos_authorize_test_payment get_categories get_pos_order_for_refund process_pos_refund bazaar_get_bank_details bazaar_get_inventory_locations "
out="$(ajax_all -e CALL_USER="id:$CASH_ID")"
for a in "${ACTION_LIST[@]}"; do
  if [[ "$CASHIER_ALLOWED" == *" $a "* ]]; then
    expect "cashier: $a allowed" "$(body "$out" "$a")" "$ALLOWED"
  else
    expect "cashier: $a refused" "$(body "$out" "$a")" "$FORBIDDEN"
  fi
done
for a in get_report bazaar_receive_po bazaar_save_bank_details; do
  expect "cashier: $a refused (named in the task)" "$(body "$out" "$a")" "$FORBIDDEN"
done

# Nonces
SAMPLE='get_orders,create_pos_order,bazaar_receive_po'
out="$(ajax_some "$SAMPLE" -e CALL_BAD_NONCE=1)"
for a in get_orders create_pos_order bazaar_receive_po; do expect "bad nonce: $a refused" "$(body "$out" "$a")" "$BAD_NONCE"; done
out="$(ajax_some "$SAMPLE" -e CALL_NO_NONCE=1)"
for a in get_orders create_pos_order bazaar_receive_po; do expect "missing nonce: $a refused" "$(body "$out" "$a")" "$BAD_NONCE"; done
out="$(ajax_some "$SAMPLE" -e CALL_SECURITY_FIELD=1)"
for a in get_orders create_pos_order bazaar_receive_po; do expect "security field: $a accepted" "$(body "$out" "$a")" "$ALLOWED"; done
out="$(ajax_some get_orders -e CALL_SECURITY_FIELD=bad)"
expect 'security field: a bad value is refused' "$(body "$out" get_orders)" "$BAD_NONCE"
out="$(ajax_some get_orders -e CALL_USER=logged-out -e CALL_BAD_NONCE=1)"
expect 'bad nonce, logged out: the cap check answers first' "$(body "$out" get_orders)" "$FORBIDDEN"
out="$(ajax_some create_pos_order,get_report -e CALL_USER="id:$CASH_ID" -e CALL_BAD_NONCE=1)"
expect 'bad nonce, cashier: sell action refused for the nonce' "$(body "$out" create_pos_order)" "$BAD_NONCE"
expect 'bad nonce, cashier: report refused for the cap' "$(body "$out" get_report)" "$FORBIDDEN"

# Report-only: caps still enforced, nonce failures only logged.
out="$(ajax_some get_orders -e CALL_REPORT_ONLY=1 -e CALL_BAD_NONCE=1)"
expect 'report-only: bad nonce is let through' "$(body "$out" get_orders)" "$ALLOWED"
out="$(ajax_some get_orders -e CALL_REPORT_ONLY=1 -e CALL_USER="id:$SUB_ID")"
expect 'report-only: a missing cap is still refused' "$(body "$out" get_orders)" "$FORBIDDEN"

# enforce_ajax() directly: unmapped fails closed; an allowed call stores the context.
out="$(wp_eval "
define('DOING_AJAX', true);
add_filter('wp_die_ajax_handler', function () { return function () { throw new RuntimeException('done', 4242); }; });
\$G = '${P}_Security_Guard';
\$admins = get_users(['role' => 'administrator', 'number' => 1, 'orderby' => 'ID', 'order' => 'ASC', 'fields' => 'ID']);
wp_set_current_user((int) \$admins[0]);
\$_SERVER['HTTP_X_WP_NONCE'] = wp_create_nonce('wp_rest');
\$_SERVER['HTTP_X_BAZAAR_DEVICE'] = 'guard-sh-device';
ob_start();
try { \$r = \$G::enforce_ajax('bazaar_guard_sh_unmapped'); \$how = \$r ? 'context' : 'null'; } catch (RuntimeException \$e) { \$how = 'answered'; }
echo 'unmapped=' . \$how . ' ' . ob_get_clean() . \"\n\";
\$ctx = \$G::enforce_ajax('get_orders');
echo 'context=' . (\$ctx ? wp_json_encode(\$ctx->to_array()) : 'null') . \"\n\";
echo 'context_matches=' . (\$ctx && \$ctx->operator_user_id === (int) \$admins[0] && \$ctx->acting_user_id === (int) \$admins[0] && \$G::context() === \$ctx ? 'yes' : 'no') . \"\n\";
")"
expect 'unmapped: refused before any handler' "$out" 'unmapped=answered'
expect 'unmapped: forbidden body' "$out" "$FORBIDDEN"
expect 'context: login context with the device header' "$out" '"staff_session_id":0,"device_id":"guard-sh-device","via":"login"'
expect 'context: operator and acting user are the admin' "$out" 'context_matches=yes'

# rest_permission()
out="$(wp_eval "
\$G = '${P}_Security_Guard';
\$show = function (\$result) { return is_wp_error(\$result) ? \$result->get_error_code() . ':' . (\$result->get_error_data()['status'] ?? '') : var_export(\$result, true); };
\$req = function (array \$headers = []) { \$r = new WP_REST_Request('GET', '/bazaar/v1/guard-sh'); foreach (\$headers as \$k => \$v) { \$r->set_header(\$k, \$v); } return \$r; };
wp_set_current_user(0);
echo 'logged_out=' . \$show(\$G::rest_permission('bazaar_sell')(\$req())) . \"\n\";
wp_set_current_user($SUB_ID);
echo 'subscriber=' . \$show(\$G::rest_permission('bazaar_sell')(\$req())) . \"\n\";
wp_set_current_user($CASH_ID);
echo 'cashier_sell=' . \$show(\$G::rest_permission('bazaar_sell')(\$req(['X-Bazaar-Device' => 'dev-1']))) . \"\n\";
echo 'cashier_context=' . wp_json_encode(\$G::context()->to_array()) . \"\n\";
echo 'cashier_can_any=' . var_export(\$G::can_any('bazaar_view_reports'), true) . ',' . var_export(\$G::can_any('bazaar_view_reports', 'bazaar_refund'), true) . \"\n\";
echo 'cashier_reports=' . \$show(\$G::rest_permission('bazaar_view_reports')(\$req())) . \"\n\";
echo 'staff_header=' . \$show(\$G::rest_permission('bazaar_sell')(\$req(['X-Bazaar-Staff-Session' => 'not-a-session-token']))) . \"\n\";
echo 'staff_header_empty=' . \$show(\$G::rest_permission('bazaar_sell')(\$req(['X-Bazaar-Staff-Session' => '']))) . \"\n\";
echo 'no_caps=' . \$show(\$G::rest_permission()(\$req())) . \"\n\";
\$admins = get_users(['role' => 'administrator', 'number' => 1, 'orderby' => 'ID', 'order' => 'ASC', 'fields' => 'ID']);
wp_set_current_user((int) \$admins[0]);
echo 'admin_settings=' . \$show(\$G::rest_permission('bazaar_manage_settings')(\$req())) . \"\n\";
")"
expect 'rest: logged out is the only 401' "$out" 'logged_out=rest_not_logged_in:401'
expect 'rest: subscriber refused 403' "$out" 'subscriber=forbidden:403'
expect 'rest: cashier may sell' "$out" 'cashier_sell=true'
expect 'rest: the login context carries the device' "$out" "cashier_context={\"operator_user_id\":$CASH_ID,\"acting_user_id\":$CASH_ID,\"staff_session_id\":0,\"device_id\":\"dev-1\",\"via\":\"login\"}"
expect 'rest: can_any checks the acting user' "$out" 'cashier_can_any=false,true'
expect 'rest: cashier refused reports 403' "$out" 'cashier_reports=forbidden:403'
expect 'rest: an unknown staff session is 403, never 401' "$out" 'staff_header=staff_session_invalid:403'
expect 'rest: an empty staff session header is 403' "$out" 'staff_header_empty=staff_session_invalid:403'
expect 'rest: no caps refuses everyone' "$out" 'no_caps=forbidden:403'
expect 'rest: administrator holds settings' "$out" 'admin_settings=true'

cleanup
trap - EXIT
left="$(wp_eval "echo (get_user_by('id', $SUB_ID) ? 'sub-left ' : '') . (get_user_by('id', $CASH_ID) ? 'cash-left' : '') . 'done';" | tail -1)"
expect_equal 'cleanup: throwaway users deleted' "$left" 'done'

bz_finish
