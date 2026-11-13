#!/usr/bin/env python3
"""Generate one Open Graph image per page of the documentation site.

Every page of php-regex.com gets its own 1200x630 card — the page's title,
its description and its section — so a link shared on WhatsApp, X,
LinkedIn or Slack shows what it leads to, not the same card for every page.
The head template serves docs/assets/og/<path>.jpg when the file exists and
falls back to the site card otherwise, so a page added since the last run
still has an image.

The page list is read from the built site (titles, descriptions, sections,
article bylines), so every page Jekyll writes — documentation, tutorial,
articles, the findings of every project — is covered without a list to
maintain here.

Usage:
    (cd docs && bundle exec jekyll build)
    python3 art/og.py --site docs/_site \\
        --inter-display /path/to/inter/extras/otf \\
        --mono docs/assets/fonts/jetbrains-mono-var.woff2

    # or, from a page inventory written elsewhere (see --dump):
    python3 art/og.py --pages pages.json ...

Needs fonttools, uharfbuzz and brotli (pip), rsvg-convert and ImageMagick.
"""
import argparse
import html
import json
import os
import re
import subprocess
import sys
import tempfile

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import brand as B  # noqa: E402

W, H = 1200, 630

# Each section has its hue: a shared link says where it leads before it is
# read. Every hue is measured on #030712 for its eyebrow text (>= 4.5:1).
SECTIONS = {
    'learn': ('Learn', '#FB923C'),
    'tutorial': ('Tutorial', '#34D399'),
    'integrations': ('Integration', '#38BDF8'),
    'concepts': ('Concepts', '#A78BFA'),
    'reference': ('Reference', '#2DD4BF'),
    'internals': ('Internals', '#FBBF24'),
    'article': ('Article', '#F472B6'),
    'findings': ('Findings', '#F87171'),
    'home': ('', '#FB923C'),
}

CRUMB_SECTIONS = {'Learn': 'learn', 'Tutorial': 'tutorial', 'Integrations': 'integrations',
                  'Concepts': 'concepts', 'Reference': 'reference', 'Internals': 'internals'}


# ---------------------------------------------------------------------------
# The page inventory, read from the built site.
# ---------------------------------------------------------------------------

def inventory(site):
    pages = []
    for root, _, files in os.walk(site):
        rel_root = os.path.relpath(root, site)
        if rel_root.startswith(('assets', 'feed')):
            continue
        for name in files:
            if not name.endswith('.html'):
                continue
            path = os.path.join(root, name)
            s = open(path, encoding='utf-8').read()
            if 'http-equiv="refresh"' in s or '<main' not in s:
                continue
            url = '/' + os.path.relpath(path, site).replace(os.sep, '/')
            if url.endswith('index.html'):
                url = url[:-len('index.html')]

            def text(rx):
                m = re.search(rx, s, re.S)
                return re.sub(r'\s+', ' ', html.unescape(re.sub(r'<[^>]+>', '', m.group(1)))).strip() if m else ''

            desc = re.search(r'<meta name="description" content="([^"]*)"', s)
            nav = re.search(r'<nav class="breadcrumbs".*?</nav>', s, re.S)
            crumbs = [re.sub(r'\s+', ' ', html.unescape(re.sub(r'<[^>]+>', '', c))).strip()
                      for c in re.findall(r'<li[^>]*>(.*?)</li>', nav.group(0), re.S)] if nav else []
            pages.append({
                'url': url,
                'title': re.sub(r'\s+—\s+PHPRegex$', '', text(r'<title>(.*?)</title>')),
                'h1': text(r'<h1[^>]*>(.*?)</h1>'),
                'desc': html.unescape(desc.group(1)) if desc else '',
                'crumbs': crumbs,
                'byline': text(r'class="article-byline"[^>]*>(.*?)</(?:p|div)>'),
            })
    return sorted(pages, key=lambda p: p['url'])


def og_path(url):
    """The image's path under docs/assets/og/, the head template's rule:
    the page's path without its slashes at either end, nor .html; the
    home page is index."""
    rel = url.strip('/')
    if rel.endswith('.html'):
        rel = rel[:-5]
    return (rel or 'index') + '.jpg'


