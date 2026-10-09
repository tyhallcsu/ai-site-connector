# Handoff — AI Site Connector

**Updated:** 2026-10-08 (America/Denver) · **State:** v0.13.1 released and on the dev site. #166 and #167 are merged but not yet released. One open issue: #170 (CI). **PAUSED.** · **Session:** `asc-dev-audit-ship`
**Repo:** tyhallcsu/ai-site-connector (public). The parent `ess-custom-plugins` directory is not a repo.
**Primary checkout:** `/Users/tylerhall/Documents/GitHub/ess-custom-plugins/ai-site-connector`. Its local `main` is `16f5816`, far behind `origin/main`; fast-forward it before use. This session did not modify it.
**Session worktree:** `.claude/worktrees/asc-dev-audit-ship`, branch `docs/checkpoint-166-167` (this checkpoint).
**Rules:** `docs/development/OPERATING_BRIEF.md` · **Dev site:** `docs/development/DEV_SITE.md` · **Log:** `docs/development/WORK_LOG.md` (evidence for everything below)

## Verified state

| Item | Value | Evidence |
|------|-------|----------|
| `origin/main` | `dcdf376` — feat(cli): wp ai-connector access-preview (#169) | `git log origin/main` |
| Latest release | **v0.13.1**: tag on `345ffc6`, stable, `releases/latest`. ZIP is 313,205 B with 69 files, sha256 `4fa08c24…bd4e`. Embeds 0.13.1; no `assets/brand/`; no dev paths. A local rebuild of the tag gives the same bytes. | release run 37492378604 |
| Unreleased on main | **Plugin:** #169, `wp ai-connector access-preview` (#167), a new CLI command, so the next release should be 0.14.0. **Tooling and docs:** #161 CI gate; #168 dev-site Docker hang guard and stale-record warning (#166); runbook updates. Nothing ships until the owner asks. | `git log v0.13.1..origin/main` |
| Branch protection | `main` requires the `CI gate` check, reported by GitHub Actions. Branches don't have to be up to date, no review is required, admins are not enforced, and force pushes and deletions are off. Agents never use the admin override. | `gh api repos/tyhallcsu/ai-site-connector/branches/main/protection` |
| CI | 21 jobs; `CI gate` needs all 11 job groups. #160 20/20 (upgrade v0.13.0 → v0.13.1; integration 117/117 × 6 WP builds). #161 21/21. | runs 37491800393, 37492301242 |
| Dev site | http://localhost:8790 runs **v0.13.1** from the release asset (same sha256). Audit: all tabs clean. Header logo `…mark-128.png?ver=0.13.1`: 128×128 shown at 64×64, HTTP 200, 7,593 B. This local instance is the canonical dev target (#97). | `bin/dev-site.sh status`, `audit` |
| Dev audit (CLI/REST/MCP) | 20 checks pass on v0.13.1: content update dry run, apply and rollback; inventory; 21 MCP tools and snapshot discovery; #111, #118 and #120 behaviour; bundle; auth. No new issues. One grep hit was a false positive: core route argument names (`password`) in `rest-routes.json`, not secrets. | WORK_LOG 2026-10-06 |

## Owner decisions / blocked

None open. #97 is closed: no remote dev site; the local instance is canonical. #99 is decided: `CI gate` is required. #131 is decided: the newer mint/cyan icon.

## Next actions

1. #170 (P2): the WP compat rows still have `continue-on-error`, so a failing row doesn't fail `CI gate`. The runtime smoke's downloads also have no retry; a 504 outage on 2026-10-09 UTC broke CI runs until re-run.
2. Release 0.14.0 when the owner asks; it would ship #167.
3. Local branches: this session's merged branches were pruned on 2026-10-08. Earlier sessions' merged branches were left in place.

Resume: `cd <worktree> && git fetch && gh pr list -R tyhallcsu/ai-site-connector && bin/dev-site.sh status`

## How to test locally

```bash
docker start asc-mysql-test || docker run -d --name asc-mysql-test -e MYSQL_ROOT_PASSWORD=root -p 127.0.0.1:33306:3306 mysql:8.0
TMPDIR=$PWD/.tmp composer test        # composer install first; vendor/ is gitignored
WP_DB_HOST=127.0.0.1:33306 WP_DB_PASSWORD=root WP_PORT=8775 bash tests/runtime-smoke.sh
bash tests/zip-upgrade-smoke.sh "$(bin/build-release-zip.sh | tail -n1)"
```

Without Docker, these all pass locally: `composer test`, `composer lint`, `composer phpcs`, `tests/security-grep.sh` and `tests/package-smoke.sh`. The package smoke enforces a 400 KiB ZIP and a 20 KiB header mark.

## Workers / services

- Subagents: none. Background watchers: none running.
- Docker Desktop hung twice (2026-10-05 and overnight 2026-10-06/07). It was recovered both times, and `DEV_SITE.md` has the steps. Engine 28.3.3. Running: `asc-dev-wordpress-1`, `asc-dev-db-1`, and another project's `uptime-kuma` (leave it alone). `asc-mysql-test` is stopped (disposable). Stop the dev site with `bin/dev-site.sh stop`.
- Sandbox: `deploy`, `rollback`, `audit` and PHPCS need a writable `TMPDIR` (the session scratchpad). `audit` uses `ASC_DEV_CACHE` set to a scratchpad Playwright install.
