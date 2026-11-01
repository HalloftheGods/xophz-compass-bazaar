#!/usr/bin/env bash
# Shared helpers for the Bazaar integration scripts. Source it; do not run it.
#
#   HERE="$(cd "$(dirname "$0")" && pwd)"
#   source "$HERE/lib.sh"
#   ...checks using wp_eval / rest / expect...
#   bz_finish
#
# Needs the local Docker WordPress. Env: WP_CONTAINER (default u-wordpress),
# WP_PATH (default /var/www/html).
#
# These helpers guarantee only what they do: they run commands in the
# container and count expectations. Cleaning up fixtures is each script's job.

CONTAINER="${WP_CONTAINER:-u-wordpress}"
WP_PATH="${WP_PATH:-/var/www/html}"
PASS=0
FAIL=0

# Strip PHP deprecation noise, blank lines and sendmail errors from wp output.
bz_filter() {
  grep -v -e '^Deprecated' -e '^$' -e 'sendmail'
}

# wp_eval '<php code>' : run PHP inside WordPress.
wp_eval() {
  docker exec "$CONTAINER" wp --allow-root --path="$WP_PATH" eval "$1" 2>&1 | bz_filter
}

# wp_file <host-file> [docker -e flags...] : copy a helper into the container and eval-file it.
wp_file() {
  local file="$1" name
  shift
  name="bazaar-$(basename "$file")"
  docker cp "$file" "$CONTAINER:/tmp/$name" >/dev/null
  docker exec "$@" "$CONTAINER" wp --allow-root --path="$WP_PATH" eval-file "/tmp/$name" 2>&1 | bz_filter
}

# rest <METHOD> <route> [body-json] [user] : run call-rest.php.
# user: a user ID or login; "0" means logged out; empty means the first administrator.
# Prints one JSON line: {"status":<int>,"data":<response data>}.
rest() {
  local method="$1" route="$2" body="${3:-}" user="${4:-}"
  wp_file "$LIB_DIR/call-rest.php" -e REST_METHOD="$method" -e REST_ROUTE="$route" -e REST_BODY="$body" -e REST_USER="$user"
}

# expect <name> <output> <needle> : pass when the output contains the text.
expect() {
  local name="$1" output="$2" needle="$3"
  if grep -qF -- "$needle" <<<"$output"; then
    PASS=$((PASS + 1))
  else
    FAIL=$((FAIL + 1))
    printf 'FAIL %s\n  expected to contain: %s\n  got: %s\n' "$name" "$needle" "$(head -c 400 <<<"$output")"
  fi
}

# expect_equal <name> <actual> <expected>
expect_equal() {
  local name="$1" actual="$2" expected="$3"
  if [ "$actual" = "$expected" ]; then
    PASS=$((PASS + 1))
  else
    FAIL=$((FAIL + 1))
    printf 'FAIL %s\n  expected: %s\n  got: %s\n' "$name" "$expected" "$(head -c 400 <<<"$actual")"
  fi
}

LIB_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# bz_finish : print the totals and exit non-zero when anything failed.
bz_finish() {
  echo "$PASS passed, $FAIL failed"
  [ "$FAIL" -eq 0 ]
}
