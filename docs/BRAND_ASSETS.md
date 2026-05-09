# AI Site Connector Brand Assets

## Purpose

These assets provide a clean, original brand mark for AI Site Connector across the GitHub README, WordPress admin/plugin screens, release notes, and repo/social previews.

The visual system uses a shield for authorized site access, connected nodes for REST/API automation, and a small terminal cue for AI coding agent workflows. It avoids the WordPress logo, Claude/OpenAI marks, and any third-party trademarked artwork.

## Files

- `assets/brand/ai-site-connector-mark.svg` — **runtime asset.** Compact square mark for admin UI; loaded by the Tools → AI Site Connector page header. Must be present in the plugin install or the admin header image will be broken (PHP still works fine).
- `assets/brand/ai-site-connector-logo.svg` — horizontal logo with the AI Site Connector wordmark. Repo display only.
- `assets/brand/ai-site-connector-readme-banner.svg` — README banner with the tagline "Secure REST API access for AI coding agents". Repo display only.
- `assets/brand/ai-site-connector-logo-512.png` — optional 512px PNG export of the compact mark. Repo display only; **excluded from the plugin install ZIP**.
- `assets/brand/ai-site-connector-logo-256.png` — optional 256px PNG export of the compact mark. Repo display only; **excluded from the plugin install ZIP**.
- `assets/brand/ai-site-connector-banner.png` — optional PNG export of the README banner. Repo display only; **excluded from the plugin install ZIP**.

The release ZIP workflow (`.github/workflows/release-zip.yml`) bundles only the SVG files, so the install footprint stays small while the SVG mark renders crisply at any zoom level in the admin.

## Usage Notes

- Prefer SVG for GitHub, documentation, and WordPress admin use because it stays crisp at every size.
- Use the compact mark when the available space is square or narrow.
- Use the README banner at the top of repo documentation or social preview contexts where a wide aspect ratio is useful.
- Keep sufficient whitespace around the mark so the shield and connected nodes remain legible.

## Safety And Legal Notes

The artwork is original vector artwork authored for this repo. It contains no embedded raster images, no stock assets, no external font files, no copied third-party logos, and no copied trademarks.

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
