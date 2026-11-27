---
description: "A lookbehind must have a bounded length: (?<=a+) is refused on every PHP, and (?<=a{1,3}) before PHP 8.4. The message by release, and the rewrites that work."
head_title: "\"lookbehind assertion is not fixed length\" in PHP"
parent: /reference/diagnostics/
engine_family: "Compilation failed"
engine_order: 14
quick_fix: "Bound the quantifier, or restart the match with \\K"
quick_fix_order: 4
engine:
  message: "Compilation failed: length of lookbehind assertion is not limited at offset 0"
  pattern: "/(?<=a+)b/"
  also:
    - "Compilation failed: lookbehind assertion is not fixed length at offset 0"
codes:
  - regex.lookbehind.unbounded
  - regex.lookbehind.variable_length_not_supported
---
# Lookbehind assertion is not fixed length

The lookbehind holds a quantifier without a ceiling, and PCRE2 refuses to compile it.

```php
preg_match('/(?<=a+)b/', $value);
// preg_match(): Compilation failed: length of lookbehind assertion is not limited at offset 0
```

A lookbehind needs a maximum length: PCRE2 must know how far back the check can reach, and `a+` has none. The offset, counted from the opening delimiter, is the `(` that opens the lookbehind.

The wording depends on the release. PHP 8.4 and later print this one. Before, on a PCRE2 before 10.43, the same pattern gives `lookbehind assertion is not fixed length at offset 0`.

## Why PHP prints it

A quantifier without a ceiling inside a lookbehind gives it no maximum length. `+`, `*` and `{n,}` are the three. Every lookbehind of the pattern is checked, positive or negative.

**`+`, `*` or `{n,}` in the lookbehind.**

```php
preg_match('/(?<=a{1,})b/', $value);
// preg_match(): Compilation failed: length of lookbehind assertion is not limited at offset 0
```

**A negative lookbehind.** It is refused the same way:

```php
preg_match('/(?<!a+)b/', $value);
// preg_match(): Compilation failed: length of lookbehind assertion is not limited at offset 0
```

**One alternative without a ceiling.** The others may be fixed:

```php
preg_match('/(?<=a+|b)c/', $value);
// preg_match(): Compilation failed: length of lookbehind assertion is not limited at offset 0
```

## How to fix it

Three fixes need no bound:

```php
// Preceded by one or more a is preceded by a: the quantifier goes
preg_match('/(?<=a)b/', $value); // matches "b" in "aaab"

// Or capture the text instead of looking at it
preg_match('/(a+)b/', $value, $matches); // matches "aaab", and $matches[1] holds "aaa"

// Or match the text, then restart the match at b
preg_match('/a+\Kb/', $value, $matches); // matches "b"
```

`(?<=a)b` says what `(?<=a+)b` meant: the text before `b` ends with `a+` exactly when it ends with `a`. The capture puts the `a`s in `$matches[0]`. With `\K`, `$matches[0]` is `b` as with a lookbehind, but the `a`s are consumed: `/^a+\Kb$/` matches `aaab`, where `/^(?<=a)b$/` matches nothing.

The fourth fix gives the quantifier a ceiling, and works from PHP 8.4: `(?<=a{1,3})b` is accepted from PHP 8.4 and refused before, while `(?<=a|bb)`, two alternatives of different lengths, is accepted on every PHP 8.

```php
// A ceiling, from PHP 8.4
preg_match('/(?<=a{1,3})b/', $value); // matches "b" in "aaab"
```

The ceiling has one of its own, at 255 characters. `/(?<=a{1,255})b/` compiles, and `/(?<=a{1,256})b/` is refused with another message:

```php
preg_match('/(?<=a{1,256})b/', $value);
// preg_match(): Compilation failed: branch too long in variable-length lookbehind assertion at offset 0
```

## Where it hides

- **A lookbehind written for JavaScript.** JavaScript accepts `/(?<=a+)b/`, and matches `b` in `aaab`.
- **A quantifier deeper in the lookbehind.** `/(?<=\w+)b/` and `/(?<=ab+)b/` give the same message.
- **A count without a ceiling.** `/(?<=\d{3,})b/`: `{3,}` is refused like `+`.
- **A negative lookbehind.** `/(?<!a+)b/` asks for what `/(?<!a)b/` says: not preceded by one `a` is not preceded by `a+`.

## What PHPRegex reports

- `regex.lookbehind.unbounded` for `/(?<=a+)b/`: "Lookbehind is unbounded. PCRE requires a bounded maximum length." The library adds the offset and a hint: "Use a bounded quantifier instead of "+"."
- `regex.lookbehind.variable_length_not_supported` for `/(?<=a{1,3})b/` when the pattern is checked for a PHP before 8.4, with `php_version` set to `8.3`: "Variable-length lookbehind needs PCRE2 10.43, which PHP bundles from 8.4." The hint follows: "Give each branch of the lookbehind a fixed length, or target PHP 8.4+."

[Open `/(?<=a+)b/` in the playground](/#pattern=%2F%28%3F%3C%3Da%2B%29b%2F&subject=aaab): the Diagnostics tab shows the code, its message, and what PHP says.

## Related

- [Lookarounds & Assertions](../../tutorial/06-lookarounds.md): the chapter, with the constraints of a lookbehind.
- [Quantified Assertion](../rules/quantified-assertion.md): the linter also reports a quantifier written on the lookaround itself.
- [Diagnostics and Error Messages](../diagnostics.md): the layers of validation, the semantic layer among them.
