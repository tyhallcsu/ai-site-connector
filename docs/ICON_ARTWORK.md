# Plugin icon provenance

Generated with OpenAI image generation on 2026-10-05. The mint-and-cyan connection symbol replaces the shield/node icon in the admin mark, horizontal wordmark, legacy banner, 256px/512px updater icons, and plugin-details banner. No third-party identity assets were supplied.

The generated transparent image was proportionally downsampled to 512px and 256px using macOS `sips`. The 512px PNG is embedded as a data URI in the three existing SVG paths, preserving their offline runtime and packaging behavior. These SVGs contain raster artwork; they are not vector tracings. The banner PNG was re-exported using `rsvg-convert`. Existing README chapter illustrations are unchanged.

The runtime admin header reads the bundled SVG, so existing installations receive that copy with their next plugin package update. Updater and plugin-details images use the existing remote main-branch URLs, subject to client/CDN caching. This artwork change does not itself publish a new plugin release.

## Prompt

Generate a single premium application icon for AI Site Connector, square 1024x1024. A bold sculptural interlocking connection mark: two chunky opposing rounded C-shaped links forming one continuous compact bridge, one mint green and one icy cyan, with a clear dark negative-space slot between them. Slight bevels, precise soft studio highlights, dimensional but restrained. Centered on an opaque deep midnight navy rounded-square tile that fills nearly the whole canvas, with genuinely transparent corners outside the tile. Large simple silhouette readable at 32px, generous 15 percent internal padding, straight-on view, no perspective. Modern professional developer-tool identity. No shield, no network-node diagram, no terminal glyph, no letters, no text, no watermark, no third-party logos. Only one finished icon, no presentation sheet, no mockup, no floor or cast shadow outside the tile.
