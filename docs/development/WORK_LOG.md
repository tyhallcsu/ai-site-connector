# Work log

Concise, dated, evidence-backed. Newest first.

## 2026-10-05 — M1 merged; #59 closed; M2 (#63) in review

- PR #76 squash-merged → `c7f689b`. CI run 37338939083 on head `c166622`:
  17/17 green; logs show PHPUnit `OK (40 tests)` and `integration: 34 passed`
  on WP latest, 5.6, 6.5, 6.8, 6.9. Closed #67 #68 #69 #71 #72; progress
  comment on #75.
- Second-pass review (adversarial reviewer subagent, read-only) of #76: no
  authz/disclosure findings; 5 P2 + 3 P3 correctness findings, all fixed with
  regression tests before merge (template-variable mangling by
  `sanitize_text_field`, empty-table redirect masking, redirect pagination,
  Redirection regex flag, stale og_image ID, unverified partial writes,
  vacuous anon assertion, `public` route flag).
- #59: re-verified no unique work on `claude/loving-bassi-fe3679` (evidence in
  the issue), deleted the branch, closed #59. Head `efca027` remains
  reachable via PR #11.
- M2 content inventory: new `AI_Site_Connector_Content_Inventory`. Found that
  WordPress stores `post_modified_gmt = 0000-00-00` on never-published drafts,
  so GMT-column date filters misplace drafts; inventory filters on the local
  column and derives GMT for output. Local: integration 43/43, smoke passed.
- Follow-up: align older export endpoints with the inventory's conventions
  (including the zero-GMT draft dates).

## 2026-10-05 — M0 merged; M1 (PR #76) reconciliation

- PR #78 (M0) squash-merged → `2dd96bc`. CI on head `4839e11`: all 17 jobs
  green; logs confirm `OK (40 tests, 109 assertions)` and
  `integration: 3 passed` on WP latest/5.6/6.5/6.8/6.9.
- A parallel session merged PR #77 (README artwork, docs only) into main at
  `ee05bd6` during M0. No conflict; merged main into the #76 branch (merge,
  not rebase, to avoid force-pushing a shared PR branch).
- PR #76 review findings fixed:
  - `redirects()`: `if/elseif` chain meant an active Rank Math with no table
    hid Redirection data; now every present plugin is tried in order and
    `data_unavailable` lists the skipped ones. Yoast Premium path ignored
    limit/offset. Added `total`, `enabled`, `id`, `plugin` per row; Rank Math
    multi-source redirects expand to one row per source; limit capped 1..1000.
  - SEO abstraction: separate read/write maps. Rank Math robots is a
    serialized array (read normalised to a `noindex` flag; previously cast to
    `"Array"`); `noindex` is now read-only everywhere; AIOSEO 4 reads from
    `aioseo_posts` and refuses writes (legacy meta writes were silently
    ignored by AIOSEO 4); URL fields validated; per-post `edit_post` check
    (also for dry runs, which disclose current values); values `wp_slash`ed.
  - `page-builder`: `post_ids` capped at 100 (REST `maxItems`, 400 beyond),
    per-post `read_post` check, multisite network plugins, malformed meta safe.
  - `self-test`: adds export/temp dir checks and caller capabilities (#72
    criteria), `overall` field, real SEO dry-run against an existing post with
    before/after meta comparison, no filesystem paths in messages.
  - `rest-routes`: arg metadata (type/required/enum/description, never
    defaults), `?namespace=` filter, empty method names from malformed
    handlers dropped (found by a new test).
  - Exposed as MCP tools (`wp_self_test`, `wp_rest_routes`, `wp_page_builder`,
    `wp_redirects`) with MCP-spec `isError` results, and WP-CLI commands
    (`mcp-self-test`, `routes`, `page-builder`, `redirects`).
- Local evidence: integration 30 passed / 0 failed; runtime smoke passed incl.
  WP-CLI JSON/CSV checks; PHPUnit OK (40); phpcs 0 violations; security-grep
  clean; package smoke passed.

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
