#!/usr/bin/env bash
set -Eeuo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WP_VERSION="${WP_VERSION:-latest}"
WP_DIR="${WP_DIR:-}"
WP_PORT="${WP_PORT:-8765}"
WP_URL="${WP_URL:-http://127.0.0.1:${WP_PORT}}"
WP_DB_NAME="${WP_DB_NAME:-asc_runtime_smoke}"
WP_DB_USER="${WP_DB_USER:-root}"
WP_DB_PASSWORD="${WP_DB_PASSWORD:-root}"
WP_DB_HOST="${WP_DB_HOST:-127.0.0.1}"

if [ -z "$WP_DIR" ]; then
	WP_DIR="$(mktemp -d "${TMPDIR:-/tmp}/asc-wp.XXXXXX")"
	REMOVE_WP_DIR=1
else
	REMOVE_WP_DIR=0
	mkdir -p "$WP_DIR"
fi

SERVER_PID=""
log() {
	printf '[runtime-smoke] %s\n' "$*"
}

on_error() {
	local line="$1"
	local command="$2"
	echo "Runtime smoke failed at line ${line}: ${command}" >&2
	if [ -f /tmp/asc-runtime-server.log ]; then
		echo "--- php -S log ---" >&2
		cat /tmp/asc-runtime-server.log >&2 || true
		echo "--- end php -S log ---" >&2
	fi
}

cleanup() {
	if [ -n "$SERVER_PID" ] && kill -0 "$SERVER_PID" 2>/dev/null; then
		kill "$SERVER_PID"
		wait "$SERVER_PID" 2>/dev/null || true
	fi
	if [ "$REMOVE_WP_DIR" -eq 1 ]; then
		rm -rf "$WP_DIR"
	fi
}
trap cleanup EXIT
trap 'on_error "$LINENO" "$BASH_COMMAND"' ERR

require_command() {
	if ! command -v "$1" >/dev/null 2>&1; then
		echo "Required command not found: $1" >&2
		exit 1
	fi
}

require_command php
require_command wp
require_command mysql
require_command mysqladmin
require_command curl
require_command jq
require_command rsync

WP_CLI_BIN="$(command -v wp)"
wp_cli() {
	php -d "memory_limit=${WP_CLI_MEMORY_LIMIT:-512M}" "$WP_CLI_BIN" "$@"
}

mysql_args=(-h "$WP_DB_HOST" -u "$WP_DB_USER")
if [ -n "$WP_DB_PASSWORD" ]; then
	mysql_args+=("-p${WP_DB_PASSWORD}")
fi

for _ in $(seq 1 30); do
	if mysqladmin "${mysql_args[@]}" ping --silent >/dev/null 2>&1; then
		break
	fi
	sleep 2
done

mysqladmin "${mysql_args[@]}" ping --silent >/dev/null
log "Preparing MySQL database ${WP_DB_NAME}."
mysql "${mysql_args[@]}" -e "DROP DATABASE IF EXISTS \`${WP_DB_NAME}\`; CREATE DATABASE \`${WP_DB_NAME}\` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

log "Downloading WordPress ${WP_VERSION}."
wp_cli core download --path="$WP_DIR" --version="$WP_VERSION" --quiet
log "Creating wp-config.php."
wp_cli config create \
	--path="$WP_DIR" \
	--dbname="$WP_DB_NAME" \
	--dbuser="$WP_DB_USER" \
	--dbpass="$WP_DB_PASSWORD" \
	--dbhost="$WP_DB_HOST" \
	--skip-check \
	--quiet \
	--extra-php <<'PHP'
define( 'WP_DEBUG', true );
define( 'WP_ENVIRONMENT_TYPE', 'local' );
define( 'AI_SITE_CONNECTOR_ALLOW_HTTP', true );
PHP

