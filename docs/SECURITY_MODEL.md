# AI Site Connector — Security Model

This document explains the security posture of the AI Site Connector plugin and the assumptions behind it. Read this before deploying to production.

## Trust boundaries

| Boundary                                   | Trust level | Notes                                                        |
| ------------------------------------------ | ----------- | ------------------------------------------------------------ |
| WordPress administrator (`manage_options`) | Trusted     | Only this role can use the wizard, mint App Passwords, or revoke. |
| AI service user (`ai_site_operator` etc.)  | Limited     | Capability set defined by `ai_site_connector_operator_caps`. |
| Application Password holder (the AI tool)  | Untrusted   | Same authority as the user it belongs to, no more.           |
| Public REST consumers                      | Untrusted   | Minimal `/health`, `/openapi.json`, and discovery are public. The connection-pack download uses a secret single-use token rather than user authentication. |

## Threat model — what the plugin defends against

- **Credential storage.** WordPress core stores a hash of the Application Password. The plugin temporarily stores the plaintext connection pack in an admin flash transient (60 seconds) and, for optional one-time downloads, a site transient (five minutes). These may be stored in the database or object cache. Display/download consumption deletes the corresponding transient; expiration is not a secure-erasure guarantee for storage or backups.
- **CSRF on credential mint/revoke.** Every form posts through `admin-post.php` with a nonce verified by `check_admin_referer()`.
- **Privilege escalation through endpoints.** Authenticated routes declare capability checks. Public health/OpenAPI routes and the token-protected connection-pack download intentionally have public callbacks; the download handler validates and consumes the token. Plugin tool permissions add checks on the routes that use the permission guard, not on every WordPress REST route.
- **Credential creation over HTTP.** `create_for_user()` refuses to mint a password unless `is_ssl()` is true OR `WP_DEBUG` / `AI_SITE_CONNECTOR_ALLOW_HTTP` is set.
- **Username collision / impersonation.** `create_user()` rejects existing usernames and emails.
- **Audit gap.** Activation, deactivation, user creation, password creation, password revocation, and authenticated health access are all logged to `{prefix}ai_site_connector_log`.

## Threat model — what the plugin does NOT defend against

- A compromised WordPress administrator can do anything a WP admin can do — including deleting the audit log table. The plugin assumes the admin is trusted.
- Server-level RCE, host filesystem compromise, or database compromise. If the host is owned, all bets are off.
- WAF / Cloudflare misconfiguration that strips `Authorization` headers. The plugin reports symptoms but cannot fix the upstream config.
- A leaked Application Password — the plugin makes revoke easy but cannot retroactively un-leak.

## Capability-to-endpoint matrix

| Endpoint                            | Required capability   | Method |
| ----------------------------------- | --------------------- | ------ |
| `/wp-json/ai-site-connector/v1/health`             | none (richer if auth) | GET    |
| `/wp-json/ai-site-connector/v1/me/capabilities`    | any logged-in user (returns ONLY caller's caps) | GET |
| `/wp-json/ai-site-connector/v1/site-info`          | `edit_posts`          | GET    |
| `/wp-json/ai-site-connector/v1/plugins`    | `manage_options`      | GET    |
| `/wp-json/ai-site-connector/v1/themes`     | `manage_options`      | GET    |
| `/wp-json/ai-site-connector/v1/pages`      | `edit_pages`          | GET    |
| `/wp-json/ai-site-connector/v1/posts`      | `edit_posts`          | GET    |

The table above covers the original read endpoints. The plugin also registers POST routes for MCP, cache purge, media sideload, credential rotation, and safe content update / rollback (`/content/update`, `/content/rollback`; dry-run by default, real writes need the default-off `write_content` permission, SEO fields also `update_seo`), plus `GET /content/snapshots/<id>`. MCP `wp_create_post` / `wp_update_post` also require `write_content` and honour read-only mode. Content-update rollback snapshots store full copies of the changed fields (including post content) as non-autoloaded `wp_options` rows; they are deleted with the post and by the opt-in uninstall wipe. See the [current endpoint reference](../README.md#rest-endpoints-added-by-this-plugin) for authentication and tool-permission requirements. Agents can also write through core REST routes (`/wp-json/wp/v2/posts`, `/media`, etc.) under the user’s capabilities. The plugin’s read-only tool setting is not a universal restriction on those core routes.

## Things this plugin will never add

- Arbitrary PHP / shell command execution endpoints
- Direct SQL execution endpoints
- Plugin or theme installer endpoints
- File editor endpoints
- Endpoints that bypass `current_user_can()`
- Hidden admin users
- Cron-based callbacks to external command-and-control servers
- Reverse-shell helpers
- Telemetry or analytics
- Pingbacks to a vendor service

If a fork or PR adds any of those, reject it.

## Operational checklist

- [ ] HTTPS enforced site-wide.
- [ ] `ai_site_operator` role used (not Administrator) unless required.
- [ ] Connection pack stored in a password manager, **not** in git, Slack, email, or wiki.
- [ ] Audit log reviewed weekly.
- [ ] Application Password revoked when AI engagement ends.
- [ ] Plugin removed from sites that no longer need AI access.
