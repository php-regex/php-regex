#!/usr/bin/env python3
"""Generate every PHPRegex brand asset from one source of truth.

The mark: a three-node tree — the AST — between the two slashes that
delimit a PHP pattern, /…/. Everything below is drawn from the geometry in
MARK and the tokens in PALETTE: the bare marks, the horizontal logos, the
favicon and the app icons, the GitHub organization icons, the README
banner, the per-package banners, and the social cards. Text is never set
as text: every glyph is a path shaped by HarfBuzz from the real fonts, so
every file renders the same in every viewer.

Usage:
    pip install fonttools uharfbuzz brotli
    python3 art/brand.py --inter-display DIR --mono JetBrainsMono.woff2

DIR holds the static Inter Display OTFs (InterDisplay-Regular.otf,
-Medium, -SemiBold) from https://github.com/rsms/inter/releases; --mono is
the JetBrains Mono variable font (the one the site serves,
docs/assets/fonts/jetbrains-mono-var.woff2, works). With rsvg-convert and
ImageMagick on the PATH the PNG and ICO renditions are written too.
"""
import argparse
import os
import shutil
import subprocess
import sys

import uharfbuzz as hb
from fontTools.pens.svgPathPen import SVGPathPen
from fontTools.pens.transformPen import TransformPen
from fontTools.ttLib import TTFont

# ---------------------------------------------------------------------------
# Tokens. Contrast, measured (WCAG 2.2): ink on paper 20.13, ink-soft on
# paper 4.83 (gray-500, the "PHP" of the wordmark), accent text on paper
# 5.18; on the dark ground: ink 19.27, ink-soft 7.93, accent 8.90. The node
# gradients are graphics (3:1): light #EA580C..#C2410C is 3.56..5.18 on
# white, dark #FDBA74..#F97316 is 12.0..7.0 on #030712.
# ---------------------------------------------------------------------------

PALETTE = {
    'light': {
        'ground': '#FFFFFF', 'ink': '#030712', 'soft': '#6B7280', 'mute': '#4B5563',
        'accent': '#C2410C', 'node': ('#EA580C', '#C2410C'), 'ring': 'rgba(3,7,18,0.10)',
        'halo': 'rgba(234,88,12,0.10)', 'halo2': 'rgba(59,130,246,0.05)',
    },
    'dark': {
        'ground': '#030712', 'ink': '#F9FAFB', 'soft': '#9CA3AF', 'mute': '#9CA3AF',
        'accent': '#FB923C', 'node': ('#FDBA74', '#F97316'), 'ring': 'rgba(255,255,255,0.10)',
        'halo': 'rgba(249,115,22,0.16)', 'halo2': 'rgba(59,130,246,0.10)',
    },
}

# The dark tile of the icons, top to bottom.
TILE = ('#1F2937', '#030712')

# The code panel of the social cards (the site's code panel).
PANEL = {
    'bg': '#0B1020', 'ring': 'rgba(255,255,255,0.08)', 'text': '#E5E7EB', 'dim': '#7D8799',
    'tree': '#475569', 'key': '#F472B6', 'str': '#86EFAC', 'num': '#FDBA74', 'call': '#7DD3FC',
    'type': '#FDE68A', 'group': '#C4B5FD', 'quant': '#5EEAD4', 'set': '#38BDF8',
    'ok': '#86EFAC', 'bad': '#FCA5A5', 'warn': '#FDBA74', 'info': '#7DD3FC',
}

# ---------------------------------------------------------------------------
# The mark, in a box centred on (0, 0). Two weights: regular for 48px and
# up, bold for the 16-32px icons, where a 24-unit edge would fall under a
# pixel. The slashes lean 14 degrees, the angle of a slash in Inter.
# ---------------------------------------------------------------------------

MARK = {
    'slash_l': ((-176, 126), (-112, -126)),
    'slash_r': ((112, 126), (176, -126)),
    'root': (0, -64), 'kids': ((-52, 72), (52, 72)),
    'regular': {'slash': 34, 'edge': 24, 'root': 40, 'kid': 32},
    'bold': {'slash': 44, 'edge': 30, 'root': 44, 'kid': 36},
}
# Extent of the mark's ink, regular weight: x +-193, y +-143.
MARK_HALF_W = 193
MARK_HALF_H = 143


