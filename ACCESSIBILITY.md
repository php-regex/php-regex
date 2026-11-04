# Accessibility

PHPRegex is a developer tool, and developer tools are no excuse for barriers.
Most of the interaction with the library happens through code, but its
documentation, its online playground and its CLI output are interfaces too —
we want them usable by everyone, including people who rely on screen readers,
keyboard-only navigation, magnification, or reduced-motion settings.

We use [WCAG 2.2](https://www.w3.org/TR/WCAG22/) level AA as our reference
standard. We fix gaps as we learn about them, and we do not claim full
conformance — if you hit a barrier, we want to hear about it.

## What this covers

- The documentation site and the online playground at
  [php-regex.com](https://php-regex.com) — navigation, contrast, keyboard
  operability, and screen-reader announcements.
- The CLI (`bin/regex`) — its output should stay meaningful in any terminal,
  including without color support.
- The repository content itself — README, docs, and changelogs written to be
  read as plain text first.

## Known limitations

- The playground renders dense technical output — parse trees, byte offsets,
  token highlights. These views are keyboard-operable and announced, but
  reading them entirely non-visually can still be tedious. Reports on what
  would help are especially welcome.
- Some documentation figures are static drawings; their alternatives describe
  what the figure shows rather than reproducing every value.

## Reporting a barrier

Open a [GitHub issue](https://github.com/php-regex/php-regex/issues) — the bug
template works well. Accessibility reports are treated as bugs, not feature
requests. When you can, include:

- the page or command involved,
- your browser and terminal, if relevant,
- the assistive technology you were using.

If you prefer a private report, write to **younes.ennaji.pro@gmail.com** (the
same address as the security policy).
