# AI Workflow Cookbook

Copy-paste recipes for the most common AI-agent tasks against a self-hosted WordPress install with AI Site Connector active. Every recipe is real REST API the agent can call directly with the Application Password from the connection pack.

> **Read first:** [docs/CLAUDE_CONNECTION_GUIDE.md](CLAUDE_CONNECTION_GUIDE.md) for auth setup. Every example below assumes you already have `username` and `application_password` and the site is reachable over HTTPS.

Each recipe lists:
- **Verb + endpoint** the AI calls.
- **Capability** the calling user must have. If your `ai-agent` user is the AI Site Operator role, the matrix at [/me/capabilities](#0-introspect-what-this-credential-can-do-do-this-first) tells you which recipes will work.
- **Curl** and **Python** snippets.
- **Expected status** + the common failure modes.

---

## 0. Introspect what this credential can do (do this first)

| | |
|---|---|
| **Endpoint** | `GET /wp-json/ai-site-connector/v1/me/capabilities` |
| **Capability** | any logged-in user (returns ONLY the calling user's caps) |
| **Why first** | Saves 5 speculative requests. Branch logic on the cap map before any write. |

```bash
curl -u 'ai-agent:xxxx xxxx xxxx xxxx xxxx xxxx' \
  'https://example.com/wp-json/ai-site-connector/v1/me/capabilities' | jq .
```

```python
import requests
r = requests.get(
    "https://example.com/wp-json/ai-site-connector/v1/me/capabilities",
    auth=("ai-agent", APP_PASSWORD),
    timeout=10,
)
caps = r.json()["capabilities"]
if not caps.get("edit_posts"):
    raise SystemExit("This credential can't edit posts; ask for a higher role.")
```

Expect **HTTP 200**. On 401: credentials wrong or revoked. On 404: plugin not installed.

---

## 1. List recent posts

| | |
|---|---|
| **Endpoint** | `GET /wp-json/wp/v2/posts?per_page=10&status=publish,draft` |
| **Capability** | `read` (public posts) or `edit_posts` (drafts) |

```bash
curl -u 'ai-agent:APP_PWD' \
  'https://example.com/wp-json/wp/v2/posts?per_page=10&status=publish,draft' | jq '.[] | {id, title: .title.rendered, status, slug}'
```

```python
r = requests.get(
    "https://example.com/wp-json/wp/v2/posts",
    params={"per_page": 10, "status": "publish,draft"},
    auth=("ai-agent", APP_PASSWORD),
    timeout=10,
)
for post in r.json():
    print(post["id"], post["status"], post["title"]["rendered"])
```

Expect **HTTP 200** + an array.

---

## 2. Create a draft post

| | |
|---|---|
| **Endpoint** | `POST /wp/v2/posts` |
| **Capability** | `edit_posts` |
| **Note** | `status=draft` is safe — visible to editors only, not the public. Use `status=publish` only after human review. |

```bash
curl -u 'ai-agent:APP_PWD' \
  -H 'Content-Type: application/json' \
  -X POST 'https://example.com/wp-json/wp/v2/posts' \
  -d '{
    "title": "Draft from AI agent",
    "content": "<p>Auto-generated draft. Review before publishing.</p>",
    "status": "draft"
  }'
```

```python
r = requests.post(
    "https://example.com/wp-json/wp/v2/posts",
    auth=("ai-agent", APP_PASSWORD),
    json={
        "title": "Draft from AI agent",
        "content": "<p>Auto-generated draft. Review before publishing.</p>",
        "status": "draft",
    },
    timeout=10,
)
post_id = r.json()["id"]
```

Expect **HTTP 201**. On 403: missing `edit_posts`. On 400: malformed body or invalid status.

---

## 3. Update a post

| | |
|---|---|
| **Endpoint** | `POST /wp/v2/posts/{id}` (WP REST also accepts `PUT`/`PATCH`) |
| **Capability** | `edit_posts` for own posts, `edit_others_posts` for posts authored by other users (NOT in default operator role — extend via `ai_site_connector_operator_caps` filter) |

```bash
curl -u 'ai-agent:APP_PWD' \
  -H 'Content-Type: application/json' \
  -X POST "https://example.com/wp-json/wp/v2/posts/${POST_ID}" \
  -d '{ "title": "Updated title", "content": "Updated body." }'
```

```python
r = requests.post(
    f"https://example.com/wp-json/wp/v2/posts/{post_id}",
    auth=("ai-agent", APP_PASSWORD),
    json={"title": "Updated title", "content": "Updated body."},
    timeout=10,
)
```

On 403: post is owned by someone else and the agent lacks `edit_others_posts`.

---

## 4. Publish a draft

| | |
|---|---|
| **Endpoint** | `POST /wp/v2/posts/{id}` with `status=publish` |
| **Capability** | `publish_posts` (NOT in default operator role — explicit gate by design; extend via filter if the agent should be allowed to publish without human review) |

```bash
curl -u 'ai-agent:APP_PWD' \
  -H 'Content-Type: application/json' \
  -X POST "https://example.com/wp-json/wp/v2/posts/${POST_ID}" \
  -d '{ "status": "publish" }'
```

If the calling user lacks `publish_posts`, WordPress returns **HTTP 401** with `rest_cannot_publish` — drafts can be edited but not made public.

---

## 5. Upload an image to the media library

| | |
|---|---|
| **Endpoint** | `POST /wp/v2/media` (multipart) |
| **Capability** | `upload_files` |

```bash
curl -u 'ai-agent:APP_PWD' \
  -H 'Content-Disposition: attachment; filename="hero.jpg"' \
  -H 'Content-Type: image/jpeg' \
  --data-binary @hero.jpg \
  'https://example.com/wp-json/wp/v2/media'
```

```python
with open("hero.jpg", "rb") as f:
    r = requests.post(
        "https://example.com/wp-json/wp/v2/media",
        auth=("ai-agent", APP_PASSWORD),
        headers={
            "Content-Type": "image/jpeg",
            "Content-Disposition": 'attachment; filename="hero.jpg"',
        },
        data=f.read(),
        timeout=30,
    )
media_id = r.json()["id"]
```

Expect **HTTP 201**. On 403: missing `upload_files`. On 413: file > host limit (commonly 2–10 MB).

---

## 6. Set a post's featured image

| | |
|---|---|
| **Endpoint** | `POST /wp/v2/posts/{id}` with `featured_media={media_id}` |
| **Capability** | `edit_posts` (or `edit_others_posts`) + the media must already exist |

```bash
curl -u 'ai-agent:APP_PWD' \
  -H 'Content-Type: application/json' \
  -X POST "https://example.com/wp-json/wp/v2/posts/${POST_ID}" \
  -d "{\"featured_media\": ${MEDIA_ID}}"
```

```python
requests.post(
    f"https://example.com/wp-json/wp/v2/posts/{post_id}",
    auth=("ai-agent", APP_PASSWORD),
    json={"featured_media": media_id},
    timeout=10,
)
```

---

## 7. Search posts by title

| | |
|---|---|
| **Endpoint** | `GET /wp/v2/posts?search={query}` |
| **Capability** | same as listing posts |

```bash
curl -u 'ai-agent:APP_PWD' \
  'https://example.com/wp-json/wp/v2/posts?search=upgrade&per_page=20' | jq '.[] | {id, title: .title.rendered}'
```

```python
r = requests.get(
    "https://example.com/wp-json/wp/v2/posts",
    params={"search": "upgrade", "per_page": 20},
    auth=("ai-agent", APP_PASSWORD),
    timeout=10,
)
```

Search is case-insensitive and matches title + content. WordPress sorts by relevance.

---

## 8. Edit a page

| | |
|---|---|
| **Endpoint** | `POST /wp/v2/pages/{id}` |
| **Capability** | `edit_pages` (own) or `edit_others_pages` (others') |

```bash
curl -u 'ai-agent:APP_PWD' \
  -H 'Content-Type: application/json' \
  -X POST "https://example.com/wp-json/wp/v2/pages/${PAGE_ID}" \
  -d '{ "title": "About us — refreshed", "content": "<p>New copy.</p>" }'
```

```python
requests.post(
    f"https://example.com/wp-json/wp/v2/pages/{page_id}",
    auth=("ai-agent", APP_PASSWORD),
    json={"title": "About us — refreshed", "content": "<p>New copy.</p>"},
    timeout=10,
)
```

---

## 9. Moderate a comment

| | |
|---|---|
| **Endpoint** | `POST /wp/v2/comments/{id}` with `status=approved` / `hold` / `spam` / `trash` |
| **Capability** | `moderate_comments` |

```bash
curl -u 'ai-agent:APP_PWD' \
  -H 'Content-Type: application/json' \
  -X POST "https://example.com/wp-json/wp/v2/comments/${COMMENT_ID}" \
  -d '{ "status": "approved" }'
```

---

## 10. Plugin health + diagnostics (operator workflows)

These are AI Site Connector's own endpoints, not WP core.

```bash
# Reachability + minimal payload (no auth needed):
curl 'https://example.com/wp-json/ai-site-connector/v1/health' | jq .

# Authenticated rich payload (versions, theme, multisite flag, current user):
curl -u 'ai-agent:APP_PWD' \
  'https://example.com/wp-json/ai-site-connector/v1/health' | jq .

# Site basics — name, URL, language, theme:
curl -u 'ai-agent:APP_PWD' \
  'https://example.com/wp-json/ai-site-connector/v1/site-info' | jq .
```

For local ops on the box itself:

```bash
# One-shot pass/fail health check (exits non-zero on failure):
wp ai-connector self-test

# End-to-end: mints + uses + revokes a temporary App Pwd for ai-agent:
wp ai-connector self-test --username=ai-agent --format=json
```

---

## Patterns that tend to bite AI agents

- **Don't `POST` with `Content-Type: application/x-www-form-urlencoded`** when you mean JSON. WordPress will silently coerce strings to "" for fields that expect arrays. Always send `application/json` with a JSON body.
- **`title` and `content` are objects in responses, strings on input.** Read `post["title"]["rendered"]`, send `{"title": "..."}`.
- **`status=publish` requires `publish_posts`.** The default operator role does NOT grant this — by design. Extend via filter or use a higher role.
- **Pagination defaults to 10.** Pass `per_page=100` (max) and follow `X-WP-TotalPages` if you need to walk a list.
- **`?_embed=1`** on any post/page/comment GET pulls in author, featured media, and term data inline — saves N+1 round trips.
- **Slugs vs IDs.** WP REST takes IDs. To find a post by slug: `GET /wp/v2/posts?slug=my-post` returns an array.
- **Authorization header may not survive Cloudflare / shared hosting.** If a curl that works locally returns 401 in production, run [`scripts/diagnose-hosting-auth.sh`](../scripts/diagnose-hosting-auth.sh) — it diagnoses the most common header-stripping cases.

## When you cannot do something via REST

| You need to... | Reach for... |
|---|---|
| Edit theme / plugin / wp-config files | SFTP or SSH (REST is by design unable) |
| Recover a white-screening site | SSH (REST is broken too when WP fatals) |
| Run a 10,000-row UPDATE | `wp db query` over SSH |
| Inspect server logs / PHP version / opcache | SSH |
| Bulk-revoke every Application Password for a user | `wp user application-password delete <user> --all` over SSH |

This isn't a limitation of the plugin; it's the reason the plugin is small and auditable. See the README "When NOT to use this plugin" section for more.
