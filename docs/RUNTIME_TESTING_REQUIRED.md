# Runtime Testing — Coverage and Open Items

This document is the source of truth for what has been *actually tested* versus
what *requires real-hosting verification*. It is intentionally honest: the
plugin's static checks and CI matrix are green, but no amount of CI testing
proves your specific hosting stack passes the `Authorization` header through
its WAF and reverse proxies. That part is on you and your host.

---

## 1. Verified locally (developer machine)

These ran against a throwaway WordPress install on `php -S` with the SQLite
drop-in. From a source checkout, reproduce them by running:

```bash
scripts/runtime-test-local.sh
```

The script provisions WordPress, installs the SQLite drop-in, symlinks the
plugin, and runs the full suite. It scrubs all captured Application Password
material on exit (including via `trap` on errors and SIGINT) and tears down
the throwaway directory unless `ASC_KEEP=1` is set.

| # | Test | Status |
|---|---|---|
| L1 | Plugin activates with no fatal errors | ✅ |
| L2 | `ai_site_operator` role exists with least-privilege caps (read/edit_posts/edit_pages/upload_files/moderate_comments TRUE; manage_options/install_plugins/edit_files/list_users/edit_others_*/delete_* FALSE) | ✅ |
| L3 | `{prefix}ai_site_connector_log` table exists; `plugin_activated` event recorded | ✅ |
| L4 | `wp ai-connector status` and `wp ai-connector health` produce expected output | ✅ |
| L5 | `wp help ai-connector` shows ONLY hyphenated subcommands (no `create_user` / `generate_password` / `revoke_password` underscore duplicates) | ✅ |
| L6 | `wp ai-connector create-user --username=ai-agent --role=ai_site_operator` creates user | ✅ |
| L7 | `wp ai-connector generate-password --username=ai-agent --format=json` returns a connection pack | ✅ |
| L8 | Plaintext Application Password NOT present in `wp_options`, `wp_usermeta`, or `{prefix}ai_site_connector_log` | ✅ |
| L9 | `/wp-json/ai-site-connector/v1/health` unauthenticated returns minimal payload — NO `wp_version` / `php_version` / `active_theme` / `active_plugin_count` / `is_multisite` / `user` keys | ✅ |
| L10 | Same endpoint authenticated returns rich payload including `wp_version`, `php_version`, `active_theme`, `active_plugin_count`, `is_multisite`, `user.{id,login,roles}` | ✅ |
| L11 | `/wp-json/wp/v2/users/me` with App Password → 200 | ✅ |
| L12 | `/site-info`, `/posts`, `/pages` with operator → 200; `/plugins`, `/themes` with operator → 403; unauth `/site-info` → 401 | ✅ |
| L13 | `wp ai-connector revoke-password --username=ai-agent --uuid=<uuid>` succeeds; same App Pwd then returns 401 | ✅ |
| L14 | Administrator role typed-confirmation gate (server-side): refuses without exact phrase, allows with `I UNDERSTAND THIS GRANTS FULL SITE ACCESS` | ✅ |
| L15 | Audit log records all 6 distinct event types (activated, user_created, pwd_created, pwd_revoked, health_accessed_authenticated, admin_refused) | ✅ |

The local test environment uses:

| Component | Value |
|---|---|
| WordPress core | latest (`wp core download`) |
| Database | SQLite via `sqlite-database-integration` drop-in |
| HTTP server | `php -S 127.0.0.1:8765` |
| `WP_ENVIRONMENT_TYPE` | `local` (required so WP core enables Application Passwords on plain HTTP) |
| `WP_DEBUG` | true |
| `AI_SITE_CONNECTOR_ALLOW_HTTP` | true |

**What this proves:** the plugin's PHP code paths, REST handlers, capability
gates, audit log writes, App Password lifecycle, and CLI command surface are
all functionally correct under WordPress.

**What this does NOT prove:** that any specific production host passes the
`Authorization` header through its proxy / WAF / security plugin to PHP.
SQLite is not MySQL. `php -S` is not Apache or nginx.

---

## 2. Verified in CI (GitHub Actions)

The `CI` workflow (`.github/workflows/ci.yml`) runs on every push and PR.
Status badges are in the README.

| Check | Detail |
|---|---|
| `php -l` syntax matrix | PHP 7.4, 8.0, 8.1, 8.2, 8.3 |
| Composer metadata + WordPressCS + PHPCompatibility | `composer validate --strict`, `composer lint`, `composer phpcs` |
| Plugin structure | required files exist, plugin header `Version:` matches `readme.txt` `Stable tag:` |
| No forbidden files committed | `.env`, `node_modules/`, `vendor/`, `*.log`, `connection-pack.json`, `.DS_Store` |
| Asset validation | SVGs parse with `xmllint`, render via `rsvg-convert`; `node --check` on admin JS |
| Dangerous-pattern grep | `eval`, `shell_exec`, `exec`, `passthru`, `system`, `proc_open`, `popen`, `assert(`, `create_function`, `base64_decode` |
| Credential / secret scan | `.env`, `connection-pack.json`, `*credentials*`, `*_secret*`, private keys, plausibly-real Application Password strings in committed JSON / YAML / Markdown |
| Release ZIP smoke | `tests/package-smoke.sh` builds the ZIP and verifies contents/exclusions |
| WordPress + MySQL runtime smoke | `tests/runtime-smoke.sh` provisions WP on a real MySQL service and runs the same suite as the local script |

