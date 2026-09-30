# Art assets

Brand assets for RegexParser. Every asset is authored as SVG first; PNG files
are rendered snapshots kept in sync with their SVG source.

## Assets

| File | Role |
|---|---|
| `banner.svg` / `banner.png` | README header, displayed at full content width |
| `social.svg` / `social.png` | GitHub social preview (upload in repo settings) |
| `org-icon.svg` / `org-icon.png` | GitHub organization avatar; doubles as the small-size icon variant (16-32 px contexts) |
| `favicon.svg` / `favicon.png` | Browser tab / docs-site icon (128 px and up) |

## Palette

Core:

| Color | Hex | Use |
|---|---|---|
| Navy | `#1e293b` → `#0f172a` | Background gradient (diagonal, top-left to bottom-right) |
| Sky | `#38bdf8` | Tree root, underline accent |
| Teal | `#06b6d4` | Tree, left child |
| Deep teal | `#0891b2` | Tree, right child (depth cue, always opaque — never alpha) |
| Amber | `#fbbf24` | Tree, left leaf — the single warm accent |
| Slate | `#cbd5e1` | Brackets, edges, right leaf, wordmark fade |

Extensions (documented, use sparingly):

| Color | Hex | Use |
|---|---|---|
| Muted slate | `#94a3b8` | Secondary text ("Parser", repo path) |
| Mid navy | `#334155` | Gradient midpoint on large formats |
| PHP violet | `#777bb4` → `#8892bf` | `<?php` mark only |

## Rules

- **Tree grammar** — root sky, children teal with the right one deep teal, leaves
  amber + slate. Same topology everywhere: the right child never has leaves.
- **Brackets** — slate `#cbd5e1`, stroked paths, never text glyphs.
- **No text glyphs in brand marks.** The wordmark is vectorized paths
  (Inter Display Black + Bold); brackets and icons are paths. An SVG that
  depends on the viewer's fonts renders differently on every machine.
- **Grid** — 40px cells, white at `0.04` opacity, only on formats rendered at
  128 px and up; never on the org icon.
- **Rounded corners** — `rx` = 25% of the canvas on square icons.

## Regenerating the PNGs

```console
$ rsvg-convert -w 1000 -h 200 art/banner.svg -o art/banner.png
$ rsvg-convert -w 1280 -h 640 art/social.svg -o art/social.png
$ rsvg-convert -w 256 -h 256 art/favicon.svg -o /tmp/fav.png
$ pngquant --force --output art/favicon.png /tmp/fav.png
$ rsvg-convert -w 1024 -h 1024 art/org-icon.svg -o art/org-icon.png
```

The wordmark paths in `banner.svg` and `social.svg` are generated from Inter
Display (Black 900 / Bold 700) at font-size 100 with -3 letter-spacing, baseline
y=20. Regenerate them with a font-to-path tool if the wording ever changes —
do not hand-edit the path data.