def node_gradient(gid, theme):
    a, b = PALETTE[theme]['node']
    return (f'<linearGradient id="{gid}" x1="0" y1="0" x2="1" y2="1">'
            f'<stop offset="0" stop-color="{a}"/><stop offset="1" stop-color="{b}"/></linearGradient>')


def mark(gid, ink, weight='regular'):
    w = MARK[weight]
    (lx1, ly1), (lx2, ly2) = MARK['slash_l']
    (rx1, ry1), (rx2, ry2) = MARK['slash_r']
    rx, ry = MARK['root']
    (k1x, k1y), (k2x, k2y) = MARK['kids']
    return (
        f'<g fill="none" stroke="{ink}" stroke-linecap="round">'
        f'<path d="M{lx1} {ly1}L{lx2} {ly2}M{rx1} {ry1}L{rx2} {ry2}" stroke-width="{w["slash"]}"/>'
        f'<path d="M{rx} {ry}L{k1x} {k1y}M{rx} {ry}L{k2x} {k2y}" stroke-width="{w["edge"]}"/></g>'
        f'<g fill="url(#{gid})"><circle cx="{rx}" cy="{ry}" r="{w["root"]}"/>'
        f'<circle cx="{k1x}" cy="{k1y}" r="{w["kid"]}"/><circle cx="{k2x}" cy="{k2y}" r="{w["kid"]}"/></g>'
    )


# ---------------------------------------------------------------------------
# Type: HarfBuzz shapes, fontTools draws.
# ---------------------------------------------------------------------------

class Fonts:
    def __init__(self, inter_dir, mono_path):
        self.inter_dir = inter_dir
        self.mono_path = mono_path
        self.cache = {}

    def load(self, key):
        if key in self.cache:
            return self.cache[key]
        if key.startswith('mono'):
            from fontTools.varLib import instancer
            weight = int(key.split(':')[1])
            tt = instancer.instantiateVariableFont(TTFont(self.mono_path), {'wght': weight})
            tmp = f'/tmp/brand-mono-{weight}.ttf'
            tt.flavor = None
            tt.save(tmp)
            path = tmp
        else:
            path = os.path.join(self.inter_dir, f'InterDisplay-{key}.otf')
        tt = TTFont(path)
        face = hb.Face(hb.Blob.from_file_path(path))
        self.cache[key] = (tt, hb.Font(face), face.upem)
        return self.cache[key]

    def path(self, text, key, size, x=0.0, y=0.0, tracking=0.0):
        """SVG path data for `text`, baseline at y; returns (d, advance)."""
        tt, font, upem = self.load(key)
        buf = hb.Buffer()
        buf.add_str(text)
        buf.guess_segment_properties()
        features = {'kern': True, 'liga': key.startswith('mono') is False, 'calt': False}
        hb.shape(font, buf, features)
        glyphs, order, scale = tt.getGlyphSet(), tt.getGlyphOrder(), size / upem
        pen_x, parts = 0.0, []
        infos, positions = buf.glyph_infos, buf.glyph_positions
        for i, (info, pos) in enumerate(zip(infos, positions)):
            pen = SVGPathPen(glyphs, ntos=lambda v: ('%.2f' % v).rstrip('0').rstrip('.'))
            glyphs[order[info.codepoint]].draw(TransformPen(pen, (
                scale, 0, 0, -scale, x + (pen_x + pos.x_offset) * scale, y - pos.y_offset * scale)))
            if pen.getCommands():
                parts.append(pen.getCommands())
            pen_x += pos.x_advance + (tracking * upem if i < len(infos) - 1 else 0)
        return ' '.join(parts), pen_x * scale

    def cap(self, key, size):
        tt, _, upem = self.load(key)
        return tt['OS/2'].sCapHeight * size / upem


# ---------------------------------------------------------------------------
# Pieces.
# ---------------------------------------------------------------------------

def svg_open(w, h, title, extra_defs=''):
    return (f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {w} {h}" width="{w}" height="{h}" '
            f'role="img" aria-label="{title}"><title>{title}</title><defs>{extra_defs}</defs>')


