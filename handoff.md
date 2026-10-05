# Handoff — AI Site Connector

**Updated:** 2026-10-05 (America/Denver)
**Repo:** tyhallcsu/ai-site-connector · **Checkout:** `/Users/tylerhall/Documents/GitHub/ess-custom-plugins/ai-site-connector` (standalone repo, not the parent)
**Rules:** `docs/development/OPERATING_BRIEF.md` · **Plan:** `docs/development/ROADMAP.md` · **Log:** `docs/development/WORK_LOG.md`

## Current state

- Last verified `origin/main`: `2dd96bc` (M0, PR #78 merged).
- Current milestone: **M1** — PR #76 on `feature/queue-wordpress-mcp-diagnostics-and-export-tools` (#67 #68 #69 #71 #72, #75 partial). main merged in; defects fixed; integration tests added; second-pass security review in progress.
- Latest release: v0.9.1. No release candidate yet; 0.10.0 planned after M1 + one of M2–M4.
- A parallel session (Codex) also works in this repo (merged PR #77, README artwork). Always `git fetch` and check open PRs before touching README/docs.

## Completed

| Milestone | PR | Merge SHA | Evidence |
|-----------|----|-----------|----------|
| M0 CI truthfulness + harness | #78 | `2dd96bc` | CI run 37337044022: PHPUnit OK (40), integration 3/3 on WP latest, 5.6, 6.5, 6.8, 6.9 |

## Open PRs

| PR | Branch | State |
|----|--------|-------|
| #76 | feature/queue-wordpress-mcp-diagnostics-and-export-tools | draft; local work committed, not yet pushed at time of writing |

## Remaining for #75 after M1

`content-inventory`, `media-audit`, `export`, `enable`/`disable` subcommands land with M2–M6.

## How to test locally

```bash
docker start asc-mysql-test || docker run -d --name asc-mysql-test -e MYSQL_ROOT_PASSWORD=root -p 127.0.0.1:33306:3306 mysql:8.0
TMPDIR=$PWD/.tmp composer test
WP_DB_HOST=127.0.0.1:33306 WP_DB_PASSWORD=root bash tests/runtime-smoke.sh   # PHP 8.5: error_reporting=E_ALL&~E_DEPRECATED, display_errors=stderr via PHP_INI_SCAN_DIR
ASC_IT_FILTER=redirects ...                                                   # run a subset of integration cases
```

## Workers / services

- Active subagents: 1 (read-only security reviewer for PR #76).
- Test service: Docker container `asc-mysql-test` (MySQL 8.0, 127.0.0.1:33306) — disposable; `docker rm -f asc-mysql-test`.

## Next three actions

1. Address reviewer findings, push PR #76, update its description, mark ready, watch CI, merge.
2. Close #67 #68 #69 #71 #72 with merge evidence; comment progress on #75.
3. Start M2 (#63 content inventory) from fresh main.

Resume: `cd /Users/tylerhall/Documents/GitHub/ess-custom-plugins/ai-site-connector && git fetch && gh pr list -R tyhallcsu/ai-site-connector`
