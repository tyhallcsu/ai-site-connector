# Handoff — AI Site Connector

**Updated:** 2026-10-05 (America/Denver)
**Repo:** tyhallcsu/ai-site-connector · **Checkout:** `/Users/tylerhall/Documents/GitHub/ess-custom-plugins/ai-site-connector` (standalone repo, not the parent)
**Rules:** `docs/development/OPERATING_BRIEF.md` · **Plan:** `docs/development/ROADMAP.md` · **Log:** `docs/development/WORK_LOG.md`

## Current state

- Last verified `origin/main`: `c7f689b` (M1, PR #76 merged).
- Current milestone: **M2** — content inventory (#63) on `feature/content-inventory`; PR being opened.
- Next: export consistency PR (align older `/export/*` routes with the inventory's conventions, incl. draft dates; see SECURITY.md before writing any issue text), then M3 (#64/#65), M4 (#66).
- Latest release: v0.9.1. 0.10.0 candidate after M2 merges (M1+M2 = coherent new read-only tool group).
- A parallel session (Codex) also works in this repo (merged PR #77, README artwork). Always `git fetch` and check open PRs before touching README/docs.

## Completed

| Milestone | PR | Merge SHA | Evidence |
|-----------|----|-----------|----------|
| M0 CI truthfulness + harness | #78 | `2dd96bc` | run 37337044022: PHPUnit OK (40), integration 3/3 on WP latest/5.6/6.5/6.8/6.9 |
| M1 diagnostics + SEO abstraction | #76 | `c7f689b` | run 37338939083: integration 34/34 on all WP rows; closes #67 #68 #69 #71 #72 |
| #59 superseded branch | — | — | branch deleted; evidence in issue comment |

## Open PRs

| PR | Branch | State |
|----|--------|-------|
| (M2) | feature/content-inventory | opening |

## Open issues

#63 (M2), #64 #65 (M3), #66 (M4), #73 #74 (M5), #75 (partial; remaining subcommands with M2–M6), #70 (M7).

## How to test locally

```bash
docker start asc-mysql-test || docker run -d --name asc-mysql-test -e MYSQL_ROOT_PASSWORD=root -p 127.0.0.1:33306:3306 mysql:8.0
TMPDIR=$PWD/.tmp composer test
WP_DB_HOST=127.0.0.1:33306 WP_DB_PASSWORD=root bash tests/runtime-smoke.sh   # PHP 8.5: error_reporting=E_ALL&~E_DEPRECATED, display_errors=stderr via PHP_INI_SCAN_DIR
ASC_IT_FILTER=redirects ...                                                   # run a subset of integration cases
```

## Workers / services

- Active subagents: 0.
- Test service: Docker container `asc-mysql-test` (MySQL 8.0, 127.0.0.1:33306) — disposable; `docker rm -f asc-mysql-test`.

## Next three actions

1. Open M2 PR, watch CI, merge; close #63; comment on #75.
2. Export consistency PR for older `/export/*` routes, with integration tests.
3. M3: media SEO audit (#64) + duplicate media (#65) with CLI `media-audit`.

Resume: `cd /Users/tylerhall/Documents/GitHub/ess-custom-plugins/ai-site-connector && git fetch && gh pr list -R tyhallcsu/ai-site-connector`
