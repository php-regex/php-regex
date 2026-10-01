#!/usr/bin/env python3
"""Generate the per-package banners (light + dark) under src/*/art/.

Each banner: the PHPRegex mark + the one-word brand "PHPRegex" set in ink
(PHP in Inter Display Black, Regex in Inter Display Bold) followed by the
package display name in amber (Inter Display Bold) — square card, 1000x200,
same construction as the root art/banner.svg whose mark group is copied
verbatim to stay in sync.

Long display names shrink the type (never the mark) until the lockup fits
the card with its optical margins; the lockup stays centred on x=500.

Usage:
    python3 art/package-banners.py --black InterDisplay-Black.ttf --bold InterDisplay-Bold.ttf

Fonts: Inter Display static TTFs (Black 900 / Bold 700) from the Inter
release zip — https://github.com/rsms/inter/releases (extras/ttf/).
Writes src/<Package>/art/banner.svg and banner-dark.svg.
"""
import argparse
import os
import re
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from wordmark import T_UNITS, fmt, lsb_px, metrics, word  # noqa: E402

from fontTools.ttLib import TTFont

PACKAGES = [
    ("Automata", "Automata"), ("Cli", "CLI"), ("Explain", "Explain"),
    ("Generator", "Generator"), ("LanguageServer", "Language Server"),
    ("Laravel", "Laravel"), ("Linter", "Linter"), ("Optimizer", "Optimizer"),
    ("Parser", "Parser"), ("PHPStan", "PHPStan"), ("Redos", "Redos"),
    ("Symfony", "Symfony"), ("Toolkit", "Toolkit"), ("Transpiler", "Transpiler"),
]

SIZE = 78            # base type size, px
CANVAS_W = 1000
CENTER_X = 500
MARK_HALF = (168 + 27 / 2) * 0.52   # bracket centreline + stroke/2, scaled
MARK_W = 2 * MARK_HALF
GAP = 42             # mark right edge -> brand left stem
MAX_LOCKUP = 940     # optical margins: 30px each side
MIN_SIZE = 44

STYLES = {
    "light": ".paper{fill:#F8F7F2}.ink-f{fill:#182B45}.ink-s{stroke:#182B45;fill:none}.amber-f{fill:#A84A08}",
    "dark": ".paper{fill:#0F1B2E}.ink-f{fill:#EDF1F7}.ink-s{stroke:#EDF1F7;fill:none}.amber-f{fill:#E97625}",
}


def build(pkg_dir, display, black, bold, mark_markup, root_dir):
    upm = black["head"].unitsPerEm
    _, cmap_bl, hmtx_bl, _ = metrics(black)
    space_u = hmtx_bl[cmap_bl[ord(" ")][0] if isinstance(cmap_bl[ord(" ")], tuple) else cmap_bl[ord(" ")]][0]

    def layout(size):
        php_d, _, php_pen = word(black, "PHP", size)
        regex_d, _, regex_pen = word(bold, "Regex", size)
        comp_d, comp_bb, _ = word(bold, display, size)
        g_regex = (php_pen + T_UNITS) * size / upm                # Regex right after PHP
        g_comp = (php_pen + T_UNITS + regex_pen + space_u + T_UNITS) * size / upm
        text_ink = g_comp + comp_bb[2]                            # to component ink end
        return php_d, regex_d, comp_d, g_regex, g_comp, text_ink

    php_d, regex_d, comp_d, g_regex, g_comp, text_ink = layout(SIZE)
    size = SIZE
    if MARK_W + GAP + text_ink > MAX_LOCKUP:
        size = max(MIN_SIZE, SIZE * (MAX_LOCKUP - MARK_W - GAP) / text_ink)
        php_d, regex_d, comp_d, g_regex, g_comp, text_ink = layout(size)

    lockup = MARK_W + GAP + text_ink
    mark_x = (CANVAS_W - lockup) / 2 + MARK_HALF
    text_x = mark_x + MARK_HALF + GAP
    cap = black["OS/2"].sCapHeight * size / upm
    baseline = round(100 + cap / 2, 6)

    word_group = (
        f'<g transform="translate({fmt(text_x)} {fmt(baseline)})">'
        f'<path d="{php_d}" class="ink-f"/>'
        f'<path transform="translate({fmt(g_regex)} 0)" d="{regex_d}" class="ink-f"/>'
        f'<path transform="translate({fmt(g_comp)} 0)" d="{comp_d}" class="amber-f"/></g>'
    )
    mark_line = re.sub(r"translate\([\d.]+ 100\)", f"translate({fmt(mark_x)} 100)", mark_markup, count=1)

    out = []
    for theme in ("light", "dark"):
        svg = (
            f'<svg viewBox="0 0 1000 200" width="1000" height="200" xmlns="http://www.w3.org/2000/svg" role="img">\n'
            f'<title>PHPRegex {display}</title>\n'
            f'<style>{STYLES[theme]}</style>\n'
            f'<rect x="10" y="10" width="980" height="180" class="paper" stroke="#64748B" stroke-width="2.5"/>\n'
            f'{mark_line}\n{word_group}\n</svg>\n'
        )
        art_dir = os.path.join(root_dir, "src", pkg_dir, "art")
        os.makedirs(art_dir, exist_ok=True)
        name = "banner.svg" if theme == "light" else "banner-dark.svg"
        open(os.path.join(art_dir, name), "w").write(svg)
    return {"pkg": pkg_dir, "display": display, "size": round(size, 4),
            "lockup": round(lockup, 2), "left": round(mark_x - MARK_HALF, 2),
            "right": round(mark_x + MARK_HALF + GAP + text_ink, 2)}


def main():
    ap = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    ap.add_argument("--black", required=True, help="InterDisplay-Black.ttf")
    ap.add_argument("--bold", required=True, help="InterDisplay-Bold.ttf")
    ap.add_argument("--root", default=os.path.dirname(os.path.dirname(os.path.abspath(__file__))),
                    help="repository root (default: parent of art/)")
    args = ap.parse_args()

    black, bold = TTFont(args.black), TTFont(args.bold)
    root_banner = open(os.path.join(args.root, "art", "banner.svg")).read()
    mark_markup = re.search(r'<g transform="translate\([\d.]+ 100\) scale\(0\.52\)">.*</g></g>', root_banner).group(0)

    rows = [build(pkg, display, black, bold, mark_markup, args.root) for pkg, display in PACKAGES]
    for r in rows:
        print(f"{r['pkg']:<16} size={r['size']:<9} lockup={r['lockup']:<8} ink {r['left']}..{r['right']} (centre {(r['left'] + r['right']) / 2:.1f})")
    print(f"\nwrote {len(rows) * 2} SVGs under src/*/art/")


if __name__ == "__main__":
    main()
