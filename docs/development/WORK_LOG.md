# Work log

Concise, dated, evidence-backed. Newest first.

## 2026-10-05 — Session bootstrap (M0)

State verified (`gh` + `git fetch`):

- `origin/main` = `e5f7ed2` (v0.9.1). Latest release v0.9.1 (2026-05-11).
- Open PR #76 (draft) on `feature/queue-wordpress-mcp-diagnostics-and-export-tools`,
  3 commits on top of `e5f7ed2`, all CI checks green on its head `d3d6f6b`.
- Open issues: #59, #63–#75. `main` has no branch protection; repo allows
  squash/merge/rebase, auto-merge disabled, delete-branch-on-merge on.
- No `handoff.md` existed in any case variant.

Findings:

1. **PHPUnit ran zero tests in CI.** `tests/phpunit/phpunit.xml` used
   `<directory>.</directory>` (default suffix `Test.php`) while files are named
   `test-class-*.php`. PHPUnit 9 prints "No tests executed!" and exits 0, so
   the PHPUnit job was green without testing anything. Fixed with
   `prefix="test-" suffix=".php"`; CI step now fails unless `OK (N tests` with N ≥ 1.
2. Running the suite exposed 3 latent failures — all test-harness bugs, not
   product bugs: WP_Mock defines `do_action`/`apply_filters` itself, so the
   bootstrap stubs and `userFunction('apply_filters')` never took effect; and
   `file:///etc/passwd` has no host, so it hits the malformed-URL gate before
   the scheme gate. Fixed via `WP_Mock::expectAction` / `WP_Mock::onFilter`
   and a hostful `ftp://` URL. Result: `OK (40 tests, 109 assertions)`.
3. Added `tests/integration/` — an in-WordPress suite run by
   `tests/runtime-smoke.sh` via `wp eval-file`, with a DB-fingerprint helper
   for read-only/dry-run invariants. Empty suite = failure.
4. `handoff.md` and `docs/development/` are excluded from the release ZIP
   (`bin/build-release-zip.sh`) and asserted absent by `tests/package-smoke.sh`.
5. `tests/runtime-smoke.sh` accepts `WP_DB_HOST=host:port` for local runs
   against a disposable MySQL container.

Local evidence (macOS, PHP 8.5, MySQL 8.0 in Docker on 127.0.0.1:33306):

- `composer test` → `OK (40 tests, 109 assertions)`
- `tests/runtime-smoke.sh` → passed; integration: 3 passed, 0 failed
