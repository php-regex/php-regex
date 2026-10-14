---
description: "Lay a long regex out over lines under x, or compact a laid-out one to a single line, with the guarantee that the result matches exactly what the input matched."
---
# Formatting Patterns

A long pattern on one line reads like minified JSON: you split it in your head before you can follow it. `format` does the splitting for you. It lays the pattern out over lines under the `x` flag, the way a code formatter lays out code, and keeps its comments. `compact` does the reverse, like a minifier: one line, no `x`, no layout, no comments.

Both only change how the pattern is written, never what it matches.

{% include install-prerelease.html package="php-regex/regex-toolkit" %}

## Quick start

```bash
vendor/bin/regex format '#^(?<scheme>https?)://(?:(?<user>[^:@/]+)(?::(?<pass>[^@/]*))?@)?(?<host>\[[0-9a-f:.]+\]|[^:/?\#]+)(?::(?<port>\d+))?(?<path>/[^?\#]*)?(?:\?(?<query>[^\#]*))?(?:\#(?<fragment>.*))?$#i'
```

```
#
    ^
    (?<scheme>https?)
    ://
    (?:
        (?<user>[^:@/]+)
        (?::(?<pass>[^@/]*))?
        @
    )?
    (?<host>\[[0-9a-f:.]+\]|[^:/?\#]+)
    (?::(?<port>\d+))?
    (?<path>/[^?\#]*)?
    (?:\?(?<query>[^\#]*))?
    (?:\#(?<fragment>.*))?
    $
#ix
```

`vendor/bin/regex format --compact` on that output gives back the one-line pattern, byte for byte.

From PHP:

```php
use PHPRegex\Toolkit\Regex;

$regex = Regex::create();

$result = $regex->format($pattern);        // FormatResult
echo $result->pattern;                     // the laid-out pattern
$result->changed;                          // false when it already fit

echo $regex->compact($laidOut)->pattern;   // one line again
```

`new PatternFormatter($width)` in `PHPRegex\Parser\Printer` does the same on a tree you already parsed: `format(RegexNode)` and `compact(RegexNode)` both return a `FormatResult`.

In an editor, the [language server](lsp.md) offers both as code actions on a regex literal: "Format pattern over lines" and "Compact pattern to one line".

## The layout rules

- **A construct stays on one line** when it fits the width (80 by default, `--width=N`), holds no `#` comment, and nests at most two groups. Otherwise it breaks. Lookarounds, conditionals, `(?|`, atomic groups and `(?(DEFINE)` count as groups; character classes do not.
- **A broken group** opens on its own line, indents its body by four spaces, and closes on its own line with its quantifier: `)?`, `){2,}`.
- **Inside a broken group**, each group gets its own line, and runs of plain atoms (literals, classes, escapes, anchors) stay together.
- **A broken alternation** puts one branch per line, with `| ` hanging in the indent so every branch starts in the same column:

  ```
  /
      (?<int>\d+)         # an integer
    | (?<str>"[^"]*")     # a string
    | (?<name>[a-z_]\w*)  # a name
  /x
  ```

- **Delimiters** sit on their own lines, the flags after the closing one. A leading `(*UTF)`, `(*CR)` or `(*LIMIT_MATCH=…)` stays on the opening delimiter's line, because PCRE reads them only at the very start.
- **Width** is counted in code points under `u` or `(*UTF)`, and in bytes otherwise. It is a goal, not a promise: an atom wider than the width stays whole, and past ten indent levels the rest of a construct stays on one line.

## Comments

Comments stay where the author put them. A comment written after an item stays on that item's line. Trailing comments on consecutive lines align on one column, the way `gofmt` aligns them. One blank line the author left between items is kept; two become one.

`compact` drops every comment, `#` and `(?#…)` alike.

## Spelling

The formatter changes the layout, never the vocabulary. Escapes, group-name syntax (`(?<n>`, `(?'n'`, `(?P<n>`), backreference syntax and quantifier text come back as written. Only what `x` would otherwise swallow is respelled:

- a space becomes `\ ` (`\x20` at the end of a line, where an editor might strip it), and `#` becomes `\#`;
- a raw tab, line break, vertical tab or form feed becomes its escape, so every line break in the output is layout;
- under `u`, the characters PCRE also skips under `x` (NEL, LRM, RLM, LS, PS) become `\x{…}`; without `u`, a raw byte 0x85 becomes `\x85`.

`compact` only removes things. Where removing a comment or layout would fuse two tokens, it writes an empty group `(?:)` between them: `a{(?#c),2}` becomes `a{(?:),2}`, because `a{,2}` is a quantifier on PCRE2 10.43 and later.

## Guarantees

- **A pattern that already fits comes back byte for byte.** A short pattern is left alone.
- **Formatting is idempotent.** Formatting the output again changes nothing.
- **The result is the same pattern.** Before it returns, the formatter parses its own output and compares the tree with the input's. If they differ, it throws `FormattingException` (code `regex.formatting.unsafe`) instead of handing back a wrong pattern. That would be a bug: please report it with the pattern.
- **The output does not depend on the PCRE version.** The same input gives the same output on every supported PHP.

## When it refuses

In three cases, laying the pattern out under `x` would change what it matches. `format` then returns the pattern unchanged, with `refusal` set (the CLI prints a note on stderr and exits 0):

| refusal | when | why |
|---|---|---|
| `newline_convention` | the pattern holds `#` comments under `(*CR)`, `(*CRLF)` or `(*NUL)` | under those conventions a `#` comment does not end at a line feed |
| `spaced_quantifier` | a pattern without `x` writes a quantifier with spaces, like `a{ 1,2 }` | it is a quantifier on PCRE2 10.43 and later, a literal before: no single layout means the same on both |
| `extended_mode_switched_off` | a top-level `(?-x)` or `(?^)` | after it, a line break is a literal |

## The pretty printer

`new PatternPrinter(pretty: true)` uses the same layout engine with normalized escapes. It lays a pattern out only when it already has `x`, never adds `x`, and never refuses: where `format` would refuse, it prints one line.
