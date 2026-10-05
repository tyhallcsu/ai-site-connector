# Handoff — AI Site Connector

**Updated:** 2026-10-05 (America/Denver)
**Repo:** tyhallcsu/ai-site-connector · **Primary checkout:** `/Users/tylerhall/Documents/GitHub/ess-custom-plugins/ai-site-connector` (standalone repo, not the parent)
**Rules:** `docs/development/OPERATING_BRIEF.md` · **Plan:** `docs/development/ROADMAP.md` · **Log:** `docs/development/WORK_LOG.md`

## Verified state (gh/git, 2026-10-05)

| Item | Value |
|------|-------|
| Latest `origin/main` | `6645155` — chore(release): 0.11.0 (#86); CI 19/19 success on this SHA |
| Latest published release | **v0.11.0** — tag → `6645155`; https://github.com/tyhallcsu/ai-site-connector/releases/tag/v0.11.0; stable, `releases/latest`; ZIP 264022 B, sha256 `95b337b122d7ed198ab72b5a9aed90006616953fd7e2568228508ecfbd4086f3` |
| v0.11.0 verification | downloaded asset: checksum OK, header/constant/readme = 0.11.0, top-level allowlist clean, no M7 code; published ZIP clean install OK and upgrade from **published v0.10.0** via its updater OK (exit 0) |
| Previous release | v0.10.0 — tag → `9c07f10`, sha256 `2783dea6…06dd` |
| Release candidate | none |
| Open issues | #70 (M7, draft PR #87), #88 (cross-window duplicate detection) |

## Open PRs

| PR | Branch | Head | State |
|----|--------|------|-------|
| #87 | `feature/content-update` | `ad4a914` | **DRAFT — do not merge.** M7 / #70. Worktree `<scratchpad>/wt-m7` (session-local; the branch on origin is the durable copy). Review 2 open items: 1 P1 + 5 P2 + 2 P3 — see `docs/development/M7_REVIEW.md` on that branch. |
| #89 | `fix/export-coverage` | (latest push) | Bundle index `complete`/`scope`/`limitations`; README scope wording; this handoff. |

## Completed milestones

| Milestone | PR | Merge SHA | Evidence |
|-----------|----|-----------|----------|
| M0 CI truthfulness + harness | #78 | `2dd96bc` | PHPUnit OK (40), integration 3/3 |
| M1 diagnostics + SEO abstraction | #76 | `c7f689b` | 34/34; closed #67 #68 #69 #71 #72 |
| M2 content inventory | #79 | `2949c89` | 43/43; closed #63 |
| Export consistency | #80 | `4d948e0` | 46/46 incl. WP 5.6 |
| v0.10.0 prep + release pipeline | #81 | `9c07f10` | released v0.10.0 |
| M3 media audit + duplicates | #82 | `4f44c85` | 54/54; closed #64 #65 |
| M4 broken links | #83 | `c270434` | 62/62; closed #66 |
| M5 export bundle | #84 | `8dbb1d0` | 69/69; closed #73 #74 |
| M6 disable/enable | #85 | `d81b62d` | 71/71 on WP 5.6–7.1.2; closed #75 |
| v0.11.0 prep | #86 | `6645155` | released v0.11.0 (verified above) |
| #59 superseded branch | — | — | branch deleted; evidence in issue |

## Next three actions

1. Merge #89 after CI.
2. M7 (#87): fix the eight open review-2 items with regression tests (ledger), run the combined suite on the PR head, focused third review (rollback authz under current permissions, concurrent edits/stale snapshots, mid-operation + restore consistency, direct-row recovery path, honest failure reporting), CI, mark ready, merge, release 0.12.0.
3. #88 cross-window duplicate detection.

Resume: `cd /Users/tylerhall/Documents/GitHub/ess-custom-plugins/ai-site-connector && git fetch && gh pr list -R tyhallcsu/ai-site-connector`. If the scratchpad worktree is gone: `git worktree prune`, then add a new worktree for `feature/content-update` outside the parent `ess-custom-plugins` tree.

## How to test locally

```bash
docker start asc-mysql-test || docker run -d --name asc-mysql-test -e MYSQL_ROOT_PASSWORD=root -p 127.0.0.1:33306:3306 mysql:8.0
TMPDIR=$PWD/.tmp composer test
WP_DB_HOST=127.0.0.1:33306 WP_DB_PASSWORD=root WP_PORT=8775 bash tests/runtime-smoke.sh   # PHP 8.5: error_reporting=E_ALL&~E_DEPRECATED, display_errors=stderr
bash tests/zip-upgrade-smoke.sh "$(bin/build-release-zip.sh | tail -n1)"
```

Port 8765 may be held by another project's server (not ours — leave it). WP 5.6 needs PHP ≤ 8.0 (CI covers it).

## Workers / services / unrelated files

- Active subagents: 0.
- Docker container `asc-mysql-test` (MySQL 8.0, 127.0.0.1:33306) — disposable; `docker rm -f asc-mysql-test`.
- Untracked `ai-site-connector-autonomous-development-prompt.md` (copy of the committed brief) and `composer.lock` in the primary checkout are not ours to commit — leave them.
- A parallel Codex session has merged docs PRs before (#77); `git fetch` and check open PRs before editing README/docs.
