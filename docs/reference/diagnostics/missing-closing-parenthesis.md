---
description: "A group opens with ( and never closes. The offset of the message is the end of the pattern: count the parentheses to find the one left open, literal ones too."
head_title: "\"missing closing parenthesis\" in PHP preg_match"
parent: /reference/diagnostics/
engine_family: "Compilation failed"
engine_order: 2
quick_fix: "Close the group, or escape the ("
quick_fix_order: 9
engine:
  message: "Compilation failed: missing closing parenthesis at offset 4"
  pattern: "/(abc/"
codes:
  - regex.group.unclosed
---
# Missing closing parenthesis

A group opens with `(`, and the pattern ends before a `)` closes it.

```php
preg_match('/(abc/', $value);
// preg_match(): Compilation failed: missing closing parenthesis at offset 4
```

The group opened by `(` is still open when PCRE2 reaches the end of the pattern. The offset is that end, not the `(` left open: count the parentheses, escaped ones aside, to find the one without a partner. `preg_replace()` and the other functions print the same message under their own name.

## Why PHP prints it

Every `(` that is neither escaped nor inside a class opens a group, and each group needs its `)`. Three shapes leave one open.

**A `)` left out.**

```php
preg_match('/(a(b)c/', $value);
// preg_match(): Compilation failed: missing closing parenthesis at offset 6
```

Two groups open and one closes. The inner group is closed, the outer is not.

**A literal `(` not escaped.**

```php
preg_match('/Total (incl. tax/', $value);
// preg_match(): Compilation failed: missing closing parenthesis at offset 16
```

The parenthesis belongs to the text to match. For PCRE2 it opens a group that runs to the end of the pattern.

**A `)` escaped.**

```php
preg_match('/(a\)b/', $value);
// preg_match(): Compilation failed: missing closing parenthesis at offset 5
```

`\)` is a character of the text. It does not close the group that `(` opened.

## How to fix it

Close the group that is open, or escape a parenthesis that belongs to the text.

```php
// Both groups closed
preg_match('/(a(b)c)/', 'abc');
// matches "abc"

// A literal ( escaped
preg_match('/Total \(incl\. tax/', 'Total (incl. tax');
// matches "Total (incl. tax"

// The group closed after a literal )
preg_match('/(a\)b)/', 'a)b');
// matches "a)b"
```

Escape the two parentheses of a pair together. `/\(abc)/` escapes only the first, and prints `unmatched closing parenthesis`.

## Where it hides

- **A group assembled at run time.** `'/^(' . implode('|', $words)` ends before the `)` is appended. With `$words = ['foo', 'bar']`, the pattern is `/^(foo|bar/`.
- **A quantifier moved inside the group.** `/(abc?/` repeats the `c` alone and leaves the group open. The optional group is `/(abc)?/`.
- **A snippet cut at its end.** `/(\d{4})-(\d{2}/` lost its last `)` with the rest of the line.
- **Every kind of group.** `(?:`, `(?=`, `(?<=` and `(?<name>` open a group too: `/(?:abc/` and `/(?<=abc/` print the message.

## What PHPRegex reports

- `regex.group.unclosed` for `/(abc/`: `Expected ) at end of input (found eof).` The library gives the same offset as PHP, and no hint.

[Open `/(abc/` in the playground](/#pattern=%2F%28abc%2F&subject=abc): the Diagnostics tab shows the code, its message, and what PHP says.

## Related

- [Unmatched closing parenthesis](unmatched-closing-parenthesis.md): the message of a `)` that closes no group.
- [Chapter 5: Groups & Alternation](../../tutorial/05-groups-alternation.md): capturing, non-capturing and named groups.
- [Unrecognized character after (? or (?-](unrecognized-character-after-group-open.md): when `(?` is followed by a character PCRE2 cannot read.
- [Missing terminating \] for character class](missing-terminating-bracket-for-character-class.md): the same message for a class.
