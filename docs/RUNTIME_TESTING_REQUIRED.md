# Runtime Testing — Results

**Status:** _Verified against a throwaway WordPress install on 2026-05-08._

The full runtime checklist below was executed on a clean WordPress 6.9.4 + PHP 8.5.5 + SQLite-database-integration drop-in install, served via `php -S localhost:8765`. The plugin was symlinked into `wp-content/plugins/ai-site-connector` and activated via `wp plugin activate`. Test environment was torn down after the run; no real credentials remain on disk.

> Re-run this checklist on each target host (Apache/nginx, real MySQL, HTTPS, multisite) before relying on the plugin in production. SQLite + built-in PHP server prove the **plugin code path** works; they don't prove your specific stack does.

## Test environment

| Component | Version |
|---|---|
| WordPress core | 6.9.4 |
| PHP | 8.5.5 (built-in dev server) |
| Database | SQLite via `sqlite-database-integration` drop-in |
| Web server | `php -S localhost:8765` |
| `WP_ENVIRONMENT_TYPE` | `local` (required by WP core for App Passwords on HTTP) |
| `WP_DEBUG` | true |
| WP-CLI | 2.12.0 |

## Test results

### Activation & structure

| # | Test | Result |
|---|---|---|
| 1 | `wp plugin activate ai-site-connector` — no fatal errors | ✅ PASS |
| 2 | `ai_site_operator` role exists | ✅ PASS |
| 3 | `wp_ai_site_connector_log` table created | ✅ PASS |
| 4 | `plugin_activated` event recorded in audit log | ✅ PASS |

### Default operator capabilities (least-privilege check)

| Capability | Expected | Actual |
|---|---|---|
| `read` | TRUE | ✅ TRUE |
| `edit_posts` | TRUE | ✅ TRUE |
| `edit_pages` | TRUE | ✅ TRUE |
| `upload_files` | TRUE | ✅ TRUE |
| `moderate_comments` | TRUE | ✅ TRUE |
| `list_users` | FALSE | ✅ FALSE |
| `edit_others_posts` | FALSE | ✅ FALSE |
| `edit_others_pages` | FALSE | ✅ FALSE |
| `manage_options` | FALSE | ✅ FALSE |
| `install_plugins` | FALSE | ✅ FALSE |
| `edit_files` | FALSE | ✅ FALSE |
| `delete_posts` | FALSE | ✅ FALSE |
| `delete_published_posts` | FALSE | ✅ FALSE |

### WP-CLI commands

| # | Command | Result |
|---|---|---|
| 5 | `wp ai-connector status` returns diagnostic table | ✅ PASS |
| 6 | `wp ai-connector health` returns valid JSON | ✅ PASS |
| 7 | `wp ai-connector create-user --username=ai-agent --role=ai_site_operator` creates user (id=2) | ✅ PASS |
| 8 | `wp ai-connector generate-password --username=ai-agent --name="..." --format=json` returns connection pack | ✅ PASS |
| 9 | `wp ai-connector revoke-password --username=ai-agent --uuid=<uuid>` removes credential | ✅ PASS |

### Application Password plaintext isolation

| # | Test | Result |
|---|---|---|
| 10 | Plaintext NOT present in `wp_options` (LIKE %password%) | ✅ PASS |
| 11 | Plaintext NOT present in `wp_usermeta` | ✅ PASS |
| 12 | Plaintext NOT present in `wp_ai_site_connector_log` | ✅ PASS |
| 13 | `WP_Application_Passwords::get_user_application_passwords()` returns metadata only (uuid, name, app_id, created, last_used; password field is hashed by core) | ✅ PASS |
| 14 | `application_password_created` and `application_password_revoked` events recorded with UUID but no plaintext | ✅ PASS |

### REST endpoints — `/wp-json/ai-site-connector/v1/*`

