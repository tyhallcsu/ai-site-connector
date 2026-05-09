=== AI Site Connector ===
Contributors: sharmanhall
Tags: rest-api, application-passwords, claude, ai, codex, automation
Requires at least: 5.6
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 0.1.0
License: MIT
License URI: https://opensource.org/licenses/MIT

Connect Claude / Codex / AI coding agents to a self-hosted WordPress site over the REST API using Application Passwords. No WordPress.com required.

== Description ==
AI Site Connector adds a Tools → AI Site Connector admin page that helps you:

* Create a dedicated AI/service user with a least-privilege "AI Site Operator" role
* Generate WordPress Application Passwords for that user
* Copy a "connection pack" that Claude / Codex / scripts can use over HTTP Basic Auth
* Audit credential creation and revocation
* Diagnose REST API / HTTPS / Application Passwords availability

This plugin uses only WordPress core APIs. WordPress.com, Jetpack, and external services are NOT required.

== Installation ==
1. Upload the plugin to /wp-content/plugins/
2. Activate "AI Site Connector"
3. Go to Tools → AI Site Connector

== Frequently Asked Questions ==
= Does this work without Jetpack or WordPress.com? =
Yes. The plugin uses WordPress core Application Passwords only.

= Where is the password stored? =
The plugin stores ONLY metadata (uuid, name, created, last_used). The plaintext is shown once at creation time and never written by this plugin.

= Can I extend the AI Site Operator role? =
Yes — use the apply_filters( 'ai_site_connector_operator_caps', $caps ) filter.

== Changelog ==
= 0.1.0 =
* Initial release.
