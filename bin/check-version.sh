#!/usr/bin/env bash
# Verify every canonical version field agrees.
#
#   bin/check-version.sh            # header == constant == readme Stable tag == MCP example package.json
#   bin/check-version.sh --release  # also: CHANGELOG.md "## [X.Y.Z] - YYYY-MM-DD" and readme.txt "= X.Y.Z =" entries
#   bin/check-version.sh --release --expect X.Y.Z   # also: equals the tag being released
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
RELEASE=0
EXPECT=""
while [ $# -gt 0 ]; do
	case "$1" in
		--release) RELEASE=1 ;;
		--expect) EXPECT="${2:-}"; shift ;;
		*) echo "Unknown argument: $1" >&2; exit 2 ;;
	esac
	shift
done

fail=0
header="$(grep -E '^[[:space:]]*\*[[:space:]]*Version:' "$ROOT_DIR/ai-site-connector.php" | head -1 | sed -E 's/.*Version:[[:space:]]*//' | tr -d '[:space:]')"
constant="$(grep -E "define\(\s*'AI_SITE_CONNECTOR_VERSION'" "$ROOT_DIR/ai-site-connector.php" | sed -E "s/.*'AI_SITE_CONNECTOR_VERSION',[[:space:]]*'([^']+)'.*/\1/")"
stable="$(grep -E '^Stable tag:' "$ROOT_DIR/readme.txt" | sed -E 's/Stable tag:[[:space:]]*//' | tr -d '[:space:]')"
package="$(sed -nE 's/^[[:space:]]*"version":[[:space:]]*"([^"]+)".*/\1/p' "$ROOT_DIR/examples/mcp-server/package.json" | head -1)"

echo "plugin header Version:        ${header:-<missing>}"
echo "AI_SITE_CONNECTOR_VERSION:    ${constant:-<missing>}"
echo "readme.txt Stable tag:        ${stable:-<missing>}"
echo "examples/mcp-server version:  ${package:-<missing>}"

if [ -z "$header" ]; then
	echo "Could not read the plugin header version." >&2
	exit 1
fi
for pair in "AI_SITE_CONNECTOR_VERSION:$constant" "readme.txt Stable tag:$stable" "examples/mcp-server/package.json:$package"; do
	name="${pair%%:*}"
	value="${pair#*:}"
	if [ "$value" != "$header" ]; then
		echo "MISMATCH: $name is '$value', plugin header is '$header'." >&2
		fail=1
	fi
done

if [ -n "$EXPECT" ] && [ "$EXPECT" != "$header" ]; then
	echo "MISMATCH: expected version '$EXPECT' (tag), plugin header is '$header'." >&2
	fail=1
fi

if [ "$RELEASE" -eq 1 ]; then
	escaped="${header//./\\.}"
	if ! grep -qE "^## \[${escaped}\] - [0-9]{4}-[0-9]{2}-[0-9]{2}$" "$ROOT_DIR/CHANGELOG.md"; then
		echo "MISSING: CHANGELOG.md heading '## [$header] - YYYY-MM-DD'." >&2
		fail=1
	fi
	if ! grep -qE "^= ${escaped} =$" "$ROOT_DIR/readme.txt"; then
		echo "MISSING: readme.txt changelog entry '= $header ='." >&2
		fail=1
	fi
fi

if [ "$fail" -ne 0 ]; then
	exit 1
fi
echo "Version fields consistent: $header"
