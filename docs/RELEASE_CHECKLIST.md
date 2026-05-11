# Release checklist

A short, opinionated runbook for cutting a release of AI Site Connector.

## Pre-flight

- [ ] `git status` clean on the release branch (or `main` if releasing
      direct).
- [ ] `composer run lint` — no PHP parse errors. Equivalent: `find . -name
      '*.php' -not -path './vendor/*' -not -path './.git/*' -print0 |
      xargs -0 -n1 php -l`.
- [ ] `composer run security-grep` — exits 0. Catches stray
      `eval`/`shell_exec`/`base64_decode` and forbidden file shapes
      (`.env`, `connection-pack.json`, plaintext app passwords in docs).
- [ ] `bash tests/runtime-smoke.sh` — if a WP install is available. The
      script tolerates WP-CLI stdout pollution (added in 0.1.x).
- [ ] `bash tests/package-smoke.sh` — verifies the build ZIP is
      structurally sound.

## Version bump

Update **all three** of:

1. `ai-site-connector.php` plugin header `Version:`
2. `ai-site-connector.php` `AI_SITE_CONNECTOR_VERSION` constant
3. `readme.txt` `Stable tag:` and `== Changelog ==` block

Also:

- [ ] `CHANGELOG.md` — new `## [vX.Y.Z]` section dated.
- [ ] `docs/RELEASE_NOTES_<version>.md` — optional but recommended for
      meaningful bumps.

## Build

```bash
bin/build-release-zip.sh
```

Produces `build/ai-site-connector-v<version>.zip`. The script auto-resolves
the version from the plugin header. Exclusions in the rsync block:

- `.git/`, `.github/`, `.gitignore`, `.DS_Store`
- `node_modules/`, `vendor/`, `build/`, `dist/`, `bin/`
- `scripts/runtime-test-local.sh`, `tests/`
- `*.zip`, `*.log`, `.env`, `.env.*`
- `*connection-pack.json` variants
- `composer.json`, `composer.lock`, `phpcs.xml.dist`, `phpunit.xml(.dist)`
- `assets/brand/*.png`, `TESTING_CHECKLIST.md`

## Manual verification

Smoke-test on a real WordPress install before pushing the tag:

- [ ] Activate the plugin — no fatal error. Audit log table created /
      upgraded (check `{prefix}_ai_site_connector_log` columns include
      `tool`, `status`, `ip_hash`, `meta`).
- [ ] Visit Tools → AI Site Connector. Every tab renders.
- [ ] On the **Permissions** tab, toggle `purge_cache` off, then
      `POST /ai-site-connector/v1/cache/purge` with a generated App
      Password — expect HTTP 403 with body `{"code":"rest_forbidden_tool",...}`.
      Toggle on, expect 200 + a `purged` array.
- [ ] On the **Audit Log** tab, the deny + the success should both appear,
      with `tool=purge_cache` and `status=denied` / `status=success`.
- [ ] On the **Export** tab, write a "site manifest". File appears under
      `wp-content/uploads/ai-site-connector/exports/`. URL is browser-loadable.
- [ ] On the **Connection Test** tab, the "last successful MCP request"
      shows a recent timestamp.

## Tag + release

```bash
git tag -a v<version> -m "v<version>"
git push origin v<version>

gh release create v<version> \
  --repo tyhallcsu/ai-site-connector \
  --title "v<version>" \
  --notes-file docs/RELEASE_NOTES_<version>.md \
  build/ai-site-connector-v<version>.zip
```

For pre-releases, add `--prerelease`.

## After

- [ ] Update any sibling repos that pin a specific ZIP URL.
- [ ] Close associated GitHub issues with the release version mentioned in
      the closing comment.
- [ ] Open a follow-up "next-release tracking" issue if there's known work
      already deferred.
