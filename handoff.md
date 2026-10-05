# Handoff — AI Site Connector

**Updated:** 2026-10-05 (America/Denver)
**Repo:** tyhallcsu/ai-site-connector · **Checkout:** `/Users/tylerhall/Documents/GitHub/ess-custom-plugins/ai-site-connector` (standalone repo, not the parent)
**Rules:** `docs/development/OPERATING_BRIEF.md` · **Plan:** `docs/development/ROADMAP.md` · **Log:** `docs/development/WORK_LOG.md`

## Current state

- Last verified `origin/main`: `4d948e0` (PR #80 merged).
- Current milestone: **0.10.0 release** — prep PR on `chore/release-0.10.0` (version bump, release tooling). After it merges: tag the merge SHA `v0.10.0`, watch `release-zip.yml`, download + verify the asset.
- Then: M3 (#64 media SEO audit + #65 duplicate media), M4 (#66), M5 (#73/#74), M6 (#75 rest), M7 (#70).
- Latest release: v0.9.1. Candidate: v0.10.0 (not yet tagged).
- A parallel session (Codex) also works in this repo (merged PR #77). Always `git fetch` and check open PRs first.

## Completed

| Milestone | PR | Merge SHA | Evidence |
|-----------|----|-----------|----------|
| M0 CI truthfulness + harness | #78 | `2dd96bc` | run 37337044022: PHPUnit OK (40), integration 3/3 on WP latest/5.6/6.5/6.8/6.9 |
| M1 diagnostics + SEO abstraction | #76 | `c7f689b` | run 37338939083: integration 34/34; closed #67 #68 #69 #71 #72 |
| M2 content inventory | #79 | `2949c89` | run 37339837499: integration 43/43; closed #63 |
| Export consistency | #80 | `4d948e0` | run 37341039538: integration 46/46 incl. WP 5.6 |
| #59 superseded branch | — | — | branch deleted; evidence in issue comment |

## Open PRs

| PR | Branch | State |
|----|--------|-------|
| (0.10.0 prep) | chore/release-0.10.0 | opening |

## Open issues

#64 #65 (M3), #66 (M4), #73 #74 (M5), #75 (partial), #70 (M7).

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

1. Merge the 0.10.0 prep PR after CI (confirm the new `Release ZIP install + upgrade` job and the 7.0 row ran).
2. `git tag -a v0.10.0 -m v0.10.0 <merge-sha> && git push origin v0.10.0`; follow docs/RELEASE_CHECKLIST.md §3 to verify.
3. Start M3 (#64/#65) from fresh main.

Resume: `cd /Users/tylerhall/Documents/GitHub/ess-custom-plugins/ai-site-connector && git fetch && gh pr list -R tyhallcsu/ai-site-connector`
