# Handoff — AI Site Connector

**Updated:** 2026-10-06 (America/Denver) · **State:** v0.13.0 released, verified and deployed to the dev site. The owner's feature list is done. #125 merged after the owner decided #131, and is not yet released. **PAUSED** under credit budget mode (weekly limit 74%, resets 2026-10-07). · **Session:** `asc-dev-audit-ship`
**Repo:** tyhallcsu/ai-site-connector (public). The parent `ess-custom-plugins` directory is not a repo.
**Primary checkout:** `/Users/tylerhall/Documents/GitHub/ess-custom-plugins/ai-site-connector`. Its local `main` is `16f5816`, 28 commits behind `origin/main`; fast-forward it before use. This session did not modify it.
**Session worktree:** `.claude/worktrees/asc-dev-audit-ship`, branch `docs/checkpoint-125` (this checkpoint).
**Rules:** `docs/development/OPERATING_BRIEF.md` · **Dev site:** `docs/development/DEV_SITE.md` · **Log:** `docs/development/WORK_LOG.md` (evidence for everything below)

## Verified state

| Item | Value | Evidence |
|------|-------|----------|
| `origin/main` | `2395ea4` — chore(release): v0.13.0 (#155) | `git log origin/main` |
| Latest release | **v0.13.0**: tag on `2395ea4`, stable, `releases/latest`. ZIP is 1,154,500 B, sha256 `189711e1…2156`, 71 files, embeds 0.13.0, no dev paths. A local rebuild from the tag archive gives the same sha256. | release run 37471734711 |
| CI | 20 jobs. #155 20/20 (upgrade v0.12.5 → v0.13.0; integration 116/116 × 6 WP builds); main `2395ea4` 20/20 | runs 37469725758, 37470047823 |
| Dev site | http://localhost:8790 runs **v0.13.0**, deployed 2026-10-06T13:52Z from the release asset (sha256 `189711e1…2156`, the same as published). Audit: all tabs clean on desktop and phone. Headless checks pass for the access preview, the live sign-in card and the Plugins row ("Up to date (v0.13.0)", Changelog, GitHub release). | `bin/dev-site.sh status`, `audit` |
| Unreleased on main | #158 → `aa39cd2` (#125, #131): the header mark is a 7.6 KB PNG, and brand sources are out of the ZIP (1,154,500 → 312,795 B, 69 files). Package smoke enforces 400 KiB for the ZIP and 20 KiB for the mark. | PR CI run 37484091055 20/20, integration 117/117 |
| Open PRs | this checkpoint only | `gh pr list` |

## Owner decisions / blocked

- **0.13.1:** whether to release the packaging-only fix (#125). Nothing ships until the owner asks.
- **#97:** whether to set up a remote dev site.
- **#99:** required checks on `main` (an admin setting).

## Next actions (when budget allows)

1. Owner: decide #97 and #99, and whether to release 0.13.1.
2. Optional local cleanup: the squash-merged feature branches (#146–#155) still have local pointers. `git branch -d` refuses them because of the squash merges; they are safe to delete with `-D` once each PR shows MERGED.

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
- Docker: restarted 2026-10-06 at the owner's request (a hung backend, up 22 h, was force-killed). Engine 28.3.3. Running: `asc-dev-wordpress-1`, `asc-dev-db-1`, and another project's `uptime-kuma`, which came back healthy on its own. `asc-mysql-test` is stopped (disposable). Stop the dev site with `bin/dev-site.sh stop`.
- Sandbox: `deploy`, `rollback`, `audit` and PHPCS need a writable `TMPDIR` (the session scratchpad).
