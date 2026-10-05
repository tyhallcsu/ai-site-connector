#!/usr/bin/env bash
# Persistent LOCAL development WordPress for AI Site Connector.
#
# Runbook: docs/development/DEV_SITE.md. Needs Docker (Compose v2), git, jq,
# unzip and shasum/sha256sum; `deploy --release` also needs gh. Not shipped in
# the release ZIP (bin/ is excluded).
#
#   bin/dev-site.sh up                     start; installs WordPress on first run
#   bin/dev-site.sh deploy [REF]           build REF (default origin/main) from git, install it
#       --allow-unmerged                   permit a commit that is not on origin/main
#   bin/dev-site.sh deploy --release TAG   install a published GitHub release asset
#   bin/dev-site.sh status                 URL, versions, deployed SHA and artifact checksum
#   bin/dev-site.sh rollback               reinstall the previously deployed artifact
#   bin/dev-site.sh seed                   add synthetic fixture content (idempotent)
#   bin/dev-site.sh wp ARGS...             run WP-CLI against the dev site
#   bin/dev-site.sh with-admin -- CMD...   run CMD with ASC_DEV_URL, ASC_DEV_ADMIN_USER and
#                                          ASC_DEV_ADMIN_PASSWORD set (never printed)
#   bin/dev-site.sh backups                list database snapshots (one is taken before
#                                          every deploy and rollback; newest 10 kept)
#   bin/dev-site.sh restore-db FILE --yes-overwrite-dev-db   restore a snapshot
#   bin/dev-site.sh mail                   show captured outbound mail
#   bin/dev-site.sh debug-log              show the WordPress debug log
#   bin/dev-site.sh logs                   follow the web server log
#   bin/dev-site.sh stop                   stop containers; keep all data
#   bin/dev-site.sh destroy --yes-destroy-dev-data   remove containers AND volumes
#
# ASC_DEV_PORT (default 8790) changes the port. Use the same value for every
# command: the site URL is stored in the database at install time.
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
COMPOSE_FILE="$ROOT_DIR/bin/dev-site/compose.yml"
PORT="${ASC_DEV_PORT:-8790}"
SITE_URL="http://localhost:${PORT}"
STATE=/asc-dev-state
WP_ROOT=/var/www/html
PLUGIN_SLUG=ai-site-connector
ADMIN_USER=asc-dev-admin
RELEASE_REPO=tyhallcsu/ai-site-connector
KEEP_BACKUPS=10
WORK_DIR=""

export ASC_DEV_PORT="$PORT"

die() {
	echo "dev-site: $*" >&2
	exit 1
}

note() {
	echo "dev-site: $*" >&2
}

cleanup() {
	if [ -n "$WORK_DIR" ]; then
		rm -rf "$WORK_DIR"
	fi
}
trap cleanup EXIT

compose() {
	docker compose -f "$COMPOSE_FILE" "$@"
}

# WP-CLI in a throwaway container that shares the site's volumes.
wp_cli() {
	compose run --rm -T cli wp "$@"
}

# Command in the running web container, as root (state-volume housekeeping).
in_web() {
	compose exec -T wordpress "$@"
}

sha256_file() {
	if command -v sha256sum >/dev/null 2>&1; then
		sha256sum "$1" | cut -d' ' -f1
	else
		shasum -a 256 "$1" | cut -d' ' -f1
	fi
}

is_running() {
	compose ps --status running --services 2>/dev/null | grep -qx wordpress
}

require_running() {
	is_running || die "the dev site is not running. Start it with: bin/dev-site.sh up"
}

make_work_dir() {
	WORK_DIR="$(mktemp -d "${TMPDIR:-/tmp}/asc-dev-site.XXXXXX")"
	WORK_DIR="$(cd "$WORK_DIR" && pwd -P)"
}

read_state() {
	in_web sh -c "cat '$STATE/$1' 2>/dev/null || true"
}

write_state() {
	in_web sh -c "cat > '$STATE/$1' && chown www-data:www-data '$STATE/$1'"
}

append_log() {
	in_web sh -c "cat >> '$STATE/deployments.jsonl' && chown www-data:www-data '$STATE/deployments.jsonl'"
}

