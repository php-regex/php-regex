---
description: "The \\uXXXX of JavaScript and Python is refused in PHP. Write \\x{XXXX}, with the u modifier past U+FF, or build the bytes in byte mode. The escapes refused too."
head_title: "PHP regex has no \\u escape: PCRE2 refuses it"
parent: /reference/diagnostics/
engine_family: "Compilation failed"
engine_order: 13
quick_fix: "Write \\x{…} where another language writes \\u"
quick_fix_order: 11
engine:
  message: "Compilation failed: PCRE2 does not support \\F, \\L, \\l, \\N{name}, \\U, or \\u at offset 2"
  pattern: "/\\u{41}/"
codes:
  - regex.escape.unsupported
---
# PCRE2 does not support \F, \L, \l, \N{name}, \U, or \u

The pattern writes a character by its code point with `\u`, and PCRE2 has no `\u` escape.

```php
preg_match('/\u{41}/', $value);
// preg_match(): Compilation failed: PCRE2 does not support \F, \L, \l, \N{name}, \U, or \u at offset 2
```

`\u{41}` is how JavaScript writes `A`. PCRE2 reads the backslash, meets the `u`, and stops: the offset, counted from the opening delimiter, is the `u`. The message lists the six escapes it refuses for that reason.

## Why PHP prints it

PCRE2 writes a character by its code point with `\x{…}` and no other way. The message names the six escapes it refuses for that reason, and each prints it on its own.

**The `\u` of JavaScript and Python.** Four hexadecimal digits, or any number between braces:

```php
preg_match('/\u0041/', $value);
// preg_match(): Compilation failed: PCRE2 does not support \F, \L, \l, \N{name}, \U, or \u at offset 2
```

**An eight-digit `\U`.** Python reads it; here the offset is still the letter:

```php
preg_match('/\U00000041/', $value);
// preg_match(): Compilation failed: PCRE2 does not support \F, \L, \l, \N{name}, \U, or \u at offset 2
```

**A character by its name.** `\N` alone is another escape, which compiles. Followed by `{` and a name, it is refused at the brace:

```php
preg_match('/\N{LATIN SMALL LETTER A}/', $value);
// preg_match(): Compilation failed: PCRE2 does not support \F, \L, \l, \N{name}, \U, or \u at offset 3
```

## How to fix it

```php
// The same code point, the way PCRE2 writes it
preg_match('/\x{41}/', $value); // matches "A"

// Past U+FF, the u modifier is needed
preg_match('/\x{E9}/u', $text); // matches "é"

// In byte mode: the bytes of the character
preg_match('/\xC3\xA9/', $text); // matches "é"
```

`\x{41}` and `\x{FF}` compile with or without `u`. From `\x{100}` on, the pattern needs the modifier: without it, another message refuses the pattern.

## Where it hides

- **A pattern copied from JavaScript or Python.** `/\u0041/`, `/\u{41}/` and `/\U00000041/` are refused here.
- **A conversion that forgets the modifier.** `\u` becomes `\x`, and `\u{1F600}` becomes `/\x{1F600}/`: without `u`, another message refuses it. With `u`, it compiles.
- **A pattern in double quotes.** PHP reads `"\u{41}"` as one of its own string escapes: the character reaches PCRE2, and `preg_match("/\u{41}/", 'A')` matches. `"\u0041"` is not a PHP escape, so that pattern is refused at the `\u`.
- **The case escapes.** `/\l/`, `/\L/`, `/\U/` and `/\F/` print the same message.

## What PHPRegex reports

- `regex.escape.unsupported` for `/\u{41}/`: "PCRE does not support the escape "\u" (\F, \L, \l, \N{name}, \U and \u are not supported)." The library names the escape, `\u` or `\U`, and gives its offset. It adds no hint. For `/\N{LATIN SMALL LETTER A}/` it names the escape "\N{".

[Open `/\u{41}/` in the playground](/#pattern=%2F%5Cu%7B41%7D%2F&subject=A): the Diagnostics tab shows the code, its message, and what PHP says.

## Related

- [`Unrecognized character follows \`](unrecognized-character-follows-backslash.md): the message of the letters PCRE2 refuses without naming them.
- [Character code point value in \x{} or \o{} is too large](character-code-point-value-is-too-large.md): what a `\x{…}` above U+FF needs.
- [Braced Hex Escape Without /u](../rules/braced-hex-escape-without-u.md): the lint finding for a `\x{…}` above U+FF written without the flag.
- [Unicode Shorthand Without /u](../rules/unicode-shorthand-without-u.md): what the `u` modifier changes for `\w`, `\d` and `\s`.
