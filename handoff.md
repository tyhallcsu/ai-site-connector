# Handoff — AI Site Connector

**Updated:** 2026-10-05 ~20:20 UTC (14:20 America/Denver) · **Session:** `asc-dev-audit-ship` (dev audit + ship)
**Repo:** tyhallcsu/ai-site-connector (public, standalone — the parent `ess-custom-plugins` dir is not a repo)
**Primary checkout:** `/Users/tylerhall/Documents/GitHub/ess-custom-plugins/ai-site-connector` (on `main` @ `16f5816`, 1 behind origin — fast-forward it before use)
**Session worktree:** `.claude/worktrees/asc-dev-audit-ship` · active branch `feature/dev-site` (owner: this session)
**Rules:** `docs/development/OPERATING_BRIEF.md` · **Dev site:** `docs/development/DEV_SITE.md` · **Plan:** `docs/development/ROADMAP.md` · **Log:** `docs/development/WORK_LOG.md`

## Verified state

| Item | Value | Evidence |
|------|-------|----------|
| `origin/main` | `9112f8e` — design: new plugin icon (#96, Codex session) | `git fetch`; post-merge CI run 37366878299 still queued during a GitHub Actions incident |
| Latest release | **v0.12.1** → `450fdcd`; ZIP sha256 `768b7064…e021` | `gh release view` digest |
| Unreleased on main | #95 docs, #96 brand assets (no PHP change) | `git log v0.12.1..origin/main` |
| Dev site | http://localhost:8790 — local Docker, **deployed `9112f8e`** (origin/main), ZIP sha256 `adc52dc3…fd8c`, 70 files verified | `bin/dev-site.sh status` 20:17 UTC |
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
| #101 | enhancement | Byte-reproducible release ZIP | open — ready |

## Open PRs

| PR | Branch | State |
|----|--------|-------|
| #100 | `feature/dev-site` | draft; implements #98; local runs pass (up, deploy release, deploy main, refusal, rollback) |

## Audit coverage

| Area | Status |
|------|--------|
| Dev environment / deploy path | done → #97 #98 #101 |
| Repo process / CI gates | done → #99 |
| Fresh install + onboarding UI (headless) | next |
| Admin tabs, diagnostics, connection test, REST/MCP/CLI parity | not yet |
| Content update preview/rollback on fixtures | not yet |
| Inventory/export/media/link audits on fixtures | not yet |

## Next three actions

1. Finish PR #100: commit runbook + fixes, CI green on head (Actions incident permitting), mark ready, merge, re-verify `status` on dev.
2. Headless Playwright journey on dev: login → AI Site Connector admin → onboarding/connection setup → diagnostics; file issues for real findings.
3. Pick the next ready issue (#101 or an audit finding), branch from main, draft PR early.

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
- Docker: `asc-dev` compose project (persistent dev site: `asc-dev-db-1`, `asc-dev-wordpress-1`) — keep; stop with `bin/dev-site.sh stop`. `asc-mysql-test` is not running (disposable).
- A parallel Codex session merged #96; `git fetch` and check open PRs before editing README/docs/assets.
