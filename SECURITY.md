# Security Policy

This plugin manages WordPress credentials and authorized site access. Treat security reports with priority.

## Reporting a vulnerability

**Do not open a public GitHub issue for a real vulnerability.**

Instead:
- Email the maintainer at the address listed on the GitHub profile of `tyhallcsu`, or
- Open a GitHub *Security Advisory* draft on this repo (Security tab → Report a vulnerability), which is private until disclosed.

Include:
1. Affected version (plugin file `ai-site-connector.php` `Version:` header).
2. WordPress and PHP versions.
3. Reproduction steps.
4. Impact assessment (information disclosure, privilege escalation, RCE, etc.).
5. Any proof-of-concept payload — but **never include real Application Passwords or real connection packs**. Redact them.

We aim to acknowledge within 5 business days.

## What is in scope

- The plugin's PHP code in `ai-site-connector.php` and `includes/`.
- The plugin's REST endpoints under `/wp-json/ai-site-connector/v1/*`.
- The plugin's admin page under Tools → AI Site Connector.
- The plugin's WP-CLI commands under `wp ai-connector …`.

## What is out of scope

- WordPress core vulnerabilities — report to https://wordpress.org/news/category/security/.
- Hosting / WAF / Cloudflare misconfiguration on a specific site.
- Vulnerabilities introduced by third-party filters that customize `ai_site_connector_operator_caps` to grant dangerous capabilities. The plugin documents the recommended caps; downstream changes are the operator's responsibility.
- Issues that require pre-existing administrator access. The plugin's threat model assumes administrators are trusted.

## Hard constraints (will not be added)

The following are explicitly forbidden. PRs introducing them will be rejected.

- Arbitrary PHP / shell command execution endpoints
- Direct SQL execution endpoints
- Plugin or theme installer endpoints
- File editor endpoints (theme/plugin/wp-config)
- Endpoints that bypass `current_user_can()`
- Endpoints that operate without `permission_callback`
- Hidden admin users
- Outbound callbacks to vendor command-and-control
- Reverse-shell helpers
- Telemetry, analytics, or "phone-home" pings
- Plaintext storage of Application Passwords
- Backwards-compatibility shims that re-enable removed dangerous behavior

## Sensitive data handling

- The plugin never persists plaintext Application Passwords. WP core stores only metadata (uuid, name, created, last_used).
- Connection-pack JSON containing the plaintext password is shown exactly once via a 60-second flash transient and then discarded. It is **never** written to disk by this plugin.
- The audit log records actions, actor, target, IP, and user-agent — but never the password itself.

## Disclosure

We follow a **coordinated disclosure** model. Once a fix lands and a release is tagged, we will publish a CVE-style advisory on the repo's Security tab.
