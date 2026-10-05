# Roadmap — diagnostics / export / content-update queue

Source of truth for issue state is GitHub; this file records ordering,
acceptance gates, and release grouping. Operating rules:
[`OPERATING_BRIEF.md`](OPERATING_BRIEF.md). Resumable state: [`/handoff.md`](../../handoff.md).

## Milestones (dependency order)

| # | Milestone | Issues | Depends on | Status |
|---|-----------|--------|------------|--------|
| M0 | CI truthfulness + integration harness + control docs | — | — | merged #78 (`2dd96bc`) |
| M1 | Finish PR #76: self-test, REST routes, page builder, redirects, SEO abstraction hardening, MCP + WP-CLI exposure | #67 #68 #69 #71 #72 (+#75 partial) | M0 | merged #76 (`c7f689b`) |
| M2 | Content inventory (JSON + CSV) + CLI | #63 (+#75 partial) | M1 (SEO read) | in review |
| M3 | Media SEO audit + duplicate media + CLI | #64 #65 (+#75 partial) | M0 | queued |
| M4 | Broken internal link scanner + CLI | #66 (+#75 partial) | M0 | queued |
| M5 | Export bundle + deterministic manifests + CLI `export` | #73 #74 (+#75 partial) | M1–M4 | queued |
| M6 | WP-CLI remainder (`status`, `enable`/`disable` guards) — close #75 | #75 | M1–M5 | queued |
| M7 | Safe content update (dry-run, snapshot, rollback) | #70 | M1 (SEO write), backup manager | queued |
| M8 | Superseded branch cleanup | #59 | — | done (branch deleted, #59 closed) |

## Acceptance gates (every milestone)

- Behaviour implemented through a shared service class; REST, MCP and WP-CLI
  call the same service.
- Integration cases in `tests/integration/` cover: denied access (anonymous +
  under-privileged), malformed input, pagination bounds, empty-site behaviour,
  and a DB fingerprint proving read-only tools do not mutate.
- `composer test`, `composer lint`, `composer phpcs`, `tests/security-grep.sh`,
  `tests/package-smoke.sh`, `tests/runtime-smoke.sh` all pass locally and in CI.
- Write-capable work (M7) gets a second-pass review before merge.

## Release grouping

- **0.10.0** — M0–M4 (new read-only diagnostics/audit tools; additive, no
  contract changes). Cut once M1 + at least one of M2–M4 is merged and green.
- **0.11.0** — M5 + M6 (export bundle/manifests, CLI completion).
- **0.12.0** — M7 (first new write surface; default-off).

Pre-1.0 minor bumps signal new tool surface; patch bumps are fixes only.