log "Installing WordPress at ${WP_URL}."
wp_cli core install \
	--path="$WP_DIR" \
	--url="$WP_URL" \
	--title="AI Site Connector Runtime Smoke" \
	--admin_user=admin \
	--admin_password='admin-password-please-ignore' \
	--admin_email=admin@example.test \
	--skip-email \
	--quiet

log "Activating AI Site Connector."
PLUGIN_DIR="$WP_DIR/wp-content/plugins/ai-site-connector"
mkdir -p "$PLUGIN_DIR"
rsync -a \
	--exclude='.git/' \
	--exclude='vendor/' \
	--exclude='build/' \
	--exclude='dist/' \
	"$ROOT_DIR/" "$PLUGIN_DIR/"
wp_cli plugin activate ai-site-connector --path="$WP_DIR" --quiet
wp_cli rewrite structure '/%postname%/' --path="$WP_DIR" --quiet
wp_cli rewrite flush --hard --path="$WP_DIR" --quiet

log "Checking activation artifacts."
# wp_cli eval can print PHP startup notices (memory_limit, deprecation warnings)
# to stdout under some PHP configurations, which then pollutes captured output.
# Strip stderr, take only the final line, and assert it looks like a real
# WordPress table name (must contain the prefix). Empty / unprefixed output is
# a hard fail — silently letting it through means downstream queries hit the
# wrong table name and the smoke test reports a misleading error.
AUDIT_TABLE="$(wp_cli --path="$WP_DIR" eval 'echo AI_Site_Connector_Audit_Log::table_name();' 2>/dev/null | tail -n 1)"
case "$AUDIT_TABLE" in
	*ai_site_connector_log)
		: ;;
	*)
		echo "Could not resolve audit table name (got: '${AUDIT_TABLE}'). Plugin likely not loaded yet." >&2
		exit 1
		;;
esac
log "Audit table resolved as: ${AUDIT_TABLE}"
wp_cli eval '
global $wpdb;
$table = AI_Site_Connector_Audit_Log::table_name();
$found = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table ) );
if ( $found !== $table ) {
	fwrite( STDERR, "Missing audit log table: {$table}\n" );
	exit( 1 );
}
$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE action = %s", "plugin_activated" ) );
if ( $count < 1 ) {
	fwrite( STDERR, "Missing plugin_activated audit event\n" );
	exit( 1 );
}
' --path="$WP_DIR"

log "Checking AI Site Operator capabilities."
wp_cli eval '
$role = get_role( "ai_site_operator" );
if ( ! $role ) {
	fwrite( STDERR, "Missing ai_site_operator role\n" );
	exit( 1 );
}
$must_have = array( "read", "edit_posts", "edit_pages", "edit_published_posts", "edit_published_pages", "upload_files", "moderate_comments" );
foreach ( $must_have as $cap ) {
	if ( ! $role->has_cap( $cap ) ) {
		fwrite( STDERR, "Missing expected operator capability: $cap\n" );
		exit( 1 );
	}
}
$must_not_have = array( "manage_options", "install_plugins", "edit_files", "delete_posts", "delete_published_posts", "list_users", "edit_others_posts", "edit_others_pages" );
foreach ( $must_not_have as $cap ) {
	if ( $role->has_cap( $cap ) ) {
		fwrite( STDERR, "Unexpected operator capability: $cap\n" );
		exit( 1 );
	}
}
' --path="$WP_DIR"

log "Checking WP-CLI commands."
wp_cli ai-connector status --path="$WP_DIR" | grep -q 'plugin_version'
wp_cli ai-connector health --path="$WP_DIR" | jq -e '.plugin == "ai-site-connector" and .authenticated == false' >/dev/null

log "Running self-test (no credential round-trip yet)."
SELF_TEST_OUT="$(wp_cli ai-connector self-test --format=json --path="$WP_DIR")"
printf '%s' "$SELF_TEST_OUT" | jq -e '.ok == true and (.checks | length) >= 5' >/dev/null \
	|| { echo "self-test (no username) did not report ok=true"; printf '%s\n' "$SELF_TEST_OUT" >&2; exit 1; }