cmd_up() {
	command -v docker >/dev/null 2>&1 || die "docker is required"
	compose up -d --wait db wordpress >&2

	local i
	for i in $(seq 1 60); do
		if in_web test -f "$WP_ROOT/wp-config.php"; then
			break
		fi
		sleep 1
	done
	in_web test -f "$WP_ROOT/wp-config.php" || die "WordPress files did not appear in the web container"

	in_web sh -c "mkdir -p '$STATE/artifacts' '$STATE/backups' '$WP_ROOT/wp-content/mu-plugins' \
		&& chown -R www-data:www-data '$STATE' '$WP_ROOT/wp-content/mu-plugins' && chmod 700 '$STATE'"
	compose cp "$ROOT_DIR/bin/dev-site/mu-plugins/asc-dev-mail-sink.php" \
		"wordpress:$WP_ROOT/wp-content/mu-plugins/asc-dev-mail-sink.php" >/dev/null 2>&1
	in_web chown www-data:www-data "$WP_ROOT/wp-content/mu-plugins/asc-dev-mail-sink.php"

	if ! wp_cli core is-installed >/dev/null 2>&1; then
		# The password is generated and stored inside the state volume (outside
		# the web root); it never passes through this script's output.
		compose run --rm -T cli sh -c "
			set -e
			umask 077
			head -c 32 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | cut -c1-24 > '$STATE/admin-password'
			wp core install --url='$SITE_URL' --title='AI Site Connector Dev' \
				--admin_user='$ADMIN_USER' --admin_password=\"\$(cat '$STATE/admin-password')\" \
				--admin_email=dev-admin@example.invalid --skip-email
		" >/dev/null
		wp_cli option update blog_public 0 >/dev/null
		wp_cli rewrite structure '/%postname%/' >/dev/null
		note "installed WordPress at $SITE_URL (admin user: $ADMIN_USER)"
	fi
	cmd_status
}

# Every installed plugin file must match the artifact byte for byte.
verify_install() {
	local zip="$1" dir="$WORK_DIR/verify"
	rm -rf "$dir"
	mkdir -p "$dir/unzipped"
	unzip -q "$zip" -d "$dir/unzipped"
	(cd "$dir/unzipped/$PLUGIN_SLUG" && find . -type f -print0 | xargs -0 shasum -a 256) | LC_ALL=C sort > "$dir/expected"
	in_web sh -c "cd '$WP_ROOT/wp-content/plugins/$PLUGIN_SLUG' && find . -type f -print0 | xargs -0 sha256sum" | LC_ALL=C sort > "$dir/actual"
	if ! diff -u "$dir/expected" "$dir/actual" > "$dir/diff"; then
		head -40 "$dir/diff" >&2
		die "installed files do not match the artifact"
	fi
	note "verified $(wc -l < "$dir/expected" | tr -d ' ') installed files against the artifact"
}

# Dump with the MySQL container's own client: the CLI image's MariaDB client
# cannot authenticate against MySQL 8 (caching_sha2_password without TLS).
# Prints the backup path; exits non-zero (nothing changed yet) on failure.
backup_db() {
	local label="$1" file
	file="$STATE/backups/$(date -u +%Y%m%dT%H%M%SZ)-$label.sql"
	compose exec -T db sh -c 'MYSQL_PWD=wordpress mysqldump --single-transaction --no-tablespaces -uwordpress wordpress' \
		| in_web sh -c "cat > '$file' && chown www-data:www-data '$file'" \
		|| die "database backup failed; the site was not changed"
	in_web test -s "$file" || die "database backup $file is empty; the site was not changed"
	in_web sh -c "ls -1t '$STATE'/backups/*.sql 2>/dev/null | tail -n +$((KEEP_BACKUPS + 1)) | xargs -r rm -f"
	echo "$file"
}

cmd_restore_db() {
	local file="${1:-}"
	[ -n "$file" ] || die "usage: bin/dev-site.sh restore-db $STATE/backups/FILE.sql --yes-overwrite-dev-db"
	[ "${2:-}" = "--yes-overwrite-dev-db" ] \
		|| die "restore-db replaces the whole dev database. Re-run with --yes-overwrite-dev-db."
	require_running
	in_web test -s "$file" || die "no such backup: $file"
	local safety
	safety="$(backup_db "pre-restore")"
	in_web cat "$file" | compose exec -T db sh -c 'MYSQL_PWD=wordpress mysql -uwordpress wordpress' \
		|| die "restore failed; the pre-restore snapshot is $safety"
	note "restored $file (the previous database is saved as $safety). Plugin files were not changed."
}

install_artifact() {
	wp_cli plugin install "$1" --force --activate >&2
}

