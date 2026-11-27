---
description: "PHP reads what follows an unescaped delimiter as modifiers. Escape the delimiter, or pick one the pattern does not hold: both causes, with the fixes."
head_title: "PHP preg_match \"Unknown modifier\": how to fix it"
parent: /reference/diagnostics/
engine_family: "The checks of PHP"
engine_order: 1
quick_fix: "Escape the delimiter, or choose one the pattern does not hold"
quick_fix_order: 1
engine:
  message: "Unknown modifier '['"
  pattern: "/^[a-z]+/[0-9]+$/"
codes:
  - regex.delimiter.unescaped
  - regex.flag.unknown
  - regex.flag.removed_e
---
# Unknown modifier

PHP stopped the pattern at its second delimiter, and what follows it is not a list of modifiers.

```php
preg_match('/^[a-z]+/[0-9]+$/', $value);
// preg_match(): Unknown modifier '['
```

The pattern holds three slashes. The first opens it, and the second, after `[a-z]+`, closes it: the pattern is `^[a-z]+`, and PHP reads `[0-9]+$/` as the modifiers. `[` is not one, so the call returns `false` and nothing is compiled. `preg_match_all()`, `preg_replace()`, `preg_split()` and the others print the same warning under their own name; the replacing functions return `null`.

## Why PHP prints it

A delimiter closes the pattern only when nothing escapes it. PHP finds it by looking for the next delimiter without a backslash, and that look knows nothing of a character class, a comment or a quote. Two situations follow.

**A delimiter stands inside the pattern.** The pattern ends there, and the rest is read as modifiers.

```php
preg_match('/https://example\.com/i', $url);
// preg_match(): Unknown modifier '/'
```

**A modifier PHP does not know.** The modifiers are `i`, `m`, `s`, `x`, `u`, `A`, `D`, `S`, `U`, `X`, `J` and `n`, with `r` from PHP 8.4 ([the list on php.net](https://www.php.net/manual/en/reference.pcre.pattern.modifiers.php)). A letter of another language, a capital `I`, or the `e` of PHP 5:

```php
preg_match('/foo/g', $text);
// preg_match(): Unknown modifier 'g'

preg_match('/foo/e', $text);
// preg_match(): Unknown modifier 'e'
```

`e` ran the replacement of `preg_replace()` as PHP code. PHP 7.0 removed it: `preg_replace_callback()` does the same work.

## How to fix it

Escape the delimiter where it stands, or choose a delimiter the pattern does not hold. The second reads better when the delimiter appears more than once, as in a date, a path or a URL.

```php
// The delimiter escaped
preg_match('/^[a-z]+\/[0-9]+$/', $value);

// Another delimiter: nothing to escape
preg_match('#^[a-z]+/[0-9]+$#', $value);

// A URL keeps its slashes
preg_match('~https://example\.com~i', $url);
```

When a variable builds the pattern, `preg_quote()` escapes the delimiter with it: `preg_quote($path, '#')` escapes `#` as well as the metacharacters. A modifier that is not known is a misspelling, or a flag of another language: the `g` of JavaScript and Perl has no counterpart here, since `preg_match_all()` finds every match.

## Where it hides

- **A character class.** `/[/]/` protects nothing: the delimiter ends the pattern inside the class, and PHP reads `]` as the modifier.
- **A comment.** In `/a(?#comment with / inside)b/` the delimiter ends the pattern in the middle of the comment.
- **A `\Q…\E` quote.** In `/\Qa/b\E/` the quoted text is literal to PCRE2, not to PHP.
- **An HTML entity.** A pattern pasted from a page can hold `&#10;` where a newline was meant: PHP reads `&` as a modifier.
- **A doubled delimiter.** `/foo//i` closes the pattern at the second slash, and reads the third as a modifier.

## What PHPRegex reports

PHPRegex reads the pattern as PHP does, and names the cause:

- `regex.delimiter.unescaped` for `/^[a-z]+/[0-9]+$/`: "Unescaped delimiter "/" at position 7 ends the pattern early; what follows is read as modifiers. Escape it as "\/" or use another delimiter."
- `regex.flag.unknown` for `/foo/g`: "Unknown regex flag(s) found: "g"."
- `regex.flag.removed_e` for `/foo/e`: the `e` flag was removed in PHP 7.0, and `preg_replace_callback()` replaces it.

[Open `/^[a-z]+/[0-9]+$/` in the playground](/#pattern=%2F%5E%5Ba-z%5D%2B%2F%5B0-9%5D%2B%24%2F&subject=ab12): the Diagnostics tab shows the code, its message, and what PHP says.

## Related

- [No ending delimiter found](no-ending-delimiter.md), the other message a misplaced delimiter gives.
- [Delimiter must not be alphanumeric, backslash, or NUL byte](delimiter-must-not-be-alphanumeric.md).
- [Regex in PHP](../../guides/regex-in-php.md): delimiters and modifiers from the start.
- [Useless Flag 'i' (Caseless)](../rules/useless-flag-i-caseless.md): the linter also reports a modifier that changes nothing.
