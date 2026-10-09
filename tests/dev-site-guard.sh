#!/usr/bin/env bash
# bin/dev-site.sh must give up quickly, not block, when Docker is hung or
# down (#166). Uses a fake `docker` on PATH, so no Docker is needed.
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
FAKE="$(mktemp -d)"
trap 'rm -rf "$FAKE"' EXIT

fake_docker() {
	printf '#!/bin/sh\n%s\n' "$1" > "$FAKE/docker"
	chmod +x "$FAKE/docker"
}

# name, timeout, text the error must contain
expect_failure() {
	local start took out rc=0
	start="$(date +%s)"
	out="$(PATH="$FAKE:$PATH" ASC_DEV_DOCKER_TIMEOUT="$2" "$ROOT_DIR/bin/dev-site.sh" status 2>&1)" || rc=$?
	took=$(($(date +%s) - start))
	if [ "$rc" -eq 0 ]; then
		echo "FAIL $1: exit 0: $out" >&2
		exit 1
	fi
	if [ "$took" -gt 10 ]; then
		echo "FAIL $1: took ${took}s" >&2
		exit 1
	fi
	if ! printf '%s' "$out" | grep -q "$3"; then
		echo "FAIL $1: unexpected message: $out" >&2
		exit 1
	fi
	echo "ok   $1 (${took}s)"
}

fake_docker 'exec sleep 60'
expect_failure "hung daemon gives up and names the recovery steps" 2 "docs/development/DEV_SITE.md"
fake_docker 'echo "Cannot connect to the Docker daemon" >&2; exit 1'
expect_failure "stopped daemon fails at once" 2 "not running"
fake_docker 'exit 0'
expect_failure "non-numeric timeout is refused" abc "whole number"
echo "dev-site Docker guard passed."
