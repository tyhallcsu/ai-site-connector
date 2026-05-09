# Runtime Testing Required

**Status:** _Unverified against a real WordPress install._

This pre-release pass was completed without a live WordPress instance available on the development machine. Static checks (PHP lint, dangerous-pattern grep, code review) all passed. The runtime tests below have **not** been executed and must be performed before tagging a public release.

## Environment used for static checks

- macOS, PHP 8.x available locally
- WP-CLI (`/opt/homebrew/bin/wp`) installed, but no local WP site to attach it to
- No `wp-config.php` discovered under `~/`, `~/Documents/`, `~/Sites/`, or `/Volumes/`
- No `Local Sites/` directory found

## Tests that must be performed on a real WP install

### 1. Plugin activation (single-site)

```bash
wp plugin activate ai-site-connector
```

- [ ] Activates with no PHP fatal errors.
- [ ] `option_ai_site_connector_db_version` exists in `wp_options`.
- [ ] `{prefix}ai_site_connector_log` table exists.
- [ ] Audit log shows a `plugin_activated` event.

### 2. Role creation

- [ ] `ai_site_operator` role exists in `wp_options.wp_user_roles`.
- [ ] Default capability map matches `AI_Site_Connector_Roles::default_caps()`.
- [ ] `list_users`, `edit_others_posts`, `edit_others_pages` are FALSE by default.
- [ ] `manage_options`, `install_plugins`, `edit_files` are FALSE.

### 3. Admin page

- [ ] Tools → AI Site Connector loads for an admin user.
- [ ] Returns 403 / `wp_die` for non-admin users.
- [ ] All five tabs render (Overview, Setup Wizard, Credentials, Audit Log, Docs).
- [ ] HTTPS / REST / App-Pwd badges show correct color.

### 4. Setup Wizard

- [ ] Creates a user with default username `ai-agent`.
- [ ] Refuses to create when username already exists.
- [ ] Refuses to create when email already exists.
- [ ] Selecting Administrator without typing the confirmation phrase REFUSES creation and logs `ai_user_admin_refused`.
- [ ] Selecting Administrator with the typed phrase `I UNDERSTAND THIS GRANTS FULL SITE ACCESS` proceeds.
- [ ] Audit log records `ai_user_created` on success.

### 5. Application Password generation

- [ ] Generation works for the AI user.
- [ ] Plaintext is shown ONCE on the next page load via the flash transient.
- [ ] After page reload the plaintext is gone.
- [ ] Plaintext is NOT in `wp_options`, `wp_usermeta`, the audit table, or any plugin file.
- [ ] The connection-pack JSON is well-formed and copyable.
- [ ] Copy buttons for curl / Python / JS / Claude Code work.
- [ ] `application_password_created` is logged.

### 6. Application Password revocation

- [ ] Revoke button removes the credential.
- [ ] After revocation, `curl -u user:pwd .../wp-json/wp/v2/users/me` returns 401.
- [ ] `application_password_revoked` is logged.

### 7. REST endpoints

```bash
# Should succeed unauthenticated (minimal payload only)
curl -s 'https://example.com/wp-json/ai-site-connector/v1/health' | jq .

# Should NOT contain wp_version, php_version, active_theme, plugin count, user
curl -s 'https://example.com/wp-json/ai-site-connector/v1/health' \
  | jq 'has("wp_version") or has("php_version") or has("active_theme")'
# Expect: false

# Should succeed authenticated and include richer payload
curl -s -u 'ai-agent:APP_PWD' 'https://example.com/wp-json/ai-site-connector/v1/health' | jq .

# Should be 401 unauthenticated
curl -s -o /dev/null -w '%{http_code}\n' 'https://example.com/wp-json/ai-site-connector/v1/site-info'

# Should succeed with edit_posts
curl -s -u 'ai-agent:APP_PWD' 'https://example.com/wp-json/ai-site-connector/v1/site-info' | jq .

# Plugins / themes endpoints — admin only, should be 401 for ai-agent (operator)
curl -s -o /dev/null -w '%{http_code}\n' -u 'ai-agent:APP_PWD' \
  'https://example.com/wp-json/ai-site-connector/v1/plugins'
# Expect 401 or 403

# Posts / pages — operator should succeed
curl -s -u 'ai-agent:APP_PWD' 'https://example.com/wp-json/ai-site-connector/v1/posts' | jq .
curl -s -u 'ai-agent:APP_PWD' 'https://example.com/wp-json/ai-site-connector/v1/pages' | jq .
```

### 8. Standard WP REST API auth

```bash
curl -u 'ai-agent:APP_PWD' 'https://example.com/wp-json/wp/v2/users/me'
```

- [ ] Returns HTTP 200 and the AI user's data.

### 9. WP-CLI commands

```bash
wp ai-connector status
wp ai-connector health
wp ai-connector create-user --username=ai-agent --role=ai_site_operator
wp ai-connector generate-password --username=ai-agent --name="Claude AI Connector"
wp ai-connector revoke-password --username=ai-agent --uuid=<uuid-from-previous>
```

- [ ] `status` prints the diagnostic table.
- [ ] `health` prints valid JSON.
- [ ] `generate-password` prints the connection pack and warns about one-time visibility.
- [ ] `revoke-password` succeeds.
- [ ] No secrets are printed except immediately after generation.

### 10. Multisite

- [ ] Plugin activates on a network install with no fatal errors.
- [ ] Per-site activation works.
- [ ] Tools → AI Site Connector appears at the per-site level.

### 11. Deactivation

- [ ] `plugin_deactivated` is logged.
- [ ] `ai_site_operator` role is preserved.
- [ ] Audit table is preserved.
- [ ] Application Passwords created during use are preserved.
- [ ] No PHP errors on deactivate.

### 12. Negative paths

- [ ] Forms reject missing/invalid nonces.
- [ ] App-Pwd creation refuses over plain HTTP unless `WP_DEBUG` or `AI_SITE_CONNECTOR_ALLOW_HTTP`.
- [ ] All inputs round-trip through `sanitize_*` / `esc_*` (spot-check with `'<script>alert(1)</script>'`).

## After running

When all of the above pass on a real install, update [docs/RELEASE_NOTES_0.1.0.md](RELEASE_NOTES_0.1.0.md) to move items from "Not yet tested" to "Tested", and tag `v0.1.0`.
