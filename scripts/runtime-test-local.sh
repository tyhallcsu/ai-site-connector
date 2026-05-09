#!/usr/bin/env bash
# scripts/runtime-test-local.sh
#
# Spin up a throwaway WordPress on the SQLite drop-in, activate AI Site
# Connector, and run the full runtime test suite end-to-end.
#
# Differs from tests/runtime-smoke.sh (which CI uses) by:
#   - Using the SQLite drop-in instead of MySQL — no external service needed.
#   - Being explicitly safe to run on a developer machine: refuses to clobber
#     a non-throwaway directory, scrubs every captured credential before exit,
#     prints loud progress so a human reviewing the run can spot anomalies.
#
# Safety rules enforced by this script:
#   - WP_DIR must be empty or non-existent. If it contains any pre-existing
#     wp-config.php or files we did not create, the script exits without
#     touching anything.
#   - Application Password plaintext is never written to disk except in a
#     0600 tmpfile under TMPDIR, which is shredded on exit.
#   - The HTTP server only binds to 127.0.0.1.
#   - Cleanup runs via trap on every exit path, including SIGINT.
#
# Required commands: php, wp (WP-CLI), curl, jq, rsync, unzip.
# Optional: shred (used if available; falls back to rm -P or rm -f).
#
# Usage:
#   scripts/runtime-test-local.sh           # default: /tmp/asc-runtime-test-XXXXXX
#   ASC_KEEP=1 scripts/runtime-test-local.sh   # keep the WP install for inspection
#   ASC_PORT=8765 scripts/runtime-test-local.sh

set -Eeuo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PORT="${ASC_PORT:-8765}"
URL="http://127.0.0.1:${PORT}"
KEEP="${ASC_KEEP:-0}"

# ---------------------------------------------------------------- pretty output
red()    { printf '\033[31m%s\033[0m\n' "$*"; }
green()  { printf '\033[32m%s\033[0m\n' "$*"; }
yellow() { printf '\033[33m%s\033[0m\n' "$*"; }
step()   { printf '\n\033[1;36m▸ %s\033[0m\n' "$*"; }
fail()   { red "FAIL: $*" >&2; exit 1; }

# ---------------------------------------------------------------- preflight
require() { command -v "$1" >/dev/null 2>&1 || fail "missing required command: $1"; }
require php
require wp
require curl
require jq
require rsync
require unzip

WP_CLI_BIN="$(command -v wp)"
wp_cli() {
	php -d "memory_limit=${WP_CLI_MEMORY_LIMIT:-512M}" "$WP_CLI_BIN" "$@"
}

# ---------------------------------------------------------------- temp paths
WP_DIR="$(mktemp -d "${TMPDIR:-/tmp}/asc-runtime-test.XXXXXX")"
PWD_FILE="$(mktemp "${TMPDIR:-/tmp}/asc-pwd.XXXXXX")"
chmod 600 "$PWD_FILE"
SERVER_LOG="${WP_DIR}/.server.log"
SERVER_PID=""

# ---------------------------------------------------------------- safety check
if [ -e "${WP_DIR}/wp-config.php" ] || [ -e "${WP_DIR}/wp-load.php" ]; then
	fail "refusing to clobber an existing WordPress install at ${WP_DIR}"
fi

scrub_secret() {
	if [ -f "$1" ]; then
		if command -v shred >/dev/null 2>&1; then
			shred -u "$1" 2>/dev/null || rm -f "$1"
		elif rm -P "$1" 2>/dev/null; then
			:
		else
			rm -f "$1"
		fi
	fi
}

cleanup() {
	local code=$?
	step "cleanup (exit code ${code})"
	if [ -n "$SERVER_PID" ] && kill -0 "$SERVER_PID" 2>/dev/null; then
		kill "$SERVER_PID" 2>/dev/null || true
		wait "$SERVER_PID" 2>/dev/null || true
	fi
	scrub_secret "$PWD_FILE"
	if [ "$KEEP" = "1" ]; then
		yellow "ASC_KEEP=1 set; preserving ${WP_DIR}"
	else
		rm -rf "$WP_DIR"
	fi
	exit "$code"
}
trap cleanup EXIT INT TERM

# ---------------------------------------------------------------- WP install
step "downloading WordPress core into ${WP_DIR}"
wp_cli core download --skip-content --path="$WP_DIR" --quiet