def classify(page):
    """(section key, eyebrow, title, description, big numeral or None)."""
    url, h1 = page['url'], page['h1'] or page['title']
    desc = page['desc']
    if url.startswith('/findings/') and url.count('/') >= 4:
        repo = '/'.join(url.strip('/').split('/')[1:3])
        return ('findings', 'Findings', repo,
                f'What the linter reports on the regular expressions of {repo}, '
                'judged for the PHP version its own composer.json requires.', None)
    if url.startswith('/findings/'):
        return 'findings', 'Findings', 'What the linter finds in real PHP', desc, None
    if url.startswith('/blog/') and page['byline']:
        parts = [p.strip() for p in page['byline'].split('·')]
        date = next((p for p in parts if re.search(r'\d{4}', p)), '')
        minutes = next((p for p in parts if 'min read' in p), '')
        eyebrow = ' · '.join(x for x in ('Article', short_date(date), minutes.replace(' read', '')) if x)
        return 'article', eyebrow, h1, desc, None
    if url == '/blog/':
        return 'article', 'Articles', h1, desc, None
    m = re.match(r'Chapter (\d+): (.*)', h1)
    if m:
        return 'tutorial', f'Tutorial · Chapter {int(m.group(1)):02d}', m.group(2), desc, f'{int(m.group(1)):02d}'
    if url in ('/', '/docs/', '/404.html', '/privacy/'):
        return 'home', '', h1.rstrip('.') + ('.' if h1.endswith('.') else ''), desc, None
    section = next((CRUMB_SECTIONS[c] for c in page['crumbs'] if c in CRUMB_SECTIONS), None)
    if section is None:
        section = 'integrations' if url.startswith('/guides/') else 'learn'
    return section, SECTIONS[section][0], h1, desc, None


def short_date(text):
    m = re.search(r'(\d{1,2}) (\w+) (\d{4})', text)
    return f'{int(m.group(1))} {m.group(2)[:3]} {m.group(3)}' if m else text


# ---------------------------------------------------------------------------
# Drawing.
# ---------------------------------------------------------------------------

def wrap(fonts, text, key, size, width, track=0.0, max_lines=3):
    """Greedy wrap by measured width. A line breaks at a space, or after a
    slash or a hyphen inside a word too long for the line (a repository's
    name); the last line ends in an ellipsis when the text does not fit."""
    pieces = []  # (text, joiner before it)
    for i, word in enumerate(text.split()):
        parts = re.findall(r'[^/-]+[/-]?|[/-]', word) if fonts.path(word, key, size, tracking=track)[1] > width else [word]
        for j, part in enumerate(parts):
            pieces.append((part, ' ' if i and j == 0 else ''))
    lines, line = [], ''
    for part, joiner in pieces:
        trial = line + joiner + part if line else part
        if fonts.path(trial, key, size, tracking=track)[1] <= width or not line:
            line = trial
        else:
            lines.append(line)
            line = part
    if line:
        lines.append(line)
    if len(lines) > max_lines:
        lines = lines[:max_lines]
        last = lines[-1]
        while fonts.path(last + '…', key, size, tracking=track)[1] > width and ' ' in last:
            last = last.rsplit(' ', 1)[0]
        lines[-1] = last.rstrip(',;:—–-') + '…'
    return lines


