# Release notes — v0.2.0

Adds the MCP tool surface, permission guard, audit log v2, media upload,
cache purge, and export helpers. Backwards-compatible with v0.1 — every
existing REST endpoint and admin form still works unchanged.

## What's new

- **MCP tool surface** — seven new tools registered under
  `/ai-site-connector/v1/`:
  `GET /tools`, `GET /diagnostics/site-report`,
  `POST /cache/purge`, `POST /media/sideload`,
  `GET /export/media-manifest`, `/export/recent-changes`,
  `/export/page/<id>`, `/export/site-manifest`.
- **Permission guard** (`AI_Site_Connector_Permissions`). Conservative
  defaults: only read-only introspection and export are on out of the
  box. Operators opt in to write/destructive tools explicitly via the
  new **Permissions** admin tab. A global **read-only mode** toggle
  implicitly denies every non-read tool.
- **Audit log v2** — extended schema (`tool`, `target_type`,
  `target_id`, `status`, `summary`, `request_hash`, `ip_hash`, `meta`).
  IP storage is now SHA-256-hashed by default (filterable). Admin Audit
  Log tab gains a filter form and CSV download.
- **Connection Test tab** — pass/fail badges for every link in the MCP
  chain, available-tools table, copy-paste agent prompt with no secret.
- **Diagnostics tab** — full site capability report (WP/PHP versions,
  detected plugins, page builders, SEO and cache plugins, env limits)
  plus a "purge all caches" button.
- **Export tab** — write JSON manifests under
  `wp-content/uploads/ai-site-connector/exports/` for downstream
  repo-sync workflows. No GitHub credentials required.

See [`CHANGELOG.md`](../CHANGELOG.md) and
[`docs/FEATURES.md`](FEATURES.md) for the full surface.

## Upgrade notes

- DB schema migrates v1 → v2 automatically on first load.
- Existing AI agents continue to work — no changes required on the
  client side.
- **New write tools are off by default.** After upgrade, visit
  Tools → AI Site Connector → Permissions and enable the tools you want
  to expose. Most installs will want at minimum:
  - `view_diagnostics` (already on)
  - `export_manifest` (already on)
  - `purge_cache` (turn on if you're going to give an agent the "publish
    + bust the cache" flow)
  - `upload_media` + `update_seo` (turn on if you're going to let an
    agent add featured images and social-image meta)

## Security

- Every new write path is gated by both the WP capability check AND the
  per-tool permission whitelist.
- No new use of `eval` / `shell_exec` / `base64_decode` / etc.
  `tests/security-grep.sh` continues to enforce the forbidden-function
  list.
- Cloudflare cache purge reads token + zone from WP options only — never
  hardcoded.
- Export directory gets an auto-`Options -Indexes` `.htaccess` with
  `X-Robots-Tag: noindex, nofollow`.

## Files added

- `includes/class-permissions.php`
- `includes/class-diagnostics.php`
- `includes/class-cache.php`
- `includes/class-media.php`
- `includes/class-export.php`
- `CHANGELOG.md`
- `docs/FEATURES.md`
- `docs/RELEASE_CHECKLIST.md`
- `docs/RELEASE_NOTES_0.2.0.md`

## Files changed

- `ai-site-connector.php` — load new classes, version bump.
- `includes/class-plugin.php` — boot new modules.
- `includes/class-audit-log.php` — schema v2 + extended `record()`.
- `includes/class-rest-controller.php` — new tool routes + last-request
  hook + tools catalog.
- `includes/class-admin-page.php` — new tabs (Connection Test,
  Permissions, Diagnostics, Export), filter UI + CSV on Audit tab.
- `readme.txt` — Stable tag + changelog.

## Issues closed

- #24 — Add MCP connection test admin page
- #25 — Add tool whitelist and permission guard
- #26 — Add audit log for MCP and agent actions
- #27 — Add safe media upload tool with SEO metadata
- #28 — Add cache purge MCP tool
- #29 — Add GitHub/repo sync helper and export manifest
- #30 — Add site capability report MCP tool