step "installing the SQLite drop-in"
SQLITE_ZIP="${WP_DIR}/.sqlite-database-integration.zip"
curl -sSLo "$SQLITE_ZIP" 'https://downloads.wordpress.org/plugin/sqlite-database-integration.zip'
unzip -q "$SQLITE_ZIP" -d "$WP_DIR/wp-content/plugins/"
cp "$WP_DIR/wp-content/plugins/sqlite-database-integration/db.copy" "$WP_DIR/wp-content/db.php"
sed -i.bak \
	-e "s|{SQLITE_IMPLEMENTATION_FOLDER_PATH}|$WP_DIR/wp-content/plugins/sqlite-database-integration|" \
	-e "s|{SQLITE_PLUGIN}|sqlite-database-integration/load.php|" \
	"$WP_DIR/wp-content/db.php"
rm "$WP_DIR/wp-content/db.php.bak" "$SQLITE_ZIP"

step "writing wp-config.php"
cp "$WP_DIR/wp-config-sample.php" "$WP_DIR/wp-config.php"
sed -i.bak \
	-e "s|database_name_here|wordpress|" \
	-e "s|username_here|root|" \
	-e "s|password_here|root|" \
	-e "s|define( 'WP_DEBUG', false );|define( 'WP_DEBUG', true );\\
define( 'WP_ENVIRONMENT_TYPE', 'local' );\\
define( 'AI_SITE_CONNECTOR_ALLOW_HTTP', true );|" \
	"$WP_DIR/wp-config.php"
rm "$WP_DIR/wp-config.php.bak"

step "installing WordPress"
wp_cli --path="$WP_DIR" core install \
	--url="$URL" \
	--title='AI Site Connector — Runtime Test' \
	--admin_user=admin \
	--admin_password='admin-test-password-please-ignore' \
	--admin_email=admin@asc.test \
	--skip-email --quiet

step "copying and activating the plugin"
PLUGIN_DIR="$WP_DIR/wp-content/plugins/ai-site-connector"
mkdir -p "$PLUGIN_DIR"
rsync -a \
	--exclude='.git/' \
	--exclude='vendor/' \
	--exclude='build/' \
	--exclude='dist/' \
	"$ROOT_DIR/" "$PLUGIN_DIR/"
wp_cli --path="$WP_DIR" plugin activate ai-site-connector --quiet

# ---------------------------------------------------------------- tests
step "1/12 ai_site_operator role exists"
wp_cli --path="$WP_DIR" role exists ai_site_operator 2>/dev/null >/dev/null \
	|| fail "ai_site_operator role missing"

step "2/12 default operator caps are least-privilege"
wp_cli --path="$WP_DIR" eval '
$role = get_role("ai_site_operator");
$must = ["read","edit_posts","edit_pages","upload_files","moderate_comments"];
foreach ($must as $c) if (!$role->has_cap($c)) { fwrite(STDERR,"missing cap: $c\n"); exit(1); }
$forbidden = ["manage_options","install_plugins","edit_files","list_users","edit_others_posts","edit_others_pages","delete_posts","delete_published_posts"];
foreach ($forbidden as $c) if ($role->has_cap($c)) { fwrite(STDERR,"unexpected cap: $c\n"); exit(1); }
' >/dev/null

step "3/12 audit log table created and activation event recorded"
wp_cli --path="$WP_DIR" eval '
global $wpdb;
$t = $wpdb->prefix . "ai_site_connector_log";
$exists = $wpdb->get_var("SELECT name FROM sqlite_master WHERE type=\"table\" AND name=\"$t\"");
if ($exists !== $t) { fwrite(STDERR,"audit table missing\n"); exit(1); }
$count = (int) $wpdb->get_var("SELECT COUNT(*) FROM $t WHERE action=\"plugin_activated\"");
if ($count < 1) { fwrite(STDERR,"plugin_activated event missing\n"); exit(1); }
' >/dev/null

# wp-cli 2.12 on PHP 8.5 emits deprecation warnings from its own internal phar
# during certain command paths. Those warnings interleave with output and break
# downstream parsers. We always discard stderr and extract only the JSON object
# (lines from a leading `{` to the matching closing `}`) before passing to jq.
wp_q() { wp_cli --path="$WP_DIR" "$@" 2>/dev/null; }
extract_json() { sed -n '/^[[:space:]]*[{[]/,/^[[:space:]]*[}\]]/p'; }

