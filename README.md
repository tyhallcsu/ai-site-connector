# AI Site Connector

A WordPress plugin that lets Claude, Codex, and other AI coding agents authenticate to a self-hosted WordPress site over the REST API using **Application Passwords** — with **no WordPress.com account, no Jetpack, and no third-party cloud service** required.

> Built for site operators who manage many WordPress installs and want a clean, repeatable, least-privilege way to connect AI tooling for maintenance and content work.

---

## What this plugin does

1. Adds a **Tools → AI Site Connector** admin page with status cards, a setup wizard, credential management, and an audit log.
2. Registers a custom **AI Site Operator** role with sensible read/edit capabilities (no plugin/file/theme editing, no `manage_options`).
3. Provides a **wizard** to create a dedicated AI/service user (default username `ai-agent`) — or pick an existing user.
4. Generates **Application Passwords** using WordPress core (`WP_Application_Passwords`), shown **once**, never stored in plaintext.
5. Produces a copy-paste **connection pack** containing site URL, REST base, username, and password, plus curl / Python / JavaScript / Claude Code examples.
6. Exposes a small set of **REST endpoints** under `/wp-json/ai-site-connector/v1/` for safe, read-only diagnostics.
7. Maintains an **audit log** of plugin events (user creation, password generation, revocation, health access).
8. Ships **WP-CLI commands** under `wp ai-connector …`.

## Why WordPress.com is not required