cmd_deploy() {
	local ref="" release="" allow_unmerged=0
	while [ $# -gt 0 ]; do
		case "$1" in
			--allow-unmerged) allow_unmerged=1 ;;
			--release)
				release="${2:-}"
				[ -n "$release" ] || die "--release needs a tag, e.g. --release v0.12.1"
				shift
				;;
			-*) die "unknown deploy option: $1" ;;
			*)
				[ -z "$ref" ] || die "deploy takes one REF"
				ref="$1"
				;;
		esac
		shift
	done
	require_running
	command -v jq >/dev/null 2>&1 || die "jq is required"
	make_work_dir

	local sha source zip expected_digest
	git -C "$ROOT_DIR" fetch --quiet --tags origin
	if [ -n "$release" ]; then
		[ -z "$ref" ] || die "pass either REF or --release, not both"
		sha="$(git -C "$ROOT_DIR" rev-parse --verify "refs/tags/$release^{commit}")" || die "unknown tag $release"
		gh release download "$release" -R "$RELEASE_REPO" -p '*.zip' -D "$WORK_DIR/release" >&2
		zip="$(find "$WORK_DIR/release" -name '*.zip' | head -1)"
		[ -n "$zip" ] || die "release $release has no ZIP asset"
		expected_digest="$(gh release view "$release" -R "$RELEASE_REPO" --json assets \
			--jq '.assets[] | select(.name | endswith(".zip")) | .digest' | head -1)"
		if [ -n "$expected_digest" ] && [ "sha256:$(sha256_file "$zip")" != "$expected_digest" ]; then
			die "downloaded asset does not match GitHub's recorded digest $expected_digest"
		fi
		source="release $release"
	else
		ref="${ref:-origin/main}"
		sha="$(git -C "$ROOT_DIR" rev-parse --verify "$ref^{commit}")" || die "unknown ref $ref"
		if git -C "$ROOT_DIR" merge-base --is-ancestor "$sha" origin/main; then
			source="git $ref"
		elif [ "$allow_unmerged" -eq 1 ]; then
			source="UNMERGED $ref"
		else
			die "$sha is not on origin/main. Deploy merged code, or pass --allow-unmerged for a labelled candidate."
		fi
		# Build from the commit itself, never from the working tree. The ceiling
		# stops the build script from finding an enclosing repository when
		# TMPDIR happens to live inside a checkout.
		mkdir -p "$WORK_DIR/src"
		git -C "$ROOT_DIR" archive --format=tar "$sha" | tar -x -C "$WORK_DIR/src"
		zip="$(GIT_CEILING_DIRECTORIES="$WORK_DIR" "$WORK_DIR/src/bin/build-release-zip.sh" | tail -n1)"
	fi

	local zip_sha version artifact backup previous now record
	zip_sha="$(sha256_file "$zip")"
	version="$(unzip -p "$zip" "$PLUGIN_SLUG/$PLUGIN_SLUG.php" \
		| grep -m1 -E '^[[:space:]]*\*[[:space:]]*Version:' \
		| sed -E 's/.*Version:[[:space:]]*//' | tr -d '[:space:]')"
	[ -n "$version" ] || die "could not read the plugin version from $zip"

	artifact="$STATE/artifacts/${sha}-${zip_sha:0:12}.zip"
	compose cp "$zip" "wordpress:$artifact" >/dev/null 2>&1
	in_web chown www-data:www-data "$artifact"

	backup="$(backup_db "pre-${sha:0:12}")"
	previous="$(read_state current.json)"
	install_artifact "$artifact"
	verify_install "$zip"

	now="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
	record="$(jq -cn \
		--arg sha "$sha" --arg source "$source" --arg version "$version" \
		--arg artifact "$artifact" --arg zip_sha256 "$zip_sha" --arg at "$now" \
		--arg backup "$backup" --arg url "$SITE_URL" \
		'{action: "deploy", sha: $sha, source: $source, version: $version, artifact: $artifact,
		  zip_sha256: $zip_sha256, deployed_at: $at, db_backup: $backup, url: $url}')"
	printf '%s\n' "$record" | append_log
	if [ -n "$previous" ]; then
		jq -n --argjson cur "$record" --argjson prev "$previous" '$cur + {previous: ($prev | del(.previous))}' | write_state current.json
	else
		printf '%s\n' "$record" | write_state current.json
	fi
	note "deployed $PLUGIN_SLUG $version from $source ($sha) to $SITE_URL"
	cmd_status
}

cmd_rollback() {
	require_running
	command -v jq >/dev/null 2>&1 || die "jq is required"
	make_work_dir

	local current previous artifact zip_sha backup now record
	current="$(read_state current.json)"
	[ -n "$current" ] || die "nothing has been deployed yet"
	previous="$(printf '%s' "$current" | jq -c '.previous // empty')"
	[ -n "$previous" ] || die "no previous deployment is recorded"
	artifact="$(printf '%s' "$previous" | jq -r .artifact)"
	zip_sha="$(printf '%s' "$previous" | jq -r .zip_sha256)"

	compose cp "wordpress:$artifact" "$WORK_DIR/previous.zip" >/dev/null 2>&1 || die "previous artifact $artifact is missing"
	[ "$(sha256_file "$WORK_DIR/previous.zip")" = "$zip_sha" ] || die "previous artifact checksum changed; refusing to install it"

	backup="$(backup_db "pre-rollback")"
	install_artifact "$artifact"
	verify_install "$WORK_DIR/previous.zip"

	now="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
	record="$(printf '%s' "$previous" | jq -c --arg at "$now" --arg backup "$backup" \
		--arg from "$(printf '%s' "$current" | jq -r .sha)" \
		'del(.previous) + {action: "rollback", deployed_at: $at, db_backup: $backup, rolled_back_from: $from}')"
	printf '%s\n' "$record" | append_log
	jq -n --argjson cur "$record" --argjson prev "$current" '$cur + {previous: ($prev | del(.previous))}' | write_state current.json
	note "rolled back to $(printf '%s' "$record" | jq -r '.version + " (" + .sha + ")"'). The database was not restored; pre-rollback snapshot: $backup"
	cmd_status
}

