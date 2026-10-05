#!/usr/bin/env bash
# Install the built release ZIP into a fresh WordPress, then upgrade from the
# previous published release ZIP to it. Uses only the actual ZIP artifacts.
#
#   tests/zip-upgrade-smoke.sh <new-zip> [previous-version]
#
# previous-version defaults to the latest published GitHub release that is
# not the new version. Requires: php, wp, mysql client, curl, unzip, jq.
# Env: WP_DB_NAME WP_DB_USER WP_DB_PASSWORD WP_DB_HOST (host[:port]) WP_VERSION.
set -Eeuo pipefail

NEW_ZIP="${1:?usage: tests/zip-upgrade-smoke.sh <new-zip> [previous-version]}"
PREV_VERSION="${2:-}"
REPO="${GITHUB_REPOSITORY:-tyhallcsu/ai-site-connector}"
WP_VERSION="${WP_VERSION:-latest}"
WP_DB_NAME="${WP_DB_NAME:-asc_zip_smoke}"
WP_DB_USER="${WP_DB_USER:-root}"
WP_DB_PASSWORD="${WP_DB_PASSWORD:-root}"
WP_DB_HOST="${WP_DB_HOST:-127.0.0.1}"

log() { printf '[zip-upgrade-smoke] %s\n' "$*"; }
WORK="$(mktemp -d "${TMPDIR:-/tmp}/asc-zip.XXXXXX")"
trap 'rm -rf "$WORK"' EXIT

WP_CLI_BIN="$(command -v wp)"
wp_cli() { php -d "memory_limit=${WP_CLI_MEMORY_LIMIT:-512M}" "$WP_CLI_BIN" --path="$WORK/wp" "$@"; }

mysql_args=(-h "${WP_DB_HOST%%:*}" -u "$WP_DB_USER")
if [ "${WP_DB_HOST#*:}" != "$WP_DB_HOST" ]; then
	mysql_args+=(-P "${WP_DB_HOST#*:}")
fi
if [ -n "$WP_DB_PASSWORD" ]; then
	mysql_args+=("-p${WP_DB_PASSWORD}")
fi

NEW_VERSION="$(unzip -p "$NEW_ZIP" ai-site-connector/ai-site-connector.php | grep -E '^[[:space:]]*\*[[:space:]]*Version:' | head -1 | sed -E 's/.*Version:[[:space:]]*//' | tr -d '[:space:]')"
test -n "$NEW_VERSION" || { echo "Could not read version from $NEW_ZIP" >&2; exit 1; }
log "New ZIP version: $NEW_VERSION"

if [ -z "$PREV_VERSION" ]; then
	auth=()
	if [ -n "${GITHUB_TOKEN:-}" ]; then
		auth=(-H "Authorization: Bearer ${GITHUB_TOKEN}")
	fi
	PREV_VERSION="$(curl -fsSL ${auth[@]+"${auth[@]}"} "https://api.github.com/repos/${REPO}/releases?per_page=20" \
		| jq -r --arg v "v$NEW_VERSION" '[.[] | select(.draft == false and .prerelease == false and .tag_name != $v)][0].tag_name' | sed 's/^v//')"
fi
test -n "$PREV_VERSION" && [ "$PREV_VERSION" != "null" ] || { echo "Could not resolve previous release." >&2; exit 1; }
PREV_ZIP="$WORK/prev.zip"
log "Downloading previous release v$PREV_VERSION."
curl -fsSL -o "$PREV_ZIP" "https://github.com/${REPO}/releases/download/v${PREV_VERSION}/ai-site-connector-v${PREV_VERSION}.zip"

fresh_site() {
	mysql "${mysql_args[@]}" -e "DROP DATABASE IF EXISTS \`${WP_DB_NAME}\`; CREATE DATABASE \`${WP_DB_NAME}\`;" 2>/dev/null
	rm -rf "$WORK/wp"
	wp_cli core download --version="$WP_VERSION" --quiet
	wp_cli config create --dbname="$WP_DB_NAME" --dbuser="$WP_DB_USER" --dbpass="$WP_DB_PASSWORD" --dbhost="$WP_DB_HOST" --skip-check --quiet
	wp_cli core install --url=http://127.0.0.1 --title=zip-smoke --admin_user=admin --admin_password='zip-smoke-ignore' --admin_email=admin@example.test --skip-email --quiet
}

