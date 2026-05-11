# ai-site-connector-mcp

Stdio MCP server for the AI Site Connector WordPress plugin. Speaks MCP over stdio (the transport Claude Desktop and Cursor use locally) and forwards every `tools/call` to the plugin's HTTP MCP endpoint (`/wp-json/ai-site-connector/v1/mcp`) via Basic Auth.

Use this when:

- You're running Claude Desktop or Cursor locally and want a one-binary install.
- Your target site is publicly reachable on the internet (the plugin's HTTP MCP endpoint is what does the work).

Otherwise, use the connection-pack tabs in Tools → AI Site Connector → Credentials to wire up an HTTP-transport MCP config directly.

## Requirements

- Node 18+
- `@modelcontextprotocol/sdk` (installed via `npm install` in this directory)

## Install

```bash
cd examples/mcp-server
npm install
```

## Configure

Set environment variables — the same shape the plugin's connection-pack tabs emit:

```bash
export WORDPRESS_SITE_URL="https://example.com"
export WORDPRESS_USERNAME="ai-agent"
export WORDPRESS_APPLICATION_PASSWORD="XXXX XXXX XXXX XXXX XXXX XXXX"
```

Or point at a connection-pack JSON file:

```bash
export AI_SITE_CONNECTOR_PACK="/Users/you/secrets/ai-agent-pack.json"
```

(The pack JSON wins if set.)

## Run standalone (smoke test)

```bash
node index.mjs
```

The process will sit on stdin and emit nothing — that's correct. Pipe MCP JSON-RPC requests in on stdin to drive it manually.

## Wire into Claude Desktop

Add to `~/Library/Application Support/Claude/claude_desktop_config.json` (macOS) or `%APPDATA%\Claude\claude_desktop_config.json` (Windows):

```json
{
  "mcpServers": {
    "example.com": {
      "command": "node",
      "args": ["/absolute/path/to/ai-site-connector/examples/mcp-server/index.mjs"],
      "env": {
        "WORDPRESS_SITE_URL":             "https://example.com",
        "WORDPRESS_USERNAME":             "ai-agent",
        "WORDPRESS_APPLICATION_PASSWORD": "XXXX XXXX XXXX XXXX XXXX XXXX"
      }
    }
  }
}
```

Restart Claude Desktop. The 9 tools (`wp_health`, `wp_site_info`, `wp_list_posts`, `wp_get_post`, `wp_create_post`, `wp_update_post`, `wp_list_pages`, `wp_list_plugins`, `wp_list_themes`) appear in the tool picker.

## Wire into Cursor

`.cursor/mcp.json` in your project root, same shape as the Claude Desktop block above.

## Tools

This server forwards every call to the PHP-side `AI_Site_Connector_MCP_Server` class. See [`includes/class-mcp-server.php`](../../includes/class-mcp-server.php) for the canonical tool descriptors. The two sides must stay in sync — add new tools in both places.

## Why not just configure HTTP MCP directly?

Some Claude Desktop / Cursor builds don't speak HTTP MCP yet; stdio is the lowest-common-denominator transport. This bundled bridge gives you stdio's reach while keeping all the business logic on the WordPress side where audit logging, scope enforcement, and IP allowlist work.

## License

MIT, same as the parent plugin.