log "Creating managed AI user."
AI_USER="ai-agent"
wp_cli ai-connector create-user --username="$AI_USER" --role=ai_site_operator --path="$WP_DIR" >/dev/null
if wp_cli ai-connector create-user --username="$AI_USER" --role=ai_site_operator --path="$WP_DIR" >/tmp/asc-duplicate-user.out 2>&1; then
	echo "Duplicate AI user creation unexpectedly succeeded." >&2
	exit 1
fi

log "Generating Application Password connection pack."
PACK="$(
	wp_cli ai-connector generate-password \
		--username="$AI_USER" \
		--name='CI Runtime Smoke' \
		--format=json \
		--path="$WP_DIR" 2>/tmp/asc-generate-password.err
)"
APP_PASSWORD="$(printf '%s' "$PACK" | jq -r '.application_password')"
APP_UUID="$(printf '%s' "$PACK" | jq -r '.app_password_uuid')"

if [ -z "$APP_PASSWORD" ] || [ "$APP_PASSWORD" = "null" ] || [ -z "$APP_UUID" ] || [ "$APP_UUID" = "null" ]; then
	echo "Connection pack did not include an Application Password and UUID." >&2
	exit 1
fi

log "Starting local WordPress HTTP server."
ROUTER="$WP_DIR/router.php"
cat > "$ROUTER" <<'PHP'
<?php
$path = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
if ( '/' !== $path && file_exists( __DIR__ . $path ) ) {
	return false;
}
require __DIR__ . '/index.php';
PHP

php -S "127.0.0.1:${WP_PORT}" "$ROUTER" -t "$WP_DIR" >/tmp/asc-runtime-server.log 2>&1 &
SERVER_PID="$!"

log "Waiting for REST API."
for _ in $(seq 1 30); do
	if curl -fsS "$WP_URL/wp-json/ai-site-connector/v1/health" >/dev/null 2>&1; then
		break
	fi
	sleep 1
done

log "Checking public health endpoint."
HEALTH_PUBLIC="$(curl -fsS "$WP_URL/wp-json/ai-site-connector/v1/health")"
printf '%s' "$HEALTH_PUBLIC" | jq -e '.plugin == "ai-site-connector" and .authenticated == false and (has("wp_version") | not)' >/dev/null

log "Checking /.well-known/ai-site-connector.json discovery payload."
DISCOVERY_JSON="$(curl -fsS "$WP_URL/.well-known/ai-site-connector.json")"
printf '%s' "$DISCOVERY_JSON" | jq -e '
	.spec_version == "1"
	and .plugin == "ai-site-connector"
	and (.version | type == "string")
	and (.rest_namespace == "ai-site-connector/v1")
	and (.rest_base | type == "string")
	and (.openapi_url | type == "string")
	and (.tools_catalog_url | type == "string")
	and (.mcp.http | type == "string")
	and (.auth_methods | index("basic_auth_application_password"))
' >/dev/null

log "Checking REST permission boundaries."
status="$(curl -sS -o /dev/null -w '%{http_code}' "$WP_URL/wp-json/ai-site-connector/v1/site-info")"
if [ "$status" != "401" ]; then
	echo "Expected unauthenticated /site-info to return 401, got $status." >&2
	exit 1
fi

log "Checking Application Password authentication."
status="$(curl -sS -o /dev/null -w '%{http_code}' --user "$AI_USER:$APP_PASSWORD" "$WP_URL/wp-json/wp/v2/users/me")"
if [ "$status" != "200" ]; then
	echo "Expected Application Password auth to wp/v2/users/me to return 200, got $status." >&2
	exit 1
fi

