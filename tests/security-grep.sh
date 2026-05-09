#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

fail=0

patterns=(
	'\beval\s*\('
	'\bshell_exec\s*\('
	'\bexec\s*\('
	'\bpassthru\s*\('
	'\bsystem\s*\('
	'\bproc_open\s*\('
	'\bpopen\s*\('
	'\bassert\s*\('
	'\bcreate_function\s*\('
	'\bbase64_decode\s*\('
)

for pattern in "${patterns[@]}"; do
	hits="$(
		grep -rn \
			--exclude-dir='.git' \
			--exclude-dir='vendor' \
			--exclude-dir='build' \
			--exclude-dir='dist' \
			--include='*.php' \
			-E "$pattern" . || true
	)"
	if [ -n "$hits" ]; then
		echo "FORBIDDEN PHP function pattern: $pattern"
		echo "$hits"
		fail=1
	fi
done

for path in ".env" "connection-pack.json"; do
	if git ls-files | grep -E "(^|/)${path}\$" >/dev/null; then
		echo "FORBIDDEN file committed: $path"
		fail=1
	fi
done

for glob in '*credentials*' '*_secret*' '*secret_*'; do
	matches="$(git ls-files "$glob" 2>/dev/null || true)"
	if [ -n "$matches" ]; then
		echo "FORBIDDEN credential-like file(s): $matches"
		fail=1
	fi
done

if grep -rn \
	--exclude-dir='.git' \
	--exclude-dir='vendor' \
	--exclude-dir='build' \
	--exclude-dir='dist' \
	--binary-files=without-match \
	-E -- '-----BEGIN [A-Z ]*PRIVATE KEY-----' .; then
	echo "FORBIDDEN: private key material committed"
	fail=1
fi

if grep -rn --include='*.json' --include='*.yml' --include='*.yaml' --include='*.md' \
	--exclude-dir='.git' \
	--exclude-dir='vendor' \
	--exclude-dir='build' \
	--exclude-dir='dist' \
	-E '"application_password"\s*:\s*"[A-Za-z0-9 ]{20,}"' . \
	| grep -v 'xxxx xxxx xxxx xxxx xxxx xxxx' \
	| grep -v 'REPLACE_WITH_GENERATED_APP_PASSWORD' \
	| grep -v 'DISPLAY_ONCE_ONLY'; then
	echo "FORBIDDEN: looks like a real Application Password committed in a doc/example"
	fail=1
fi

exit "$fail"