def wordmark(fonts, theme, x, baseline, size, track=-0.02):
    """"PHP" in the soft ink, "Regex" in the ink: the subject of the name is
    the regex. One weight, SemiBold; returns (markup, width)."""
    p = PALETTE[theme]
    d1, w1 = fonts.path('PHP', 'SemiBold', size, x, baseline, track)
    d2, w2 = fonts.path('Regex', 'SemiBold', size, x + w1 + track * size, baseline, track)
    return f'<path d="{d1}" fill="{p["soft"]}"/><path d="{d2}" fill="{p["ink"]}"/>', w1 + track * size + w2


def lockup(fonts, theme, gid, x, mid_y, size):
    """Mark + wordmark, the mark 1.35 cap heights tall, centred on mid_y.
    Returns (markup, width)."""
    p = PALETTE[theme]
    cap = fonts.cap('SemiBold', size)
    scale = cap * 1.35 / (2 * MARK_HALF_H)
    mark_w = 2 * MARK_HALF_W * scale
    out = (f'<g transform="translate({x + mark_w / 2:.2f} {mid_y:.2f}) scale({scale:.4f})">'
           f'{mark(gid, p["ink"])}</g>')
    gap = size * 0.40
    wm, ww = wordmark(fonts, theme, x + mark_w + gap, mid_y + cap / 2, size)
    return out + wm, mark_w + gap + ww


def halo(theme, gid, cx, cy, rx, ry):
    p = PALETTE[theme]
    return (f'<radialGradient id="{gid}" cx="{cx}" cy="{cy}" r="1" gradientUnits="userSpaceOnUse" '
            f'gradientTransform="translate({cx} {cy}) scale({rx} {ry}) translate({-cx} {-cy})">'
            f'<stop offset="0" stop-color="{p["halo"]}"/><stop offset="1" stop-color="{p["halo"]}" stop-opacity="0"/>'
            f'</radialGradient>')


def tile_defs(gid):
    return (f'<linearGradient id="{gid}" x1="0" y1="0" x2="0" y2="1">'
            f'<stop offset="0" stop-color="{TILE[0]}"/><stop offset="1" stop-color="{TILE[1]}"/></linearGradient>')


# ---------------------------------------------------------------------------
# Assets.
# ---------------------------------------------------------------------------

def asset_mark(theme):
    p = PALETTE[theme]
    w, h = 2 * MARK_HALF_W + 16, 2 * MARK_HALF_H + 16
    return (svg_open(w, h, 'PHPRegex', node_gradient('n', theme))
            + f'<g transform="translate({w / 2} {h / 2})">{mark("n", p["ink"])}</g></svg>')


def asset_logo(fonts, theme):
    size = 96
    markup, width = lockup(fonts, theme, 'n', 8, 70, size)
    w = int(width + 16)
    return (svg_open(w, 140, 'PHPRegex', node_gradient('n', theme)) + markup + '</svg>')


def asset_icon(kind):
    """kind: favicon (rounded tile, bold mark), touch (full-bleed square),
    maskable (full-bleed, mark inside the 80% safe circle), org-light and
    org-dark (full-bleed squares, mark inside the inscribed circle)."""
    if kind == 'org-light':
        defs = node_gradient('n', 'light')
        ground = '<rect width="512" height="512" fill="#FFFFFF"/>'
        ink, scale, weight = PALETTE['light']['ink'], 0.80, 'regular'
    else:
        defs = node_gradient('n', 'dark') + tile_defs('t')
        rx = ' rx="116"' if kind == 'favicon' else ''
        ground = f'<rect width="512" height="512"{rx} fill="url(#t)"/>'
        if kind == 'favicon':
            ground += '<rect x="1" y="1" width="510" height="510" rx="115" fill="none" stroke="#FFFFFF" stroke-opacity=".10" stroke-width="2"/>'
        ink = PALETTE['dark']['ink']
        scale, weight = {'favicon': (0.92, 'bold'), 'touch': (0.74, 'regular'),
                         'maskable': (0.62, 'regular'), 'org-dark': (0.80, 'regular')}[kind]
    return (svg_open(512, 512, 'PHPRegex', defs) + ground
            + f'<g transform="translate(256 256) scale({scale})">{mark("n", ink, weight)}</g></svg>')


