---
description: "PCRE2 counts at most 65535 repetitions, so a {70000} or a {0,100000} is refused at compile time. Lower the count, or use * or + for an open-ended repeat."
head_title: "\"number too big in {} quantifier\" in PHP"
parent: /reference/diagnostics/
engine_family: "Compilation failed"
engine_order: 5
engine:
  message: "Compilation failed: number too big in {} quantifier at offset 7"
  pattern: "/a{70000}/"
codes:
  - regex.quantifier.too_big
---
# Number too big in {} quantifier

A counted repeat asks for more repetitions than PCRE2 accepts.

```php
preg_match('/a{70000}/', $value);
// preg_match(): Compilation failed: number too big in {} quantifier at offset 7
```

`{70000}` asks for exactly 70000 `a`. PCRE2 counts at most 65535 repetitions: `/a{65535}/` compiles, and `/a{65536}/` prints the same message. `preg_replace()` and the other functions print it under their own name.

## Why PHP prints it

Both numbers of a `{…}` repeat are checked when the pattern compiles, and neither may pass 65535.

**A minimum above the ceiling.**

```php
preg_match('/a{65536,}/', $value);
// preg_match(): Compilation failed: number too big in {} quantifier at offset 7
```

The repeat has no maximum. The minimum alone is too large.

**An upper bound above the ceiling.**

```php
preg_match('/a{0,100000}/', $value);
// preg_match(): Compilation failed: number too big in {} quantifier at offset 10
```

The second number is checked as well: `/a{0,65536}/` and `/a{,65536}/` are refused, and `/a{0,65535}/` compiles.

**A bound that comes from a variable.**

```php
preg_match('/a{1,9223372036854775807}/', $value);
// preg_match(): Compilation failed: number too big in {} quantifier at offset 23
```

The upper bound stands for "no limit". For PCRE2 it is a number too large.

## How to fix it

Lower the count, or use an open-ended repeat when the count stood for any number.

```php
// One or more: the ceiling is gone
preg_match('/a+/', 'aaa');
// matches "aaa"

// At least two, with no ceiling
preg_match('/a{2,}/', 'aa');
// matches "aa"

// An exact count of 70000: two counted repeats
preg_match('/^a{65535}a{4465}$/', str_repeat('a', 70000));
// 1
```

`+` accepts one repetition where `{70000}` demanded 70000: check which of the two the pattern needs. The two counted repeats work for one character or one class. A group repeated that many times meets another limit first: `/(ab){10000}/` prints `regular expression is too large`.

## Where it hides

- **A bound from a variable.** `'/a{1,' . $limit . '}/'` builds `/a{1,9223372036854775807}/` when `$limit` is `PHP_INT_MAX`.
- **Digits in braces meant as text.** `/{70000}/` prints the message too: the braces open a count even with no item before them. Escape them, as in `/\{70000\}/`, which matches `{70000}`.
- **A count on a group.** `/(ab){70000}/` prints the message. Lowering it to 10000 prints another one.
- **Another language's habit.** JavaScript and Python accept `a{70000}`. PHP refuses it.

## What PHPRegex reports

- `regex.quantifier.too_big` for `/a{70000}/`: `Number too big in "{70000}" quantifier: PCRE allows at most 65535 repetitions.` The library gives the same offset as PHP, and the hint: `PCRE counts at most 65535 repetitions: lower the count, or use "*" or "+" for an open-ended repeat.`

[Open `/a{70000}/` in the playground](/#pattern=%2Fa%7B70000%7D%2F&subject=aaa): the Diagnostics tab shows the code, its message, and what PHP says.

## Related

- [Numbers out of order in {} quantifier](numbers-out-of-order-in-quantifier.md): the other message of a count PCRE2 refuses.
- [Regular expression is too large](regular-expression-is-too-large.md): the limit a repeated group meets first.
- [Chapter 4: Quantifiers & Greediness](../../tutorial/04-quantifiers.md): `{n}`, `{n,}` and `{n,m}`.
- [The PCRE syntax page on php.net](https://www.php.net/manual/en/reference.pcre.pattern.syntax.php): the section on repetition.
