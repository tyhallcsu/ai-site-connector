# Changelog

All notable changes to AI Site Connector are documented here. The format
loosely follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and the project follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.2.0] - 2026-05-11

### Added

#### MCP tool surface

- **Connection Test tab** (Tools → AI Site Connector → Connection Test). Verifies every link in the MCP chain — HTTPS, REST API reachable, Application Passwords available, MCP routes registered, read-only mode, last successful MCP request timestamp. Lists every tool with its allow/deny state for the current user. Includes a copy-paste agent prompt that contains no secret.
- **Tool whitelist + permission guard** (`includes/class-permissions.php`). Central gate consulted by every MCP tool before execution. Permission keys:
  - `read_content` (default on)
  - `view_diagnostics` (default on)
  - `export_manifest` (default on)
  - `write_content`
  - `upload_media`
  - `update_seo`
  - `purge_cache`
  - `update_options`
  - `destructive_operations`
  - Global **read-only mode** toggle implicitly denies every non-read tool.
- **Site capability report** at `GET /ai-site-connector/v1/diagnostics/site-report`. WordPress/PHP versions, active theme + parent, active plugins normalized, page-builder detection (Elementor, Beaver Builder, Divi, block themes, Oxygen, Bricks), SEO plugin detection (Rank Math, Yoast, AIOSEO, SEOPress), cache plugin detection, REST namespace routes, current user capability snapshot, ini limits, cron health, uploads writability.
- **Cache purge tool** at `POST /ai-site-connector/v1/cache/purge`. Layers (each independently toggleable in the request body):
  - `object` — `wp_cache_flush()`
  - `rocket` — `rocket_clean_domain()` + `rocket_clean_minify()`
  - `litespeed` — `LiteSpeed_Cache_API::purge_all()` / `\LiteSpeed\Purge::purge_all()` / `litespeed_purge_all` action
  - `w3tc` — `w3tc_flush_all()`
  - `elementor` — `\Elementor\Plugin::$instance->files_manager->clear_cache()`
  - `cloudflare` — REST call to `api.cloudflare.com/.../purge_cache` (only when both `ai_site_connector_cloudflare_api_token` and `ai_site_connector_cloudflare_zone_id` options are set)
  - Returns structured `{ success, purged[], skipped[], warnings[] }`.
- **Safe media upload** at `POST /ai-site-connector/v1/media/sideload`. URL-sideload only (no `base64_decode`, no multipart hand-rolling). Sanitises filename, validates mime via `wp_handle_sideload`, sets attachment title/alt/caption/description, optionally `set_post_thumbnail()` and writes Yoast / Rank Math social-image meta when those plugins are active. AIOSEO is detected and reported as a warning (custom-table backend, not safely auto-writable).
- **Export / repo-sync helpers**:
  - `GET /export/media-manifest` — attachment_id, url, filename, title, alt, caption, description, attached_to, mime_type, size_bytes, sha256 (best-effort, capped at 200 MB per file), modified_gmt.
  - `GET /export/recent-changes` — posts + pages newer than `since`, with content hash for diffing.
  - `GET /export/page/<id>` — single post/page body with featured-image reference.
  - `GET /export/site-manifest` — counts + recent + detected plugins in one call.
  - Admin "Export" tab buttons write the same payloads to `wp-content/uploads/ai-site-connector/exports/*.json` with an auto-dropped `noindex` `.htaccess`.
- **Available-tools list** at `GET /tools` — name, method, route, permission key, allow state for the calling user, last successful MCP request timestamp.

#### Audit log v2

- Schema upgrade adds `tool`, `target_type`, `target_id`, `status`, `summary`, `request_hash`, `ip_hash`, `meta` columns plus indexes on `tool`, `status`, and `(target_type, target_id)`. dbDelta migrates v1 tables in place; pre-existing rows keep their original values and get defaults for new columns.
- `ip_hash` is SHA-256 keyed by `wp_salt('auth')` — same visitor on a different site hashes to a different value, defeating cross-site correlation from a stolen dump.
- New `record()` signature accepts `tool`, `status` (`success` / `failure` / `denied` / `info`), `target_type`, `target_id`, `summary`, `request_hash`, and `meta` (JSON-encoded, 64 KB cap). The legacy v1 signature (`actor_user_id`, `target_user_id`, `message`) keeps working — additions are purely opt-in.
- New filters:
  - `ai_site_connector_log_raw_ip` — return `''` to store only the hash, no raw IP at rest.
  - `ai_site_connector_can_execute_tool` — final allow/deny override for every tool decision.
- Admin Audit Log tab gains a filter form (action, tool, status) and a "Download filtered rows as CSV" button.

### Changed

- Tool denials are themselves audited (`tool_denied` action) with the deny reason in `meta.reason` — silent denies were a security anti-pattern.
- The `/health` and `/me/capabilities` endpoints are unchanged but now coexist with the v0.2 tool surface.

### Security

- Every new write path is gated by `AI_Site_Connector_Permissions::require_permission()` AND the underlying WP capability check.
- No new use of `eval`, `shell_exec`, `exec`, `passthru`, `system`, `proc_open`, `popen`, `assert`, `create_function`, or `base64_decode`. `tests/security-grep.sh` enforces this.
- Cloudflare API token and zone ID are read from WP options (`ai_site_connector_cloudflare_api_token`, `ai_site_connector_cloudflare_zone_id`) only — never hardcoded. Cache purge skips Cloudflare entirely when either is empty.
- Export directory gets an auto-dropped `.htaccess` with `Options -Indexes` and `X-Robots-Tag: noindex, nofollow`.

### Documentation

- New `docs/FEATURES.md` — per-feature reference with REST request shapes.
- New `docs/RELEASE_CHECKLIST.md` — release process.
- New `docs/RELEASE_NOTES_0.2.0.md`.
- `docs/SECURITY_MODEL.md` extended with the permission-guard layer and audit-log v2 retention contract.

### Migration notes

- DB schema upgrade from v1 → v2 runs automatically on `plugins_loaded` (`maybe_upgrade()`). dbDelta is non-destructive — existing rows are preserved.
- No breaking changes to the v0.1 REST surface. Existing AI agents continue to work unchanged.
- Tool permissions default conservatively: any write/destructive tool must be explicitly enabled in the new Permissions tab before agents can call it. Read-only introspection (`read_content`, `view_diagnostics`, `export_manifest`) is on by default.

## [0.1.0] - Initial release
