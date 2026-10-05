# Handoff — AI Site Connector

**Updated:** 2026-10-05 ~21:40 UTC (15:40 America/Denver) · **State:** v0.12.2 release prep (PR from `chore/release-0.12.2`); GitHub CI on main `15b43de` verified 19/19 · **Mode:** continuous low-usage (single agent; new features stay in backlog) · **Session:** `asc-dev-audit-ship`
**Repo:** tyhallcsu/ai-site-connector (public, standalone — the parent `ess-custom-plugins` dir is not a repo)
**Primary checkout:** `/Users/tylerhall/Documents/GitHub/ess-custom-plugins/ai-site-connector` (on `main` @ `16f5816`, 1 behind origin — fast-forward it before use)
**Session worktree:** `.claude/worktrees/asc-dev-audit-ship` · branch `chore/release-0.12.2` (release prep). Primary checkout not touched by this session
**Rules:** `docs/development/OPERATING_BRIEF.md` · **Dev site:** `docs/development/DEV_SITE.md` · **Plan:** `docs/development/ROADMAP.md` · **Log:** `docs/development/WORK_LOG.md`

## Verified state

| Item | Value | Evidence |
|------|-------|----------|
| `origin/main` | `15b43de` — #110 handoff checkpoint, on `72b70fe` (#107) and `80a887a` (#100) | `gh pr view` merge commits |
| Latest release | **v0.12.1** → `450fdcd`; ZIP sha256 `768b7064…e021` | `gh release view` digest |
| Unreleased on main | #96 icon, #100 dev tooling (not in the ZIP), **#107 admin fixes** → v0.12.2 prepared in this PR | commits since tag v0.12.1 |
| Dev site | http://localhost:8790 — **deployed `72b70fe`** (origin/main), ZIP sha256 `00680619…a295`, 70 files verified; `audit` 22/22 tab views clean | `bin/dev-site.sh status` / `audit` 21:18 UTC |
| CI | **GitHub CI verified on `15b43de`**: run 37375258741, 19/19 success. PHPUnit 40 tests; integration 91/91 on WP latest (PHP 8.3) and 5.6/6.5/6.8/6.9/7.0 (PHP 8.0); ZIP smoke; install + upgrade. Before that, #100/#107 were merged on owner-authorized local CI during the Actions outage, a one-off exception and not policy. Run 37366878299 (`9112f8e`) shows failure only from 10 outage cancellations | `gh run view` job list + log grep |
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

## Open PRs

| PR | Branch | State |
|----|--------|-------|
| #100 | `feature/dev-site` | merged → `80a887a` |
| #107 | `fix/admin-ui-audit` | merged → `72b70fe`; dev evidence on PR |

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

1. Release-prep PR: CI green on its head (zip upgrade should test v0.12.1 → 0.12.2) → squash-merge → `git tag -a v0.12.2` on the merge SHA → push the tag; `release-zip.yml` is the only publisher.
2. Verify the published asset (digest, embedded 0.12.2, contents); on dev, `deploy --release v0.12.1`, then `--release v0.12.2`, then `audit`.
3. Record the release in WORK_LOG and handoff (one checkpoint PR). Still open: owner decisions #97/#99; backlog #101 #106 #108 #109.

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
