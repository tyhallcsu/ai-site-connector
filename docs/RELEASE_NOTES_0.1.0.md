# AI Site Connector — v0.1.0 Release Notes

**Status:** Initial public GitHub release. Code complete, CI release gates configured, runtime smoke coverage added for WordPress + MySQL in GitHub Actions, and the local throwaway WordPress + SQLite harness documented in [RUNTIME_TESTING_REQUIRED.md](RUNTIME_TESTING_REQUIRED.md).

## Static and security review coverage

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
| WordPress security/i18n checks and PHP compatibility via PHPCS | Pass |
| SVG brand assets parse and render       | Pass          |
| Release ZIP builds and excludes dev-only files | Pass |
| Dangerous-pattern grep (12 patterns)    | Pass (none)   |
| Unfiltered superglobal use              | Pass (none)   |
| Manual code review of all admin actions | Pass          |
| Manual code review of all REST routes   | Pass          |
| Confirm no plaintext password storage   | Pass          |
| Confirm permission_callback on /health, /site-info, /plugins, /themes, /pages, /posts | Pass |

## Runtime tests executed

| Test                                                                               | Result        |
| ---------------------------------------------------------------------------------- | ------------- |
| Plugin activation                                                                  | **PASS** in local SQLite harness; covered by CI MySQL harness |
| `ai_site_operator` role + table created                                            | **PASS**      |
| Default capabilities = least-privilege (13 caps verified)                          | **PASS**      |
| `wp ai-connector status / health`                                                  | **PASS**      |
| `wp ai-connector create-user / generate-password / revoke-password`                | **PASS** (after fix — see Bugs) |
| Application Password plaintext NOT in options / usermeta / audit log               | **PASS**      |
| Audit log records 6 event types (activated, user_created, pwd_created/revoked, health_accessed, admin_refused) | **PASS** |
| `/wp-json/ai-site-connector/v1/health` unauth returns minimal payload              | **PASS**      |
| `/wp-json/ai-site-connector/v1/health` auth returns rich payload                   | **PASS**      |
| `/site-info`, `/posts`, `/pages` for operator → 200                                | **PASS**      |
| `/plugins`, `/themes` for operator → 403                                           | **PASS**      |
| `/plugins` unauthenticated → 401                                                   | **PASS**      |
| `wp/v2/users/me` with App Password → 200                                           | **PASS**      |
| Same call after revoke → 401                                                       | **PASS**      |
| Administrator role gate: refuses without exact phrase                              | **PASS**      |
| Administrator role gate: allows with exact phrase                                  | **PASS**      |

See [RUNTIME_TESTING_REQUIRED.md](RUNTIME_TESTING_REQUIRED.md) for the full coverage matrix and production-host checks.

## CI release gates

The `CI` workflow now checks:

- PHP syntax on PHP 7.4, 8.0, 8.1, 8.2, and 8.3.
- Composer metadata, dependency installation, `composer lint`, and PHPCS for WordPress security/i18n plus PHP compatibility.
- Required plugin files, brand assets, and plugin/readme version consistency.
- Admin JavaScript syntax and SVG parse/render health.
- Dangerous PHP function and credential-pattern grep.
- Release ZIP build contents through `tests/package-smoke.sh`.
- A WordPress + MySQL runtime smoke test covering activation, role caps, WP-CLI commands, Application Password generation/revocation, REST permissions, audit events, and plaintext password isolation.

## Bugs found and fixed during runtime testing

- **WP-CLI hyphenated subcommands** (`generate-password`, `revoke-password`) failed parameter parsing because the `--username=<username>` option was missing the required `: description` line in the PHPDoc. Fixed in `includes/class-wp-cli.php` and matching explicit hyphen registrations added in `includes/class-plugin.php`.

## Tests still NOT performed (require a different stack)

| Test | Status |
|------|--------|
| Apache `mod_rewrite` + `Authorization` header pass-through on real hosting | Not run |
| Target host's exact MySQL / MariaDB version and configuration | Not run |
| HTTPS-mandatory mode | Not run (`WP_ENVIRONMENT_TYPE=local` used) |
| Multisite | Not run |
| WordPress versions other than 6.9.x | Not run |
| PHP runtime versions other than the CI runtime PHP version | Not run for runtime |
| Browser-side JS test of admin wizard typed-confirmation row toggle | Not run (server-side check verified) |
| Behavior under Wordfence / iThemes Security / WP Cerber | Not run |

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

- This is `0.1.0`, the first public GitHub release.
- Subsequent patch/minor/major versions follow [SemVer](https://semver.org/).