assert_active_version() {
	local expected="$1"
	wp_cli plugin is-active ai-site-connector || { echo "plugin not active" >&2; exit 1; }
	local v
	v="$(wp_cli eval 'echo AI_SITE_CONNECTOR_VERSION;' 2>/dev/null | tail -n 1)"
	[ "$v" = "$expected" ] || { echo "Expected AI_SITE_CONNECTOR_VERSION=$expected, got '$v'" >&2; exit 1; }
	wp_cli eval '
		$r = rest_do_request( new WP_REST_Request( "GET", "/ai-site-connector/v1/health" ) );
		if ( 200 !== $r->get_status() ) { fwrite( STDERR, "health returned " . $r->get_status() . "\n" ); exit( 1 ); }
	' --user=admin
}

log "Clean install of the new ZIP."
fresh_site
wp_cli plugin install "$NEW_ZIP" --activate --quiet
assert_active_version "$NEW_VERSION"
wp_cli ai-connector mcp-self-test --user=admin --format=json | jq -e '.overall != "fail"' >/dev/null \
	|| { echo "mcp-self-test failed on clean install" >&2; exit 1; }

log "Upgrade v$PREV_VERSION -> v$NEW_VERSION."
fresh_site
wp_cli plugin install "$PREV_ZIP" --activate --quiet
assert_active_version "$PREV_VERSION"
wp_cli ai-connector create-user --username=zip-smoke-agent --quiet >/dev/null
before_rows="$(wp_cli db query "SELECT COUNT(*) FROM wp_ai_site_connector_log" --skip-column-names)"
# Real update path: seed the previous release's updater cache with the new
# ZIP (a local path, which WP_Upgrader::download_package accepts), let its
# pre_set_site_transient_update_plugins hook inject the update, then run
# Plugin_Upgrader through `wp plugin update`.
NEW_ZIP_ABS="$(cd "$(dirname "$NEW_ZIP")" && pwd)/$(basename "$NEW_ZIP")"
ASC_NEW_VERSION="$NEW_VERSION" ASC_NEW_ZIP="$NEW_ZIP_ABS" wp_cli eval '
	set_site_transient(
		"ai_site_connector_remote_release",
		array(
			"version"       => getenv( "ASC_NEW_VERSION" ),
			"zip_url"       => getenv( "ASC_NEW_ZIP" ),
			"asset_name"    => basename( getenv( "ASC_NEW_ZIP" ) ),
			"body"          => "",
			"published_at"  => gmdate( "c" ),
			"is_prerelease" => false,
			"html_url"      => "",
		),
		HOUR_IN_SECONDS
	);
	delete_site_transient( "update_plugins" );
	wp_update_plugins();
	$t = get_site_transient( "update_plugins" );
	$r = isset( $t->response[ AI_SITE_CONNECTOR_BASENAME ] ) ? $t->response[ AI_SITE_CONNECTOR_BASENAME ] : null;
	if ( ! $r || getenv( "ASC_NEW_VERSION" ) !== $r->new_version ) {
		fwrite( STDERR, "updater did not offer the new version\n" );
		exit( 1 );
	}
'
wp_cli plugin update ai-site-connector
assert_active_version "$NEW_VERSION"
wp_cli user get zip-smoke-agent --field=user_login >/dev/null || { echo "AI user lost on upgrade" >&2; exit 1; }
after_rows="$(wp_cli db query "SELECT COUNT(*) FROM wp_ai_site_connector_log" --skip-column-names)"
[ "${after_rows:-0}" -ge "${before_rows:-0}" ] || { echo "audit log rows lost on upgrade ($before_rows -> $after_rows)" >&2; exit 1; }

mysql "${mysql_args[@]}" -e "DROP DATABASE IF EXISTS \`${WP_DB_NAME}\`;" 2>/dev/null
log "ZIP install + upgrade smoke passed (v$PREV_VERSION -> v$NEW_VERSION)."
