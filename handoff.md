# Handoff — AI Site Connector

**Updated:** 2026-10-09 (America/Denver) · **State:** v0.14.0 released, verified and on the dev site. No open issues and no open PRs. **PAUSED.** · **Session:** `asc-dev-audit-ship`
**Repo:** tyhallcsu/ai-site-connector (public). The parent `ess-custom-plugins` directory is not a repo.
**Primary checkout:** `/Users/tylerhall/Documents/GitHub/ess-custom-plugins/ai-site-connector`. Its local `main` is `16f5816`, far behind `origin/main`; fast-forward it before use. This session did not modify it.
**Session worktree:** `.claude/worktrees/asc-dev-audit-ship`, branch `docs/checkpoint-0.14.0` (this checkpoint).
**Rules:** `docs/development/OPERATING_BRIEF.md` · **Dev site:** `docs/development/DEV_SITE.md` · **Log:** `docs/development/WORK_LOG.md` (evidence for everything below)

## Verified state

| Item | Value | Evidence |
|------|-------|----------|
| `origin/main` | `48a2851` — chore(release): v0.14.0 (#173), plus this checkpoint | `git log origin/main` |
| Latest release | **v0.14.0**: tag on `48a2851`, stable, `releases/latest`. ZIP is 314,945 B with 69 files, sha256 `ce60d54f…8177`. Embeds 0.14.0; no dev paths. A local rebuild of the tag gives the same bytes. | release run 37914458451 |
| Unreleased on main | nothing apart from docs | `git log v0.14.0..origin/main` |
| Branch protection | `main` requires `CI gate`, reported by GitHub Actions. It passes only when every CI job succeeded, including each WP compat row (#170). Branches don't have to be up to date, no review is required, admins are not enforced, and force pushes and deletions are off. Agents never use the admin override. | `gh api repos/tyhallcsu/ai-site-connector/branches/main/protection` |
| CI | 21 jobs. Compat rows block, and downloads retry (#170). #173 21/21 (upgrade v0.13.1 → v0.14.0; integration 117/117 × 6 WP builds); main `48a2851` 21/21. | runs 37913909226, 37914223152 |
| Dev site | http://localhost:8790 runs **v0.14.0** from the release asset (same sha256), and the audit is clean on every tab. `access-preview` was checked against real enforcement: for `ai-agent` on post 8 it predicted `asc_forbidden_post`, and a real dry-run update failed with that code. `bin/dev-site.sh` now gives up on a hung Docker and flags a stale deploy record (#166). | `bin/dev-site.sh status`, WORK_LOG 2026-10-09 |

## Owner decisions / blocked

None open. #97: the local dev site is canonical. #99: `CI gate` is required. #131: the newer mint/cyan icon.

## Next actions

1. Nothing is required. New work starts from `gh issue list` (empty at this checkpoint).
2. Docker Desktop has hung twice (2026-10-05 and 2026-10-07). `bin/dev-site.sh` now fails fast with recovery steps; the recovery itself is in `DEV_SITE.md`.

Resume: `cd <worktree> && git fetch && gh pr list -R tyhallcsu/ai-site-connector && bin/dev-site.sh status`

## How to test locally

```bash
docker start asc-mysql-test || docker run -d --name asc-mysql-test -e MYSQL_ROOT_PASSWORD=root -p 127.0.0.1:33306:3306 mysql:8.0
TMPDIR=$PWD/.tmp composer test        # composer install first; vendor/ is gitignored
WP_DB_HOST=127.0.0.1:33306 WP_DB_PASSWORD=root WP_PORT=8775 bash tests/runtime-smoke.sh
bash tests/zip-upgrade-smoke.sh "$(bin/build-release-zip.sh | tail -n1)"
```

Without Docker, these all pass locally: `composer test`, `composer lint`, `composer phpcs`, `tests/security-grep.sh`, `tests/package-smoke.sh` (400 KiB ZIP budget, 20 KiB header mark) and `tests/dev-site-guard.sh`.

## Workers / services

- Subagents: none. Background watchers: none running.
- Docker engine 28.3.3. Running: `asc-dev-wordpress-1`, `asc-dev-db-1`, and another project's `uptime-kuma` (leave it alone). `asc-mysql-test` is stopped (disposable). Stop the dev site with `bin/dev-site.sh stop`.
- Sandbox: `deploy`, `rollback`, `audit` and PHPCS need a writable `TMPDIR` (the session scratchpad). `audit` uses `ASC_DEV_CACHE` set to a scratchpad Playwright install.
