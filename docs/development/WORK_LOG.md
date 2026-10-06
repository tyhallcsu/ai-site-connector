# Work log

Concise, dated, evidence-backed. Newest first.

## 2026-10-06 — #131 decided; #125 ZIP size fixed (unreleased)

- Owner, on #131: the approved icon is "the newer one". That is the
  mint/cyan mark that has shipped since v0.12.2, so the artwork is
  unchanged.
- PR #158 → `aa39cd2` (closes #125, #131); CI run 37484091055 20/20;
  integration 117/117 on 6 WordPress builds.
  - The admin header uses `assets/ai-site-connector-mark-128.png` (7,593 B,
    from the mark's embedded 512px PNG) with `?ver=<version>`, so upgrades
    never show a cached image.
  - The build leaves out `assets/brand/`. ZIP: 1,154,500 → 312,795 B
    (69 files).
  - Package smoke enforces a 400 KiB ZIP and a 20 KiB mark.
- Not released; 0.13.1 waits for the owner.

## 2026-10-06 — Docker restarted; v0.13.0 on the dev site

- At the owner's request, Docker Desktop was restarted. `docker desktop
  restart` timed out. A graceful quit and TERM stopped the VM, but the
  backend (hung for 22 h) survived until it was force-killed. After a
  relaunch, engine 28.3.3 came up. The dev containers and another
  project's `uptime-kuma` restarted healthy on their own.
- `bin/dev-site.sh deploy --release v0.13.0`: 0.12.3 → 0.13.0 from
  `2395ea4`; zip sha256
  `189711e10977e835ca0aff54035bafb17c5bc5953534f9d1b12dabe26be12156`
  (same as the published asset); plugin active.
- `bin/dev-site.sh audit`: all tabs clean on desktop and phone.
- Headless text checks (no screenshots):
  - **#124:** Credentials → access preview rendered for `ai-agent`. Verdict "Depends": post access is unknown without a post ID.
  - **#106:** the live sign-in card is present.
  - **#127:** the Plugins row shows "Check for updates", "Up to date (v0.13.0)", Changelog and GitHub release.

## 2026-10-06 — v0.13.0 released

- PR #155 → `2395ea4`; PR CI run 37469725758 20/20 (ZIP install + upgrade
  v0.12.5 → v0.13.0; integration 116/116 on 6 WordPress builds); main CI run
  37470047823 20/20. Tag `v0.13.0` → `2395ea4`; release run 37471734711
  succeeded; stable; `releases/latest` = v0.13.0. Asset
  `ai-site-connector-v0.13.0.zip`: 1,154,500 B, sha256
  `189711e10977e835ca0aff54035bafb17c5bc5953534f9d1b12dabe26be12156`;
  checksum OK, embeds 0.13.0, 71 files, no dev or test paths.
- First release under the reproducible build (#101): rebuilding the tag
  from `git archive v0.13.0` gave the same sha256 on macOS (the asset itself was built on Linux CI).
- Shipped issues (#101 #106 #108 #109 #122 #123 #124 #127 #133)
  commented with the release link.
- Dev site still on v0.12.3: Docker Desktop is still hung (`docker ps`
  times out), so the deploy waits for an owner restart.

## 2026-10-06 — Feature batch (0.13.0 prep)

The owner listed the backlog features to build. Built in this order, one PR each, each squash-merged after a green CI run pinned to its head:

| Issue | PR → merge | Green CI run (head) | Notes |
|---|---|---|---|
| #109 | #146 → `a8b0506` | 37464007697 (`7cddc55`) | No behaviour change; the catalog contract test freezes the tool names |
| #101 | #147 → `60eeb18` | 37464255962 (`b7e3e2e`) | sha256 matched across macOS and Linux |
| #108 | #148 → `8d1b238` | 37465048676 (`a9b888c`) | The first run caught unregistered CLI commands |
| #123 | #150 → `5086c93` | 37465229426 (`a52480f`) | 21 MCP tools |
| #106 | #149 → `7618797` | 37465325105 (`d2dda18`) | The first run caught `has_action` used on an admin-only hook |
| #133 | #152 → `534e676` | 37466066601 (`adea419`) | Checked against Wordfence 9.0.2 source |
| #122 | #151 → `f22c386` | 37466136831 (`4955352`) | Merge conflict with #123 resolved, keeping both changes |
| #127 | #153 → `598054d` | 37466411471 (`a59b22a`) | — |
| #124 | #154 → `9c47a7e` | 37469211915 (`d669195`) | The first run failed 6/6 on one test regex; see below |

- #124: `Permissions::gate_reason()` is now the side-effect-free core of
  `require_permission()`, so the preview and enforcement share one order.
  PHPUnit permission tests unchanged (40/40). The first PR run failed on
  one assertion because WordPress's `selected()` adds its own leading
  space. Only the test was wrong; the fix was in the test.
- Main CI was green after every merge through `598054d`. Integration
  suite: 116 cases.
- #125 still waits for #131: the owner must name the approved icon file.

## 2026-10-06 — v0.12.5 released

- PR #144 → `6edeee1`; PR CI run 37462746859 20/20 (ZIP install + upgrade
  v0.12.4 → v0.12.5; integration 105/105 on 6 WordPress builds; both
  Multisite checks pass); main CI run 37463011527 20/20. Tag `v0.12.5` →
  `6edeee1`; release run 37463275191 succeeded; stable;
  `releases/latest` = v0.12.5. Asset `ai-site-connector-v0.12.5.zip`:
  1,139,204 B, sha256
  `6edea96318bb2d105fc6f109b938f4781ebf0455ae63419c3efefbca61a7b4be`;
  checksum OK, embeds 0.12.5, 70 files, no dev or test paths, fixes
  present. Shipped issues (#112 #129 #130 #132) commented.
- Open issues are now features, owner decisions or blocked: #131 (approved
  icon file?), #97, #99, and the backlog. The dev site stays on v0.12.3
  until Docker is restarted.

## 2026-10-06 — Multisite, bridge and docs fixes (0.12.5 prep)

- PR #141 → `f73220f` (closes #130, #132): CI 20/20, including the new
  "Multisite activation" job (two-site network; 9 checks on update
  reactivation and the rollback round trip; plugin ends active-network).
- PR #142 → `7c6d5a9` (closes #129): CI 20/20, integration 105/105; the
  multisite job confirms each site serves its own OpenAPI server URL.
- PR #143 → `ed0de94` (closes #112): CI 20/20; the bridge contract test
  passes in CI (2/2) and failed 2/2 against the old bridge locally
  (red before green).
- #128 closed as already fixed by #134 (v0.12.3); evidence on the issue.
  #131 is waiting for the owner to name the approved icon file.
- Dev site still on v0.12.3: Docker Desktop has been unresponsive since
  the disk-full episode and needs an owner restart.

## 2026-10-06 — v0.12.4 released

- PR #139 → `dba1914`; PR CI run 37398279040 19/19 (ZIP install +
  upgrade v0.12.3 → v0.12.4; integration 104/104 on 6 WordPress builds);
  main CI run 37398509090 19/19. Tag `v0.12.4` → `dba1914`; release run
  37398690817 succeeded; stable; `releases/latest` = v0.12.4. Asset
  `ai-site-connector-v0.12.4.zip`: 1,138,034 B, sha256
  `6c4a4e78ce8a70bf95816405ccad5b3cd0c904819ec139522c14cca5d2e5e74c`;
  checksum OK, embeds 0.12.4, 70 files, no dev paths, fixes present.
  Shipped issues commented.
- Dev deploy not done: Docker Desktop stopped responding (`docker ps`
  hangs) after the earlier disk-full episode. The deploy was stopped
  before it changed anything; dev stays on v0.12.3. Pending owner restart
  of Docker. Then: `deploy --release v0.12.4`, `audit`, and HTTP checks:
  `wp_get_post` with a nonexistent id must return isError/404 (#111),
  `post_type=pages` must return asc_unsupported_post_type (#118), and
  openapi.json `/content/snapshots/{id}` must have `id` in path (#120).

## 2026-10-06 — P2 batch from the second audit (0.12.4 prep)

- PR #137 → `6e02b3a` (closes #117, #111, #118): CI 19/19, integration
  101/101. The first run caught two test mistakes (simulated SEO plugin
  slug; `nav_menu_item` is REST-enabled since WP 5.9), fixed before merge.
- PR #138 → `c34af14` (closes #121, #120, #116, #113): CI 19/19 on the PR
  merged with main, integration 104/104 on 6 WordPress builds. The runtime
  smoke seeds settings and verifies the wipe.
- The local disk filled during this work (297 MiB free), blocking local
  runs. Work was pushed as drafts and verified by GitHub CI instead. Space
  later recovered (1.5 GiB free) without action from this session.

## 2026-10-05 — v0.12.3 released (P1 fixes #114, #119)

- PR #135 → `4bef824`; PR CI run 37380234124 19/19 (ZIP install + upgrade
  v0.12.2 → v0.12.3; integration 97/97 on 6 WordPress builds); main CI run
  37380505933 19/19. Tag `v0.12.3` → `4bef824`; release run 37380756899
  succeeded; stable; `releases/latest` = v0.12.3. Asset
  `ai-site-connector-v0.12.3.zip`: 1,135,602 B, sha256
  `01b973c29c426b6f366d34e7a22a042f1fdb020c45b4aeaba63ee5d2db594afe`;
  checksum OK, embeds 0.12.3, 70 files, no dev paths, both fixes present.
- Dev site, published asset: upgrade v0.12.2 → v0.12.3 (70 files verified).
  #114: an empty 0.12.0 backup dir was hidden from the list. The UI
  Rollback to 0.12.2 ran through the real handler, producing
  byte-identical files and audit started → roll-away backup → completed.
  The dev site was then restored to v0.12.3. #119: the generated Claude
  Desktop snippet URL `…/ai-site-connector/v1/mcp` answered HTTP
  `initialize` with 200.
- The first headless UI attempt timed out because the rollback forms sit
  in a collapsed `<details>` panel: a test-script issue, not a plugin
  defect. Evidence comments are on #114 and #119.

## 2026-10-05 — P1 fixes #114 and #119 (0.12.3 prep)

- PR #134 → `422f953` (closes #114, #119). PR CI run 37379827867 on
  `e66518f`: 19/19. Integration 97 passed / 0 failed on WP latest
  (PHP 8.3) and 5.6/6.5/6.8/6.9/7.0 (PHP 8.0); the 6 new cases inject copy
  failures, partial/empty/staging/tampered backups and a full rollback
  round trip, and initialize MCP through a generated snippet's route.
  PHPUnit 40 OK.
- Found while fixing: the old `pre_install` created the backup base dir
  non-recursively, so the first backup on a site without
  `wp-content/upgrade-backups` failed. Now created with `wp_mkdir_p()`.
  Backups made by 0.12.2 and earlier code during an upgrade can still be
  missing for that reason.
- Single-agent self-review (low-usage mode, no reviewer agent) added a
  symlinked-install refusal and an exact recovery message for a failed
  restore rename.

## 2026-10-05 — v0.12.2 released

- PR #115 → `72e268e`. PR CI run 37377009311 on head `f91972d`: 19/19.
  ZIP install + upgrade v0.12.1 → v0.12.2; integration 91/91 on 6 WordPress
  builds; PHPUnit 40. Main CI run 37377306710 on `72e268e`: 19/19.
- Tag `v0.12.2` → `72e268e`; release run 37377593413 succeeded; stable;
  `releases/latest` = v0.12.2. Asset `ai-site-connector-v0.12.2.zip`:
  1,132,005 B, sha256
  `826eba735e422335361d0cc114d373a0118ea2a186564e36c18ec08219084e8e`
  (checksum file verified). It embeds 0.12.2 and holds 70 files with no
  dev paths.
- Dev site: upgraded published v0.12.1 → published v0.12.2 (digest
  matched, 70 files verified); `audit` 22/22 clean; #102/#104/#105
  rechecked; CLI self-test 6/6. Shipped issues commented.
- Release body notes known issue #114 (pre-existing P1 rollback
  validation). ZIP size jump from #96's raster-backed SVGs filed as #125.
- Another session (essremodel) filed #111–#124 during this window,
  including P1s #114 and #119. Not touched here; no PRs open for them.

## 2026-10-05 — dev site, admin audit fixes, outage-time merges, 0.12.2 prep

- No dev target existed (#97). Added the local dev site, `bin/dev-site.sh`
  (#98 → PR #100). Its headless audit found admin defects #102–#105, fixed
  in PR #107. Filed #99 (no required checks; #96 merged 12 s after
  opening), plus backlog #101 #106 #108 #109.
- GitHub Actions incident, about 19:58–21:20 UTC: on the owner's
  instruction, #100 (`80a887a`) and #107 (`72b70fe`) were merged on local
  CI: security-grep, PHPCS, php -l, package smoke, check-version, and
  runtime smoke 91/91 on PHP 8.5. Run 37366878299 on `9112f8e` ended
  "failure" only through 10 outage cancellations; no job failed.
- After recovery, CI run 37375258741 on `15b43de` (current main; same
  plugin code as `72b70fe` plus handoff) passed 19/19: PHPUnit
  `OK (40 tests, 109 assertions)`; integration `91 passed, 0 failed` on WP
  latest (PHP 8.3) and on 5.6/6.5/6.8/6.9/7.0 (PHP 8.0); ZIP smoke; ZIP
  install + upgrade (v0.12.0 → 0.12.1 build). This is the first GitHub CI
  evidence for the merged code; the local runs above were the outage-time
  substitute.
- Dev site http://localhost:8790 deployed `72b70fe` (70 files verified,
  ZIP sha256 `00680619…a295`); `bin/dev-site.sh audit` 22/22 tab views clean.
- 0.12.2 prep: user-facing admin fixes (#102–#105) plus the new icon (#96).

## 2026-10-05 — v0.12.1 released; backlog complete

- PR #93 → `450fdcd`; main CI 19/19. Tag `v0.12.1` → `450fdcd`; release run
  37359135722 success. Published asset: sha256
  `768b7064858d0a35f01c74332b001bd22adbfae041c1686f94188058eca3e021` OK,
  versions 0.12.1, MCP write gate present, clean top-level; published ZIP
  clean install + upgrade from published v0.12.0 (exit 0).
- Open issues: 0. Open PRs: 0 (besides this checkpoint).

## 2026-10-05 — v0.12.0 released; final audit; 0.12.1 prep

- PR #91 → `441df7f`; main CI 19/19; tag `v0.12.0` → `441df7f`; release run
  37356331709 success. Published asset verified: sha256 `66391168…e23f` OK,
  versions 0.12.0, includes content-update class, no dev files; published
  ZIP clean install + upgrade from published v0.11.0 (exit 0). Comments on
  #70 #88.
- Final cross-cutting audit (read-only reviewer): no P1. Pre-existing gap:
  MCP `wp_create_post` / `wp_update_post` bypassed `write_content` and
  read-only mode (red-before-green verified). Fixed with the other findings
  in #92 → `94169ad` (CI 88/88 on all WP rows). Process slip: `835e201` was
  pushed without re-running PHPUnit; CI failed (discovery unit test lacked
  the Permissions class); fixed in `72550fe`.
- 0.12.1 local gates (WP 7.1.2): PHPUnit OK (40), phpcs 0, security-grep,
  package smoke, actionlint, integration 88/88, upgrade v0.12.0 → v0.12.1
  via v0.12.0's updater (exit 0).

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