step "4/12 wp ai-connector status / health"
wp_q ai-connector status | grep -q 'plugin_version' \
	|| fail "wp ai-connector status missing plugin_version"
HEALTH_JSON="$(wp_q ai-connector health | extract_json)"
echo "$HEALTH_JSON" \
	| jq -e '.plugin == "ai-site-connector" and .authenticated == false' >/dev/null \
	|| fail "wp ai-connector health did not emit expected JSON"

step "5/12 wp help shows hyphenated subcommands only (no underscore duplicates)"
HELP="$(wp_cli --path="$WP_DIR" help ai-connector 2>/dev/null || true)"
for sub in create-user generate-password revoke-password status health; do
	echo "$HELP" | grep -q "$sub" || fail "wp help missing subcommand: $sub"
done
for under in create_user generate_password revoke_password; do
	if echo "$HELP" | grep -qE "^[[:space:]]+${under}[[:space:]]"; then
		fail "wp help still exposes underscore alias: $under"
	fi
done

step "6/12 create AI user"
wp_q ai-connector create-user --username=ai-agent --role=ai_site_operator >/dev/null

step "7/12 generate Application Password"
PACK="$(wp_q ai-connector generate-password --username=ai-agent --name='Local Runtime Test' --format=json | extract_json)"
APP_PASSWORD="$(printf '%s' "$PACK" | jq -r '.application_password')"
APP_UUID="$(printf '%s' "$PACK" | jq -r '.app_password_uuid')"
[ -n "$APP_PASSWORD" ] && [ "$APP_PASSWORD" != "null" ] || fail "no application_password in pack"
[ -n "$APP_UUID" ] && [ "$APP_UUID" != "null" ] || fail "no uuid in pack"
printf '%s' "$APP_PASSWORD" > "$PWD_FILE"
green "  captured uuid=${APP_UUID} pwd=…${APP_PASSWORD: -4}"

step "8/12 plaintext password NOT in options/usermeta/audit log"
ASC_PWD="$APP_PASSWORD" wp_cli --path="$WP_DIR" eval '
global $wpdb;
$needle = getenv("ASC_PWD");
foreach ([
	[$wpdb->options, "option_value"],
	[$wpdb->usermeta, "meta_value"],
	[$wpdb->prefix . "ai_site_connector_log", "message"],
] as $check) {
	[$tbl, $col] = $check;
	$n = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $tbl WHERE $col LIKE %s", "%" . $wpdb->esc_like($needle) . "%"));
	if ($n > 0) { fwrite(STDERR, "leak in $tbl.$col\n"); exit(1); }
}
' >/dev/null

step "9/12 starting php -S http server on 127.0.0.1:${PORT} (with WP REST router)"
# Router lets /wp-json/* and other front-controller URLs reach index.php even
# without pretty-permalinks rewrites — same pattern tests/runtime-smoke.sh uses.
ROUTER="$WP_DIR/router.php"
cat > "$ROUTER" <<'PHP'
<?php
$path = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
if ( '/' !== $path && file_exists( __DIR__ . $path ) ) {
	return false;
}
require __DIR__ . '/index.php';
PHP
wp_q rewrite structure '/%postname%/' >/dev/null || true
wp_q rewrite flush --hard >/dev/null || true

php -S "127.0.0.1:${PORT}" "$ROUTER" -t "$WP_DIR" >"$SERVER_LOG" 2>&1 &
SERVER_PID=$!
for _ in $(seq 1 30); do
	if curl -fsS "${URL}/wp-json/ai-site-connector/v1/health" >/dev/null 2>&1; then
		break
	fi
	sleep 0.4
done
curl -fsS "${URL}/wp-json/ai-site-connector/v1/health" >/dev/null \
	|| fail "REST never came up; see $SERVER_LOG"

step "10/12 /health unauth returns minimal payload (no leak of WP/PHP/theme/user)"
PAYLOAD="$(curl -fsS "${URL}/wp-json/ai-site-connector/v1/health" 2>/dev/null || true)"
[ -n "$PAYLOAD" ] || fail "could not fetch /health (server log: $SERVER_LOG)"
echo "$PAYLOAD" | jq -e '
	(.plugin == "ai-site-connector")
	and (.authenticated == false)
	and (has("wp_version") | not)
	and (has("php_version") | not)
	and (has("active_theme") | not)
	and (has("active_plugin_count") | not)
	and (has("user") | not)
