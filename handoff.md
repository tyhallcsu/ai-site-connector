# Handoff — AI Site Connector

**Updated:** 2026-10-05 (America/Denver)
**Repo:** tyhallcsu/ai-site-connector · **Checkout:** `/Users/tylerhall/Documents/GitHub/ess-custom-plugins/ai-site-connector` (standalone repo, not the parent)
**Rules:** `docs/development/OPERATING_BRIEF.md` · **Plan:** `docs/development/ROADMAP.md` · **Log:** `docs/development/WORK_LOG.md`

## Current state

- Last verified `origin/main`: `e5f7ed2` (v0.9.1).
- Current milestone: **M0** — CI truthfulness (PHPUnit actually runs), in-WordPress integration harness, control docs. Branch `chore/ci-test-harness`.
- Next milestone: **M1** — finish PR #76 (draft) for #67 #68 #69 #71 #72.
- Latest release: v0.9.1. No release candidate.

## Open PRs

| PR | Branch | State |
|----|--------|-------|
| #76 | feature/queue-wordpress-mcp-diagnostics-and-export-tools | draft, CI green on `d3d6f6b`; known defects listed below; to be rebased onto M0 |

## Known defects to fix in M1 (found reviewing PR #76)

- `redirects()` uses `if/elseif`, so an active-but-tableless Rank Math blocks Redirection detection; Yoast Premium path ignores limit/offset.
- SEO write map: Rank Math `rank_math_robots` is a serialized array and SEOPress/Yoast noindex use plugin-specific encodings — a plain string write corrupts them; AIOSEO legacy `_aioseop_*` keys are ignored by AIOSEO 4. Reads cast arrays to `"Array"`.
- `update_seo_meta()` has no per-object `edit_post` check.
- `/diagnostics/page-builder?post_ids=` unbounded.
- Self-test lacks the export/temp-directory check required by #72.
- New diagnostics are REST-only; not exposed as MCP tools or WP-CLI.

## How to test locally

```bash
docker start asc-mysql-test || docker run -d --name asc-mysql-test -e MYSQL_ROOT_PASSWORD=root -p 127.0.0.1:33306:3306 mysql:8.0
TMPDIR=$PWD/.tmp composer test
WP_DB_HOST=127.0.0.1:33306 WP_DB_PASSWORD=root bash tests/runtime-smoke.sh   # PHP 8.5: set error_reporting=E_ALL&~E_DEPRECATED, display_errors=stderr
```

## Workers / services

- Active subagents: 0.
- Test service: Docker container `asc-mysql-test` (MySQL 8.0, port 33306) — disposable; remove with `docker rm -f asc-mysql-test`.

## Next three actions

1. Push `chore/ci-test-harness`, open PR, watch CI, merge.
2. Rebase PR #76 onto new main; fix the defects above with integration tests; add MCP tools + WP-CLI subcommands.
3. Start M2 (#63 content inventory).

Resume: `cd /Users/tylerhall/Documents/GitHub/ess-custom-plugins/ai-site-connector && git fetch && gh pr list -R tyhallcsu/ai-site-connector`
