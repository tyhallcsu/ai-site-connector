# Discovery — `/.well-known/ai-site-connector.json`

AI Site Connector serves a tiny public JSON file at a stable path so AI tooling and integrations can detect the plugin and the URLs of its sub-surfaces (MCP transport, OpenAPI spec, tools catalog) without trial-and-error probing. The pattern is modeled on the OAuth 2.0 [`.well-known`](https://datatracker.ietf.org/doc/html/rfc8414) convention.

## URL

```
https://<your-wp-site>/.well-known/ai-site-connector.json
```

Served by [`includes/class-discovery.php`](../includes/class-discovery.php) via a top-priority rewrite rule. The response is cacheable for 5 minutes (`Cache-Control: public, max-age=300`) and CORS-open (`Access-Control-Allow-Origin: *`).

## Payload (spec version 1)

```json
{
  "spec_version": "1",
  "plugin": "ai-site-connector",
  "version": "0.9.0",
  "homepage": "https://github.com/tyhallcsu/ai-site-connector",
  "rest_namespace": "ai-site-connector/v1",
  "rest_base": "https://example.com/wp-json/ai-site-connector/v1",
  "openapi_url": "https://example.com/wp-json/ai-site-connector/v1/openapi.json",
  "tools_catalog_url": "https://example.com/wp-json/ai-site-connector/v1/tools",
  "mcp": {
    "http": "https://example.com/wp-json/ai-site-connector/v1/mcp"
  },
  "auth_methods": ["basic_auth_application_password"],
  "status": "active"
}
```

### Field reference

| Field | Type | Meaning |
| --- | --- | --- |
| `spec_version` | string | Discovery file schema version. Bumped when fields are renamed, removed, or change shape. Additive changes (new optional fields) do not bump. Current: `"1"`. |
| `plugin` | string | Stable plugin slug — `"ai-site-connector"`. A tool can use this to confirm it found the right plugin (e.g. distinguish from a future unrelated `.well-known` file). |
| `version` | string | Installed plugin version. SemVer. |
| `homepage` | string (URL) | Project homepage on GitHub. |
| `rest_namespace` | string | The REST namespace under `wp-json/`. Stable across versions; the plugin's contract is "all routes live under this namespace." |
| `rest_base` | string (URL) | Fully-resolved base URL for the namespace. `rest_namespace` joined to the WP site's `rest_url()`. Tools should prefer this over hand-building the URL. |
| `openapi_url` | string (URL) | OpenAPI 3 spec for every route in the namespace. Generated live from the REST registry — new routes appear automatically. 1-hour cache. Public. |
| `tools_catalog_url` | string (URL) | `GET /tools` — MCP tool catalog with per-tool allow state for the calling user. Requires authentication; the URL is public, the payload is not. |
| `mcp.http` | string (URL) | HTTP MCP transport endpoint (JSON-RPC 2.0 over POST). |
| `auth_methods` | string[] | Supported auth schemes. Currently always `["basic_auth_application_password"]`. |
| `status` | string | One of `"active"` (normal), `"degraded"` (a sub-surface is disabled — e.g. MCP killed via `AI_SITE_CONNECTOR_MCP_DISABLE`), reserved for future use. The current implementation always emits `"active"`. |

## Disabling the discovery file

Some operators don't want the plugin's presence to be auto-detectable. Define the kill switch in `wp-config.php`:

```php
define( 'AI_SITE_CONNECTOR_DISCOVERY_DISABLE', true );
```

The rewrite rule is then not registered and `/.well-known/ai-site-connector.json` returns a 404 (handled by WordPress's normal template hierarchy).

## How to consume it

### From a shell

```bash
curl -fsSL https://example.com/.well-known/ai-site-connector.json | jq .
```

If the response is HTTP 404, the plugin is not installed (or discovery is disabled). If it is HTTP 200 but missing `rest_namespace`, the discovery file is corrupt — file a bug.

### From the bundled stdio MCP server

The [stdio MCP bridge](../examples/mcp-server/) does not consume the discovery file directly — it expects an explicit site URL via `WORDPRESS_SITE_URL` (or a connection-pack JSON file). Higher-level launchers that pick a site URL from user input can call the discovery URL first to confirm the plugin is installed.

### From a tool integration

A typical bootstrap looks like:

1. User pastes a WordPress site URL.
2. Integration fetches `{site}/.well-known/ai-site-connector.json`.
3. If present, integration uses `mcp.http` as the MCP endpoint and `tools_catalog_url` as the tool inventory.
4. Integration asks user for an Application Password (via the plugin's Tools → AI Site Connector page).
5. Integration calls `mcp.http` with `Authorization: Basic base64(user:app-password)`.

## Compatibility

| Plugin version | `spec_version` | Notes |
| --- | --- | --- |
| 0.8.0+ | `"1"` | Initial discovery file (closes #18). |

Backwards-incompatible changes to existing fields require a major plugin release AND a `spec_version` bump. Additive fields are not breaking and do not require a bump.

## See also

- [OpenAPI spec](../includes/class-openapi.php) — the source of truth for route shapes.
- [`GET /tools`](../includes/class-rest-controller.php) — the runtime tool catalog the discovery file points at.
- [`examples/mcp-server/`](../examples/mcp-server/) — the bundled stdio MCP server.
