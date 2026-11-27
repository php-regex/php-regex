---
description: "PHP takes the first character of the pattern as its delimiter. A letter, a digit, a backslash or a NUL byte cannot be one: wrap the pattern in /…/ or #…#."
head_title: "\"Delimiter must not be alphanumeric\" in PHP"
parent: /reference/diagnostics/
engine_family: "The checks of PHP"
engine_order: 2
quick_fix: "Wrap the pattern in a pair of delimiters"
quick_fix_order: 2
engine:
  message: "Delimiter must not be alphanumeric, backslash, or NUL byte"
  pattern: "foo/i"
codes:
  - regex.delimiter.invalid
---
# Delimiter must not be alphanumeric, backslash, or NUL byte

PHP read the first character of the pattern as its delimiter, and refused it: a letter, a digit, a backslash or a NUL byte cannot be one.

```php
preg_match('foo/i', $value);
// preg_match(): Delimiter must not be alphanumeric, backslash, or NUL byte
```

The pattern has no delimiters. PHP takes the first character of the string as the opening one, whatever it is, and refuses four kinds of character. Here it is `f`. The call returns `false` and nothing is compiled.

## Why PHP prints it

A PHP pattern is an expression between two delimiters, then its modifiers. Any character can be the delimiter, except a letter, a digit, a backslash and a NUL byte.

**The expression stands alone.** It comes from a tester or a language that writes no delimiters, and it starts with a letter or a digit:

```php
preg_match('a{2}b{2}a', $value);
// preg_match(): Delimiter must not be alphanumeric, backslash, or NUL byte
```

**The expression starts with a backslash.** An escape puts a backslash first, and the backslash cannot open the pattern:

```php
preg_match('\d+\d', $value);
// preg_match(): Delimiter must not be alphanumeric, backslash, or NUL byte
```

**A NUL byte comes first.** ASCII text in UTF-16BE begins with one:

```php
preg_match("\x00/a/", $value);
// preg_match(): Delimiter must not be alphanumeric, backslash, or NUL byte
```

PHP skips the whitespace that comes before the delimiter: in `' foo'` it reads the `f`.

## How to fix it

Wrap the expression in a pair of delimiters. Pick a character the expression does not hold: a `/` inside the expression has to be escaped, where `~` or `#` leaves nothing to escape.

```php
// The expression between two slashes, its modifier after the closing one
preg_match('/foo/i', 'FOO');
// 1

// A slash inside the expression: another delimiter leaves nothing to escape
preg_match('~https?://~', 'https://example.com');
// 1
```

When a variable holds the expression, the delimiters go around it in the code, as in `'/^' . $expression . '$/'`.

## Where it hides

- **A rule stored bare.** A setting, a constant or a database row holds `foo|bar`, and the code passes it as it is.
- **A pattern built from parts.** `implode('|', $keywords)` gives `foo|bar`, and nothing stands around it.
- **The habit of a language without delimiters.** A pattern copied from it has none: `\bword\b` starts with a backslash.
- **A slash escaped inside double quotes.** PHP keeps the backslashes of `"\/foo\/i"`, so the string starts with `\`.
- **A string padded with NUL bytes.** `str_pad('foo', 6, "\0", STR_PAD_LEFT)` begins with a NUL byte.

## What PHPRegex reports

- `regex.delimiter.invalid` for `foo/i`: "Invalid delimiter "f". Delimiters must not be alphanumeric, backslash, or NUL byte. Try #foo/i#." The message names the character PHP took, and its hint is "Wrap the pattern in a pair of delimiters, such as "/.../" or "#...#"." The pattern it offers wraps the string as it stands: `#foo/i#` matches the text `foo/i`. When `i` was a modifier, write `/foo/i`.

[Open `foo/i` in the playground](/#pattern=foo%2Fi&subject=foo): the Diagnostics tab shows the code, its message, and what PHP says.

## Related

- [Unknown modifier](unknown-modifier.md)—the delimiters are there, and one stands inside the expression.
- [No ending delimiter found](no-ending-delimiter.md)—the first delimiter has no second.
- [Empty regular expression](empty-regular-expression.md)—the pattern holds nothing at all.
- [Regex in PHP](../../guides/regex-in-php.md)—delimiters and modifiers from the start.
