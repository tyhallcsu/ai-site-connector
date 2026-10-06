# Handoff — AI Site Connector

**Updated:** 2026-10-06 (America/Denver) · **State:** v0.13.0 released and verified. The owner's feature list is done except #125, which is blocked. **PAUSED** under credit budget mode (weekly limit 74%, resets 2026-10-07). · **Session:** `asc-dev-audit-ship`
**Repo:** tyhallcsu/ai-site-connector (public). The parent `ess-custom-plugins` directory is not a repo.
**Primary checkout:** `/Users/tylerhall/Documents/GitHub/ess-custom-plugins/ai-site-connector`. Its local `main` is `16f5816`, 28 commits behind `origin/main`; fast-forward it before use. This session did not modify it.
**Session worktree:** `.claude/worktrees/asc-dev-audit-ship`, branch `docs/checkpoint-0.13.0` (this checkpoint).
**Rules:** `docs/development/OPERATING_BRIEF.md` · **Dev site:** `docs/development/DEV_SITE.md` · **Log:** `docs/development/WORK_LOG.md` (evidence for everything below)

## Verified state

| Item | Value | Evidence |
|------|-------|----------|
| `origin/main` | `2395ea4` — chore(release): v0.13.0 (#155) | `git log origin/main` |
| Latest release | **v0.13.0**: tag on `2395ea4`, stable, `releases/latest`. ZIP is 1,154,500 B, sha256 `189711e1…2156`, 71 files, embeds 0.13.0, no dev paths. A local rebuild from the tag archive gives the same sha256. | release run 37471734711 |
| CI | 20 jobs. #155 20/20 (upgrade v0.12.5 → v0.13.0; integration 116/116 × 6 WP builds); main `2395ea4` 20/20 | runs 37469725758, 37470047823 |
| Dev site | http://localhost:8790, **still v0.12.3**. Docker Desktop has been hung since the disk-full episode (`docker ps` times out). Restarting it also restarts another project's `uptime-kuma` container, so the owner must do it. | `docker ps` timeout |
| Open PRs | this checkpoint only | `gh pr list` |

## Owner decisions / blocked

- **#131:** which icon file is approved? This blocks **#125** (ZIP size).
- **#97:** whether to set up a remote dev site.
- **#99:** required checks on `main` (an admin setting).
- **Docker restart.** Then run `bin/dev-site.sh deploy --release v0.13.0` and `bin/dev-site.sh audit`. Spot-check the new UI: Credentials → Effective access preview, Connection Test → live sign-in check, and Plugins → Installed Plugins row actions.

## Next actions (when budget allows)

1. Owner: restart Docker Desktop, then deploy v0.13.0 to dev and audit (above).
2. Owner: decide #131, #97 and #99.
3. Optional local cleanup: the squash-merged feature branches (#146–#155) still have local pointers. `git branch -d` refuses them because of the squash merges; they are safe to delete with `-D` once each PR shows MERGED.

Resume: `cd <worktree> && git fetch && gh pr list -R tyhallcsu/ai-site-connector && bin/dev-site.sh status`

## How to test locally

```bash
docker start asc-mysql-test || docker run -d --name asc-mysql-test -e MYSQL_ROOT_PASSWORD=root -p 127.0.0.1:33306:3306 mysql:8.0
TMPDIR=$PWD/.tmp composer test        # composer install first; vendor/ is gitignored
WP_DB_HOST=127.0.0.1:33306 WP_DB_PASSWORD=root WP_PORT=8775 bash tests/runtime-smoke.sh
bash tests/zip-upgrade-smoke.sh "$(bin/build-release-zip.sh | tail -n1)"
```

Without Docker, these all pass locally: `composer test`, `composer lint`, `composer phpcs`, `tests/security-grep.sh` and `tests/package-smoke.sh`. GitHub CI runs everything else.

## Workers / services

- Subagents: none. Background watchers: none running.
- Docker: the `asc-dev` compose project (persistent dev site) is unreachable while Docker is hung. Stop it with `bin/dev-site.sh stop` once Docker responds.
- Sandbox: `deploy`, `rollback`, `audit` and PHPCS need a writable `TMPDIR` (the session scratchpad).
