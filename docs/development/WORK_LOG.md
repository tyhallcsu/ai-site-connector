# Work log

Concise, dated, evidence-backed. Newest first.

## 2026-10-05 — #89, #90, #87 merged; 0.12.0 release prep

- #89 (export coverage) → `1098ee4`; #90 (library-wide duplicate scan,
  closes #88) → `c255de3` after review (1 P1 visibility-after-permission-
  change + 7 P2 + 3 P3 fixed); #87 (M7 safe content update, closes #70) →
  `d378e50` after four reviews (31 findings, ledger
  `docs/development/M7_REVIEW.md`). CI on each final head green on WP 5.6,
  6.5, 6.8, 6.9, 7.0, 7.1.2 (73/73, 86/86).
- No open issues or PRs remain.
- 0.12.0 local gates (WP 7.1.2): versions consistent, PHPUnit OK (40),
  phpcs 0, security-grep, package smoke, actionlint, integration 86/86,
  clean install + v0.11.0 → v0.12.0 via v0.11.0's updater (exit 0).
- Answered a question from the celememorate.com maintainer session about
  updating the plugin there: no rollout constraint from this session;
  live-site updates require that session's user's authorization.

## 2026-10-05 — v0.11.0 released; M7 draft #87; export coverage

- PR #86 squash-merged → `6645155`; main CI 19/19 success (check-runs API)
  before tagging. Tag `v0.11.0` → `6645155`; release run 37350399627 success.
  Published asset verified: sha256 `95b337b1…86f3` OK, versions 0.11.0, clean
  contents (no M7), clean install + upgrade from published v0.10.0 (exit 0).
  Availability comments on #64 #65 #66 #73 #74 #75.
- M7 checkpoint pushed: draft PR #87 (`ad4a914`) with
  `docs/development/M7_REVIEW.md` mapping all review findings.
- Export coverage: index entries now carry `complete`/`scope`/`limitations`
  so partial scans are never presented as complete audits; README states the
  inventory is published-only, not a full-site inventory. Opened #88
  (cross-window duplicate detection, with fixture). Local 72/72.

## 2026-10-05 — M6 merged; 0.11.0 release prep; M7 redesign under review

- PR #85 (M6, #75) squash-merged → `d81b62d`; CI run 37349217489 on
  `f634b91`: integration 71/71 on WP 5.6–7.1.2. #75 closed. The WP 5.6 row
  exposed that WordPress < 5.7 returns `rest_pre_dispatch` errors from
  `dispatch()` unconverted; MCP `dispatch_checked()` would fatal on them —
  fixed, and the test harness normalises the same way.
- 0.11.0 local gates (WP 7.1.2): check-version OK, PHPUnit OK (40), phpcs 0,
  security-grep clean, package smoke OK, actionlint OK, integration 71/71,
  ZIP clean install + v0.10.0 → v0.11.0 via v0.10.0's own updater OK.
- M7 (#70): first draft review found 2 P1 + 6 P2 + 3 P3; redesigned (single
  wp_update_post after meta/terms, sanitize-db pre-computation and
  untouched-column refusal, column guard with exact `$wpdb` restore,
  rollback re-validation and interrupted-snapshot recovery, option-per-
  snapshot storage, strict typing, public REST-enabled targets). Mutation
  checks confirmed the safety tests fail when guards are removed. Worktree
  suite 79/79; second-pass review of the redesign running.

## 2026-10-05 — M5 merged; M6 (#75 disable/enable) in review

- PR #84 (M5) squash-merged → `8dbb1d0`; CI run 37346467734 on `3c65437`:
  integration 69/69 on WP 5.6–7.1.2. Closed #73 #74.
- M6 site-wide switch. Second-pass review: 2 P1 — WordPress matches REST
  routes case-insensitively (`'@^' . $route . '$@i'`, verified in WP 7.1.2
  source) so a case-sensitive gate let `/AI-Site-Connector/v1/mcp` through,
  and MCP tools that dispatch straight to `/wp/v2` had no switch check of
  their own; plus blocked requests stamped as "last successful request", a
  misleading CLI message (Application Passwords still work on core routes),
  a filter able to override the switch, tests that could leave the site
  disabled, and multisite scope not named. All fixed with regression tests
  (mixed-case REST + real-HTTP `?rest_route=` MCP probes). Existing
  app-password route scopes are an allowlist, so case variants fail closed
  there (no bypass).
- Local port 8765 was held by another project's server (not ours, left
  alone); local smoke now uses 8775. One local smoke run failed at
  `ai-connector status` and did not reproduce in three subsequent runs.

## 2026-10-05 — M4 merged; M5 (#73/#74) in review

- PR #83 (M4, #66) squash-merged → `c270434`; CI run 37345095645 on
  `983fc3c`: integration 62/62 on WP 5.6–7.1.2. #66 closed.
- M5 export bundle: first pass 67/67 locally (CLI export twice → identical
  bytes). `generate()` performs a loopback HTTP probe, so the bundle uses a
  new side-effect-free `Diagnostics::detected_plugins()` instead.
- Second-pass review: 7 P2 + 2 P3, all fixed with tests — recursive
  volatile-key stripping deleted real nested data (route args named
  `offset`/`limit`); self-test manifest varied by admin user / PHP process
  user (now portable checks only); paginated sections could scan a whole
  large site in one request (now bounded by rows scanned); counters and
  items disagreed after overshoot; `--dir` writer could leave a mixed old/new
  directory and exit 0 on failures (now staged + moved, stale manifests
  removed, non-zero exit); exception text could carry server paths into the
  committed index; drafts/private/protected excerpts in the "commit me"
  inventory (now published only, protected excerpts + excerpt-derived SEO
  description blanked); CLI without an admin `--user` exported near-empty
  manifests; duplicate scan window tied to `max_items`. Local 69/69.