' >/dev/null || fail "/health unauth payload leaks data: $PAYLOAD"

step "11/12 /health auth + /me/capabilities + capability gating"
AUTH_PAYLOAD="$(curl -fsS -u "ai-agent:${APP_PASSWORD}" "${URL}/wp-json/ai-site-connector/v1/health" 2>/dev/null || true)"
[ -n "$AUTH_PAYLOAD" ] || fail "could not fetch authenticated /health"
echo "$AUTH_PAYLOAD" | jq -e '.authenticated == true and has("wp_version") and .user.login == "ai-agent"' >/dev/null \
	|| fail "/health auth payload missing rich fields: $AUTH_PAYLOAD"

ME_CAPS="$(curl -fsS -u "ai-agent:${APP_PASSWORD}" "${URL}/wp-json/ai-site-connector/v1/me/capabilities" 2>/dev/null || true)"
[ -n "$ME_CAPS" ] || fail "/me/capabilities returned nothing"
echo "$ME_CAPS" | jq -e '
	.login == "ai-agent"
	and .operator_role_active == true
	and .capabilities.edit_posts == true
	and .capabilities.upload_files == true
	and .capabilities.manage_options == false
	and .capabilities.install_plugins == false
' >/dev/null || fail "/me/capabilities response did not match operator role: $ME_CAPS"

http_code() { curl -s -o /dev/null -w '%{http_code}' "$@"; }
[ "$(http_code "${URL}/wp-json/ai-site-connector/v1/me/capabilities")" = "401" ] \
	|| fail "unauth /me/capabilities should be 401"
[ "$(http_code -u "ai-agent:${APP_PASSWORD}" "${URL}/wp-json/wp/v2/users/me")"      = "200" ] || fail "wp/v2/users/me with App Pwd should be 200"
[ "$(http_code -u "ai-agent:${APP_PASSWORD}" "${URL}/wp-json/ai-site-connector/v1/site-info")" = "200" ] || fail "operator /site-info should be 200"
[ "$(http_code -u "ai-agent:${APP_PASSWORD}" "${URL}/wp-json/ai-site-connector/v1/posts")"     = "200" ] || fail "operator /posts should be 200"
[ "$(http_code -u "ai-agent:${APP_PASSWORD}" "${URL}/wp-json/ai-site-connector/v1/pages")"     = "200" ] || fail "operator /pages should be 200"
[ "$(http_code -u "ai-agent:${APP_PASSWORD}" "${URL}/wp-json/ai-site-connector/v1/plugins")"   = "403" ] || fail "operator /plugins should be 403"
[ "$(http_code -u "ai-agent:${APP_PASSWORD}" "${URL}/wp-json/ai-site-connector/v1/themes")"    = "403" ] || fail "operator /themes should be 403"
[ "$(http_code "${URL}/wp-json/ai-site-connector/v1/site-info")"                                = "401" ] || fail "unauth /site-info should be 401"

step "12/13 wp ai-connector self-test (round-trips a temporary App Pwd)"
SELF_TEST_OUT="$(wp_q ai-connector self-test --username=ai-agent --format=json | extract_json)"
echo "$SELF_TEST_OUT" | jq -e '
	.ok == true
	and (.checks | map(select(.name == "credential_round_trip")) | .[0].ok == true)
' >/dev/null || fail "self-test --username did not report ok=true: $SELF_TEST_OUT"
# self-test must never print the temp plaintext password.
if printf '%s' "$SELF_TEST_OUT" | grep -Ei -q '"application_password"\s*:'; then
	fail "self-test JSON unexpectedly contains an application_password field"
fi

step "13/13 revoke and verify original App Pwd is now 401"
wp_q ai-connector revoke-password --username=ai-agent --uuid="$APP_UUID" >/dev/null
[ "$(http_code -u "ai-agent:${APP_PASSWORD}" "${URL}/wp-json/wp/v2/users/me")" = "401" ] \
	|| fail "revoked App Pwd unexpectedly still works"

green "All 13 runtime tests passed."
