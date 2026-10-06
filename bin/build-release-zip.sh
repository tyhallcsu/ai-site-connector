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

# Package tracked files only, so ignored or untracked local files (editor
# cruft, hook logs, CI scratch files) can never reach the ZIP: copy the
# tracked tree (working-copy contents) to a temp dir, then apply the exclude
# list below to that copy.
SOURCE_DIR="$ROOT_DIR"
if git -C "$ROOT_DIR" rev-parse --is-inside-work-tree >/dev/null 2>&1; then
	TRACKED_DIR="$(mktemp -d)"
	trap 'rm -rf "$TRACKED_DIR"' EXIT
	git -C "$ROOT_DIR" ls-files -z \
		| rsync -a --from0 --files-from=- "$ROOT_DIR/" "$TRACKED_DIR/"
	SOURCE_DIR="$TRACKED_DIR"
fi

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
	--exclude='scripts/runtime-test-local.sh' \
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
	--exclude='/handoff.md' \
	--exclude='/docs/development/' \
	"$SOURCE_DIR/" "$STAGE_DIR/"

# Byte-reproducible archive (#101): the same commit gives the same sha256 on
# any machine, so a published asset can be checked by rebuilding its tag.
# Every entry gets the commit time, normalised modes, a fixed (C-locale)
# order, no extra attributes (-X) and UTC DOS timestamps.
EPOCH="${SOURCE_DATE_EPOCH:-}"
if [ -z "$EPOCH" ] && git -C "$ROOT_DIR" rev-parse --is-inside-work-tree >/dev/null 2>&1; then
	EPOCH="$(git -C "$ROOT_DIR" log -1 --format=%ct 2>/dev/null || true)"
fi
if [ -z "$EPOCH" ]; then
	# Not a checkout (e.g. a git archive tree): use the newest file time.
	EPOCH="$(find "$STAGE_DIR" -type f -exec stat -c %Y {} + 2>/dev/null || find "$STAGE_DIR" -type f -exec stat -f %m {} +)"
	EPOCH="$(printf '%s\n' "$EPOCH" | sort -n | tail -n1)"
fi
STAMP="$(date -u -d "@$EPOCH" +%Y%m%d%H%M.%S 2>/dev/null || date -u -r "$EPOCH" +%Y%m%d%H%M.%S)"
find "$STAGE_DIR" -type d -exec chmod 755 {} +
find "$STAGE_DIR" -type f -perm -u+x -exec chmod 755 {} +
find "$STAGE_DIR" -type f ! -perm -u+x -exec chmod 644 {} +
find "$STAGE_DIR" -exec env TZ=UTC touch -h -t "$STAMP" {} +

(
	cd "$BUILD_DIR"
	find ai-site-connector -print | LC_ALL=C sort | TZ=UTC zip -q -X -@ "$(basename "$ZIP_PATH")"
)

echo "$ZIP_PATH"
