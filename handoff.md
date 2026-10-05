# Handoff — AI Site Connector

**Updated:** 2026-10-05 ~23:25 UTC (17:25 America/Denver) · **State:** v0.12.3 released and verified on dev; queue closed out; paused for the owner · **Mode:** continuous low-usage (single agent; new features stay in backlog) · **Session:** `asc-dev-audit-ship`
**Repo:** tyhallcsu/ai-site-connector (public, standalone — the parent `ess-custom-plugins` dir is not a repo)
**Primary checkout:** `/Users/tylerhall/Documents/GitHub/ess-custom-plugins/ai-site-connector`. Its local `main` is at `16f5816`, 5 commits behind `origin/main` (read from its refs 21:48 UTC; not modified by this session; fast-forward before use)
**Session worktree:** `.claude/worktrees/asc-dev-audit-ship` · branch `docs/handoff-0.12.3` (this checkpoint)
**Rules:** `docs/development/OPERATING_BRIEF.md` · **Dev site:** `docs/development/DEV_SITE.md` · **Plan:** `docs/development/ROADMAP.md` · **Log:** `docs/development/WORK_LOG.md`

## Verified state

| Item | Value | Evidence |
|------|-------|----------|
| `origin/main` | `4bef824` — chore(release): v0.12.3 (#135), on `422f953` (#134 P1 fixes) | `gh pr view 135` merge commit |
| Latest release | **v0.12.3** → tag on `4bef824`; stable; `releases/latest`; ZIP 1,135,602 B, sha256 `01b973c2…94afe`; checksum, embedded 0.12.3, 70 files, no dev paths and both fixes present all verified | release run 37380756899 |
| Unreleased on main | nothing | commits since tag v0.12.3 |
| Dev site | http://localhost:8790 — **published v0.12.3** (`4bef824`), 70 files verified. #114: a verified 0.12.2 backup plus an empty 0.12.0 dir present, only 0.12.2 offered. UI Rollback to 0.12.2 via the admin-post handler gave byte-identical files and audit started → roll-away backup → completed; then restored to v0.12.3. #119: the generated snippet URL `…/wp-json/ai-site-connector/v1/mcp`, HTTP `initialize` with the snippet's header → 200 | `bin/dev-site.sh status`; audit ids 17–19 |
| CI | #134 run 37379827867 19/19 (integration 97/97 × 6 WP builds); #135 run 37380234124 19/19 (upgrade v0.12.2 → v0.12.3); main `4bef824` run 37380505933 19/19 | `gh run view` |
| Remote dev target | none exists — owner decision tracked in #97 | config/registry search |

## Startup reconciliation (2026-10-05)

- `repo-reconcile` skill, report-only: no stashes, no dirty files; all 19 local branches equal merged PR heads (#38, #76, #78–#95) — nothing unique to preserve. Nothing deleted.
- Untracked, user-owned, left alone in the primary checkout: `03-dev-audit-discover-and-ship.md` (this session's prompt), `ai-site-connector-autonomous-development-prompt.md`, `composer.lock`.
- New since the last handoff: PR #96 (Codex) merged 12 s after opening with CI never run → #99.
- `refresh-client-context` skill: no client mapping exists for this plugin (no `.imessage-sync`/`.notes-calls-sync` state, no `~/clients` registry entry). Not bootstrapped: this is a public reusable plugin repo, and client message trails must not land here. Product/operator context = repo docs, issues and the operator's WordPress registry (production client sites only; none is a dev target).

## Issues filed this session

| # | Type | Title | State |
|---|------|-------|-------|
| #97 | environment | No development WordPress target configured | open — owner decision (remote dev site?) |
| #98 | enhancement | Persistent local dev site with exact-SHA deploy/rollback | closed by #100 (`80a887a`) |
| #99 | investigation | main has no required checks; #96 merged before CI ran | open — needs owner (admin setting) |
| #101 | enhancement | Byte-reproducible release ZIP | backlog (not auto-implemented in low-usage mode) |
| #102 | bug | Permission checkboxes lack accessible names (+ duplicate nonce ids) | closed by #107 (`72b70fe`), verified on dev |
| #103 | bug | Wide tables overflow on phones (5 tabs) | closed by #107 (`72b70fe`), verified on dev |
| #104 | bug | Connection Test REST self-test returns to Overview | closed by #107 (`72b70fe`), verified on dev |
| #105 | bug | Notices render inside the page header | closed by #107 (`72b70fe`), verified on dev |
| #106 | enhancement | Live sign-in check in wp-admin (CLI self-test parity) | backlog |
| #108 | enhancement | WP-CLI safe content update + rollback | backlog |
| #109 | investigation | Steer MCP agents to wp_update_content (no removal) | backlog |
| #125 | performance | Release ZIP 4x larger from raster-backed brand SVGs | backlog |

## Open PRs

| PR | Branch | State |
|----|--------|-------|
| #100 | `feature/dev-site` | merged → `80a887a` |
| #107 | `fix/admin-ui-audit` | merged → `72b70fe`; dev evidence on PR |
| #115 | `chore/release-0.12.2` | merged → `72e268e`; tagged and released v0.12.2 |

## Audit coverage

| Area | Status |
|------|--------|
| Dev environment / deploy path | done → #97 #98 #101 |
| Repo process / CI gates | done → #99 |
| Fresh install + onboarding UI (headless) | done → #104 #105 #106 (wizard → pack w/ live pre-flight ✓ → 8 snippet formats) |
| Admin tabs: layout, a11y, console/HTTP errors (`bin/dev-site.sh audit`) | done → #102 #103 |
| Diagnostics, REST/MCP/CLI parity | partial (CLI self-test 6/6 on dev) → #106 #108 #109 |
| Link / media / duplicate scans on seeded fixtures (CLI, `--user` required) | done, no defects: 1 broken link `not_found`, `missing_alt: 1`, duplicate pair by sha256 (ids 5, 6) |
| Content update preview/rollback on fixtures | not yet |
| Inventory / export bundle on fixtures | not yet |

## Next three actions

1. Owner decisions #97 (remote dev site?) and #99 (required checks on `main`).
2. Next fixes from the other session's audit (P2): #111 #113 #116 #117 #118 #120 #121, after checking with that session; features #112 #122–#124 stay in the backlog.
3. Backlog: #101 #106 #108 #109 #125. Dev site holds fixture data plus extra `ai-agent` Application Passwords from verification runs (dev only).

Resume: `cd <worktree> && git fetch && gh pr list -R tyhallcsu/ai-site-connector && bin/dev-site.sh status`

## How to test locally

```bash
docker start asc-mysql-test || docker run -d --name asc-mysql-test -e MYSQL_ROOT_PASSWORD=root -p 127.0.0.1:33306:3306 mysql:8.0
TMPDIR=$PWD/.tmp composer test
WP_DB_HOST=127.0.0.1:33306 WP_DB_PASSWORD=root WP_PORT=8775 bash tests/runtime-smoke.sh   # PHP 8.5: error_reporting=E_ALL&~E_DEPRECATED, display_errors=stderr
bash tests/zip-upgrade-smoke.sh "$(bin/build-release-zip.sh | tail -n1)"
```

Port 8765 may be held by another project's server (not ours — leave it). WP 5.6 needs PHP ≤ 8.0 (CI covers it).

## Workers / services

- Subagents: 0 active.
- Docker: `asc-dev` compose project (persistent dev site: `asc-dev-db-1`, `asc-dev-wordpress-1`) — running, keep; stop with `bin/dev-site.sh stop`. Dev data: AI user `ai-agent` (id 2) with one Application Password, `asc-fixture-*` content. `asc-mysql-test` stopped (disposable).
- Sandbox note: `deploy`, `rollback`, `audit` and PHPCS need a writable `TMPDIR` (session scratchpad); `audit` used `ASC_DEV_CACHE` pointing at a scratchpad Playwright install.
- A parallel Codex session merged #96; `git fetch` and check open PRs before editing README/docs/assets.
