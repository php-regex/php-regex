---
description: "A range in [...] runs from a higher character to a lower one, as [z-a] or [9-0]: PCRE2 refuses it at compile time. Swap the ends, or escape the hyphen."
head_title: "\"range out of order in character class\" in PHP"
parent: /reference/diagnostics/
engine_family: "Compilation failed"
engine_order: 7
engine:
  message: "Compilation failed: range out of order in character class at offset 4"
  pattern: "/[z-a]/"
codes:
  - regex.range.reversed
  - regex.range.invalid_bounds
---
# Range out of order in character class

A range in a character class runs from a character to one that comes before it.

```php
preg_match('/[z-a]/', $value);
// preg_match(): Compilation failed: range out of order in character class at offset 4
```

`[z-a]` asks for the characters from `z` down to `a`. PCRE2 reads a range upwards, from the lower character to the higher one, and finds nothing in it. The offset is the end of the range. `preg_replace()` and the other functions print the same message under their own name.

## Why PHP prints it

Inside `[…]` a hyphen between two characters makes a range, and its first character must not come after its last.

**A range typed backwards.**

```php
preg_match('/[9-0]/', $value);
// preg_match(): Compilation failed: range out of order in character class at offset 4
```

`[9-0]` is `[0-9]` read the other way.

**A capital where a small letter was meant.**

```php
preg_match('/[a-Z]/', $value);
// preg_match(): Compilation failed: range out of order in character class at offset 4
```

The capital letters come before the small ones, so `a-Z` runs downwards. `[A-Za-z]` names both cases.

**Escapes out of order.**

```php
preg_match('/[\x{61}-\x{41}]/', $value);
// preg_match(): Compilation failed: range out of order in character class at offset 14
```

`\x{61}` is `a` and `\x{41}` is `A`: the same range as `a-A`.

A shorthand class or a POSIX class as an end of a range gives another message: `/[\d-a]/` prints `invalid range in character class`.

## How to fix it

Swap the two ends, or write both cases.

```php
// The lower character first
preg_match('/[0-9]/', '5');
// matches "5"

// Both cases, each range in order
preg_match('/[A-Za-z]/', 'Hello');
// matches "H"

// A literal hyphen: escaped
preg_match('/[a\-z]/', '-');
// matches "-"
```

For `[\d-a]`, decide what the hyphen means. A digit or a hyphen is `[\d-]`.

## Where it hides

- **A range built at run time.** `'/[' . $last . '-' . $first . ']/'` builds `/[z-a]/` when its two ends are passed in the wrong order.
- **Numbers written as a range.** `/[12-1]/` prints the message: a class holds characters, so PCRE2 reads the range `2-1`.
- **Both cases in one range.** `/[a-Z]/` is refused. `[A-z]` compiles, and takes the punctuation between `Z` and `a` too.
- **An accented character as the first end.** `"/[\xC3\xA9-a]/u"`, a range from `é` down to `a`, prints the message. `"/[a-\xC3\xA9]/u"` compiles.

## What PHPRegex reports

- `regex.range.reversed` for `/[z-a]/`: `Invalid range "z-a": start character comes after end character.` The library gives the same offset as PHP, and the hint: `Write the lower end of the range first, or escape the "-" as "\-" to match it literally.`
- `regex.range.invalid_bounds` for `/[\d-a]/`, which PHP refuses with `invalid range in character class`: `Invalid range in character class: a character type, POSIX class, or Unicode property cannot be a range endpoint at position 4.`

[Open `/[z-a]/` in the playground](/#pattern=%2F%5Bz-a%5D%2F&subject=abc): the Diagnostics tab shows the code, its message, and what PHP says.

## Related

- [Chapter 2: Character Classes & Escapes](../../tutorial/02-character-classes.md): ranges and the hyphen in a class.
- [Suspicious ASCII ranges](../rules/suspicious-ascii-ranges.md): `[A-z]` also takes the punctuation between `Z` and `a`.
- [Missing terminating \] for character class](missing-terminating-bracket-for-character-class.md): the class that never closes.