def card_ground(theme, w, h, inset=0, radius=0):
    p = PALETTE[theme]
    r = f' rx="{radius}"' if radius else ''
    out = f'<rect x="{inset}" y="{inset}" width="{w - 2 * inset}" height="{h - 2 * inset}"{r} fill="{p["ground"]}"/>'
    out += f'<rect x="{inset}" y="{inset}" width="{w - 2 * inset}" height="{h - 2 * inset}"{r} fill="url(#halo)"/>'
    if inset:
        out += (f'<rect x="{inset + .75}" y="{inset + .75}" width="{w - 2 * inset - 1.5}" height="{h - 2 * inset - 1.5}"{r} '
                f'fill="none" stroke="{p["ring"]}" stroke-width="1.5"/>')
    return out


def asset_banner(fonts, theme, package=None):
    """README header, 1000x200: a rounded card that reads on GitHub's white
    and dark pages alike, the halo of the accent over its centre."""
    p = PALETTE[theme]
    w, h = 1000, 200
    defs = node_gradient('n', theme) + halo(theme, 'halo', 500, 0, 520, 210)
    out = svg_open(w, h, 'PHPRegex' + (' ' + package if package else ''), defs) + card_ground(theme, w, h, 8, 24)
    size = 64
    if package is None:
        markup, width = lockup(fonts, theme, 'n', 0, 0, size)
        tag = 'Static analysis, linter & logic solver for PHP regular expressions'
        td, tw = fonts.path(tag, 'Regular', 19, 0, 0, 0)
        x = (w - width) / 2
        markup, _ = lockup(fonts, theme, 'n', x, 86, size)
        td, _ = fonts.path(tag, 'Regular', 19, (w - tw) / 2, 150, 0)
        return out + markup + f'<path d="{td}" fill="{p["mute"]}"/></svg>'
    # A package: the lockup, a hairline, the package's name in the accent.
    while True:
        _, width = lockup(fonts, theme, 'n', 0, 0, size)
        pd, pw = fonts.path(package, 'SemiBold', size, 0, 0, -0.02)
        gap = size * 0.55
        total = width + 2 * gap + pw
        if total <= 900 or size <= 40:
            break
        size -= 2
    x = (w - total) / 2
    mid = 100
    markup, width = lockup(fonts, theme, 'n', x, mid, size)
    cap = fonts.cap('SemiBold', size)
    rule_x = x + width + gap
    pd, _ = fonts.path(package, 'SemiBold', size, rule_x + gap, mid + cap / 2, -0.02)
    return (out + markup
            + f'<rect x="{rule_x - 1:.2f}" y="{mid - cap * 0.75:.2f}" width="2" height="{cap * 1.5:.2f}" rx="1" fill="{p["ring"]}"/>'
            + f'<path d="{pd}" fill="{p["accent"]}"/></svg>')


def code_panel(fonts, x, y, w, h, title, meta, rows):
    """A dark code panel: a title strip, then rows in JetBrains Mono. A row
    is (depth, runs): depth None for a plain line, 0.. for a tree node,
    whose connectors are drawn as hairlines (no box-drawing glyph needed);
    a run is (token, text)."""
    out = (f'<rect x="{x}" y="{y}" width="{w}" height="{h}" rx="20" fill="{PANEL["bg"]}"/>'
           f'<rect x="{x + .75}" y="{y + .75}" width="{w - 1.5}" height="{h - 1.5}" rx="19.5" fill="none" stroke="{PANEL["ring"]}" stroke-width="1.5"/>'
           f'<rect x="{x}" y="{y + 56}" width="{w}" height="1.5" fill="{PANEL["ring"]}"/>')
    td, _ = fonts.path(title.upper(), 'mono:500', 15, x + 28, y + 34, 0.12)
    out += f'<path d="{td}" fill="{PANEL["dim"]}"/>'
    md, mw = fonts.path(meta, 'mono:400', 15, 0, 0)
    md, _ = fonts.path(meta, 'mono:400', 15, x + w - 28 - mw, y + 34)
    out += f'<path d="{md}" fill="{PANEL["dim"]}"/>'
    line_h, size, unit = 34, 20, 26
    mids = []
    for i, (depth, row) in enumerate(rows):
        by = y + 56 + 44 + i * line_h + (12 if depth is not None and rows[0][0] is None else 0)
        mid = by - 7
        mids.append(mid)
        cx = x + 28 + (depth or 0) * unit
        if depth:
            # Up to the previous node at this depth or above, then across.
            j = i - 1
            while j >= 0 and (rows[j][0] is None or rows[j][0] > depth):
                j -= 1
            vx = x + 28 + (depth - 1) * unit + 7
            top = mids[j] + 9 if j >= 0 and rows[j][0] == depth - 1 else mids[j] if j >= 0 else mid
            out += (f'<path d="M{vx} {top:.1f}V{mid:.1f}H{cx - 7}" fill="none" stroke="{PANEL["tree"]}" '
                    f'stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>')
        for token, text in row:
            d, adv = fonts.path(text, 'mono:500' if token in ('bad', 'ok', 'warn', 'info') else 'mono:400', size, cx, by)
            if d:
                out += f'<path d="{d}" fill="{PANEL[token]}"/>'
            cx += adv
    return out