def card(fonts, page):
    section, eyebrow, title, desc, numeral = classify(page)
    hue = SECTIONS[section][1]
    defs = (B.node_gradient('n', 'dark')
            + f'<radialGradient id="glow" cx="1000" cy="0" r="1" gradientUnits="userSpaceOnUse" '
              f'gradientTransform="translate(1000 0) scale(760 520) translate(-1000 0)">'
              f'<stop offset="0" stop-color="{hue}" stop-opacity=".22"/><stop offset="1" stop-color="{hue}" stop-opacity="0"/></radialGradient>'
            + '<radialGradient id="glow2" cx="80" cy="630" r="1" gradientUnits="userSpaceOnUse" '
              'gradientTransform="translate(80 630) scale(620 360) translate(-80 -630)">'
              '<stop offset="0" stop-color="#3B82F6" stop-opacity=".08"/><stop offset="1" stop-color="#3B82F6" stop-opacity="0"/></radialGradient>')
    label = (title + ' — PHPRegex').replace('&', '&amp;').replace('<', '&lt;').replace('"', '&quot;')
    out = (B.svg_open(W, H, label, defs)
           + f'<rect width="{W}" height="{H}" fill="#030712"/>'
           + f'<rect width="{W}" height="{H}" fill="url(#glow)"/><rect width="{W}" height="{H}" fill="url(#glow2)"/>')

    # The section's sign on the right: a chapter's number, or the mark.
    text_w = 1040
    if numeral:
        d, nw = fonts.path(numeral, 'SemiBold', 330, 0, 0, -0.04)
        d, _ = fonts.path(numeral, 'SemiBold', 330, W - 64 - nw, 470, -0.04)
        out += f'<path d="{d}" fill="none" stroke="{hue}" stroke-opacity=".45" stroke-width="2"/>'
        text_w = W - 64 - nw - 80 - 40
    else:
        # The mark, large and faint, cut by the right edge: the brand's
        # watermark under every card that has no number of its own.
        out += (f'<g transform="translate(1060 360) scale(1.15)" opacity=".09">'
                f'{B.mark("n", "#F9FAFB")}</g>')

    lk, _ = B.lockup(fonts, 'dark', 'n', 72, 82, 30)
    out += lk

    y = 196
    if eyebrow:
        d, _ = fonts.path(eyebrow.upper(), 'mono:500', 18, 72, y, 0.12)
        out += f'<path d="{d}" fill="{hue}"/>'
        y += 30
    size = 66
    while True:
        lines = wrap(fonts, title, 'SemiBold', size, text_w, -0.03, 3)
        if (len(lines) <= 2 or size <= 50) and not lines[-1].endswith('…') or size <= 46:
            break
        size -= 4
    lh = size * 1.12
    y += size * 0.86
    for line in lines:
        d, _ = fonts.path(line, 'SemiBold', size, 72, y, -0.03)
        out += f'<path d="{d}" fill="#F9FAFB"/>'
        y += lh
    y += 6
    # The description takes the lines left above the address, three at most.
    room = min(3, int((500 - (y + 8)) // 36) + 1)
    for line in (wrap(fonts, desc, 'Regular', 25, min(text_w, 960), -0.005, room) if room > 0 else []):
        d, _ = fonts.path(line, 'Regular', 25, 72, y + 8, -0.005)
        out += f'<path d="{d}" fill="#9CA3AF"/>'
        y += 36

    url = 'php-regex.com' + (page['url'].rstrip('/') if page['url'] != '/' else '')
    d, uw = fonts.path(url, 'mono:500', 18, 72, 574, 0.02)
    out += f'<path d="{d}" fill="#6B7280"/>'
    out += f'<rect x="72" y="532" width="{min(uw, 40):.0f}" height="3" rx="1.5" fill="{hue}"/>'
    return out + '</svg>'


def main():
    ap = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    src = ap.add_mutually_exclusive_group(required=True)
    src.add_argument('--site', help='the built site, docs/_site')
    src.add_argument('--pages', help='a page inventory written by --dump')
    ap.add_argument('--dump', help='write the inventory read from --site to this JSON file and stop')
    ap.add_argument('--inter-display', help='directory of the Inter Display OTFs')
    ap.add_argument('--mono', help='JetBrains Mono (variable woff2 or TTF)')
    ap.add_argument('--out', default=os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))),
                                                  'docs', 'assets', 'og'))
    ap.add_argument('--only', help='only the pages whose URL starts with this prefix')
    args = ap.parse_args()

    pages = inventory(args.site) if args.site else json.load(open(args.pages))
    if args.dump:
        json.dump(pages, open(args.dump, 'w'), ensure_ascii=False, indent=1)
        print(f'{len(pages)} pages written to {args.dump}')
        return
    if not (args.inter_display and args.mono):
        ap.error('--inter-display and --mono are required to draw')
    fonts = B.Fonts(args.inter_display, args.mono)
    # The home page keeps the site's card: the one image that says what the
    # whole project does.
    pages = [p for p in pages if p['url'] != '/']
    if args.only:
        pages = [p for p in pages if p['url'].startswith(args.only)]
    with tempfile.TemporaryDirectory() as tmp:
        for page in pages:
            target = os.path.join(args.out, og_path(page['url']))
            os.makedirs(os.path.dirname(target), exist_ok=True)
            svg = os.path.join(tmp, 'card.svg')
            png = os.path.join(tmp, 'card.png')
            open(svg, 'w').write(card(fonts, page))
            subprocess.run(['rsvg-convert', '-w', str(W), '-h', str(H), svg, '-o', png], check=True)
            # JPEG, full chroma: about 45 KB a card where a PNG weighs 100,
            # and the glows keep their smoothness, which a palette PNG bands.
            subprocess.run(['convert', png, '-strip', '-quality', '85', '-sampling-factor', '4:4:4',
                            '-interlace', 'Plane', target], check=True)
    print(f'{len(pages)} cards under {os.path.relpath(args.out)}')


if __name__ == '__main__':
    main()