log "Checking authenticated health payload."
HEALTH_AUTH="$(curl -fsS --user "$AI_USER:$APP_PASSWORD" "$WP_URL/wp-json/ai-site-connector/v1/health")"
printf '%s' "$HEALTH_AUTH" | jq -e '.authenticated == true and .user.login == "ai-agent" and has("wp_version")' >/dev/null

log "Checking /me/capabilities introspection."
# /me/capabilities must require auth and never reveal another user's caps.
status="$(curl -sS -o /dev/null -w '%{http_code}' "$WP_URL/wp-json/ai-site-connector/v1/me/capabilities")"
if [ "$status" != "401" ]; then
	echo "Expected unauthenticated /me/capabilities to return 401, got $status." >&2
	exit 1
fi
ME_CAPS="$(curl -fsS --user "$AI_USER:$APP_PASSWORD" "$WP_URL/wp-json/ai-site-connector/v1/me/capabilities")"
printf '%s' "$ME_CAPS" | jq -e '
	.login == "ai-agent"
	and (.roles | index("ai_site_operator"))
	and .operator_role_active == true
	and .capabilities.edit_posts == true
	and .capabilities.upload_files == true
	and .capabilities.manage_options == false
	and .capabilities.install_plugins == false
	and .capabilities.edit_files == false
' >/dev/null || {
	echo "/me/capabilities did not report the expected operator-role capability map." >&2
	echo "$ME_CAPS" | jq '.' >&2
	exit 1
}

log "Checking allowed operator REST routes."
for route in site-info posts pages; do
	status="$(curl -sS -o /dev/null -w '%{http_code}' --user "$AI_USER:$APP_PASSWORD" "$WP_URL/wp-json/ai-site-connector/v1/$route")"
	if [ "$status" != "200" ]; then
		echo "Expected authenticated /$route to return 200, got $status." >&2
		exit 1
	fi
done

log "Checking denied operator REST routes."
for route in plugins themes; do
	status="$(curl -sS -o /dev/null -w '%{http_code}' --user "$AI_USER:$APP_PASSWORD" "$WP_URL/wp-json/ai-site-connector/v1/$route")"
	if [ "$status" != "403" ]; then
		echo "Expected operator /$route to return 403, got $status." >&2
		exit 1
	fi
done

log "Checking plaintext password isolation."
ASC_APP_PASSWORD="$APP_PASSWORD" wp_cli eval '
global $wpdb;
$needle = getenv( "ASC_APP_PASSWORD" );
$checks = array(
	array( $wpdb->options, "option_value" ),
	array( $wpdb->usermeta, "meta_value" ),
	array( AI_Site_Connector_Audit_Log::table_name(), "message" ),
);
foreach ( $checks as $check ) {
	list( $table, $column ) = $check;
	$sql = "SELECT COUNT(*) FROM `{$table}` WHERE `{$column}` LIKE %s";
	$count = (int) $wpdb->get_var( $wpdb->prepare( $sql, "%" . $wpdb->esc_like( $needle ) . "%" ) );
	if ( $count > 0 ) {
		fwrite( STDERR, "Plaintext Application Password found in {$table}.{$column}\n" );
		exit( 1 );
	}
}
' --path="$WP_DIR"

log "Running self-test with credential round-trip (mints + revokes a temp App Pwd)."
SELF_TEST_OUT="$(wp_cli ai-connector self-test --username="$AI_USER" --format=json --path="$WP_DIR")"
printf '%s' "$SELF_TEST_OUT" | jq -e '
	.ok == true
	and (.checks | map(select(.name == "credential_round_trip")) | .[0].ok == true)
' >/dev/null || { echo "self-test --username did not pass round-trip"; printf '%s\n' "$SELF_TEST_OUT" >&2; exit 1; }
# self-test must NEVER expose the temporary password anywhere — assert the
# JSON does not contain a literal "application_password" field.
if printf '%s' "$SELF_TEST_OUT" | grep -Ei -q '"application_password"\s*:'; then
	echo "self-test JSON unexpectedly contains an application_password field" >&2
	exit 1