PATTERN_ROW = [('dim', '/'), ('key', '^'), ('group', '(?<'), ('type', 'year'), ('group', '>'), ('num', '\\d'),
               ('quant', '{4}'), ('group', ')'), ('text', '-'), ('group', '(?<'), ('type', 'month'), ('group', '>'),
               ('num', '\\d'), ('quant', '{2}'), ('group', ')'), ('key', '$'), ('dim', '/'), ('key', 'u')]

TREE_ROWS = [
    (None, PATTERN_ROW),
    (0, [('text', 'sequence')]),
    (1, [('text', 'anchor '), ('key', '^')]),
    (1, [('text', 'group '), ('type', 'year')]),
    (2, [('text', 'quantifier '), ('quant', '{4}'), ('text', ' '), ('num', '\\d')]),
    (1, [('text', 'literal '), ('str', '"-"')]),
    (1, [('text', 'group '), ('type', 'month')]),
    (2, [('text', 'quantifier '), ('quant', '{2}'), ('text', ' '), ('num', '\\d')]),
    (1, [('text', 'anchor '), ('key', '$')]),
]

FINDING_ROWS = [
    (None, [('bad', 'FAIL '), ('call', 'src/Http/Router.php'), ('dim', ':42')]),
    (None, [('dim', '     '), ('dim', '/'), ('key', '^'), ('group', '('), ('text', 'a'), ('quant', '+'), ('group', ')'),
     ('quant', '+'), ('key', '$'), ('dim', '/'), ('dim', '  exponential')]),
    (None, []),
    (None, [('warn', 'WARN '), ('call', 'lib/Slug.php'), ('dim', ':14')]),
    (None, [('dim', '     '), ('dim', '/'), ('num', '\\s'), ('quant', '+'), ('key', '$'), ('dim', '/'), ('dim', '  quadratic search')]),
    (None, []),
    (None, [('warn', 'WARN '), ('call', 'src/Mailer.php'), ('dim', ':88')]),
    (None, [('dim', '     '), ('dim', '/'), ('set', '['), ('text', 'a-z'), ('set', ']'), ('quant', '+'), ('set', '['),
     ('text', 'a-z0-9'), ('set', ']'), ('quant', '*'), ('dim', '/'), ('dim', '  concatenation')]),
]


def asset_social(fonts, theme, kind='site'):
    """1280x640, the code-panel card (the findings pages use it). Key content keeps 80px
    from every edge (GitHub and the networks recrop to 16:9 and 1.91:1)."""
    p = PALETTE[theme]
    w, h = 1280, 640
    defs = node_gradient('n', theme) + halo(theme, 'halo', 300, 0, 900, 520)
    out = svg_open(w, h, 'PHPRegex', defs) + card_ground(theme, w, h)
    markup, _ = lockup(fonts, theme, 'n', 80, 104, 40)
    out += markup
    if kind == 'site':
        lines = ['See a PHP regex', 'as a tree.']
        sub = ['Static analysis, linter and logic solver', 'for PHP regular expressions.']
        panel = code_panel(fonts, 640, 150, 560, 430, 'AST', 'PHP 8.4 · PCRE2 10.44', TREE_ROWS)
    else:
        lines = ['What the linter', 'finds in real PHP.']
        sub = ['Open-source projects, linted for their own', 'PHP version. Every finding replays.']
        panel = code_panel(fonts, 640, 150, 560, 430, 'Findings', 'regex lint', FINDING_ROWS)
    for i, line in enumerate(lines):
        d, _ = fonts.path(line, 'SemiBold', 68, 80, 268 + i * 78, -0.03)
        out += f'<path d="{d}" fill="{p["ink"]}"/>'
    for i, line in enumerate(sub):
        d, _ = fonts.path(line, 'Regular', 25, 80, 430 + i * 38, -0.005)
        out += f'<path d="{d}" fill="{p["mute"]}"/>'
    url, _ = fonts.path('php-regex.com' + ('/findings' if kind != 'site' else ''), 'mono:500', 18, 80, 558, 0.02)
    out += f'<path d="{url}" fill="{p["accent"]}"/>'
    return out + panel + '</svg>'