WordPress core has supported [Application Passwords](https://make.wordpress.org/core/2020/11/05/application-passwords-integration-guide/) since 5.6. They are first-class HTTP Basic Auth credentials that authenticate requests to `wp-json/wp/v2/*` and any third-party REST routes. WordPress.com / Jetpack / OAuth flows are unrelated — this plugin uses only what is already in your self-hosted WordPress install.

## How Application Passwords work

- An Application Password is a 24-character credential tied to a specific WordPress user.
- It can be passed via HTTP Basic Auth: `Authorization: Basic base64(username:application_password)`.
- It inherits the user's permissions — that is why this plugin recommends a dedicated low-privilege user.
- It can be revoked at any time, independently of the user's main login password.

---

## Installation

1. Copy the `ai-site-connector/` folder into `wp-content/plugins/` on your WordPress site.
2. Activate **AI Site Connector** in the Plugins screen.
3. Open **Tools → AI Site Connector**.
4. Confirm the connectivity checks (HTTPS, REST reachable, Application Passwords available).

You can also install via WP-CLI:

```bash
wp plugin activate ai-site-connector
```

## Create an AI user

1. Open **Tools → AI Site Connector → Setup Wizard**.
2. Pick a username (default `ai-agent`).
3. Pick a role. Recommended: **AI Site Operator** (least privilege). Choose **Editor** if the agent needs to manage other authors' content. Avoid **Administrator** unless you really need it — the plugin warns you when you do.
4. Submit — the plugin creates the user with a strong random login password (you do not need it; the AI will use an Application Password).

Or via WP-CLI:

```bash
wp ai-connector create-user --username=ai-agent --role=ai_site_operator
```

## Generate a connection pack

1. Go to **Tools → AI Site Connector → Credentials**.
2. Pick the AI user.
3. Click **Generate Connection Pack**.
4. Copy the JSON, the curl command, or the Claude Code instructions block.
5. Save the password in your password manager. You cannot view it again.

Or via WP-CLI:

```bash
wp ai-connector generate-password --username=ai-agent --format=json
```

## How Claude / Codex should authenticate

Agents send standard HTTP Basic Auth on every REST request. The connection pack tells the agent everything it needs:

```json
{
  "site_name": "Example",
  "site_url": "https://example.com",
  "rest_api_base": "https://example.com/wp-json/",
  "auth_method": "basic_auth_application_password",
  "username": "ai-agent",
  "application_password": "DISPLAY_ONCE_ONLY",
  "test_endpoint": "https://example.com/wp-json/wp/v2/users/me",
  "plugin_health_endpoint": "https://example.com/wp-json/ai-site-connector/v1/health",
  "notes": "Use HTTP Basic Auth with username and application password."
}
```

### curl

```bash
curl -u 'ai-agent:xxxx xxxx xxxx xxxx xxxx xxxx' \
  'https://example.com/wp-json/wp/v2/users/me'
```

### Python

```python
import requests
r = requests.get(
    "https://example.com/wp-json/wp/v2/users/me",
    auth=("ai-agent", "xxxx xxxx xxxx xxxx xxxx xxxx"),
    timeout=15,
)
print(r.status_code, r.json())
```

### JavaScript

```js
const auth = "Basic " + btoa("ai-agent:xxxx xxxx xxxx xxxx xxxx xxxx");
const r = await fetch("https://example.com/wp-json/wp/v2/users/me", {
  headers: { Authorization: auth },
});
console.log(r.status, await r.json());
```

### Claude Code instructions

Paste this into your Claude Code session or system prompt:

```
You can authenticate to this WordPress site using HTTP Basic Auth.
- REST base: https://example.com/wp-json/
- Username: ai-agent
- Password: (Application Password from the connection pack)
Add header: Authorization: Basic base64(username:application_password)
Plugin health endpoint: https://example.com/wp-json/ai-site-connector/v1/health
Do not commit this password to git.
```

---

## REST endpoints (added by this plugin)

All endpoints under `/wp-json/ai-site-connector/v1/`.

| Endpoint     | Auth                                       | Returns                                                      |
| ------------ | ------------------------------------------ | ------------------------------------------------------------ |
| `/health`    | Public (richer if authenticated)           | Plugin version, site URL, WP/PHP versions, HTTPS, app-pwd availability, current user info if authed |
| `/site-info` | Authenticated, `list_users`                | Site name, URL, language, theme, multisite flag              |
| `/plugins`   | Authenticated, `manage_options`            | List of installed plugins (read-only)                        |
| `/themes`    | Authenticated, `manage_options`            | List of installed themes (read-only)                         |
| `/pages`     | Authenticated, `edit_pages`                | First 200 pages (id/title/slug/status)                       |
| `/posts`     | Authenticated, `edit_posts`                | Up to 50 most recent posts                                   |

There are intentionally **no write endpoints, no file editor, no SQL exec, no plugin installer**. Use the standard WordPress REST API (`/wp-json/wp/v2/*`) for content writes.

---

## Security best practices

- **Use HTTPS.** This plugin refuses to mint Application Passwords over plain HTTP unless you explicitly set `define('AI_SITE_CONNECTOR_ALLOW_HTTP', true)` or `WP_DEBUG` is true (for local dev only).
- **Use the AI Site Operator role**, not Administrator.
- Treat connection packs like passwords: store in 1Password / Bitwarden / Vault, **never** in git.
- Rotate Application Passwords on a schedule. The plugin makes revoke easy.
- Watch the audit log under **Tools → AI Site Connector → Audit Log**.
- If the site is behind Cloudflare or a WAF, allowlist the AI IP range — many WAFs block the `Authorization` header by default.

## Recommended role / capability setup

The default capabilities of `ai_site_operator` are intentionally conservative. Extend them with the filter:

```php
add_filter( 'ai_site_connector_operator_caps', function ( $caps ) {
    $caps['publish_posts'] = true;
    return $caps;
} );
```

## How to revoke access

- Open **Tools → AI Site Connector → Credentials**, click **Revoke** on the row.
- Or `wp ai-connector revoke-password --username=ai-agent --uuid=<uuid>`.
- Or open the user's profile in `/wp-admin/users.php` and revoke from the **Application Passwords** section. Both UIs operate on the same WP core data.

## Troubleshooting

**REST API disabled** — Some security plugins (iThemes Security, Wordfence) can disable the REST API for non-logged-in users. Whitelist `/wp-json/` and ensure the `Authorization` header is allowed.

**HTTPS missing** — Configure SSL on the host (Let's Encrypt, Cloudflare Origin Cert, etc.) before going to production.

**Application Passwords unavailable** — The feature may be turned off via the `wp_is_application_passwords_available` filter. Re-enable it, or check that you are on WP 5.6+.

**Basic Auth blocked** — Some hosts strip the `Authorization` header at Apache/nginx. Add this to `.htaccess`:

```
RewriteEngine On
RewriteCond %{HTTP:Authorization} ^(.*)
RewriteRule .* - [E=HTTP_AUTHORIZATION:%1]
```

**401 unauthorized** — Verify the username, that the Application Password is correct, and that the user has not been deleted/disabled.

**403 forbidden** — The user does not have the capability required for that endpoint. Check the role.

**Cloudflare / WAF blocking** — Disable Cloudflare's "Block Authorization Header" rules for `/wp-json/*`. In the WordPress firewall, allow the AI's IP, or temporarily switch Cloudflare into Development Mode while testing.

---

## License

Proprietary. © sharmanhall. All rights reserved.

This plugin ships with no warranty. Audit before use on production sites.
