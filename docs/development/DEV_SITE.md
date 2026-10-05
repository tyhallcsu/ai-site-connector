# Dev site runbook

The canonical development target is a persistent **local** WordPress driven by
[`bin/dev-site.sh`](../../bin/dev-site.sh). No remote dev site exists yet; see
[#97](https://github.com/tyhallcsu/ai-site-connector/issues/97). Until #97 is
resolved, "deployed to dev" means this local instance and nothing else.

| Item | Value |
|------|-------|
| URL | http://localhost:8790 (bound to 127.0.0.1 only) |
| Admin | http://localhost:8790/wp-admin/ — user `asc-dev-admin`; password generated at install, stored in `/asc-dev-state/admin-password` inside the containers, never printed |
| Stack | Docker Compose project `asc-dev`: `wordpress:7.1-php8.2-apache`, `mysql:8.0`, `wordpress:cli-php8.2` (run on demand) |
| Environment | `WP_ENVIRONMENT_TYPE=local`, `WP_DEBUG` on (log only: `/asc-dev-state/debug.log`), `AI_SITE_CONNECTOR_ALLOW_HTTP`, `DISALLOW_FILE_EDIT`, search engines discouraged |
| Persistence | named volumes `asc-dev_db`, `asc-dev_wp`, `asc-dev_state` (survive `stop`, checkout and worktree changes) |
| Shared with production | nothing: own database, uploads and state; mail is captured, never sent; no webhooks configured |
| Kept apart from tests | disposable test DB `asc-mysql-test` (127.0.0.1:33306) and runtime smoke port 8775 are separate; never `destroy` the dev site as test cleanup |

## Daily use

```bash
bin/dev-site.sh up            # start (first run installs WordPress)
bin/dev-site.sh deploy        # build origin/main from git and install it
bin/dev-site.sh status        # what is deployed: SHA, version, ZIP sha256, previous
bin/dev-site.sh seed          # synthetic fixture content (idempotent)
bin/dev-site.sh stop          # stop; keeps data
```

In a sandbox that blocks the system temp dir, prefix `deploy`/`rollback` with a
writable `TMPDIR` (e.g. the session scratchpad).

## Deploy semantics

- `deploy [REF]` resolves REF (default `origin/main`) to a SHA after `git fetch`.
  The SHA must be an ancestor of `origin/main`; `--allow-unmerged` deploys a
  candidate, and `status` then labels it `UNMERGED`.
- The ZIP is built by that commit's own `bin/build-release-zip.sh` from
  `git archive <sha>` — never from the working tree.
- Before installing, the database is dumped to `/asc-dev-state/backups/`
  (newest 10 kept). Install is `wp plugin install --force --activate`, which
  replaces files like a normal upgrade (no deactivate/activate cycle).
- After installing, every file in `wp-content/plugins/ai-site-connector` is
  compared byte for byte with the ZIP; any difference fails the deploy.
- The artifact is kept at `/asc-dev-state/artifacts/<sha>-<sha256 prefix>.zip`;
  `current.json` and the append-only `deployments.jsonl` record SHA, source,
  version, ZIP sha256, time and the DB snapshot.
- `deploy --release vX.Y.Z` installs the published GitHub asset after checking
  it against GitHub's recorded digest — use it to rehearse upgrades from the
  latest release.
- The version number alone does not identify dev code: unreleased commits on
  `main` carry the last released version. Always quote the SHA.

## Rollback and recovery

- `rollback` reinstalls the previous artifact (checksum re-verified) and
  snapshots the database first. It does **not** restore the database.
- `backups` lists snapshots; `restore-db FILE --yes-overwrite-dev-db` restores
  one after taking a fresh snapshot. Only do this when a deploy damaged dev data.
- `destroy --yes-destroy-dev-data` deletes containers and volumes. It is never
  part of routine cleanup.

## Inspecting the site

- `wp ARGS...` — WP-CLI against the dev site.
- `mail` — outbound mail captured by the dev-only mu-plugin
  `bin/dev-site/mu-plugins/asc-dev-mail-sink.php`.
- `debug-log` — PHP notices/warnings (WP_DEBUG log).
- `logs` — Apache access/error log stream.
- `audit [OUT_DIR]` — headless Chromium audit of all 11 admin tabs at 1280 px
  and 375 px: page overflow, unlabeled controls, duplicate ids, PHP warning
  text, JS errors, HTTP >= 400. Screenshots + `report.json` go to OUT_DIR;
  exit 1 means findings. Installs `playwright` into `ASC_DEV_CACHE`
  (default `~/.cache/asc-dev-site`) on first use.
- `with-admin -- CMD` — runs CMD with `ASC_DEV_URL`, `ASC_DEV_ADMIN_USER` and
  `ASC_DEV_ADMIN_PASSWORD` in its environment, for headless browser journeys.
  Use an isolated headless browser context, never a personal browser profile.

## Fixture content

`seed` creates (once) the `asc-fixture-*` items, all tagged with the
`_asc_dev_fixture` meta key: a page, a published post with one valid and one
broken internal link plus an external link, a draft, and two byte-identical
images (one without alt text). They exist to exercise the inventory, link,
media and duplicate audits.
