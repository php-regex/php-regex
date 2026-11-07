#!/usr/bin/env python3
"""Regenerate the PHPRegex wordmark paths for the banner and social card.

Wordmark: "PHP" (Inter Display Black, ink) + "Regex" (Inter Display Bold,
amber) set as ONE word — the PHP/Regex junction carries the same tracking
as every glyph gap, no word space. Tracking t = -13 font units
(-0.6348% of em), measured from the original wordmark.

Pipeline: fontTools SVGPathPen + TransformPen over real hmtx advances;
the social card's two lines keep bit-identical left stems by compensating
each line's first-glyph side bearing; the banner lockup stays centred on
the card (optical centre x=500).

Usage:
    python3 art/wordmark.py --black InterDisplay-Black.ttf --bold InterDisplay-Bold.ttf

Fonts: Inter Display static TTFs (Black 900 / Bold 700) from the Inter
release zip — https://github.com/rsms/inter/releases (extras/ttf/).

The script only READS art/*.svg; it writes wordmark.json and two scratch
renders to --out (default: a fresh private temporary directory). Paste the emitted group
strings into the SVGs by hand — never hand-draw the paths.
"""
import argparse
from contextlib import contextmanager
import json
import os
import re
import secrets
import stat
import tempfile

from fontTools.misc.transform import Transform
from fontTools.pens.boundsPen import BoundsPen
from fontTools.pens.svgPathPen import SVGPathPen
from fontTools.pens.transformPen import TransformPen
from fontTools.ttLib import TTFont

T_UNITS = -13    # tracking per glyph gap, font units (measured from the original wordmark)
CENTER_X = 500.0021875  # the shipped lockup's centre — 500 + 0.0022, heritage of the
                        # first pass's shift being rounded to one decimal; keep to
                        # regenerate byte-identical transforms
SIZE_B = 78      # banner font size, px
SIZE_S = 196     # social font size, px
MARK_X, MARK_S = 238.5, 0.52          # mark group base translate/scale
MARK_HALF_INK = (168 + 27 / 2) * MARK_S  # bracket centreline + stroke/2, scaled
WORD_X = 370.6                        # wordmark group base translate
L1_Y, L2_Y = 290, 492                 # social baselines


def metrics(font):
    return font["head"].unitsPerEm, font.getBestCmap(), font["hmtx"], font.getGlyphSet()


def fmt(v):
    """File idiom: shortest round-trip form (139.4, 156.2666015625), no trailing .0."""
    s = repr(float(v))
    if "e" in s or "E" in s:
        s = f"{v:.14f}".rstrip("0").rstrip(".")
    if s.endswith(".0"):
        s = s[:-2]
    return s if s else "0"


def word(font, text, size, t_units=T_UNITS):
    """Lay out a word at `size` px. Returns (path d, bounds, pen after)."""
    upm, cmap, hmtx, gs = metrics(font)
    s = size / upm
    svg = SVGPathPen(gs)
    bp = BoundsPen(gs)
    pen = 0
    for i, ch in enumerate(text):
        if i:
            pen += t_units
        gname = cmap[ord(ch)]
        tr = Transform(s, 0, 0, -s, pen * s, 0)
        gs[gname].draw(TransformPen(svg, tr))
        gs[gname].draw(TransformPen(bp, tr))
        pen += hmtx[gname][0]
    return svg.getCommands(), bp.bounds, pen


def lsb_px(font, ch, size):
    upm, cmap, hmtx, gs = metrics(font)
    bp = BoundsPen(gs)
    gs[cmap[ord(ch)]].draw(bp)
    return bp.bounds[0] * size / upm


@contextmanager
def output_directory(path):
    """Pin a directory reached only through trusted, non-symlink ancestors."""
    if path is None:
        path = tempfile.mkdtemp(prefix="phpregex-art-")
    path = os.path.abspath(path)
    fd = os.open("/", os.O_RDONLY | os.O_DIRECTORY)
    try:
        parts = path.split(os.sep)[1:]
        for part in parts:
            parent = os.fstat(fd)
            # Sticky directories such as /tmp protect entries owned by us/root.
            if (parent.st_uid not in (0, os.geteuid()) or
                    (parent.st_mode & 0o022 and not parent.st_mode & stat.S_ISVTX)):
                raise ValueError("untrusted output directory ancestor")
            try:
                os.mkdir(part, mode=0o700, dir_fd=fd)
            except FileExistsError:
                pass
            child = os.open(part, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW, dir_fd=fd)
            os.close(fd)
            fd = child
            info = os.fstat(fd)
            if info.st_uid not in (0, os.geteuid()):
                raise ValueError("untrusted output directory owner")
        info = os.fstat(fd)
        if info.st_uid != os.geteuid() or info.st_mode & 0o022:
            raise ValueError("output directory must be owned by you and not writable by others")
        yield path, fd
    finally:
        os.close(fd)


@contextmanager
def output_file(directory_fd, name):
    """Replace an entry without following existing symlinks or hard links."""
    temporary = ".wordmark-" + secrets.token_hex(16)
    fd = os.open(temporary, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600,
                 dir_fd=directory_fd)
    try:
        with os.fdopen(fd, "w") as fh:
            yield fh
        os.replace(temporary, name, src_dir_fd=directory_fd, dst_dir_fd=directory_fd)
    finally:
        try:
            os.unlink(temporary, dir_fd=directory_fd)
        except FileNotFoundError:
            pass


