# ai-site-connector-mcp (optional local stdio bridge)

> **Most users should skip this folder.** Go to **Tools → AI Site Connector → Credentials** in WP-Admin, generate an Application Password, and paste the **Claude Desktop (MCP)** or **Cursor / VS Code (MCP)** tab into your tool's MCP config. As of v0.9.1 those tabs emit a config that uses the community [`mcp-remote`](https://www.npmjs.com/package/mcp-remote) package to call this plugin's HTTP MCP endpoint directly — no path to fill in, no `npm install`, no env vars. Paste, restart, done. Requires Node 18+ on the machine running Claude Desktop / Cursor.
>
> This folder is the **advanced fallback** for cases where `mcp-remote` doesn't fit:
>
> - The site you want to expose isn't reachable over the public internet from the machine running Claude Desktop / Cursor (air-gapped LAN, VPN-only, on-prem behind a corporate proxy).
> - You want to hand-modify the bridge logic before traffic hits the plugin (debugging, custom logging).
> - Your AI tool refuses to spawn `npx` and you need a fully pre-resolved local command.

## What this bridge does

A small Node 18+ stdio MCP server that forwards every `tools/call` to the plugin's HTTP MCP endpoint (`POST /wp-json/ai-site-connector/v1/mcp`) via Basic Auth. Functionally equivalent to `npx -y mcp-remote <url> --header Authorization:Basic ...`, but the source is in-tree so you can clone, modify, and run it locally.

## Requirements

- Node 18+
- `@modelcontextprotocol/sdk` (installed via `npm install` in this directory)

## Install

```bash
cd examples/mcp-server
npm install
```

## Configure

Set environment variables — the legacy shape:

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

## Wire into Claude Desktop (advanced — only if you really need the local bridge)

Add to `~/Library/Application Support/Claude/claude_desktop_config.json` (macOS) or `%APPDATA%\Claude\claude_desktop_config.json` (Windows). Replace the `args` path with the **actual absolute path on this machine** where you cloned the repo:

```json
{
  "mcpServers": {
    "example.com": {
      "command": "node",
      "args": ["/replace/with/absolute/path/to/ai-site-connector/examples/mcp-server/index.mjs"],
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

For 99% of installs this is overkill — the `mcp-remote` config from the plugin's Credentials tab does the same job without the path edit.

## Wire into Cursor

`.cursor/mcp.json` in your project root, same shape as the Claude Desktop block above (same caveat about the absolute path).

## Tools

This server forwards every call to the PHP-side `AI_Site_Connector_MCP_Server` class. See [`includes/class-mcp-server.php`](../../includes/class-mcp-server.php) for the canonical tool descriptors. The two sides must stay in sync — add new tools in both places.

## `mcp-remote` (recommended) vs this bridge (advanced)

| Concern | `mcp-remote` from the plugin tab | This bundled bridge |
|---|---|---|
| Install steps | none — `npx -y` resolves it | clone repo + `npm install` in this folder |
| Path in config | none | absolute path to `index.mjs` |
| Outbound HTTP from operator's laptop | required to reach the site | also required (the bridge just relocates the HTTP call) |
| Modifiable / inspectable | no (community package) | yes (in-tree source) |
| Best for | 99% of installs | air-gapped via tunnel, debugging, hand-modified workflows |

## License

MIT, same as the parent plugin.
