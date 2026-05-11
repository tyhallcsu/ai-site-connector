# Python sample agent

A small reference client demonstrating the typical AI-agent flow against a WordPress site secured by the AI Site Connector plugin.

## Requirements

- Python 3.8+
- `requests` library (`pip install requests`)

## Configuration

Set these environment variables — the same ones the plugin's connection-pack tabs emit:

```bash
export WORDPRESS_SITE_URL="https://example.com"
export WORDPRESS_USERNAME="ai-agent"
export WORDPRESS_APPLICATION_PASSWORD="XXXX XXXX XXXX XXXX XXXX XXXX"
```

## Usage

```bash
# Run the full demo (health → list posts → create draft → upload pixel)
python3 sample-agent.py

# Print every request without executing
python3 sample-agent.py --dry-run

# Machine-parseable JSON output
python3 sample-agent.py --json
```

## What it does

1. Authenticates via HTTP Basic Auth (Application Password)
2. Calls `GET /wp-json/ai-site-connector/v1/health`
3. Calls `GET /wp-json/wp/v2/posts?per_page=5&status=publish`
4. Creates a draft post via `POST /wp-json/wp/v2/posts`
5. Uploads a 1x1 PNG via `POST /wp-json/wp/v2/media`

Everything the demo creates can be cleaned up by deleting the draft post + uploaded media from wp-admin → Media / Posts.

## Adapting for your own use

This is intentionally a thin wrapper around `requests`. To turn it into your own agent, replace the demo flow in `main()` with whatever sequence of calls your use case needs. The `call()` helper handles auth and JSON parsing for you.
