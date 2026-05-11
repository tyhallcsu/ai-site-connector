=== AI Site Connector ===
Contributors: sharmanhall
Tags: rest-api, application-passwords, claude, ai, codex, automation
Requires at least: 5.6
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 0.4.0
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

= 0.1.0 =
* Initial release.