| # | Endpoint | Auth | Expected | Actual |
|---|---|---|---|---|
| 15 | `/health` | unauthenticated | minimal payload (no wp_version, php_version, theme, user, plugin count, multisite) | ✅ PASS — confirmed payload contains only `plugin`, `plugin_version`, `site_url`, `rest_url`, `https`, `authenticated`, `timestamp` |
| 16 | `/health` | App Password | rich payload (incl. wp_version, php_version, active_theme, user) | ✅ PASS |
| 17 | `/site-info` | unauth | 401 | ✅ PASS |
| 18 | `/site-info` | operator (`edit_posts`) | 200 | ✅ PASS |
| 19 | `/plugins` | unauth | 401 | ✅ PASS |
| 20 | `/plugins` | operator (no `manage_options`) | 401 / 403 | ✅ PASS (403) |
| 21 | `/themes` | operator | 401 / 403 | ✅ PASS (403) |
| 22 | `/posts` | operator (`edit_posts`) | 200 | ✅ PASS |
| 23 | `/pages` | operator (`edit_pages`) | 200 | ✅ PASS |

### WordPress core REST auth via Application Password

| # | Test | Result |
|---|---|---|
| 24 | `curl -u ai-agent:<APP_PWD> /wp-json/wp/v2/users/me` returns HTTP 200 + AI agent's data | ✅ PASS |
| 25 | After `revoke-password`, the same call returns HTTP 401 | ✅ PASS |

### Administrator role typed-confirmation gate

| # | Test | Result |
|---|---|---|
| 26 | Submitting `ai_role=administrator` with WRONG `ai_admin_confirm` → user creation refused | ✅ PASS |
| 27 | Submitting `ai_role=administrator` with EXACT phrase `I UNDERSTAND THIS GRANTS FULL SITE ACCESS` → user created with `administrator` role | ✅ PASS |

### Audit log final state

```
application_password_revoked  | uuid=88097999-... revoked for user id 2
health_accessed_authenticated | by ai-agent (id=2)
application_password_created  | "Claude Test 2" generated for ai-agent (uuid=88097999-...)
application_password_created  | "Claude Test"   generated for ai-agent (uuid=eb3b033e-...)
ai_user_created               | "ai-agent" (id=2) created with role "ai_site_operator"
plugin_activated              | AI Site Connector v0.1.0 activated.
```

All 6 distinct event types fired during the test run.

## Test artifacts cleaned up

- `php -S` server killed.
- `/tmp/asc-pwd` and `/tmp/asc-uuid` (which briefly held real Application Password material during the test) shredded with `shred -u` / `rm -f`.
- `/tmp/asc-test-wp/` is a throwaway WP install — delete with `rm -rf /tmp/asc-test-wp`.
- No real credentials committed to git.

## Bugs found during runtime testing

| # | Bug | Fix |
|---|---|---|
| A | WP-CLI subcommands `create-user`, `generate-password`, `revoke-password` registered with hyphenated names didn't expose `--username` because the docblock was missing description lines under the option | Added explicit hyphen registrations in `class-plugin.php` AND added missing `: description` lines after `--username=<username>` in `class-wp-cli.php` |

## Tests still NOT performed (require a different stack)

The following remain TODO when the plugin is deployed to a real production stack:

- [ ] Apache `mod_rewrite` URL rewriting + `Authorization` header pass-through on actual hosting (Cloudflare / cPanel / WP Engine / Kinsta)
- [ ] Real MySQL / MariaDB (this run used SQLite drop-in)
- [ ] HTTPS-mandatory mode (this run used `WP_ENVIRONMENT_TYPE=local` to bypass the SSL gate)
- [ ] Multisite (network and per-site activation)
- [ ] WordPress versions other than 6.9.x — particularly the 5.6 minimum supported
- [ ] PHP versions other than 8.5 — CI matrix covers 7.4 / 8.0 / 8.1 / 8.2 / 8.3 with `php -l` only, not runtime
- [ ] Browser-side test of the admin wizard JS (typed-confirmation row toggle); only the server-side gate has been runtime-tested
- [ ] Stress test: many concurrent password mints / revocations
- [ ] Behavior under common security plugins (Wordfence, iThemes Security, WP Cerber) which sometimes block REST or Basic Auth

When you deploy to a real site, run a representative subset and update this document.
