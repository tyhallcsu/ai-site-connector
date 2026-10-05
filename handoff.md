# Handoff — AI Site Connector

**Updated:** 2026-10-05 (America/Denver)
**Repo:** tyhallcsu/ai-site-connector · **Primary checkout:** `/Users/tylerhall/Documents/GitHub/ess-custom-plugins/ai-site-connector` (standalone repo, not the parent)
**Rules:** `docs/development/OPERATING_BRIEF.md` · **Plan:** `docs/development/ROADMAP.md` · **Log:** `docs/development/WORK_LOG.md`

## Verified state (gh/git, 2026-10-05)

| Item | Value |
|------|-------|
| Latest `origin/main` | `c255de3` — feat(media): library-wide resumable duplicate scan (#88) (#90) |
| Latest published release | **v0.11.0** — tag → `6645155`; https://github.com/tyhallcsu/ai-site-connector/releases/tag/v0.11.0; ZIP sha256 `95b337b122d7ed198ab72b5a9aed90006616953fd7e2568228508ecfbd4086f3`; verified (checksum, versions, contents, clean install, upgrade from published v0.10.0) |
| Unreleased on main | #89 export coverage reporting, #90 library-wide duplicate scan (closes #88) |
| Release candidate | 0.12.0 after #87 (M7, #70) merges |
| Open issues | #70 |

## Open PRs

| PR | Branch | State |
|----|--------|-------|
| #87 | `feature/content-update` | M7 / #70. Four second-pass reviews; all 31 findings fixed with tests or documented (`docs/development/M7_REVIEW.md`). Combined suite 86/86 locally on the merged head; awaiting CI on the pushed head, then ready → merge. |

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
| Export coverage reporting | #89 | `1098ee4` | 72/72 on WP 5.6–7.1.2 |
| Library-wide duplicate scan | #90 | `c255de3` | 73/73 on WP 5.6–7.1.2; closed #88; reviewed (1 P1 + 7 P2 + 3 P3 fixed) |
| #59 superseded branch | — | — | branch deleted; evidence in issue |

## Next three actions

1. CI on #87's head; mark ready; merge; close #70.
2. Release 0.12.0 (prep PR → CI → tag merge SHA → verify published ZIP + upgrade from v0.11.0).
3. Final integration/security/docs audit of the shipped surface; report.

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
