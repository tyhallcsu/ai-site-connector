#!/usr/bin/env bash
#
# AI Site Connector — Bash sample agent.
#
# Reference client demonstrating the typical AI-agent flow against a
# WordPress site secured by this plugin.
#
# Requires: curl, jq (optional, for prettier output).
#
# Configuration:
#   WORDPRESS_SITE_URL              e.g. https://example.com
#   WORDPRESS_USERNAME              the AI user's login
#   WORDPRESS_APPLICATION_PASSWORD  the Application Password
#
# Usage:
#   ./sample-agent.sh
#   ./sample-agent.sh --dry-run
#   ./sample-agent.sh --json
#

set -euo pipefail

DRY_RUN=0
JSON_OUT=0
for arg in "$@"; do
	case "$arg" in
		--dry-run) DRY_RUN=1 ;;
		--json) JSON_OUT=1 ;;
		-h|--help)
			sed -n '/^# AI Site Connector/,/^$/p' "$0" | sed 's/^# \{0,1\}//'
			exit 0 ;;
		*) echo "Unknown arg: $arg" >&2; exit 2 ;;
	esac
done

env_or_die() {
	local name="$1"
	local val="${!name:-}"
	if [ -z "$val" ]; then
		echo "Missing env var: $name" >&2
		exit 2
	fi
	printf '%s' "$val"
}

SITE="$(env_or_die WORDPRESS_SITE_URL)"
SITE="${SITE%/}"
USER="$(env_or_die WORDPRESS_USERNAME)"
PASS="$(env_or_die WORDPRESS_APPLICATION_PASSWORD)"
REST="$SITE/wp-json"

# Aggregate JSON output if --json
declare -A RESULTS

emit() {
	local label="$1"; shift
	local body="$*"
	if [ "$JSON_OUT" = "1" ]; then
		RESULTS["$label"]="$body"
	else
		printf '\n=== %s ===\n%s\n' "$label" "$body"
	fi
}

call() {
	local method="$1"; shift
	local path="$1"; shift
	local url="$REST$path"
	if [ "$DRY_RUN" = "1" ]; then
		printf '{"_dry_run":true,"method":"%s","url":"%s"}' "$method" "$url"
		return
	fi
	curl -sS -X "$method" -u "$USER:$PASS" "$@" "$url"
}

# 1. Health
emit "health" "$(call GET /ai-site-connector/v1/health)"

# 2. List posts
emit "posts.list" "$(call GET /wp/v2/posts?per_page=5\&status=publish)"

# 3. Create draft
emit "posts.create" "$(call POST /wp/v2/posts \
	-H 'Content-Type: application/json' \
	--data '{"title":"AI Site Connector sample-agent draft (Bash)","content":"Hello from the Bash sample agent.","status":"draft"}')"

# 4. Upload a tiny PNG
PNG_B64='iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8/5+hHgAHggJ/PchI7wAAAABJRU5ErkJggg=='
if [ "$DRY_RUN" = "1" ]; then
	emit "media.upload" '{"_dry_run":true,"method":"POST","url":"'"$REST"'/wp/v2/media"}'
else
	TMP="$(mktemp --suffix=.png)"
	# shellcheck disable=SC2064
	trap "rm -f '$TMP'" EXIT
	printf '%s' "$PNG_B64" | base64 -d > "$TMP"
	emit "media.upload" "$(curl -sS -X POST -u "$USER:$PASS" \
		-H 'Content-Disposition: attachment; filename="sample-agent-pixel.png"' \
		-H 'Content-Type: image/png' \
		--data-binary "@$TMP" \
		"$REST/wp/v2/media")"
fi

if [ "$JSON_OUT" = "1" ]; then
	# Compose final JSON object from RESULTS map.
	first=1
	printf '{'
	for k in "${!RESULTS[@]}"; do
		if [ $first -eq 1 ]; then first=0; else printf ','; fi
		printf '"%s":%s' "$k" "${RESULTS[$k]}"
	done
	printf '}\n'
fi