# ---------------------------------------------------------------------------
# The social card: what PHPRegex proves. Three verdicts no other PHP tool
# gives — a ReDoS proof with its attack, an equivalence of two patterns, a
# rewrite proven equal — beside the claim and the places it runs.
# ---------------------------------------------------------------------------

SYMBOLS = '→≡'
INTEGRATIONS = ['PHPStan', 'Psalm', 'Rector', 'PHPUnit', 'Infection', 'Symfony', 'Laravel', 'LSP', 'MCP']


def mono_runs(fonts, items, x, y, size):
    """Mono runs of (token, text); a symbol JetBrains Mono's latin subset
    lacks is set in Inter, centred in a mono cell. Returns (markup, width)."""
    import re
    out, cx = '', x
    _, cell = fonts.path('0', 'mono:400', size)
    for token, text in items:
        color = PANEL.get(token, token)
        for part in re.split('([' + SYMBOLS + '])', text):
            if not part:
                continue
            if part in SYMBOLS:
                _, gw = fonts.path(part, 'Medium', size)
                d, _ = fonts.path(part, 'Medium', size, cx + (cell - gw) / 2, y)
                cx += cell
            else:
                d, w = fonts.path(part, 'mono:400', size, cx, y)
                cx += w
            if d:
                out += f'<path d="{d}" fill="{color}"/>'
    return out, cx - x


def verdict_chip(fonts, text, right, top, color, size=13):
    """A pill, its right edge at `right`: the tint and hairline of its colour."""
    _, tw = fonts.path(text, 'mono:500', size)
    h, pad = size + 14, 11
    w = tw + 2 * pad
    x = right - w
    d, _ = fonts.path(text, 'mono:500', size, x + pad, top + h / 2 + size * 0.36)
    return (f'<rect x="{x:.1f}" y="{top}" width="{w:.1f}" height="{h}" rx="{h / 2}" fill="{color}" fill-opacity=".12"/>'
            f'<rect x="{x + .5:.1f}" y="{top + .5}" width="{w - 1:.1f}" height="{h - 1}" rx="{(h - 1) / 2}" fill="none" stroke="{color}" stroke-opacity=".35"/>'
            f'<path d="{d}" fill="{color}"/>')


PROOFS = [
    ('ReDoS', ('exponential · proven', 'bad'),
     [('dim', 'analyze  '), ('dim', '/'), ('key', '^'), ('group', '('), ('text', 'a'), ('quant', '+'), ('group', ')'),
      ('quant', '+'), ('key', '$'), ('dim', '/')],
     [('dim', 'attack  '), ('str', "'aaaaaaaaaaaaaaaaaaaaaaab'")]),
    ('Logic', ('equivalent · proven', 'ok'),
     [('dim', 'equivalent  '), ('dim', '/'), ('set', '['), ('text', '0-9'), ('set', ']'), ('quant', '+'), ('dim', '/'),
      ('dim', '  '), ('dim', '/'), ('num', '\\d'), ('quant', '+'), ('dim', '/')],
     [('dim', 'same strings, both ways')]),
    ('Rector', ('rewrite · proven equal', 'info'),
     [('call', 'preg_match'), ('text', '('), ('str', "'/^https:/'"), ('text', ', '), ('group', '$url'), ('text', ')')],
     [('dim', '→ '), ('call', 'str_starts_with'), ('text', '('), ('group', '$url'), ('text', ', '), ('str', "'https:'"), ('text', ')')]),
]