cmd_status() {
	if ! is_running; then
		echo "Dev site: not running. Start it with: bin/dev-site.sh up"
		return 0
	fi
	echo "Dev site:     $SITE_URL  (wp-admin: $SITE_URL/wp-admin/, user $ADMIN_USER)"
	compose run --rm -T cli sh -c "
		echo \"Environment:  \$(wp eval 'echo wp_get_environment_type();') · search engines discouraged: \$(wp option get blog_public | sed 's/^0\$/yes/;s/^1\$/NO/')\"
		echo \"WordPress:    \$(wp core version) · PHP \$(wp eval 'echo PHP_VERSION;') · DB \$(wp eval 'global \$wpdb; echo \$wpdb->db_server_info();')\"
		if wp plugin is-installed $PLUGIN_SLUG; then
			echo \"Plugin:       $PLUGIN_SLUG \$(wp plugin get $PLUGIN_SLUG --field=version) (\$(wp plugin get $PLUGIN_SLUG --field=status))\"
		else
			echo 'Plugin:       not installed (bin/dev-site.sh deploy)'
		fi
	" 2>/dev/null
	local current
	current="$(read_state current.json)"
	if [ -n "$current" ]; then
		printf '%s' "$current" | jq -r '"Deployed:     \(.version) from \(.source) · \(.sha)\n              zip sha256 \(.zip_sha256) · \(.action) at \(.deployed_at)"
			+ (if .previous then "\nPrevious:     \(.previous.version) from \(.previous.source) · \(.previous.sha)" else "" end)'
	else
		echo "Deployed:     nothing recorded yet"
	fi
}

cmd_seed() {
	require_running
	compose cp "$ROOT_DIR/bin/dev-site/seed.php" "wordpress:$STATE/seed.php" >/dev/null 2>&1
	in_web chown www-data:www-data "$STATE/seed.php"
	wp_cli eval-file "$STATE/seed.php"
}

cmd_with_admin() {
	require_running
	if [ "${1:-}" = "--" ]; then
		shift
	fi
	[ $# -gt 0 ] || die "usage: bin/dev-site.sh with-admin -- COMMAND [ARGS...]"
	local password
	password="$(in_web cat "$STATE/admin-password")"
	ASC_DEV_URL="$SITE_URL" ASC_DEV_ADMIN_USER="$ADMIN_USER" ASC_DEV_ADMIN_PASSWORD="$password" "$@"
}

cmd_destroy() {
	[ "${1:-}" = "--yes-destroy-dev-data" ] \
		|| die "destroy deletes the dev database, uploads, artifacts and deployment history. Re-run with --yes-destroy-dev-data."
	compose --profile cli down -v
}

usage() {
	awk 'NR > 1 && /^#/ { sub(/^# ?/, ""); print; next } NR > 1 { exit }' "${BASH_SOURCE[0]}"
}

command="${1:-}"
if [ $# -gt 0 ]; then
	shift
fi
case "$command" in
	up) cmd_up ;;
	deploy) cmd_deploy "$@" ;;
	status) cmd_status ;;
	rollback) cmd_rollback ;;
	seed) cmd_seed ;;
	wp)
		require_running
		wp_cli "$@"
		;;
	with-admin) cmd_with_admin "$@" ;;
	backups)
		require_running
		in_web sh -c "ls -lt '$STATE/backups/' | tail -n +2"
		;;
	restore-db) cmd_restore_db "$@" ;;
	mail)
		require_running
		in_web sh -c "tail -n 50 '$STATE/mail.log' 2>/dev/null || echo '(no mail captured yet)'"
		;;
	debug-log)
		require_running
		in_web sh -c "tail -n 100 '$STATE/debug.log' 2>/dev/null || echo '(debug log is empty)'"
		;;
	logs) compose logs -f --tail=100 wordpress ;;
	stop) compose stop ;;
	destroy) cmd_destroy "$@" ;;
	"" | -h | --help | help) usage ;;
	*) die "unknown command: $command (see bin/dev-site.sh --help)" ;;
esac
