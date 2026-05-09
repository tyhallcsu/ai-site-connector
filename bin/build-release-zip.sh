#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
VERSION="${1:-}"

if [ -z "$VERSION" ]; then
	VERSION="$(
		grep -E '^[[:space:]]*\*[[:space:]]*Version:' "$ROOT_DIR/ai-site-connector.php" \
			| head -1 \
			| sed -E 's/.*Version:[[:space:]]*//' \
			| tr -d '[:space:]'
	)"
fi

if [ -z "$VERSION" ]; then
	echo "Could not resolve plugin version." >&2
	exit 1
fi

BUILD_DIR="$ROOT_DIR/build"
STAGE_DIR="$BUILD_DIR/ai-site-connector"
ZIP_PATH="$BUILD_DIR/ai-site-connector-v${VERSION}.zip"

rm -rf "$STAGE_DIR" "$ZIP_PATH"
mkdir -p "$STAGE_DIR"

rsync -a \
	--exclude='.git/' \
	--exclude='.github/' \
	--exclude='.gitignore' \
	--exclude='.DS_Store' \
	--exclude='node_modules/' \
	--exclude='vendor/' \
	--exclude='build/' \
	--exclude='dist/' \
	--exclude='bin/' \
	--exclude='tests/' \
	--exclude='*.zip' \
	--exclude='*.log' \
	--exclude='.env' \
	--exclude='.env.*' \
	--exclude='connection-pack.json' \
	--exclude='*-connection-pack.json' \
	--exclude='*.connection-pack.json' \
	--exclude='composer.json' \
	--exclude='composer.lock' \
	--exclude='phpcs.xml.dist' \
	--exclude='phpcs.xml' \
	--exclude='phpunit.xml' \
	--exclude='phpunit.xml.dist' \
	--exclude='assets/brand/*.png' \
	--exclude='TESTING_CHECKLIST.md' \
	"$ROOT_DIR/" "$STAGE_DIR/"

(
	cd "$BUILD_DIR"
	zip -qr "$(basename "$ZIP_PATH")" ai-site-connector
)

echo "$ZIP_PATH"
