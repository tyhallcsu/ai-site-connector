# AI Site Connector — Manual Testing Checklist

Run through these on a fresh WordPress install (single-site and multisite) before tagging a release.

## Activation

- [ ] Plugin activates without PHP fatal errors.
- [ ] `wp_options` is updated with `ai_site_connector_db_version`.
- [ ] Audit table `{prefix}ai_site_connector_log` exists.
- [ ] Audit log shows `plugin_activated` event.

## Roles

- [ ] `ai_site_operator` role exists after activation.
- [ ] Capabilities match the `default_caps()` map.
- [ ] Filtering via `ai_site_connector_operator_caps` works.

## Admin page

- [ ] Tools → AI Site Connector loads for `manage_options` users.
- [ ] Loads with HTTP 403 / `wp_die` for non-admins.
- [ ] All four tabs render (Overview, Setup Wizard, Credentials, Audit Log, Docs).
- [ ] Status cards correctly identify HTTPS / REST / App-Pwd availability.

## User wizard

- [ ] Creates a user with default username `ai-agent`.
- [ ] Refuses to create when username already exists.
- [ ] Refuses to create when email already exists.
- [ ] Allows selection of any of: Administrator, Editor, AI Site Operator.
- [ ] Warning text is visible when Administrator selected.
- [ ] Audit log records `ai_user_created`.

## Application Passwords

- [ ] Generation works for the AI user.
- [ ] Plaintext is shown ONCE on the next page load.
- [ ] Plaintext is **not** stored in `wp_options`, `wp_usermeta`, or the audit table.
- [ ] Connection pack JSON is well-formed and copyable.
- [ ] Revoke button removes the password.
- [ ] Audit log records both `application_password_created` and `application_password_revoked`.

## REST endpoints

- [ ] `GET /wp-json/ai-site-connector/v1/health` returns JSON unauthenticated.
- [ ] Same endpoint authenticated returns extra `user` field.
- [ ] `/site-info` returns 401 unauthenticated, 200 authenticated.
- [ ] `/plugins` and `/themes` return 401 for non-admins.
- [ ] `/pages` and `/posts` return data scoped to user's capability.

## Auth pathways

- [ ] `curl -u username:app_password https://.../wp-json/wp/v2/users/me` returns HTTP 200.
- [ ] After revoke, the same curl returns HTTP 401.

## WP-CLI

- [ ] `wp ai-connector status` prints a table.
- [ ] `wp ai-connector health` prints valid JSON.
- [ ] `wp ai-connector create-user --username=ai-agent` creates the user.
- [ ] `wp ai-connector generate-password --username=ai-agent --format=json` prints the connection pack and warns about one-time visibility.
- [ ] `wp ai-connector revoke-password --username=ai-agent --uuid=<uuid>` succeeds.

## Multisite

- [ ] Plugin activates without errors on a network install.
- [ ] No fatal errors when activated per-site (not network-wide).
- [ ] Network admin sees the page only at the per-site level.

## Deactivation

- [ ] Audit log records `plugin_deactivated`.
- [ ] `ai_site_operator` role is preserved (not removed).
- [ ] Audit table is preserved.
- [ ] Application Passwords created during use are preserved.
- [ ] No PHP errors on deactivate.

## Negative paths

- [ ] Forms reject missing/invalid nonces (`check_admin_referer` fires).
- [ ] Application Password creation refuses over plain HTTP unless `WP_DEBUG` or `AI_SITE_CONNECTOR_ALLOW_HTTP`.
- [ ] All inputs round-trip through `sanitize_*` / `esc_*` (spot-check with `'<script>'`).
