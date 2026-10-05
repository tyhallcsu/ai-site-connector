# Handoff — AI Site Connector

**Updated:** 2026-10-05 (America/Denver)
**Repo:** tyhallcsu/ai-site-connector · **Checkout:** `/Users/tylerhall/Documents/GitHub/ess-custom-plugins/ai-site-connector` (standalone repo, not the parent)
**Rules:** `docs/development/OPERATING_BRIEF.md` · **Plan:** `docs/development/ROADMAP.md` · **Log:** `docs/development/WORK_LOG.md`

## Current state

- Last verified `origin/main`: `9c07f10` (v0.10.0 release prep, PR #81).
- **Latest release: v0.10.0** (2026-10-05) — tag → `9c07f10`, ZIP sha256 `2783dea6b5383c7dd566ac0cacc57715c3b7a94fc8b0a847e9433dcc873706dd`, verified (checksum, version, contents, clean install + real-updater upgrade from v0.9.1). https://github.com/tyhallcsu/ai-site-connector/releases/tag/v0.10.0
- Current milestone: **0.11.0 release** (M3–M6) — prep PR on `chore/release-0.11.0`; then tag the merge SHA. M6 merged (#85 → `d81b62d`); all issues except #70 closed.
- **M7 (#70)** lives in worktree `<scratchpad>/wt-m7` on local branch `feature/content-update` (uncommitted). Its review found 2 P1 + 6 P2 + 3 P3 (rollback privilege escalation, kses on untouched columns, non-atomic writes, snapshot storage) — needs a redesign pass before a PR.
- Drafts ready in the session scratchpad (not in git): M6 enable/disable (`m6/`), M7 safe content update (`m7/`). If the scratchpad is gone, re-derive from docs/development/ROADMAP.md.
- Then: M4 (#66 broken links), M5 (#73/#74), M6 (#75 rest: `export`, `enable`/`disable`), M7 (#70 safe content update).
- A parallel session (Codex) also works in this repo (merged PR #77). Always `git fetch` and check open PRs first.
- Untracked `ai-site-connector-autonomous-development-prompt.md` at the repo root is a copy of the committed operating brief placed by the user/another session; not ours — leave it.

## Completed

| Milestone | PR | Merge SHA | Evidence |
|-----------|----|-----------|----------|
| M0 CI truthfulness + harness | #78 | `2dd96bc` | run 37337044022: PHPUnit OK (40), integration 3/3 |
| M1 diagnostics + SEO abstraction | #76 | `c7f689b` | run 37338939083: integration 34/34; closed #67 #68 #69 #71 #72 |
| M2 content inventory | #79 | `2949c89` | run 37339837499: integration 43/43; closed #63 |
| Export consistency | #80 | `4d948e0` | run 37341039538: integration 46/46 incl. WP 5.6 |
| M6 disable/enable | #85 | `d81b62d` | run 37349217489: integration 71/71 on WP 5.6–7.1.2; closed #75 |
| M5 export bundle | #84 | `8dbb1d0` | run 37346467734: integration 69/69; closed #73 #74 |
| M4 broken links | #83 | `c270434` | run 37345095645: integration 62/62 on WP 5.6–7.1.2; closed #66 |
| M3 media audit + duplicates | #82 | `4f44c85` | run 37343885813: integration 54/54 on WP 5.6–7.1.2; closed #64 #65 |
| Release 0.10.0 prep + pipeline | #81 | `9c07f10` | run 37342326745: 46/46 on WP 5.6–7.1.2, real-updater upgrade; release run 37342852174 |
| #59 superseded branch | — | — | branch deleted; evidence in issue comment |

## Open PRs

| PR | Branch | State |
|----|--------|-------|
| (0.11.0 prep) | chore/release-0.11.0 | opening |
| (M7) | feature/content-update (worktree, local commit) | redesign under second-pass review |

## Open issues

#70 (M7).

## How to test locally

```bash
docker start asc-mysql-test || docker run -d --name asc-mysql-test -e MYSQL_ROOT_PASSWORD=root -p 127.0.0.1:33306:3306 mysql:8.0
TMPDIR=$PWD/.tmp composer test
WP_DB_HOST=127.0.0.1:33306 WP_DB_PASSWORD=root bash tests/runtime-smoke.sh   # PHP 8.5: error_reporting=E_ALL&~E_DEPRECATED, display_errors=stderr via PHP_INI_SCAN_DIR
ASC_IT_FILTER=redirects ...  # local port 8765 may be taken by another project; use WP_PORT=8775                                                   # run a subset of integration cases
```

## Workers / services

- Active subagents: 1 (read-only re-review of M7 redesign).
- Test service: Docker container `asc-mysql-test` (MySQL 8.0, 127.0.0.1:33306) — disposable; `docker rm -f asc-mysql-test`.

## Next three actions

1. Apply M3 review findings, open PR, CI, merge; close #64 #65.
2. M4: broken internal link scanner (#66) — no outbound HTTP; resolve via url_to_postid/attachment lookups.
3. M5: export bundle (#73) + deterministic manifests (#74) over the shared services.

Resume: `cd /Users/tylerhall/Documents/GitHub/ess-custom-plugins/ai-site-connector && git fetch && gh pr list -R tyhallcsu/ai-site-connector`