def asset_social_proofs(fonts, theme):
    """1280x640: lockup, claim, the integrations; three proofs on the right.
    Key content keeps 72px from every edge."""
    p = PALETTE[theme]
    w, h = 1280, 640
    defs = node_gradient('n', theme) + halo(theme, 'halo', 300, 0, 900, 520)
    out = svg_open(w, h, 'PHPRegex — regex, treated as code', defs) + card_ground(theme, w, h)
    markup, _ = lockup(fonts, theme, 'n', 80, 96, 34)
    out += markup
    for i, line in enumerate(['Regex, treated', 'as code.']):
        d, _ = fonts.path(line, 'SemiBold', 66, 80, 228 + i * 74, -0.03)
        out += f'<path d="{d}" fill="{p["ink"]}"/>'
    for i, line in enumerate(['Static analysis, linter and logic solver for',
                              'PHP: it proves, compares and rewrites',
                              'patterns — in CI, your editor and the browser.']):
        d, _ = fonts.path(line, 'Regular', 22, 80, 372 + i * 34, -0.005)
        out += f'<path d="{d}" fill="{p["mute"]}"/>'
    cx, cy = 80, 478
    for name in INTEGRATIONS:
        _, tw = fonts.path(name, 'Medium', 17)
        pw = tw + 24
        if cx + pw > 580:
            cx, cy = 80, cy + 40
        d, _ = fonts.path(name, 'Medium', 17, cx + 12, cy + 22)
        out += (f'<rect x="{cx + .75:.1f}" y="{cy + .75}" width="{pw - 1.5:.1f}" height="30.5" rx="15.25" '
                f'fill="none" stroke="{p["ring"]}" stroke-width="1.5"/><path d="{d}" fill="{p["mute"]}"/>')
        cx += pw + 8
    x, pw, ph, y = 640, 560, 150, 72
    for title, (badge, tone), line1, line2 in PROOFS:
        out += (f'<rect x="{x}" y="{y}" width="{pw}" height="{ph}" rx="18" fill="{PANEL["bg"]}"/>'
                f'<rect x="{x + .75}" y="{y + .75}" width="{pw - 1.5}" height="{ph - 1.5}" rx="17.25" fill="none" '
                f'stroke="{PANEL["ring"]}" stroke-width="1.5"/>')
        d, _ = fonts.path(title.upper(), 'mono:500', 14, x + 24, y + 34, 0.12)
        out += f'<path d="{d}" fill="{PANEL["dim"]}"/>'
        out += verdict_chip(fonts, badge, x + pw - 24, y + 15, PANEL[tone])
        r1, _ = mono_runs(fonts, line1, x + 24, y + 84, 19)
        r2, _ = mono_runs(fonts, line2, x + 24, y + 120, 19)
        out += r1 + r2
        y += ph + 22
    return out + '</svg>'


# ---------------------------------------------------------------------------
# Writing.
# ---------------------------------------------------------------------------

PACKAGES = [
    ('Automata', 'Automata'), ('Cli', 'CLI'), ('Explain', 'Explain'), ('Generator', 'Generator'),
    ('Infection', 'Infection'), ('LanguageServer', 'Language Server'), ('Laravel', 'Laravel'),
    ('Linter', 'Linter'), ('Mcp', 'MCP'), ('Optimizer', 'Optimizer'), ('Parser', 'Parser'),
    ('PHPStan', 'PHPStan'), ('PHPUnit', 'PHPUnit'), ('Psalm', 'Psalm'), ('Rector', 'Rector'),
    ('Redos', 'ReDoS'), ('Symfony', 'Symfony'), ('Toolkit', 'Toolkit'), ('Transpiler', 'Transpiler'),
]


def write(path, content):
    os.makedirs(os.path.dirname(path), exist_ok=True)
    with open(path, 'w') as f:
        f.write(content + '\n')


def render(svg, png, width, height=None):
    cmd = ['rsvg-convert', '-w', str(width)] + (['-h', str(height)] if height else []) + [svg, '-o', png]
    subprocess.run(cmd, check=True)