**What this proves:** every push goes through static, structural, and
WordPress-runtime validation against MySQL before merging.

---

## 3. Still requires REAL-HOSTING verification

These cannot be proven in CI or by a local SQLite run. Run them once on each
target host before relying on the plugin in production.

The plugin ZIP includes a small host diagnostic helper for the most important
production checks:

```bash
scripts/diagnose-hosting-auth.sh https://your-site.example 'ai-agent' 'xxxx xxxx xxxx xxxx xxxx xxxx'
```

It will emit PASS/FAIL with remediation hints for the most common hosting
problems. The Application Password is never echoed in full — only the last
four characters appear in output.

| # | Test | Why it matters |
|---|---|---|
| H1 | `Authorization` header reaches PHP through Apache `mod_rewrite` / nginx / Cloudflare / hosting WAF | Many shared hosts and "WordPress-optimized" stacks strip this header. Symptom: 401 with correct credentials. Fix: `.htaccess` rewrite rule (see README troubleshooting) or a host-specific snippet. |
| H2 | Production MySQL / MariaDB version and configuration | CI runs the runtime smoke suite against MySQL. Still verify the target host's actual database engine, SQL mode, permissions, and backups before relying on it. |
| H3 | HTTPS-mandatory mode (no `WP_DEBUG`, no `AI_SITE_CONNECTOR_ALLOW_HTTP`) | The local script bypasses this gate to make `php -S` testing feasible. Production must mint passwords without the override. |
| H4 | Reverse-proxy `X-Forwarded-Proto` | If TLS terminates at Cloudflare / a load balancer, WordPress may think the connection is HTTP. The diagnostic prints this as `https=false` even when curling https://. Fix: set `FORCE_SSL_ADMIN` or trust the proxy header. |
| H5 | Multisite (network and per-site) | The plugin activates per-site cleanly in static review; not exercised under multisite. |
| H6 | WordPress versions other than the latest | Plugin states `Requires at least: 5.6`. Version-spread testing not run. |
| H7 | PHP versions in production runtime | CI covers 7.4 – 8.3 with `php -l` only. Local runtime ran on 8.5 specifically. |
| H8 | Behavior under common security plugins (Wordfence, iThemes Security, WP Cerber) | These can disable the REST API or block Basic Auth. The diagnostic surfaces 401/403 patterns that indicate this. |
| H9 | Mod_security / WAF false positives on REST POST bodies | The diagnostic sends one benign POST to detect this. A 403 from POST while GET works is the smoking gun. |
| H10 | Browser-side admin wizard JS — Administrator typed-confirmation row toggle | Server-side gate is verified (test L14). The JS that hides/shows the confirm field is documented for manual test below. |

### Manual browser test for the Administrator confirmation gate

The server-side gate is fully covered by automated tests. The client-side
toggle that hides/shows the confirm field is one tiny piece of UI that is
better verified by hand than by spinning up a headless browser.

1. Activate the plugin on a real site.
2. Go to **Tools → AI Site Connector → Setup Wizard**.
3. With the role dropdown set to **AI Site Operator**, confirm:
   - The "Confirm Administrator" row is hidden.
4. Change the role dropdown to **Administrator**, confirm:
   - The "Confirm Administrator" row appears with a red warning notice.
   - The confirm field becomes `required` (HTML5 form validation will block submit if empty).
   - Submitting without the exact phrase shows the server-side error.
5. Type the exact phrase `I UNDERSTAND THIS GRANTS FULL SITE ACCESS` and submit.
6. The user is created with `administrator` role; the audit log shows `ai_user_created`.
7. Change the role back to **AI Site Operator**:
   - The confirm field is cleared and `required` is removed (no stale validation error).
8. Submit a different username with operator role — succeeds, no admin gate fires.

If any of those steps deviate, file an issue with screenshots.

---

## Re-running this checklist

After deploying to a new host:

```bash
# From a source checkout, before push (idempotent throwaway WordPress + SQLite):
scripts/runtime-test-local.sh

# Against the real target host:
scripts/diagnose-hosting-auth.sh https://your-site.example ai-agent 'xxxx ...'

# CI runs automatically on push.
```

Update this file when you've completed any of the H-series checks for a given
host so future operators don't re-discover the same issues. Don't claim a row
green unless you ran the test on a real host that day.