fi

log "Checking audit events."
for action in plugin_activated ai_user_created application_password_created health_accessed_authenticated self_test_run; do
	count="$(wp_cli db query "SELECT COUNT(*) FROM \`${AUDIT_TABLE}\` WHERE action = '${action}';" --path="$WP_DIR" --skip-column-names)"
	if [ "${count:-0}" -lt 1 ]; then
		echo "Expected audit event not found: $action" >&2
		exit 1
	fi
done

log "Testing audit log retention pruner."
# Insert 5 old rows, then enough recent fillers so the floor (most-recent-100
# rows by ID) is well past the old rows — otherwise rows that happen to be
# among the 100 newest by ID would be protected regardless of created_at.
wp_cli eval '
global $wpdb;
$table = AI_Site_Connector_Audit_Log::table_name();
$old   = gmdate( "Y-m-d H:i:s", time() - ( 400 * DAY_IN_SECONDS ) );
for ( $i = 0; $i < 5; $i++ ) {
	$wpdb->insert(
		$table,
		array(
			"created_at" => $old,
			"action"     => "test_old_row",
			"message"    => "fake row #" . $i,
		),
		array( "%s", "%s", "%s" )
	);
}
// Add enough recent fillers that the floor pivot is past all old rows.
for ( $i = 0; $i < AI_Site_Connector_Audit_Log::MIN_KEEP_ROWS + 5; $i++ ) {
	AI_Site_Connector_Audit_Log::record( "test_recent_row", array( "message" => "filler #" . $i ) );
}
$deleted = AI_Site_Connector_Audit_Log::prune();
$old_remaining = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE action = %s", "test_old_row" ) );
if ( $old_remaining !== 0 ) {
	fwrite( STDERR, "Expected all 5 fake-old rows pruned, {$old_remaining} remain.\n" );
	exit( 1 );
}
$pruned_event = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE action = %s", "audit_log_pruned" ) );
if ( $pruned_event < 1 ) {
	fwrite( STDERR, "Expected at least one audit_log_pruned event, found 0.\n" );
	exit( 1 );
}
' --path="$WP_DIR"

log "Revoking Application Password."
wp_cli ai-connector revoke-password --username="$AI_USER" --uuid="$APP_UUID" --path="$WP_DIR" >/dev/null
status="$(curl -sS -o /dev/null -w '%{http_code}' --user "$AI_USER:$APP_PASSWORD" "$WP_URL/wp-json/wp/v2/users/me")"
if [ "$status" != "401" ]; then
	echo "Expected revoked Application Password to return 401, got $status." >&2
	exit 1
fi

count="$(wp_cli db query "SELECT COUNT(*) FROM \`${AUDIT_TABLE}\` WHERE action = 'application_password_revoked';" --path="$WP_DIR" --skip-column-names)"
if [ "${count:-0}" -lt 1 ]; then
	echo "Expected application_password_revoked audit event not found." >&2
	exit 1
fi

log "Testing uninstall.php opt-in wipe path (destructive — must be last)."
wp_cli eval '
update_option( "ai_site_connector_wipe_on_uninstall", 1 );
if ( ! defined( "WP_UNINSTALL_PLUGIN" ) ) { define( "WP_UNINSTALL_PLUGIN", true ); }
require_once WP_PLUGIN_DIR . "/ai-site-connector/uninstall.php";
global $wpdb;
$table_still = (bool) $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $wpdb->prefix . "ai_site_connector_log" ) );
$role_still  = (bool) get_role( "ai_site_operator" );
if ( $table_still ) { fwrite( STDERR, "uninstall.php did not drop the audit table\n" ); exit( 1 ); }
if ( $role_still )  { fwrite( STDERR, "uninstall.php did not remove the operator role\n" ); exit( 1 ); }
' --path="$WP_DIR"

echo "WordPress runtime smoke test passed."
