#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ZIP_PATH="$("$ROOT_DIR/bin/build-release-zip.sh" "${1:-}")"
LISTING="$(mktemp)"

unzip -Z1 "$ZIP_PATH" > "$LISTING"

require_file() {
	local path="$1"
	if ! grep -Eq "^${path}$" "$LISTING"; then
		echo "Release ZIP missing required file: $path" >&2
		exit 1
	fi
}

forbid_path() {
	local pattern="$1"
	if grep -Eq "$pattern" "$LISTING"; then
		echo "Release ZIP contains forbidden path matching: $pattern" >&2
		grep -E "$pattern" "$LISTING" >&2
		exit 1
	fi
}

require_file 'ai-site-connector/ai-site-connector.php'
require_file 'ai-site-connector/uninstall.php'
require_file 'ai-site-connector/includes/class-plugin.php'
require_file 'ai-site-connector/includes/class-roles.php'
require_file 'ai-site-connector/includes/class-user-manager.php'
require_file 'ai-site-connector/includes/class-application-passwords.php'
require_file 'ai-site-connector/includes/class-app-password-meta.php'
require_file 'ai-site-connector/includes/class-app-password-resolver.php'
require_file 'ai-site-connector/includes/class-connection-pack-token.php'
require_file 'ai-site-connector/includes/class-usage-tracker.php'
require_file 'ai-site-connector/includes/class-audit-webhook.php'
require_file 'ai-site-connector/includes/class-rest-controller.php'
require_file 'ai-site-connector/includes/class-audit-log.php'
require_file 'ai-site-connector/includes/class-audit-digest.php'
require_file 'ai-site-connector/includes/class-permissions.php'
require_file 'ai-site-connector/includes/class-diagnostics.php'
require_file 'ai-site-connector/includes/class-cache.php'
require_file 'ai-site-connector/includes/class-media.php'
require_file 'ai-site-connector/includes/class-export.php'
require_file 'ai-site-connector/includes/class-admin-page.php'
require_file 'ai-site-connector/includes/class-connection-formats.php'
require_file 'ai-site-connector/includes/class-updater.php'
require_file 'ai-site-connector/includes/class-backup-manager.php'
require_file 'ai-site-connector/includes/class-api-explorer.php'
require_file 'ai-site-connector/includes/class-onboarding.php'
require_file 'ai-site-connector/includes/class-mcp-server.php'
require_file 'ai-site-connector/includes/class-wp-cli.php'
require_file 'ai-site-connector/assets/admin.css'
require_file 'ai-site-connector/assets/admin.js'
require_file 'ai-site-connector/assets/brand/ai-site-connector-mark.svg'
require_file 'ai-site-connector/assets/brand/ai-site-connector-logo.svg'
require_file 'ai-site-connector/assets/brand/ai-site-connector-readme-banner.svg'
require_file 'ai-site-connector/scripts/diagnose-hosting-auth.sh'
require_file 'ai-site-connector/readme.txt'
require_file 'ai-site-connector/README.md'
require_file 'ai-site-connector/LICENSE'

forbid_path 'ai-site-connector/\.git'
forbid_path 'ai-site-connector/\.github/'
forbid_path 'ai-site-connector/bin/'
forbid_path 'ai-site-connector/scripts/runtime-test-local\.sh$'
forbid_path 'ai-site-connector/tests/'
forbid_path 'ai-site-connector/vendor/'
forbid_path 'ai-site-connector/node_modules/'
forbid_path 'ai-site-connector/build/'
forbid_path 'ai-site-connector/dist/'
forbid_path 'ai-site-connector/composer\.(json|lock)$'
forbid_path 'ai-site-connector/phpcs\.xml'
forbid_path 'ai-site-connector/TESTING_CHECKLIST\.md$'
forbid_path 'ai-site-connector/assets/brand/.*\.png$'
forbid_path 'ai-site-connector/(connection-pack\.json|[^/]+-connection-pack\.json|.*\.connection-pack\.json)$'
forbid_path 'ai-site-connector/\.env'

VERSION="$(
	grep -E '^[[:space:]]*\*[[:space:]]*Version:' "$ROOT_DIR/ai-site-connector.php" \
		| head -1 \
		| sed -E 's/.*Version:[[:space:]]*//' \
		| tr -d '[:space:]'
)"

if ! grep -q "Stable tag: ${VERSION}" "$ROOT_DIR/readme.txt"; then
	echo "readme.txt Stable tag does not match plugin version ${VERSION}." >&2
	exit 1
fi

echo "Release ZIP smoke test passed: $ZIP_PATH"
