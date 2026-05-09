# AI Site Connector — v0.1.0 Release Notes

**Status:** Initial pre-release. Code complete, statically validated, runtime testing pending (see [RUNTIME_TESTING_REQUIRED.md](RUNTIME_TESTING_REQUIRED.md)).

## What works (verified by static analysis only)

- Plugin file structure and bootstrap (no PHP parse errors across 9 files).
- No use of `eval`, `shell_exec`, `exec`, `passthru`, `system`, `popen`, `proc_open`, `base64_decode`, `assert(`, `create_function`, `file_put_contents`, `fopen`, `unlink`.
- All admin-post handlers gated by `current_user_can('manage_options')` AND `check_admin_referer()`.
- All REST routes declare an explicit `permission_callback`.
- All inputs sanitized with `sanitize_user`, `sanitize_email`, `sanitize_key`, `sanitize_text_field`, plus `wp_unslash`.
- All outputs escaped with `esc_html`, `esc_attr`, `esc_url`, `esc_js`, `esc_html__`.
- `WP_Application_Passwords` is the only path used to mint passwords. The plugin stores zero plaintext; only metadata.
- One-time password display via 60-second flash transient.
- Audit log writes via `$wpdb->insert()` with format array. Reads via `$wpdb->prepare()`.
- WP-CLI commands compile (file syntax-clean) under PHP 8.

## What was tested

| Test                                    | Status        |
| --------------------------------------- | ------------- |
| `php -l` on every PHP file              | Pass          |
| Dangerous-pattern grep (12 patterns)    | Pass (none)   |
| Unfiltered superglobal use              | Pass (none)   |
| Manual code review of all admin actions | Pass          |
| Manual code review of all REST routes   | Pass          |
| Confirm no plaintext password storage   | Pass          |
| Confirm permission_callback on /health, /site-info, /plugins, /themes, /pages, /posts | Pass |

## What was NOT tested

| Test                                                                               | Status              |
| ---------------------------------------------------------------------------------- | ------------------- |
| Plugin activation on real WP                                                       | **Not run**         |
| Tools → AI Site Connector renders                                                  | **Not run**         |
| User creation (incl. Administrator typed-confirmation gate)                        | **Not run**         |
| Application Password generation + once-only display                                | **Not run**         |
| Application Password revocation                                                    | **Not run**         |
| Audit log entries on real WP                                                       | **Not run**         |
| `/wp-json/ai-site-connector/v1/*` reachable                                        | **Not run**         |
| Capability gating on `/plugins`, `/themes` (operator should be 401/403)            | **Not run**         |
| `wp ai-connector status / health / create-user / generate-password / revoke-password` | **Not run**     |
| Multisite                                                                          | **Not run**         |
| Browser test of the typed-confirmation gate JS                                     | **Not run**         |

See [RUNTIME_TESTING_REQUIRED.md](RUNTIME_TESTING_REQUIRED.md) for the exact commands to run.

## Known limitations

- **Audit log is mutable.** A compromised WordPress administrator can `TRUNCATE` the table. The plugin assumes the admin role is trusted.
- **No outbound auto-update.** The plugin makes zero outbound calls except WordPress's own REST self-check via `wp_remote_get(rest_url('wp/v2'))` at 5-second timeout.
- **Multisite is per-site only.** There is no network-wide bulk credential mint UI by design.
- **HTTPS enforcement is local only.** The plugin refuses to mint credentials over HTTP, but cannot stop a downstream proxy from terminating TLS in front of `wp-config.php`. Configure your stack accordingly.
- **Capability filter side-effects.** `ai_site_connector_operator_caps` is reapplied on every `init`. If your filter relies on per-request state, capabilities may flip unexpectedly. Keep the filter pure.
- **No log retention.** The audit table grows indefinitely. Add a cron pruner if needed.

## Security posture

| Threat                                  | Mitigation                                                                  |
| --------------------------------------- | --------------------------------------------------------------------------- |
| CSRF on credential mint/revoke          | `wp_nonce_field` + `check_admin_referer` on every form                      |
| Privilege escalation                    | `current_user_can('manage_options')` on every admin-post handler            |
| Plaintext password leakage at rest      | Plaintext never persisted by this plugin                                    |
| Plaintext password leakage in transit   | Mint refuses unless HTTPS or explicit dev override                          |
| Public over-disclosure                  | `/health` returns minimal payload unless authenticated                       |
| Endpoint privilege creep                | Every route has explicit `permission_callback`; no `__return_true` on sensitive routes |
| Accidental Administrator role           | Typed confirmation gate (server-side and client-side)                       |
| Default-role privilege creep            | Operator caps trimmed: no `list_users`, `edit_others_*`, `delete_*`         |

## Installation

```bash
# 1. Place plugin in wp-content/plugins/
git clone https://github.com/tyhallcsu/ai-site-connector.git \
  /path/to/wp-content/plugins/ai-site-connector

# 2. Activate
wp plugin activate ai-site-connector

# 3. Configure via Tools → AI Site Connector
# 4. Run RUNTIME_TESTING_REQUIRED.md before depending on this in production
```

Or upload via SFTP / WP admin and activate from the Plugins page.

## Removal / rollback

```bash
# Standard WP plugin removal — deactivate then delete
wp plugin deactivate ai-site-connector
wp plugin delete ai-site-connector

# Optional: remove the audit table (NOT removed automatically, intentionally)
wp db query "DROP TABLE IF EXISTS \`$(wp db prefix --quiet)ai_site_connector_log\`;"

# Optional: remove the AI user (intentionally preserved on plugin delete)
wp user delete ai-agent --yes --reassign=1

# Optional: remove the custom role
wp role delete ai_site_operator
```

Note: deactivating the plugin does **not** revoke existing Application Passwords. Use the Credentials tab or `wp user application-password delete` for that.

## Versioning

- This is `0.1.0`. Tag `v0.1.0` only after the runtime checklist in [RUNTIME_TESTING_REQUIRED.md](RUNTIME_TESTING_REQUIRED.md) is complete.
- Subsequent patch/minor/major versions follow [SemVer](https://semver.org/).
