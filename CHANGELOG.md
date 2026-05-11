# Changelog

All notable changes to AI Site Connector are documented here. The format
loosely follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and the project follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.9.1] - 2026-05-11

Compatibility + connection-pack UX patch. Closes #55 and #62.

### Changed

- `readme.txt` `Tested up to: 6.5` → `6.9`. Grounds the public compatibility
  claim in the green `wordpress-runtime` CI job, which has been exercising
  `WP_VERSION=latest` (= 6.9.x at this writing — wordpress.org reports
  `current: 6.9.4`) against every push since v0.7.0. Closes #55.
- `wordpress-version-compat` CI matrix gains `6.8` and `6.9` rows (still
  `continue-on-error: true`). Named-version smoke now spans 5.6 → 6.9,
  complementing the unnamed `latest` row.
- **Connection-pack: Claude Desktop / Cursor MCP tabs now use `mcp-remote`**
  instead of pointing at a local stdio bridge with an
  `/absolute/path/to/your-wordpress-mcp-server/index.js` placeholder
  (closes #62). The generated `claude_desktop_config.json` /
  `.cursor/mcp.json` snippet now drives
  `npx -y mcp-remote <site>/wp-json/ai-site-connector/v1/mcp --header
  Authorization:Basic <b64(user:app-password)>`, with the Basic Auth header
  pre-baked at generation time. Paste the snippet, restart Claude Desktop /
  Cursor, done — no path edit, no `npm install`, no env-var wire-up. Node
  18+ requirement on the operator's machine is unchanged.
- `examples/mcp-server/README.md` and the top-level `README.md` reframe the
  bundled local stdio bridge as the **advanced fallback** for air-gapped,
  debugging, and hand-modified workflows. The bridge source still ships
  unchanged — it just isn't the recommended path anymore.

### Notes

- No source / class / REST behavior changes. The plugin's HTTP MCP endpoint
  at `POST /wp-json/ai-site-connector/v1/mcp` (`AI_Site_Connector_MCP_Server`,
  JSON-RPC 2.0, protocol version `2025-06-18`) is unchanged — the new
  snippet just stops asking the operator to also run a local Node bridge
  on top of it.
- No new auth surface. The Basic Auth credential was already plaintext in
  the previous `env` block in the same `claude_desktop_config.json` file,
  so the at-rest threat model is identical.
- `examples/mcp-server/package.json` bumped to 0.9.1 in lockstep with the
  plugin to satisfy the version-sync guard added in v0.9.0.

## [0.9.0] - 2026-05-11

Audit-approved batch release. Closes 12 issues opened during the v0.8.1
post-release audit (issues #46–#58). Highlights: two SSRF guards, a
constant-first secret-storage path, CI back on green, and substantial
documentation work.

### Added

- `includes/class-url-guard.php` — shared SSRF guard with
  `check_outbound_safe($url, $context)` and a pure `is_blocked_ip($ip)`
  helper. Uses PHP's `FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE`
  plus an explicit `169.254.*` belt-and-suspenders check; resolves every A
  + AAAA record before deciding. New filter
  `ai_site_connector_url_guard_allow_host` for operators with legitimate
  internal endpoints.
- Three wp-config constants for secret storage, all constant-first with
  the existing wp_option as fallback: `AI_SITE_CONNECTOR_WEBHOOK_SECRET`,
  `AI_SITE_CONNECTOR_CLOUDFLARE_TOKEN`, `AI_SITE_CONNECTOR_CLOUDFLARE_ZONE_ID`.
  New helper `AI_Site_Connector_Audit_Webhook::secret_source()` returns
  `'constant' | 'option' | 'none'`.
- New `docs/DISCOVERY.md` documents the
  `/.well-known/ai-site-connector.json` payload, every field, the
  `spec_version` contract, the kill switch, and how to consume it.
- README gains a "MCP — Claude Desktop / Cursor (stdio bridge)" section
  linking to `examples/mcp-server/README.md`, and a "Discovery" section
  linking to `docs/DISCOVERY.md`.
- PHP 8.4 in the CI lint matrix (8.5 deferred until `shivammathur/setup-php`
  ships a stable image).
- New `wordpress-version-compat` CI job runs `tests/runtime-smoke.sh`
  against WP 5.6 and WP 6.5 in addition to `latest`. Marked
  `continue-on-error: true` initially.
- Release workflow now guards: CHANGELOG entry presence for the resolved
  version, and MCP example version matching the plugin version.
- Runtime smoke now validates the discovery JSON shape (every documented
  field present, correct types).
- PHPUnit coverage: `test-class-discovery.php` pins the public payload
  shape; `test-class-permissions.php` covers catalog defaults +
  `require_permission()` deny/allow paths; `test-class-url-guard.php`
  covers the SSRF blocklist.

### Changed

- `POST /media/sideload` and the audit-log webhook delivery now run every
  outbound URL through `AI_Site_Connector_Url_Guard::check_outbound_safe()`.
  At save time the admin gets an inline error; at send time the failure is
  recorded on the audit log. Previously the only validation was a
  scheme-only check.
- `class-discovery.php` `maybe_serve()` is refactored to call a new pure
  `build_payload()` method — no HTTP behavior change, but the payload
  shape is now unit-testable.
- README REST endpoints section enumerates all 19 routes grouped by
  purpose; the misleading "no write endpoints" claim (and matching line
  in `class-rest-controller.php`'s file header) is replaced with a
  "permission-slug gated write surface" framing that matches what the
  plugin has actually shipped since v0.4.0.

### Fixed

- CI on `main` is green again. `WordPress.Security.ValidatedSanitizedInput.InputNotSanitized`
  on `$_POST['ai_site_connector_perms']` in `class-permissions.php` —
  values are now `array_map( 'rest_sanitize_boolean', wp_unslash( ... ) )`'d.
  `WordPress.WP.I18n.UnorderedPlaceholdersText` on the export-result
  renderer — placeholders reordered and translators comments added.
- `examples/mcp-server/package.json` was at `0.8.0` while the plugin had
  shipped `0.8.1`. Bumped to match, and the release workflow now refuses
  to publish a tag when the two diverge.

### Security

- Two SSRF holes (media sideload, webhook delivery) closed by the URL
  guard — both could have been used by an authenticated caller to probe
  cloud metadata endpoints or internal services.
- Two plaintext-in-options secrets (webhook HMAC, Cloudflare token) now
  have a constant-first read path; encryption at rest remains a possible
  future improvement.

### Documentation

- `CHANGELOG.md` backfilled with entries for v0.5.0 → v0.8.1 (which had
  drifted off the file while shipping through `readme.txt`).
- Plugin header documents the new wp-config constants.

## [0.8.1] - 2026-05-11

### Fixed

- Clicking the in-plugin "Update now" button no longer leaves the plugin deactivated after the file swap (closes #44). `Plugin_Upgrader::upgrade()` deactivates the plugin via `upgrader_pre_install` but never re-activates on the single-plugin code path — only the bulk-upgrade path does that. `handle_run_update` now captures the pre-upgrade active state and explicitly calls `activate_plugin()` after a successful swap. If re-activation fails (e.g. the new code has a fatal), the redirect now bounces to the core Plugins screen so the operator sees the error inline instead of getting a 403 on the plugin's admin page (which the deactivated plugin no longer registers).

### Added

- New audit events: `update_reactivated` on the recovery path; `update_reactivation_failed` when `activate_plugin()` returns a `WP_Error`.

### Notes

- Affects every release that shipped the self-updater (v0.2.0 through v0.8.0). The native Dashboard → Updates / Plugins-screen "Update" buttons and `wp plugin update ai-site-connector` were never affected — only the in-plugin "Update now" path.

## [0.8.0] - 2026-05-11

Discovery + quality release.

### Added

- OpenAPI 3 spec at `GET /wp-json/ai-site-connector/v1/openapi.json` (closes #17). Generated from the live REST registry — new routes appear automatically. 1-hour cache, busted on plugin version change. Public read-only.
- `/.well-known/ai-site-connector.json` discovery file (closes #18). Tiny public JSON that AI tools can fetch to auto-detect the plugin's REST namespace, MCP endpoint, OpenAPI URL, and supported auth methods. New `AI_SITE_CONNECTOR_DISCOVERY_DISABLE` kill switch.
- Bundled stdio MCP server source in `examples/mcp-server/` (closes #16). Node 18+ Server that speaks MCP over stdio (Claude Desktop / Cursor's preferred transport) and forwards every `tools/call` to the plugin's HTTP MCP endpoint. Ships source-only — `npm install` locally then point your AI tool's config at `node /path/to/index.mjs`.
- PHPUnit unit-test suite (closes #3). wp-mock based — runs in CI in under 5 seconds with no MySQL. Covers caps map (`class-roles`), Application Password wrapper contract (`class-application-passwords`), and per-password meta matcher helpers (CIDR + route scope). New CI job runs `composer test` on every PR.

## [0.7.0] - 2026-05-11

Observability release — visibility into audit events and per-credential usage.

### Added

- Audit log: date range + free-text search + 50-rows-per-page pagination, on top of the action/tool/status filters + CSV export that shipped in v0.4.0 (closes #20).
- Per-Application-Password usage roll-ups (closes #19): request counts, error counts, top routes by UUID, 7-day window on the Credentials tab. Sampling knob `AI_SITE_CONNECTOR_USAGE_SAMPLE_RATE` for high-traffic sites.
- Audit-log webhook forwarder (closes #14): post every selected event to Slack / Discord / Datadog / generic JSON. Non-blocking delivery, HMAC-SHA256 signature when a secret is configured, host-only redacted error logging.
- One-click diagnostic report download (closes #21): button on the Diagnostics tab streams the full `Diagnostics::generate()` payload as a JSON attachment. New filter `ai_site_connector_diagnostic_report` for redaction.
- Configurable audit-log retention in the admin UI (closes #23): numeric input on the Audit tab, server-clamped to [1, 3650] days. Filter still wins when defined in code.
- New internal action `ai_site_connector_audit_recorded` so future features (and 3rd-party code) can react to audit writes without polling.

## [0.6.0] - 2026-05-11

Hardening release — per-Application-Password security controls.

### Added

- Atomic password rotation (closes #4): `wp ai-connector rotate-password` + admin "Rotate" button + `POST /credentials/rotate-password` REST route. Mints a new password preserving scopes/IP/expiry, revokes the old in one atomic step, rolls back on failure.
- One-time-token connection-pack download (closes #6): generate a single-use signed URL the admin can DM. Returns the pack JSON exactly once with `Content-Disposition: attachment`, then 410s. 5-minute TTL.
- Per-password REST scopes (closes #12): allow-list of method+route entries per Application Password. Enforced at `rest_pre_dispatch` priority 9 with 403 `scope_off`. UI checkbox tree on the Credentials tab.
- Application Password expiration (closes #13): optional `expires_at` per password. Same-day expiries refused at REST auth with 401 `expired`. Daily WP-Cron auto-revokes expired credentials and sends a reminder email 7 days before expiry.
- Per-password IP allowlist (closes #15): CIDR ranges (IPv4 + IPv6) per password. Enforced at REST auth with 403 `ip_off`. Reverse-proxy aware via `WP_TRUSTED_PROXIES` constant + `ai_site_connector_request_ip` filter.

### Changed

- New shared infrastructure: `AI_Site_Connector_App_Password_Meta` (sidecar metadata for scopes/IP/expiry/usage counters), `AI_Site_Connector_App_Password_Resolver` (per-request UUID resolver hooking the WP 5.7+ `application_password_did_authenticate` action).

## [0.5.2] - 2026-05-11

### Fixed

- Self-updater Updates card now auto-populates on first visit instead of showing "Not checked yet" until WordPress's own `update_plugins` schedule fires. New `AI_Site_Connector_Updater::ensure_check()` does a synchronous GitHub fetch when the cache is empty.
- Defensive `class_exists()` guard around the Updates card render — if the updater file is ever missing or surgically disabled, the card now degrades gracefully instead of throwing.

### Added

- Daily WP-Cron `ai_site_connector_update_check` pre-warms the release cache so fleet operators who never open the admin still get fresh status.

### Changed

- Activation now clears the `update_plugins` and AI Site Connector release transients so newly-activated installs immediately check fresh.

## [0.5.0] - 2026-05-11

### Added

- First-run onboarding wizard with a 5-step "Get Started" tab + welcome notice (closes #31).
- Backup-before-update + one-click rollback. Each self-update snapshots the previous plugin folder to `wp-content/upgrade-backups/ai-site-connector/{version}/`. Keeps the last 3, never deletes the currently-installed version (closes #32).
- Pre-flight verification on Application Password generation. Server-side probe of `/wp/v2/users/me` with the new credentials, with per-status hints (401 = Authorization-header stripping, 403 = caps/WAF, 404 = REST off, 5xx = server error) (closes #33).
- Sample agent code in `examples/{python,node,bash}/` — three runnable reference clients demonstrating the full flow (health, list, create, upload) (closes #34).
- MCP HTTP transport endpoint at `/wp-json/ai-site-connector/v1/mcp` speaking JSON-RPC 2.0. Supports `initialize`, `tools/list`, `tools/call`, `ping`. 9 tools wrap the plugin's REST endpoints + core WP post operations via internal `rest_do_request`. New `AI_SITE_CONNECTOR_MCP_DISABLE` constant (closes #35).
- Daily / weekly audit-log email digest. Configurable cadence, recipients, "Send test digest now". Empty windows skipped on auto-runs (closes #36).
- In-admin REST endpoint explorer ("API Explorer" tab). Lists the plugin's REST routes plus a curated set of `wp/v2/*` routes with an inline "Try it" button. Internal dispatch via `rest_do_request` — no HTTP loopback (closes #37).

## [0.4.0] - 2026-05-11

Numbered 0.4.0 (not 0.2.0) because v0.2.0 and v0.3.0 had already been
published from a parallel `claude/feature-batch-2` branch (native GH
updater, exec-bit CI fix, tool-specific connection packs) before this
feature batch landed on `main`. Skipping the colliding numbers keeps
the published-versions timeline monotonic.



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
