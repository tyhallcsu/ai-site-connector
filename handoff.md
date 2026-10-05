# Handoff — AI Site Connector

**Updated:** 2026-10-05 (America/Denver)
**Repo:** tyhallcsu/ai-site-connector · **Primary checkout:** `/Users/tylerhall/Documents/GitHub/ess-custom-plugins/ai-site-connector` (standalone repo, not the parent)
**Rules:** `docs/development/OPERATING_BRIEF.md` · **Plan:** `docs/development/ROADMAP.md` · **Log:** `docs/development/WORK_LOG.md`

## Verified state (gh/git, 2026-10-05)

| Item | Value |
|------|-------|
| Latest `origin/main` | `450fdcd` — chore(release): v0.12.1 (#93) (plus this checkpoint) |
| Latest published release | **v0.12.1** — tag → `450fdcd`; https://github.com/tyhallcsu/ai-site-connector/releases/tag/v0.12.1; stable, `releases/latest`; ZIP 283922 B, sha256 `768b7064858d0a35f01c74332b001bd22adbfae041c1686f94188058eca3e021`; verified (checksum, versions, contents, clean install, upgrade from published v0.12.0) |
| Earlier releases | v0.12.0 → `441df7f` (sha256 `66391168…e23f`), v0.11.0 → `6645155` (`95b337b1…86f3`), v0.10.0 → `9c07f10` (`2783dea6…06dd`) — all verified the same way |
| Unreleased on main | nothing (docs checkpoint only) |
| Release candidate | none |
| Open issues | none |

## Open PRs

None.

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
| v0.12.0 prep | #91 | `441df7f` | released v0.12.0 (verified) |
| v0.12.1 prep | #93 | `450fdcd` | released v0.12.1 (verified) |
| Release-audit fixes | #92 | `94169ad` | 88/88; MCP write gate verified red-before-green |
| M7 safe content update | #87 | `d378e50` | 86/86 on WP 5.6–7.1.2; closed #70; 4 reviews |
| Library-wide duplicate scan | #90 | `c255de3` | 73/73 on WP 5.6–7.1.2; closed #88; reviewed (1 P1 + 7 P2 + 3 P3 fixed) |
| #59 superseded branch | — | — | branch deleted; evidence in issue |

## Next three actions

The ready backlog is complete (all queue issues #59, #63–#75 and #88 closed; four releases published and verified). New work needs new issues.

1. Optional: live-site upgrades are a separate, explicitly authorised step (not done by this session; a celememorate.com maintainer session asked and was told it needs its own user's authorisation).
2. Optional follow-ups worth filing if wanted: CLI parity for content update (REST/MCP only today); MCP `wp_create_post`/`wp_update_post` could be retired in favour of `wp_update_content`.
3. Keep `docs/development/M7_REVIEW.md` current if the content-update tool changes.

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