def main():
    ap = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    ap.add_argument('--inter-display', required=True, help='directory of the Inter Display OTFs')
    ap.add_argument('--mono', required=True, help='JetBrains Mono (variable woff2 or TTF)')
    ap.add_argument('--root', default=os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
    ap.add_argument('--no-render', action='store_true', help='write the SVGs only')
    args = ap.parse_args()
    fonts = Fonts(args.inter_display, args.mono)
    root = args.root
    art = os.path.join(root, 'art')
    docs = os.path.join(root, 'docs')

    svgs = {
        'mark.svg': asset_mark('light'), 'mark-dark.svg': asset_mark('dark'),
        'logo.svg': asset_logo(fonts, 'light'), 'logo-dark.svg': asset_logo(fonts, 'dark'),
        'favicon.svg': asset_icon('favicon'), 'icon-touch.svg': asset_icon('touch'),
        'icon-maskable.svg': asset_icon('maskable'),
        'org-icon.svg': asset_icon('org-light'), 'org-icon-dark.svg': asset_icon('org-dark'),
        'banner.svg': asset_banner(fonts, 'light'), 'banner-dark.svg': asset_banner(fonts, 'dark'),
        'social.svg': asset_social_proofs(fonts, 'light'), 'social-dark.svg': asset_social_proofs(fonts, 'dark'),
        'findings-social.svg': asset_social(fonts, 'light', 'findings'),
        'findings-social-dark.svg': asset_social(fonts, 'dark', 'findings'),
    }
    for name, content in svgs.items():
        write(os.path.join(art, name), content)
    for pkg, display in PACKAGES:
        for theme, name in (('light', 'banner.svg'), ('dark', 'banner-dark.svg')):
            write(os.path.join(root, 'src', pkg, 'art', name), asset_banner(fonts, theme, display))
    print(f'wrote {len(svgs)} SVGs under art/ and {2 * len(PACKAGES)} package banners')

    if args.no_render or not shutil.which('rsvg-convert'):
        return
    a = lambda n: os.path.join(art, n)  # noqa: E731
    for n in ('banner', 'banner-dark'):
        render(a(n + '.svg'), a(n + '.png'), 2000, 400)
    for n in ('social', 'social-dark', 'findings-social', 'findings-social-dark'):
        render(a(n + '.svg'), a(n + '.png'), 1280, 640)
    for n in ('org-icon', 'org-icon-dark'):
        render(a(n + '.svg'), a(n + '.png'), 1024, 1024)
    for n in ('logo', 'logo-dark', 'mark', 'mark-dark'):
        render(a(n + '.svg'), a(n + '.png'), 1200 if n.startswith('logo') else 800)
    render(a('favicon.svg'), a('favicon.png'), 512, 512)
    for pkg, _ in PACKAGES:
        d = os.path.join(root, 'src', pkg, 'art')
        render(os.path.join(d, 'banner.svg'), os.path.join(d, 'banner.png'), 2000, 400)
        render(os.path.join(d, 'banner-dark.svg'), os.path.join(d, 'banner-dark.png'), 2000, 400)

    # The site's copies: the favicon set, the app icons and the social cards.
    shutil.copy(a('favicon.svg'), os.path.join(docs, 'favicon.svg'))
    render(a('favicon.svg'), os.path.join(docs, 'favicon.png'), 512, 512)
    render(a('icon-touch.svg'), os.path.join(docs, 'apple-touch-icon.png'), 180, 180)
    render(a('favicon.svg'), os.path.join(docs, 'icon-192.png'), 192, 192)
    render(a('favicon.svg'), os.path.join(docs, 'icon-512.png'), 512, 512)
    render(a('icon-maskable.svg'), os.path.join(docs, 'icon-maskable-512.png'), 512, 512)
    if shutil.which('convert'):
        sizes = []
        for s in (16, 32, 48):
            png = f'/tmp/brand-fav-{s}.png'
            render(a('favicon.svg'), png, s, s)
            sizes.append(png)
        subprocess.run(['convert'] + sizes + [os.path.join(docs, 'favicon.ico')], check=True)
    img = os.path.join(docs, 'assets', 'img')
    shutil.copy(a('social-dark.svg'), os.path.join(img, 'social.svg'))
    render(a('social-dark.svg'), os.path.join(img, 'social.png'), 1280, 640)
    render(a('findings-social-dark.svg'), os.path.join(img, 'findings-social.png'), 1280, 640)
    print('rendered the PNG, ICO and site copies')


if __name__ == '__main__':
    sys.exit(main())
