# Art assets

Brand assets for RegexParser. Every asset is authored as SVG first; PNG files
are rendered snapshots kept in sync with their SVG source.

## System

The identity is a paper-and-ink editorial system with a navy tile variant for
small formats:

- **Large formats** (banner, social) — cream paper `#F8F7F2`, ink navy `#182B45`,
  rust amber `#A84A08`, muted slate `#47586E`, hairline `#E4E1D6`.
- **Small formats** (org icon, favicon) — navy tile `#0F1B2E`, light ink
  `#EDF1F7`, warm amber `#F0A63C` (the paper system's dark tokens).
- **Corner radii** — card = h/6.4 (rx28), icon tile = c/4.57 (rx112):
  document family vs icon family, never mixed.
- **Dark theme** — swap to `#0F1B2E` paper, `#EDF1F7` ink, `#F0A63C` amber,
  `#A3B2C6` muted, `#2A3B55` hairline. Every large SVG embeds the swap as
  `@media (prefers-color-scheme: dark)` and every asset ships a `-dark`
  twin (SVG + PNG) for explicit `<picture>` switching and manual uploads.

| File | Role |
|---|---|
| `banner.svg` / `banner.png` | README header (light), full content width; transparent 10px margin + `#64748B` border so the card reads on white and dark pages |
| `banner-dark.svg` / `banner-dark.png` | Dark-token twin, for `<picture>`-based theme switching |
| `social.svg` / `social.png` | GitHub social preview (light), upload in repo settings; key content keeps a 71px+ safe margin against 16:9 recrops |
| `social-dark.svg` / `social-dark.png` | Dark-token twin of the social card |
| `org-icon.svg` / `org-icon.png` | GitHub organization avatar (circle-cropped at 20-40px); navy tile variant |
| `favicon.svg` / `favicon.png` | Browser tab / docs-site icon; same navy tile design |

## Rules

- **The mark** — brackets frame a three-node tree: one root (r38) and two
  children (r30), the tree optically centered (+14) inside the brackets.
  One construction, two optical curves: icons at <=512px use stroke 40/34,
  large formats 27/22 — small sizes get the heavier skeleton.
- **No text glyphs in brand marks.** The wordmark is vectorized paths (Inter
  Display Black for "Regex", Bold for "Parser") generated with real font
  advances — hand-placed glyph paths collide; font-derived advances cannot.
  The social tagline is the only caption (JetBrains Mono Regular),
  vectorized.
- **Contrast gates** (measured, never eyeballed): text ≥ 4.5:1, meaningful
  graphics ≥ 3:1. Current values: ink/paper 13.3:1, amber/paper 5.4:1,
  ink/navy-tile 15.2:1, amber/navy-tile 8.4:1, border/white 4.76:1,
  border/GitHub-dark 3.98:1, border/paper 4.44:1, border/dark-paper 3.63:1. All pass under grayscale (6.3-8.0:1) and
  deuteranopia simulation (4.9-6.0:1); the mark's hierarchy is carried by
  shape and size, never hue alone.
- **README usage** — serve the banner with `<picture>` (light + `-dark` twin)
  when possible; the embedded `@media` self-themes where supported.
- **Accessibility** — every SVG carries `role="img"` and a `<title>`.
- **Icon small-size floor** — before shipping any icon change, render at
  16px and require every node to sample ≥ 3:1 against its tile at the node's
  center pixel, and zero mark ink outside the inscribed circle.

## Regenerating the PNGs

```console
$ rsvg-convert -w 1000 -h 200 art/banner.svg -o art/banner.png
$ rsvg-convert -w 1000 -h 200 art/banner-dark.svg -o art/banner-dark.png
$ rsvg-convert -w 1280 -h 640 art/social.svg -o art/social.png
$ rsvg-convert -w 1280 -h 640 art/social-dark.svg -o art/social-dark.png
$ rsvg-convert -w 512 -h 512 art/favicon.svg -o /tmp/fav.png
$ pngquant --force --output art/favicon.png /tmp/fav.png
$ rsvg-convert -w 1024 -h 1024 art/org-icon.svg -o art/org-icon.png
```

The wordmark paths are generated from Inter Display (Black 900 / Bold 700) with
fontTools (SVGPathPen + TransformPen, real advance widths, slight negative
tracking); the mono tagline from JetBrains Mono Regular. If the wording ever
changes, regenerate the paths with a font-to-path tool — do not hand-edit
path data.
