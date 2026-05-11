# Bash sample agent

A small reference client demonstrating the typical AI-agent flow against a WordPress site secured by the AI Site Connector plugin.

## Requirements

- `bash` 4+ (uses associative arrays in `--json` mode)
- `curl`
- `base64` (for the media-upload demo)

## Configuration

Set these environment variables — the same ones the plugin's connection-pack tabs emit:

```bash
export WORDPRESS_SITE_URL="https://example.com"
export WORDPRESS_USERNAME="ai-agent"
export WORDPRESS_APPLICATION_PASSWORD="XXXX XXXX XXXX XXXX XXXX XXXX"
```

## Usage

```bash
chmod +x sample-agent.sh

# Run the full demo
./sample-agent.sh

# Print requests without executing
./sample-agent.sh --dry-run

# Machine-parseable JSON output (one object with all step results)
./sample-agent.sh --json
```

## What it does

1. Authenticates via HTTP Basic Auth (Application Password)
2. Calls `GET /wp-json/ai-site-connector/v1/health`
3. Calls `GET /wp-json/wp/v2/posts?per_page=5&status=publish`
4. Creates a draft post via `POST /wp-json/wp/v2/posts`
5. Uploads a 1x1 PNG via `POST /wp-json/wp/v2/media`

## Adapting for your own use

The shape `curl -sS -X METHOD -u "$USER:$PASS" URL` is all you need. Everything else is glue.