## 2026-10-05 — M3 merged; M4 (#66) in review

- PR #82 (M3) squash-merged → `4f44c85`; CI run 37343885813 on `a5f154e`:
  integration 54/54 on WP 5.6, 6.5, 6.8, 6.9, 7.0, 7.1.2. Closed #64 #65.
- M4 link scanner: first pass 59/59 locally; second-pass review found 4 P2 +
  3 P3, all fixed with tests: the `<a …>(.*?)</a>` regex hit PCRE's backtrack
  limit on large/malformed content and silently returned no links (now a
  linear tag scan; PCRE failure is reported as `extract_failed`); unclosed
  anchors swallowed the next link; `data-href` matched; archives under a
  permalink front, `/page/N` and feeds were false "broken"; relative hrefs
  resolved against home instead of the source post; the first post bypassed
  `max_links`; totals counted other users' posts (inventory had the same
  issue — both now scope by author when the caller cannot edit others');
  CLI `--all` counters were last-page only; NUL bytes in upload paths.
  Local integration 62/62.

## 2026-10-05 — v0.10.0 released; M3 (#64/#65) in review

- PR #81 squash-merged → `9c07f10`. Main CI on `9c07f10`: 19/19 check runs
  `success` (verified via the check-runs API before tagging).
- Tagged `v0.10.0` → `9c07f10` (annotated tag object `bb4e1e3`). Release
  workflow run 37342852174: every gate step succeeded (version fields, tag on
  main, all CI checks, no existing release, build, package smoke, checksum,
  publish). Release: https://github.com/tyhallcsu/ai-site-connector/releases/tag/v0.10.0
  — stable (not prerelease), `releases/latest` = v0.10.0, assets
  `ai-site-connector-v0.10.0.zip` (237050 B) + `.zip.sha256`.
- Downloaded asset: `shasum -c` OK, sha256
  `2783dea6b5383c7dd566ac0cacc57715c3b7a94fc8b0a847e9433dcc873706dd`; embeds
  0.10.0 in header + constant; top-level entries match the allowlist; no
  tests/dev docs. `tests/zip-upgrade-smoke.sh` against the **published** ZIP:
  clean install OK; v0.9.1 → v0.10.0 via v0.9.1's own updater +
  `wp plugin update` OK.
- Release-availability comments posted on #63 #67 #68 #69 #71 #72 #75.
- M3 media audit + duplicates: local integration 52/52, then second-pass
  review (6 P2 + 2 P3, all fixed with regression tests): suffix-variant
  grouping of unrelated numbered series, duplicate `_wp_attached_file` rows
  creating self-duplicates, scaled/-N names defeating title/suspicious
  checks, disabled big-image threshold disabling the dimension check,
  per-window grouping now documented + `unhashed` list, primed post caches
  and lower scan/hash ceilings for non-admins, suspicious-name false
  positives, aligned attachment status sets. Local integration 54/54.

## 2026-10-05 — M2 merged; export consistency merged; 0.10.0 release prep

- PR #79 (M2, #63) squash-merged → `2949c89`; CI run 37339837499 on
  `806a464`: integration 43/43 on all WP rows. #63 closed.
- PR #80 (export consistency) squash-merged → `4d948e0`; CI run 37341039538
  on `be33996`: integration 46/46 on all WP rows. Tests verified
  red-before-green against the old `class-export.php`. The WP 5.6 row caught
  that `read_post` only maps inherit attachments to their parent on newer
  WordPress; attachment visibility is now an explicit rule.
- Smoke flake: piping WP-CLI CSV into `head` → SIGPIPE under pipefail.
  Outputs are now captured before inspection.
- WordPress stable is 7.1.2 (api.wordpress.org version-check); `readme.txt`
  said `Tested up to: 6.9`. Local smoke + integration pass on 7.1.2 →
  raised to 7.1; added a 7.0 compat row; smoke now logs the WP version.
- Release tooling: `bin/check-version.sh` (header, constant, readme, MCP
  example package, + CHANGELOG/readme entries with `--release`), new CI job
  `Release ZIP install + upgrade` (`tests/zip-upgrade-smoke.sh`), release
  workflow gated on version/tag-on-main/required CI checks/no existing
  release, publishes ZIP + `.sha256` with CHANGELOG notes. Checklist
  rewritten to match (it said `## [vX.Y.Z]` and `gh release create`, which
  contradicted the workflow). actionlint clean.
- Second-pass review of the release pipeline (adversarial reviewer): fixed
  all findings — the CI-gate step wrote `checks.tsv` into the workspace,
  which the rsync-based build would have shipped inside the published ZIP;
  the build now packages tracked files only (`git ls-files`; also dropped a
  local hook's `artifacts/evidence.log` from local builds) and package-smoke
  enforces a top-level allowlist. Release gate now requires every CI check
  run for the SHA (PHP/WP matrices included) plus key jobs present; strict
  "release not found" check + `overwrite_files: false`; version format
  validated and passed via env; upgrade test now goes through the previous
  release's own updater + `wp plugin update` (Plugin_Upgrader).
- Local release gates for 0.10.0 (WP 7.1.2): check-version OK; PHPUnit OK
  (40); phpcs 0; security-grep clean; package smoke passed; integration 46/46;
  ZIP clean install + upgrade v0.9.1 → v0.10.0 passed. Local ZIP sha256
  `eef0d3d8…69a9` (the published asset is rebuilt by the workflow).

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