def main():
    ap = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    ap.add_argument("--black", required=True, help="InterDisplay-Black.ttf")
    ap.add_argument("--bold", required=True, help="InterDisplay-Bold.ttf")
    ap.add_argument("--out", help="trusted scratch dir (default: fresh private temporary directory)")
    ap.add_argument("--art", default=os.path.join(os.path.dirname(os.path.abspath(__file__))),
                    help="art/ directory to read the current SVGs from")
    args = ap.parse_args()
    with output_directory(args.out) as (args.out, directory_fd):
        render(args, directory_fd)


def render(args, directory_fd):
    black, bold = TTFont(args.black), TTFont(args.bold)
    social = open(os.path.join(args.art, "social.svg")).read()

    # -- banner: one word, junction gap = tracking; lockup centred on CENTER_X
    php_d, php_bb, php_pen = word(black, "PHP", SIZE_B)
    regex_d, regex_bb, _ = word(bold, "Regex", SIZE_B)
    upm = black["head"].unitsPerEm
    G = (php_pen + T_UNITS) * SIZE_B / upm          # PHP advance + junction tracking
    regex_ink_end = regex_bb[2]                      # Regex xMax relative to its origin
    D = (2 * CENTER_X - (MARK_X - MARK_HALF_INK)
         - (WORD_X + G + regex_ink_end)) / 2
    X = WORD_X + D
    mark_x = MARK_X + D
    left = MARK_X - MARK_HALF_INK + D
    right = X + G + regex_ink_end
    banner_group = (
        f'<g transform="translate({fmt(X)} 128.4)">'
        f'<path d="{php_d}" class="ink-f"/>'
        f'<path transform="translate({fmt(G)} 0)" d="{regex_d}" class="amber-f"/></g>'
    )

    # -- social: two lines, left stems aligned on the card's original margin
    l1_x = float(re.search(r'translate\(([\d.]+) 290\)', social).group(1))
    stem_x = l1_x + lsb_px(black, "R", SIZE_S)      # the card's stem x, unchanged
    x1 = stem_x - lsb_px(black, "P", SIZE_S)        # line 1: PHP (P and R share lsb)
    x2 = stem_x - lsb_px(bold, "R", SIZE_S)         # line 2: Regex Bold
    php_s_d, php_s_bb, _ = word(black, "PHP", SIZE_S)
    regex_s_d, regex_s_bb, _ = word(bold, "Regex", SIZE_S)
    social_line1 = f'<g transform="translate({fmt(x1)} {L1_Y})"><path d="{php_s_d}" class="ink-f"/></g>'
    social_line2 = f'<g transform="translate({fmt(x2)} {L2_Y})"><path d="{regex_s_d}" class="amber-f"/></g>'

    # -- scratch renders (square corners, current card shapes)
    style = '.paper{fill:#F8F7F2}.ink-f{fill:#182B45}.ink-s{stroke:#182B45;fill:none}.amber-f{fill:#A84A08}'
    with output_file(directory_fd, "scratch-banner.svg") as fh:
        fh.write(
            f'<svg viewBox="0 0 1000 200" width="1000" height="200" xmlns="http://www.w3.org/2000/svg" role="img">\n'
            f'<title>PHPRegex</title>\n<style>{style}</style>\n'
            f'<rect x="10" y="10" width="980" height="180" class="paper" stroke="#64748B" stroke-width="2.5"/>\n'
            f'<g transform="translate({fmt(mark_x)} 100) scale({MARK_S})"><!-- mark --></g>\n'
            f'{banner_group}\n</svg>\n'
        )
    with output_file(directory_fd, "scratch-social.svg") as fh:
        fh.write(
            f'<svg viewBox="0 0 1280 640" width="1280" height="640" xmlns="http://www.w3.org/2000/svg" role="img">\n'
            f'<title>PHPRegex</title>\n<style>{style}</style>\n'
            f'<rect width="1280" height="640" class="paper"/>\n'
            f'{social_line1}\n{social_line2}\n'
            f'<g transform="translate(951.4 320) scale(1.00)"><!-- mark --></g>\n</svg>\n'
        )

    data = {
        "trackingUnits": T_UNITS,
        "trackingPctEm": round(T_UNITS / upm * 100, 5),
        "bannerMarkX": fmt(mark_x),
        "bannerX": fmt(X),
        "bannerG": fmt(G),
        "bannerInk": [left, right],
        "bannerCenter": (left + right) / 2,
        "socialStemX": stem_x,
        "socialX1": fmt(x1),
        "socialX2": fmt(x2),
        "bannerGroup": banner_group,
        "socialLine1": social_line1,
        "socialLine2": social_line2,
    }
    with output_file(directory_fd, "wordmark.json") as fh:
        json.dump(data, fh, indent=2)

    print(f"tracking: {T_UNITS}u = {T_UNITS/upm*100:.4f}% em")
    print(f"banner: markX={fmt(mark_x)} X={fmt(X)} G={fmt(G)}")
    print(f"banner lockup ink: {left:.4f}..{right:.4f}  centre={(left+right)/2:.4f} (target {CENTER_X})")
    print(f"social: stemX={stem_x} x1={fmt(x1)} x2={fmt(x2)}")
    print(f"line1 right {x1 + php_s_bb[2]:.3f}  line2 right {x2 + regex_s_bb[2]:.3f} (mark ink starts ~769.9)")
    print(f"wrote {args.out}/wordmark.json + scratch-banner.svg + scratch-social.svg")


if __name__ == "__main__":
    main()
