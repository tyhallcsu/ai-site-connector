=== AI Site Connector ===
Contributors: sharmanhall
Tags: rest-api, application-passwords, claude, ai, codex, automation
Requires at least: 5.6
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 0.5.2
License: MIT
License URI: https://opensource.org/licenses/MIT

Connect Claude / Codex / AI coding agents to a self-hosted WordPress site over the REST API using Application Passwords. No WordPress.com required.

== Description ==
AI Site Connector adds a Tools → AI Site Connector admin page that helps you:

* Create a dedicated AI/service user with a least-privilege "AI Site Operator" role
* Generate WordPress Application Passwords for that user
* Copy a "connection pack" that Claude / Codex / scripts can use over HTTP Basic Auth
* Audit credential creation and revocation
* Diagnose REST API / HTTPS / Application Passwords availability

This plugin uses only WordPress core APIs. WordPress.com, Jetpack, and external services are NOT required.

== Installation ==
1. Upload the plugin to /wp-content/plugins/
2. Activate "AI Site Connector"
3. Go to Tools → AI Site Connector

== Frequently Asked Questions ==
= Does this work without Jetpack or WordPress.com? =
Yes. The plugin uses WordPress core Application Passwords only.

= Where is the password stored? =
The plugin stores ONLY metadata (uuid, name, created, last_used). The plaintext is shown once at creation time and never written by this plugin.

= Can I extend the AI Site Operator role? =
Yes — use the apply_filters( 'ai_site_connector_operator_caps', $caps ) filter.

== Changelog ==
= 0.5.2 =
* Fix: self-updater Updates card now auto-populates on first visit instead of showing "Not checked yet" until WordPress's own update_plugins schedule fires. New `AI_Site_Connector_Updater::ensure_check()` does a synchronous GitHub fetch when the cache is empty.
* New: daily WP-Cron `ai_site_connector_update_check` pre-warms the release cache so fleet operators who never open the admin still get fresh status.
* Activation now clears the update_plugins and AI Site Connector release transients so newly-activated installs immediately check fresh.
* Defensive `class_exists()` guard around the Updates card render — if the updater file is ever missing or surgically disabled, the card now degrades gracefully instead of throwing.

= 0.5.0 =
* First-run onboarding wizard with a 5-step "Get Started" tab + welcome notice (closes #31).
* Backup-before-update + one-click rollback. Each self-update snapshots the previous plugin folder to `wp-content/upgrade-backups/ai-site-connector/{version}/`. Keeps the last 3, never deletes the currently-installed version (closes #32).
* Pre-flight verification on Application Password generation. Server-side probe of `/wp/v2/users/me` with the new credentials, with per-status hints (401 = Authorization-header stripping, 403 = caps/WAF, 404 = REST off, 5xx = server error) (closes #33).
* Sample agent code in `examples/{python,node,bash}/` — three runnable reference clients demonstrating the full flow (health, list, create, upload) (closes #34).
* MCP HTTP transport endpoint at `/wp-json/ai-site-connector/v1/mcp` speaking JSON-RPC 2.0. Supports `initialize`, `tools/list`, `tools/call`, `ping`. 9 tools wrap the plugin's REST endpoints + core WP post operations via internal `rest_do_request`. New `AI_SITE_CONNECTOR_MCP_DISABLE` constant (closes #35).
* Daily / weekly audit-log email digest. Configurable cadence, recipients, "Send test digest now". Empty windows skipped on auto-runs (closes #36).
* In-admin REST endpoint explorer ("API Explorer" tab). Lists the plugin's REST routes plus a curated set of `wp/v2/*` routes with an inline "Try it" button. Internal dispatch via `rest_do_request` — no HTTP loopback (closes #37).

= 0.4.0 =
* New: Connection Test admin tab — pass/fail badges for every link in the MCP chain, available-tools table, copy-paste agent prompt.
* New: Tool whitelist / permission guard — central `AI_Site_Connector_Permissions` gate enforced before every tool call; global read-only toggle; nine permission keys (read_content, write_content, upload_media, update_seo, purge_cache, export_manifest, view_diagnostics, update_options, destructive_operations); conservative defaults.
* New: Site capability report at GET /diagnostics/site-report — WP/PHP versions, theme, active plugins, page builder / SEO / cache plugin detection, REST routes, current user caps, env limits, cron status.
* New: Cache purge tool at POST /cache/purge — flushes WP object cache, WP Rocket, LiteSpeed, W3TC, Elementor, Cloudflare (when configured). Returns `{success, purged[], skipped[], warnings[]}`.
* New: Safe media upload at POST /media/sideload — URL-sideload with title/alt/caption/description, optional featured image, optional Rank Math / Yoast social-image meta.
* New: Export / repo-sync helpers — GET /export/media-manifest (incl. SHA-256), /export/recent-changes, /export/page/<id>, /export/site-manifest. Disk writes go to wp-content/uploads/ai-site-connector/exports/ with a noindex .htaccess.
* New: Audit log schema v2 — added tool, target_type, target_id, status, summary, request_hash, ip_hash, meta columns; per-tool indexes; CSV export from admin; filter UI by action/tool/status; SHA-256 IP hashing keyed to site auth salt.
* Admin UI: new tabs Connection Test, Permissions, Diagnostics, Export. Existing Overview, Setup Wizard, Credentials, Audit Log, Docs preserved.
* Security: every new tool route gated by `AI_Site_Connector_Permissions::require_permission()`; denials audit-logged with reason; no secrets stored in the new code paths; tests/security-grep.sh continues to enforce the forbidden-function list.

= 0.3.0 =
* Tool-specific connection packs. The credential flash now shows a tabbed picker with ready-to-paste snippets for Claude Desktop (MCP), Cursor / VS Code (MCP), n8n / Make.com / Zapier, curl, Python (requests), Node.js (fetch), the generic connection-pack JSON, and plain-text agent instructions. New `AI_Site_Connector_Connection_Formats` class so the same builders can be reused later from WP-CLI or REST.
* CSS-only tabbed UI (no JS framework) using `:checked` sibling selectors.

= 0.2.0 =
* Native self-updater. The plugin now appears on Dashboard → Updates and the Plugins screen when a new release is published at github.com/tyhallcsu/ai-site-connector/releases, with a "View details" modal, "Check for updates now" and "Update now" controls on the Tools → AI Site Connector page, and audit-log entries for every check/install/failure.
* Optional `AI_SITE_CONNECTOR_UPDATE_PRERELEASE` constant to opt into pre-release tags; `AI_SITE_CONNECTOR_UPDATE_DISABLE` kill switch for managed hosts.
* `.github/workflows/release-zip.yml` now publishes a GitHub Release with the built zip attached on tag push, so the self-updater has a stable public download URL.

= 0.1.0 =
* Initial release.
