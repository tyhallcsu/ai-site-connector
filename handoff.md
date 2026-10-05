# Handoff — AI Site Connector

**Updated:** 2026-10-05 ~20:45 UTC (14:45 America/Denver) · **State: PAUSED** — all merges blocked by a GitHub Actions incident; peer budget note: weekly usage 74% (resets 2026-10-07) · **Mode:** owner add-on "continuous low-usage" (single agent, no subagents; new features stay in backlog) · **Session:** `asc-dev-audit-ship` (dev audit + ship)
**Repo:** tyhallcsu/ai-site-connector (public, standalone — the parent `ess-custom-plugins` dir is not a repo)
**Primary checkout:** `/Users/tylerhall/Documents/GitHub/ess-custom-plugins/ai-site-connector` (on `main` @ `16f5816`, 1 behind origin — fast-forward it before use)
**Session worktree:** `.claude/worktrees/asc-dev-audit-ship` · branches `feature/dev-site` (PR #100) and `fix/admin-ui-audit` (PR #107), both owned by this session
**Rules:** `docs/development/OPERATING_BRIEF.md` · **Dev site:** `docs/development/DEV_SITE.md` · **Plan:** `docs/development/ROADMAP.md` · **Log:** `docs/development/WORK_LOG.md`

## Verified state

| Item | Value | Evidence |
|------|-------|----------|
| `origin/main` | `9112f8e` — design: new plugin icon (#96, Codex session) | `git fetch`; post-merge CI run 37366878299 still queued during a GitHub Actions incident |
| Latest release | **v0.12.1** → `450fdcd`; ZIP sha256 `768b7064…e021` | `gh release view` digest |
| Unreleased on main | #95 docs, #96 brand assets (no PHP change) | `git log v0.12.1..origin/main` |
| Dev site | http://localhost:8790 — local Docker, **deployed `9112f8e`** (origin/main; rolled back after a labelled #107 candidate check), 70 files verified | `bin/dev-site.sh status` 20:34 UTC |
| CI | every run since 19:58 UTC still `queued` (GitHub Actions incident, githubstatus.com) — nothing can merge until it clears | `gh run list` |
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
| #98 | enhancement | Persistent local dev site with exact-SHA deploy/rollback | PR #100 (draft) |
| #99 | investigation | main has no required checks; #96 merged before CI ran | open — needs owner (admin setting) |
| #101 | enhancement | Byte-reproducible release ZIP | backlog (not auto-implemented in low-usage mode) |
| #102 | bug | Permission checkboxes lack accessible names (+ duplicate nonce ids) | PR #107 |
| #103 | bug | Wide tables overflow on phones (5 tabs) | PR #107 |
| #104 | bug | Connection Test REST self-test returns to Overview | PR #107 |
| #105 | bug | Notices render inside the page header | PR #107 |
| #106 | enhancement | Live sign-in check in wp-admin (CLI self-test parity) | backlog |
| #108 | enhancement | WP-CLI safe content update + rollback | backlog |
| #109 | investigation | Steer MCP agents to wp_update_content (no removal) | backlog |

## Open PRs

| PR | Branch | State |
|----|--------|-------|
| #100 | `feature/dev-site` | draft, head `a4d151f`+handoff; implements #98; local runs pass (up, deploy release/main, refusal, rollback, audit); CI queued |
| #107 | `fix/admin-ui-audit` | draft, head `30c544f`; fixes #102–#105; local: integration 91/91, PHPCS clean, dev audit 0/22 findings (main 10/22); CI queued |

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

1. When Actions recovers: confirm main CI on `9112f8e`, then CI on #100 and #107 heads (19 checks each); mark ready; squash-merge #100 then #107 (rebase #107 if handoff conflicts).
2. After #107 merges: `bin/dev-site.sh deploy` (merge SHA), `bin/dev-site.sh audit` → expect 0 findings; comment evidence on #102–#105.
3. Next uncovered audit (only when budget allows): content-update dry-run/rollback via REST on the `asc-fixture-*` posts; inventory + export bundle.

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
