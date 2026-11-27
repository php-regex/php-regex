---
description: "A \\x{...} or \\o{...} names a code point past U+10FFFF, or past U+FF without the u modifier. Add the modifier, or stay below the limit. The octal \\377 one too."
head_title: "\"character code point value is too large\" in PHP"
parent: /reference/diagnostics/
engine_family: "Compilation failed"
engine_order: 16
engine:
  message: "Compilation failed: character code point value in \\x{} or \\o{} is too large at offset 9"
  pattern: "/\\x{110000}/"
codes:
  - regex.unicode.out_of_range
  - regex.octal.out_of_range
---
# Character code point value in \x{} or \o{} is too large

The escape names a code point the engine cannot hold, and PCRE2 refuses the pattern.

```php
preg_match('/\x{110000}/', $value);
// preg_match(): Compilation failed: character code point value in \x{} or \o{} is too large at offset 9
```

`\x{110000}` is one past the last code point of Unicode, U+10FFFF. That is the highest ceiling, and the one that applies under the `u` modifier. Without `u`, a character is one byte, and the ceiling is U+FF. The offset, counted from the opening delimiter, is the last digit of the number.

## Why PHP prints it

The value of the escape meets one of two ceilings.

**Past the last code point.** U+10FFFF is the end of Unicode, with or without `u`:

```php
preg_match('/\x{110000}/u', $value);
// preg_match(): Compilation failed: character code point value in \x{} or \o{} is too large at offset 9
```

**Past U+FF without the `u` modifier.** In byte mode, a character is a byte:

```php
preg_match('/\x{100}/', $value);
// preg_match(): Compilation failed: character code point value in \x{} or \o{} is too large at offset 6
```

**An octal value past `\377`.** The ceiling of the byte, with a wording of its own:

```php
preg_match('/\777/', $value);
// preg_match(): Compilation failed: octal value is greater than \377 in 8-bit non-UTF-8 mode at offset 4
```

## How to fix it

```php
// Add the u modifier, and the character is read as UTF-8
preg_match('/\x{E9}/u', $text); // matches "é"

// Or stay in byte mode, and write the bytes of the character
preg_match('/\xC3\xA9/', $text); // matches "é"
```

`\x{41}` and `\x{FF}` compile with or without `u`. With `u`, the subject must be valid UTF-8 too: on a subject that is not, `preg_match()` returns `false`, and `preg_last_error_msg()` says `Malformed UTF-8 characters, possibly incorrectly encoded`.

## Where it hides

- **An emoji or a symbol without `u`.** `/\x{1F600}/` and `/\x{263A}/` are refused. With `u`, both compile.
- **A range in a class.** `/[\x{FF}-\x{1FF}]+/` is refused. With `u`, it compiles.
- **`\o{…}`.** It has the same two ceilings: `/\o{400}/` is refused without `u` and compiles with it.
- **Octal.** `/\400/` gives the wording of `\777`: a byte cannot hold it.

## What PHPRegex reports

- `regex.unicode.out_of_range` for `/\x{110000}/`: "Invalid Unicode codepoint "\x{110000}" (out of range)." The library gives the offset and no hint.
- `regex.octal.out_of_range` for `/\x{100}/`: "Invalid code point "\x{100}": without the "u" flag, a character is at most \xFF." The hint follows: "Add the "u" flag, or use a code point up to \xFF."

[Open `/\x{110000}/` in the playground](/#pattern=%2F%5Cx%7B110000%7D%2F&subject=A): the Diagnostics tab shows the code, its message, and what PHP says.

## Related

- [PCRE2 does not support \F, \L, \l, \N{name}, \U, or \u](pcre2-does-not-support-unicode-escapes.md): the `\u` of another language, and `\x{…}` in its place.
- [Braced Hex Escape Without /u](../rules/braced-hex-escape-without-u.md): the lint finding for a `\x{…}` above U+FF written without the flag.
- [Malformed UTF-8 characters, possibly incorrectly encoded](malformed-utf-8-characters.md): what `u` demands from the subject.
- [Suspicious Escapes](../rules/suspicious-escapes.md): the linter also reports a `\x{…}` past U+10FFFF.
