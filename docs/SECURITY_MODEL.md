# AI Site Connector — Security Model

This document explains the security posture of the AI Site Connector plugin and the assumptions behind it. Read this before deploying to production.

## Trust boundaries

| Boundary                                   | Trust level | Notes                                                        |
| ------------------------------------------ | ----------- | ------------------------------------------------------------ |
| WordPress administrator (`manage_options`) | Trusted     | Only this role can use the wizard, mint App Passwords, or revoke. |
| AI service user (`ai_site_operator` etc.)  | Limited     | Capability set defined by `ai_site_connector_operator_caps`. |
| Application Password holder (the AI tool)  | Untrusted   | Same authority as the user it belongs to, no more.           |
| Public REST consumers                      | Untrusted   | Only `/health` is reachable unauthenticated, and it returns no secrets. |

## Threat model — what the plugin defends against

- **Plaintext password leakage at rest.** The plugin never persists the plaintext Application Password. Only metadata (uuid, name, created, last_used) is stored — and that storage is owned by WP core, not this plugin.
- **CSRF on credential mint/revoke.** Every form posts through `admin-post.php` with a nonce verified by `check_admin_referer()`.
- **Privilege escalation through endpoints.** Every plugin REST endpoint declares an explicit `permission_callback` requiring an authenticated user with a documented capability. There is no `__return_true` permission callback on any write or sensitive endpoint.
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

There are no `POST` / `PUT` / `DELETE` routes registered by this plugin. To write content, AI agents use core REST routes (`/wp-json/wp/v2/posts`, `/media`, etc.) under the user's existing capabilities.

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
