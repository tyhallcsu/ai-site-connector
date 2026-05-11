# Features

This is the per-feature reference for AI Site Connector v0.2.0. For the
authentication model (Application Passwords + HTTP Basic) see
[`CLAUDE_CONNECTION_GUIDE.md`](CLAUDE_CONNECTION_GUIDE.md). For the security
posture see [`SECURITY_MODEL.md`](SECURITY_MODEL.md).

All MCP tool routes live under `/wp-json/ai-site-connector/v1/`. Every call:

1. Goes through the WordPress REST API permission callback (an `auth_*`
   capability check inside `class-rest-controller.php`).
2. Is gated again by `AI_Site_Connector_Permissions::require_permission()`
   against the per-tool whitelist + read-only-mode toggle.
3. Records a row in the audit log table on success, failure, or denial.

---

## 1. Connection Test

**Admin:** Tools → AI Site Connector → **Connection Test**

Verifies, with one pass/fail badge per check:

- HTTPS enabled
- REST API reachable
- Application Passwords available
- MCP namespace registered
- Read-only mode (informational)
- Last successful MCP request timestamp

Shows the full available-tools catalog with the current user's allow state
for each, plus a copy-paste prompt that an AI agent can use unchanged
(includes no secret).

---

## 2. Tool whitelist / permission guard

**Class:** `AI_Site_Connector_Permissions`
**Admin:** Tools → AI Site Connector → **Permissions**

Central `require_permission()` check enforced before every MCP tool runs.
Sits on top of the existing WP capability check — a tool is allowed only
when BOTH the WP cap and the whitelist agree.

Keys and defaults:

| Key                        | Default | WP cap          | Category    |
|----------------------------|---------|-----------------|-------------|
| `read_content`             | on      | `edit_posts`    | read        |
| `view_diagnostics`         | on      | `manage_options`| read        |
| `export_manifest`          | on      | `edit_posts`    | read        |
| `write_content`            | off     | `edit_posts`    | write       |
| `upload_media`             | off     | `upload_files`  | write       |
| `update_seo`               | off     | `edit_posts`    | write       |
| `purge_cache`              | off     | `manage_options`| write       |
| `update_options`           | off     | `manage_options`| admin       |
| `destructive_operations`   | off     | `manage_options`| destructive |

Filter override:

```php
add_filter( 'ai_site_connector_can_execute_tool', function ( $allowed, $tool, $context, $reason ) {
    // Custom logic — e.g. deny upload_media during business hours.
    return $allowed;
}, 10, 4 );
```

Global **read-only mode** (checkbox at top of the Permissions tab) implicitly
denies every non-read tool regardless of its individual setting.

---

## 3. Audit log v2

**Class:** `AI_Site_Connector_Audit_Log`
**Table:** `{prefix}_ai_site_connector_log` (schema v2)
**Admin:** Tools → AI Site Connector → **Audit Log**

Columns (added in v2 marked `+`):

```
id              BIGINT UNSIGNED, PK
created_at      DATETIME (UTC)
action          VARCHAR(64)
+ tool          VARCHAR(64)       -- which MCP tool, when applicable
+ target_type   VARCHAR(64)       -- e.g. 'attachment'
+ target_id     BIGINT UNSIGNED   -- e.g. attachment ID
+ status        VARCHAR(32)       -- success | failure | denied | info
actor_user_id   BIGINT UNSIGNED
target_user_id  BIGINT UNSIGNED
ip              VARCHAR(64)       -- legacy column, filterable to ''
+ ip_hash       VARCHAR(64)       -- SHA-256 keyed by wp_salt('auth')
user_agent      VARCHAR(255)
message         TEXT
+ summary       TEXT
+ request_hash  VARCHAR(64)
+ meta          LONGTEXT (JSON)
```

Indexes on `action`, `tool`, `status`, `actor`, `target_user`,
`(target_type, target_id)`, `created_at`.

The **admin Audit Log tab** has:

- Filter form (action / tool / status)
- "Download filtered rows as CSV" (server-side stream, capped at 500 rows)
- Retention controls (default 90 days, hard floor of 100 most-recent rows
  always preserved)
- Daily cron pruner with manual "Prune now" button

Retention is filterable via `ai_site_connector_log_retention_days`. Set
`ai_site_connector_log_skip_prune` to `true` to disable pruning entirely.

Raw IP storage can be disabled per row:

```php
add_filter( 'ai_site_connector_log_raw_ip', '__return_empty_string' );
```

---

## 4. Safe media upload with SEO

**Class:** `AI_Site_Connector_Media`
**Route:** `POST /ai-site-connector/v1/media/sideload`
**Permission:** `upload_media` (default off — must be enabled in the
Permissions tab before an agent can call it).

