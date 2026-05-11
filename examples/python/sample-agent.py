#!/usr/bin/env python3
"""
AI Site Connector — Python sample agent.

Reference client demonstrating the typical AI-agent flow against a
WordPress site secured by this plugin:

  1. Authenticate (Application Password via HTTP Basic Auth)
  2. Hit the plugin's /health endpoint
  3. List published posts
  4. Create a draft post
  5. Upload a tiny image
  6. (optional) Revoke the password used

Configuration:

  WORDPRESS_SITE_URL              e.g. https://example.com
  WORDPRESS_USERNAME              the AI user's login
  WORDPRESS_APPLICATION_PASSWORD  the Application Password (with or without spaces)

Usage:

  python3 sample-agent.py
  python3 sample-agent.py --dry-run
  python3 sample-agent.py --json
  python3 sample-agent.py --revoke

Requires: Python 3.8+ and the `requests` library (`pip install requests`).
"""

from __future__ import annotations

import argparse
import base64
import json
import os
import sys
import textwrap
from typing import Any

try:
    import requests
except ImportError:
    sys.stderr.write(
        "This sample requires the `requests` library. Install with: pip install requests\n"
    )
    sys.exit(2)


def env_or_die(name: str) -> str:
    value = os.environ.get(name, "").strip()
    if not value:
        sys.stderr.write(f"Missing env var: {name}\n")
        sys.exit(2)
    return value


def main() -> int:
    parser = argparse.ArgumentParser(description="AI Site Connector — Python sample agent")
    parser.add_argument("--dry-run", action="store_true", help="Print requests without making them")
    parser.add_argument("--json", action="store_true", help="Machine-parseable output")
    parser.add_argument(
        "--revoke",
        action="store_true",
        help="After demo, revoke the Application Password used (single-session credential pattern)",
    )
    args = parser.parse_args()

    site = env_or_die("WORDPRESS_SITE_URL").rstrip("/")
    user = env_or_die("WORDPRESS_USERNAME")
    password = env_or_die("WORDPRESS_APPLICATION_PASSWORD")

    rest_base = f"{site}/wp-json"
    auth = (user, password)
    results: dict[str, Any] = {}

    def log(label: str, data: Any) -> None:
        results[label] = data
        if not args.json:
            print(f"\n=== {label} ===")
            print(json.dumps(data, indent=2, default=str) if not isinstance(data, str) else data)

    def call(method: str, path: str, **kwargs: Any) -> Any:
        url = f"{rest_base}{path}"
        if args.dry_run:
            return {"_dry_run": True, "method": method, "url": url, "kwargs": list(kwargs.keys())}
        resp = requests.request(method, url, auth=auth, timeout=15, **kwargs)
        try:
            body = resp.json()
        except ValueError:
            body = resp.text
        return {"status": resp.status_code, "body": body}

    # 1. Health
    log("health", call("GET", "/ai-site-connector/v1/health"))

    # 2. List recent posts
    log("posts.list", call("GET", "/wp/v2/posts", params={"per_page": 5, "status": "publish"}))

    # 3. Create a draft post
    log(
        "posts.create",
        call(
            "POST",
            "/wp/v2/posts",
            json={
                "title": "AI Site Connector sample-agent draft",
                "content": "Hello from the Python sample agent.",
                "status": "draft",
            },
        ),
    )

    # 4. Upload a tiny image (1x1 PNG)
    png_bytes = base64.b64decode(
        "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8/5+hHgAHggJ/PchI7wAAAABJRU5ErkJggg=="
    )
    if args.dry_run:
        log("media.upload", {"_dry_run": True, "method": "POST", "url": f"{rest_base}/wp/v2/media", "bytes": len(png_bytes)})
    else:
        media_resp = requests.post(
            f"{rest_base}/wp/v2/media",
            auth=auth,
            headers={
                "Content-Disposition": 'attachment; filename="sample-agent-pixel.png"',
                "Content-Type": "image/png",
            },
            data=png_bytes,
            timeout=15,
        )
        try:
            body = media_resp.json()
        except ValueError:
            body = media_resp.text
        log("media.upload", {"status": media_resp.status_code, "body": body})

    # 5. Optional: revoke the password used (looks up its UUID via /wp/v2/users/me)
    if args.revoke and not args.dry_run:
        me = call("GET", "/wp/v2/users/me", params={"context": "edit"})
        log("users.me", me)
        # Application Passwords are not exposed on /users/me; revoke via wp-admin or WP-CLI.
        log("revoke", "Skipped: revoke from wp-admin (Tools -> AI Site Connector -> Credentials) or via WP-CLI.")

    if args.json:
        print(json.dumps(results, indent=2, default=str))

    return 0


if __name__ == "__main__":
    sys.exit(main())
