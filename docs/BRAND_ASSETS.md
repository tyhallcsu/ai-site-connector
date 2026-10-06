# AI Site Connector Brand Assets

## Purpose

These assets provide a clean, original brand mark for AI Site Connector across the GitHub README, WordPress admin/plugin screens, release notes, and repo/social previews.

The visual system uses two bold mint-and-cyan connection links on a rounded navy tile. It avoids the WordPress logo, Claude/OpenAI marks, and any third-party trademarked artwork.

The illustrated README now uses three generated PNGs documented in [README artwork provenance](README_ARTWORK.md). The icon was replaced across all six identity assets in October 2026; the illustrated README chapter artwork remains separate.

## Files

- `assets/ai-site-connector-mark-128.png` — **runtime asset** (7.6 KB). 128px export of the compact mark, shown at 64px (sharp on 2× displays) in the Tools → AI Site Connector page header. It is the only brand image in the plugin install.
- `assets/brand/ai-site-connector-mark.svg` — compact square mark, the source of the PNG exports. Repo display and the updater's `svg` icon (served from GitHub).
- `assets/brand/ai-site-connector-logo.svg` — horizontal logo with the AI Site Connector wordmark. Repo display only.
- `assets/brand/ai-site-connector-readme-banner.svg` — README banner with the tagline "Secure REST API access for AI coding agents". Repo display only.
- `assets/brand/ai-site-connector-logo-512.png` — optional 512px PNG export of the compact mark. Repo display only; excluded from the plugin install ZIP.
- `assets/brand/ai-site-connector-logo-256.png` — optional 256px PNG export of the compact mark. Repo display only; excluded from the plugin install ZIP.
- `assets/brand/ai-site-connector-banner.png` — optional PNG export of the README banner. Repo display only; excluded from the plugin install ZIP.

The release ZIP build script (`bin/build-release-zip.sh`) excludes all of `assets/brand/` (#125). Each SVG embeds a 512px PNG (about 375 KB), not resolution-independent vector artwork, so shipping them made the ZIP four times larger. `tests/package-smoke.sh` enforces a 400 KiB ZIP budget and a 20 KiB header mark.

## Usage Notes

- Use `assets/ai-site-connector-mark-128.png` at runtime and the PNG exports for updater/plugin-details surfaces. The embedded icon has a native resolution of 512px. Regenerate the runtime PNG from the 512px export: `magick assets/brand/ai-site-connector-logo-512.png -resize 128x128 assets/ai-site-connector-mark-128.png`, then `pngquant --quality=88-99` and `oxipng -o max --strip all`.
- Use the compact mark when the available space is square or narrow.
- Use the README banner at the top of repo documentation or social preview contexts where a wide aspect ratio is useful.
- Keep sufficient whitespace around the mark so the connection links remain legible.

## Safety And Legal Notes

The icon was generated with OpenAI image generation on 2026-10-05. SVG assets embed the same raster icon alongside the existing vector wordmark/layout; PNG exports use that same identity. No stock assets or third-party logos were supplied. See [icon provenance](ICON_ARTWORK.md).

The brand assets ship under the same [MIT License](../LICENSE) as the rest of the plugin code — anyone may use, modify, and redistribute. They should **not** be presented as official WordPress, Claude, OpenAI, Anthropic, or Automattic branding.

## Regenerating PNGs

The current PNG exports were generated from the SVG source files with `rsvg-convert`:

```bash
rsvg-convert -w 512 -h 512 assets/brand/ai-site-connector-mark.svg -o assets/brand/ai-site-connector-logo-512.png
rsvg-convert -w 256 -h 256 assets/brand/ai-site-connector-mark.svg -o assets/brand/ai-site-connector-logo-256.png
rsvg-convert -w 1200 assets/brand/ai-site-connector-readme-banner.svg -o assets/brand/ai-site-connector-banner.png
```

If `rsvg-convert` is unavailable, ImageMagick can usually produce equivalent exports:

```bash
magick assets/brand/ai-site-connector-mark.svg -resize 512x512 assets/brand/ai-site-connector-logo-512.png
magick assets/brand/ai-site-connector-mark.svg -resize 256x256 assets/brand/ai-site-connector-logo-256.png
magick assets/brand/ai-site-connector-readme-banner.svg -resize 1200x assets/brand/ai-site-connector-banner.png
```