URL-sideload only — `download_url()` → `wp_handle_sideload()` →
`wp_insert_attachment()`. The implementation deliberately does not accept
base64 (the project's `security-grep.sh` rejects `base64_decode`) or
multipart form data.

Request body:

```json
{
  "url":                "https://images.example.com/hero.jpg",
  "title":              "Roof Bros 2026 hero",
  "alt_text":           "Crew installing standing-seam metal roof",
  "caption":            "Photo: Dan Knopp",
  "description":        "Full-frame DSLR, 24mm.",
  "post_id":            123,
  "set_featured_image": true,
  "seo_social_image":   true,
  "filename_override":  "hero-roofbros-2026.jpg"
}
```

Response on success:

```json
{
  "attachment_id": 5421,
  "url":           "https://example.com/wp-content/uploads/2026/05/hero-roofbros-2026.jpg",
  "mime_type":     "image/jpeg",
  "filename":      "hero-roofbros-2026.jpg",
  "metadata":      { /* wp_generate_attachment_metadata() output */ },
  "parent_post":   123,
  "warnings":      []
}
```

When `seo_social_image` is `true` and the `update_seo` permission is
**also** enabled, the plugin writes the standard pair on the parent post:

- **Yoast SEO** — `_yoast_wpseo_opengraph-image[-id]`, `_yoast_wpseo_twitter-image[-id]`
- **Rank Math** — `rank_math_facebook_image[_id]`, `rank_math_twitter_image[_id]`
- **AIOSEO** — detected and surfaced as a warning (AIOSEO stores social
  images in its own `aioseo_posts` table; we don't write across versions
  to avoid drift).

---

## 5. Cache purge

**Class:** `AI_Site_Connector_Cache`
**Route:** `POST /ai-site-connector/v1/cache/purge`
**Admin button:** Tools → AI Site Connector → **Diagnostics** → "Purge all caches now"
**Permission:** `purge_cache`

Each layer is feature-detected at call time. Pass any of these as `false`
to skip:

| Layer        | Detection                                                            |
|--------------|----------------------------------------------------------------------|
| `object`     | `wp_cache_flush()` always available                                  |
| `rocket`     | `function_exists( 'rocket_clean_domain' )`                          |
| `litespeed`  | `LiteSpeed_Cache_API` / `\LiteSpeed\Purge` / `litespeed_purge_all`   |
| `w3tc`       | `function_exists( 'w3tc_flush_all' )`                               |
| `elementor`  | `\Elementor\Plugin::$instance->files_manager->clear_cache()`        |
| `cloudflare` | `ai_site_connector_cloudflare_api_token` + `_zone_id` options set    |

Response:

```json
{
  "success":  true,
  "purged":   ["object_cache", "wp_rocket", "elementor"],
  "skipped":  ["litespeed", "w3_total_cache", "cloudflare"],
  "warnings": []
}
```

Cloudflare credentials are read from WP options. Never hardcoded.

---

## 6. Export / repo sync

**Class:** `AI_Site_Connector_Export`
**Routes:** `GET /export/media-manifest`, `/export/recent-changes`, `/export/page/<id>`, `/export/site-manifest`
**Admin:** Tools → AI Site Connector → **Export**
**Permission:** `export_manifest` (default on)

No GitHub API credentials required. The plugin produces the JSON; a
downstream agent commits/pushes from its own environment.

### Media manifest

`GET /export/media-manifest?limit=1000&offset=0&include_sha256=true`

```json
{
  "generated_at": "2026-05-11T16:42:01+00:00",
  "site_url":     "https://example.com",
  "count":        42,
  "limit":        1000,
  "offset":       0,
  "items": [
    {
      "attachment_id": 5421,
      "url":           "https://example.com/wp-content/uploads/2026/05/hero.jpg",
      "filename":      "hero.jpg",
      "title":         "Hero",
      "alt":           "Crew on roof",
      "caption":       "",
      "description":   "",
      "attached_to":   123,
      "mime_type":     "image/jpeg",
      "size_bytes":    482311,
      "sha256":        "0d2f8…",
      "modified_gmt":  "2026-05-11 16:00:00"
    }
  ]
}
```

SHA-256 hashing is skipped for files larger than 200 MB to keep response
times bounded; callers can fetch a single attachment's hash on demand by
piping the URL through `sha256sum` themselves.

### Recent changes

`GET /export/recent-changes?limit=50&since=2026-05-01T00:00:00Z&post_types[]=post&post_types[]=page`

Returns `{ id, type, status, title, slug, permalink, modified_gmt,
author_id, content_length, content_hash }`. The `content_hash` is the
SHA-256 of `post_content` — useful for cheap diffing without pulling
every body.

### Single page / site manifest

`GET /export/page/<id>` and `GET /export/site-manifest` — see source for
exact shape.

### Disk exports

The admin "Export" tab writes the same payloads to
`wp-content/uploads/ai-site-connector/exports/<kind>-<timestamp>.json`
with a one-time `.htaccess` drop (`Options -Indexes`, `X-Robots-Tag:
noindex, nofollow`). The flash message includes a clickable URL.

---

## 7. Site capability report

**Class:** `AI_Site_Connector_Diagnostics`
**Route:** `GET /ai-site-connector/v1/diagnostics/site-report`
**Admin:** Tools → AI Site Connector → **Diagnostics**
**Permission:** `view_diagnostics` (default on)

Returns:

- `plugin` — name + version
- `wordpress` — version, multisite, URLs, language, permalink structure, HTTPS, app-passwords availability
- `php` — version, memory limit, exec time, post/upload max, GD/Imagick/curl/mbstring availability
- `wp_uploads` — basedir/baseurl, writable, max_upload_size
- `theme` — active + parent + is_block_theme
- `active_plugins` — normalized list (file/slug/name/version)
- `detected.page_builders` — elementor, beaver_builder, divi, gutenberg_block_theme, oxygen, bricks_theme
- `detected.seo` — rank_math, yoast, aioseo, seopress
- `detected.cache` — wp_rocket, litespeed, w3_total_cache, wp_super_cache, cache_enabler, elementor, cloudflare, object_cache
- `rest_mcp` — namespace, health endpoint, REST reachable, registered routes under our namespace, read-only mode, per-tool permission state
- `current_user` — id, login, roles, capability snapshot
- `cron` — disabled flag, next audit prune, doing_cron, alternate_cron
- `database` — audit-log table name, schema version, retention days

No secrets, tokens, or credentials are ever included.
