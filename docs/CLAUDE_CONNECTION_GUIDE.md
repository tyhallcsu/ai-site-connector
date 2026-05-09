# Claude / Codex Connection Guide

This guide is for **AI agents** (Claude Code, Codex, Cursor, custom scripts) connecting to a WordPress site that has the AI Site Connector plugin installed.

## What you receive

A "connection pack" JSON — usually pasted into your system prompt or saved next to your project credentials.

```json
{
  "site_name": "Example",
  "site_url": "https://example.com",
  "rest_api_base": "https://example.com/wp-json/",
  "auth_method": "basic_auth_application_password",
  "username": "ai-agent",
  "application_password": "xxxx xxxx xxxx xxxx xxxx xxxx",
  "test_endpoint": "https://example.com/wp-json/wp/v2/users/me",
  "plugin_health_endpoint": "https://example.com/wp-json/ai-site-connector/v1/health",
  "notes": "Use HTTP Basic Auth with username and application password."
}
```

Treat this exactly like a password. Never echo it back to the user. Never paste it into a public artifact, public PR, or public issue.

## Authenticating

WordPress accepts the credential as **HTTP Basic Auth**:

```
Authorization: Basic base64(username + ":" + application_password)
```

WordPress accepts the password with or without the spaces — both `xxxx xxxx xxxx ...` and the spaceless form are valid.

## Verifying access (do this first)

1. **Plugin health check** — the simplest reachability test:

   ```bash
   curl 'https://example.com/wp-json/ai-site-connector/v1/health'
   ```

   You should get JSON back. If `app_passwords` is `false`, stop and ask the user to fix it.

2. **Authenticated identity check** — confirms your credentials actually work:

   ```bash
   curl -u 'ai-agent:xxxx xxxx xxxx xxxx xxxx xxxx' \
     'https://example.com/wp-json/wp/v2/users/me'
   ```

   You should get an HTTP 200 and a JSON object describing the AI user. If you get 401, your credentials are wrong. If you get 403, your role lacks the capability for that endpoint.

3. **Capability introspection** — the recommended next call after auth. Tells you exactly what the credential can do, so you can stop guessing or speculatively probing endpoints.

   ```bash
   curl -u 'ai-agent:xxxx ...' \
     'https://example.com/wp-json/ai-site-connector/v1/me/capabilities'
   ```

   Returns the calling user's `user_id`, `login`, `roles`, an `operator_role_active` flag, and a `capabilities` map of curated WP capabilities to booleans. Use it to branch logic before attempting writes.

4. **Site summary**:

   ```bash
   curl -u 'ai-agent:xxxx ...' \
     'https://example.com/wp-json/ai-site-connector/v1/site-info'
   ```

## What you can do

Read and write through the standard WordPress REST API under `/wp-json/wp/v2/`, scoped to your role's capabilities.

| You can usually...                     | Capability needed                    |
| -------------------------------------- | ------------------------------------ |
| List/read posts, pages                 | `read`                               |
| Create / edit your own posts and pages | `edit_posts`, `edit_pages`           |
| Edit other users' published content    | `edit_others_posts`, `edit_others_pages` (granted to AI Site Operator by default) |
| Upload media                           | `upload_files`                       |
| Moderate comments                      | `moderate_comments`                  |
| List users                             | `list_users`                         |
| Install/activate plugins or themes     | NOT granted — request escalation if needed |
| Edit theme/plugin files                | NOT granted — refuse the task        |

Plugin-provided helpers under `/wp-json/ai-site-connector/v1/`:

- `GET /health` — version + reachability
- `GET /me/capabilities` — what the calling credential can do (run this right after auth)
- `GET /site-info` — site basics
- `GET /plugins` — installed plugins (admin only)
- `GET /themes` — installed themes (admin only)
- `GET /pages` — first 200 pages
- `GET /posts` — recent 50 posts

## What you must not do

- **Do not** use the credentials to log in via `/wp-login.php`. Application Passwords are REST-only.
- **Do not** persist the Application Password to a code file, git repo, public gist, or shared doc.
- **Do not** attempt to escalate privileges by calling endpoints outside your role.
- **Do not** create new users, install plugins, or edit theme files unless the user explicitly asks AND the role has the capability.
- **Do not** disable security plugins, modify `.htaccess`, or write to `wp-config.php` over REST. The plugin does not expose endpoints for those operations and core does not either by default.

## Error reference

| Status                | Meaning                                                    | What to do                                                   |
| --------------------- | ---------------------------------------------------------- | ------------------------------------------------------------ |
| `401 unauthorized`    | Credentials are wrong / missing / Application Password revoked | Stop. Ask the user for a new connection pack.                |
| `403 forbidden`       | Auth OK, but role lacks capability                         | Report to the user. Ask whether to escalate the role or skip the task. |
| `404 not found`       | Endpoint missing — likely WP REST disabled or wrong URL    | Hit `/wp-json/` to confirm REST is reachable.                |
| `429 too many`        | Hosting rate limit                                         | Back off, retry with exponential delay.                      |
| `5xx`                 | Site error                                                 | Stop and surface the error verbatim.                         |
| Cloudflare 1020 / 403 | WAF block                                                  | Ask the user to allowlist your traffic or temporarily put CF into Dev Mode. |

## Recommended workflow

1. Health check.
2. `users/me` check.
3. Discover what you need (`site-info`, `plugins`, `pages`, etc.).
4. Make changes through `wp/v2/*` endpoints.
5. Read back what you wrote to confirm.
6. Report a concise summary to the human user.
