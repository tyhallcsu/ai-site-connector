# Release checklist

How to cut a release of AI Site Connector. The tag-triggered
`.github/workflows/release-zip.yml` is the **only** publisher — never run
`gh release create` for the same tag.

## 1. Release-prep PR

On a branch from current `main`:

- [ ] Bump **all** version fields to `X.Y.Z`:
  1. `ai-site-connector.php` header `Version:`
  2. `ai-site-connector.php` `define( 'AI_SITE_CONNECTOR_VERSION', 'X.Y.Z' )`
  3. `readme.txt` `Stable tag:` and a `= X.Y.Z =` entry under `== Changelog ==`
  4. `examples/mcp-server/package.json` `"version"`
- [ ] `CHANGELOG.md`: rename `## [Unreleased]` to `## [X.Y.Z] - YYYY-MM-DD`
      (no `v` prefix — the workflow matches this exact form) and start a new
      empty `## [Unreleased]`.
- [ ] `bin/check-version.sh --release --expect X.Y.Z` passes.
- [ ] Local gates (see `handoff.md` for the disposable MySQL container):
  - `composer test` (must report `OK (N tests…)`, N ≥ 1)
  - `composer lint`, `composer phpcs`, `tests/security-grep.sh`
  - `tests/package-smoke.sh`
  - `tests/runtime-smoke.sh` (includes the `tests/integration` suite)
  - `tests/zip-upgrade-smoke.sh "$(bin/build-release-zip.sh | tail -n1)"`
    — clean install of the ZIP, then upgrade from the previous release ZIP.
- [ ] Open the PR, wait for CI, merge (squash).

## 2. Tag the merged commit

```bash
git fetch origin
git log -1 --format='%H %s' origin/main        # confirm this is the release-prep merge
gh api repos/tyhallcsu/ai-site-connector/commits/$(git rev-parse origin/main)/check-runs \
  --jq '.check_runs[] | "\(.name)\t\(.conclusion)"'   # all required checks: success
git tag -a vX.Y.Z -m "vX.Y.Z" origin/main
git push origin vX.Y.Z
```

Tag a resolved SHA on `main`, never a feature branch. Pre-releases use a
hyphenated tag (`vX.Y.Z-rc.1`) and are published with `prerelease: true`;
the plugin's updater only offers them when the prerelease channel is enabled
(stable sites read `/releases/latest`, which excludes pre-releases).

The workflow then refuses to publish unless: version fields equal the tag,
the tag is on `main`, the required CI checks succeeded for that SHA, and no
release exists yet for the tag. It builds the ZIP, re-runs the package smoke,
attaches `ai-site-connector-vX.Y.Z.zip` + `.zip.sha256`, and uses the
CHANGELOG section as the release body.

## 3. Verify the published release

```bash
gh run watch -R tyhallcsu/ai-site-connector $(gh run list -R tyhallcsu/ai-site-connector --workflow release-zip.yml --limit 1 --json databaseId --jq '.[0].databaseId')
gh release view vX.Y.Z -R tyhallcsu/ai-site-connector --json isPrerelease,isDraft,assets
gh release download vX.Y.Z -R tyhallcsu/ai-site-connector -D /tmp/asc-vX.Y.Z
cd /tmp/asc-vX.Y.Z && shasum -a 256 -c ai-site-connector-vX.Y.Z.zip.sha256
unzip -p ai-site-connector-vX.Y.Z.zip ai-site-connector/ai-site-connector.php | grep -E 'Version:|AI_SITE_CONNECTOR_VERSION'
```

- [ ] Status (stable / prerelease) is as intended; exactly one ZIP + checksum.
- [ ] Downloaded ZIP matches its checksum and embeds `X.Y.Z`.
- [ ] Record tag, source SHA, release URL and checksum in
      `docs/development/WORK_LOG.md` and `handoff.md`.
- [ ] Comment on the issues shipped in the release.

Never force-move a published tag or replace a release asset with different
code. If something is wrong, ship `X.Y.(Z+1)`.

Publishing a release does not install it anywhere; updating live sites is a
separate, explicitly authorised step.
