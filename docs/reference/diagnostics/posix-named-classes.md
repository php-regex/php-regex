---
description: "A POSIX class goes inside a class: [[:alpha:]], never [:alpha:]. The message for one outside, the unknown POSIX class name one, and the classes PCRE2 knows."
head_title: "POSIX named classes: only within a class"
parent: /reference/diagnostics/
engine_family: "Compilation failed"
engine_order: 17
engine:
  message: "Compilation failed: POSIX named classes are supported only within a class at offset 9"
  pattern: "/[:alpha:]+/"
codes:
  - regex.posix.outside_class
  - regex.posix.invalid
---
# POSIX named classes are supported only within a class

A POSIX class stands in the pattern on its own, without the brackets of a character class around it.

```php
preg_match('/[:alpha:]+/', $value);
// preg_match(): Compilation failed: POSIX named classes are supported only within a class at offset 9
```

`[[:alpha:]]` is a character class with one member, the POSIX class `alpha`. Alone, `[:alpha:]` has no class around it. PCRE2 recognizes the form and refuses it at the `]` that ends it: offset 9, counted from the opening delimiter.

## Why PHP prints it

A POSIX class is written `[:name:]`, and goes inside a character class, where it stands for a set of characters.

**The outer brackets are missing.**

```php
preg_match('/\d{4}[:digit:]\d{2}/', $date);
// preg_match(): Compilation failed: POSIX named classes are supported only within a class at offset 14
```

**A name PCRE2 does not know.** Inside a class, the name is checked:

```php
preg_match('/[[:foo:]]/', $value);
// preg_match(): Compilation failed: unknown POSIX class name at offset 8
```

The names are case-sensitive, and PCRE2 knows fourteen: `alpha`, `digit`, `alnum`, `upper`, `lower`, `space`, `blank`, `punct`, `print`, `graph`, `cntrl`, `xdigit`, `word` and `ascii`. `[^[:alpha:]]` and `[[:^alpha:]]` negate the class. `[[.a.]]` and `[[=a=]]` are refused with another message, `POSIX collating elements are not supported`.

## How to fix it

```php
// The POSIX class, inside a class of its own
preg_match('/^[[:alpha:]]+$/', 'abc'); // matches "abc"

// The same set, written with a shorthand
preg_match('/^[a-zA-Z]+$/', 'abc'); // matches "abc"

// With other members in the class
preg_match('/^[[:alnum:]_]+$/', 'ab_9'); // matches "ab_9"
```

`[[:alpha:]]` and `[a-zA-Z]` differ under the `u` modifier: `é` matches the first and not the second.

## Where it hides

- **Another language.** In JavaScript, `[:alpha:]+` is a plain class of the characters `:`, `a`, `l`, `p` and `h`: it matches `:a` in `x:a`.
- **A negation.** `[^:alpha:]` compiles, and means something else: a negated class of the characters `:`, `a`, `l`, `p`, `h`. In `ab`, it matches `b`, where `[^[:alpha:]]` matches nothing.
- **A colon missing.** `[[:alpha]]` compiles too. The class holds `[`, `:`, `a`, `l`, `p`, `h`, and the last `]` is a literal: it matches `a]`, and not `a`.
- **A misspelt name.** `[[:alphanumeric:]]`, `[[:number:]]` and `[[:Alpha:]]` are refused as unknown.

## What PHPRegex reports

- `regex.posix.outside_class` for `/[:alpha:]+/`: "POSIX named classes are supported only within a class." The library gives the offset, 9 here, and no hint.
- `regex.posix.invalid` for `/[[:foo:]]/`: "Invalid POSIX class: "foo"." The library gives the offset, 8 here, and no hint.

[Open `/[:alpha:]+/` in the playground](/#pattern=%2F%5B%3Aalpha%3A%5D%2B%2F&subject=abc): the Diagnostics tab shows the code, its message, and what PHP says.

## Related

- [`Missing terminating ] for character class`](missing-terminating-bracket-for-character-class.md): the message of a class left open.
- [Character Classes](../../tutorial/02-character-classes.md): classes, ranges and shorthand.
- [Alternation-like Character Classes](../rules/alternation-like-character-classes.md): a `|` inside a class is a literal character too.
- [Multibyte Character in a Class](../rules/multibyte-character-in-a-class.md): a class and the `u` modifier.
