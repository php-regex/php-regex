---
description: "A counted repeat is written with its maximum first, as {5,2}, and PCRE2 refuses it at compile time. Write the smaller count first, {2,5}."
head_title: "\"numbers out of order in {} quantifier\" in PHP"
parent: /reference/diagnostics/
engine_family: "Compilation failed"
engine_order: 6
quick_fix: "Write the smaller count first"
quick_fix_order: 7
engine:
  message: "Compilation failed: numbers out of order in {} quantifier at offset 5"
  pattern: "/a{5,2}/"
codes:
  - regex.quantifier.invalid_range
---
# Numbers out of order in {} quantifier

A counted repeat gives its maximum first: PCRE2 reads `{5,2}` as at least 5 and at most 2.

```php
preg_match('/a{5,2}/', $value);
// preg_match(): Compilation failed: numbers out of order in {} quantifier at offset 5
```

In `{min,max}` the first number is the minimum and the second the maximum. Here the minimum is the larger one, so no count satisfies the repeat. The offset is the end of the maximum. `preg_replace()` and the other functions print the same message under their own name.

## Why PHP prints it

The rule has one form: the minimum is greater than the maximum. Equal counts are accepted: `/a{2,2}/` compiles. The ways of writing the wrong order are several.

**The two counts swapped.**

```php
preg_match('/\d{10,2}/', $value);
// preg_match(): Compilation failed: numbers out of order in {} quantifier at offset 7
```

`\d{10,2}` reads as ten to two digits. Ten digits is `\d{10}`, and two to ten is `\d{2,10}`.

**An upper bound typed where the lower one stands.**

```php
preg_match('/a{1,0}/', $value);
// preg_match(): Compilation failed: numbers out of order in {} quantifier at offset 5
```

`{1,0}` reads as one to zero. At most one is `{0,1}`, or `?`.

## How to fix it

Write the smaller count first.

```php
// Two to five a
preg_match('/a{2,5}/', 'aaa');
// matches "aaa"

// An exact count: one number
preg_match('/\d{10}/', '0123456789');
// matches "0123456789"

// At most one a
preg_match('/a{0,1}/', 'a');
// matches "a"
```

Where `{5,2}` stood for an exact count, one number is enough: `/a{5}/`.

When two variables build the count, sort them on the way in. `min()` and `max()` hide which bound was wrong, so check the two values when they come from different places.

```php
// The smaller count first, whichever variable holds it
preg_match('/a{' . min($min, $max) . ',' . max($min, $max) . '}/', $value);
```

## Where it hides

- **A generated pattern.** `'/a{' . $max . ',' . $min . '}/'` builds `/a{5,2}/` when the two bounds are passed in the wrong order.
- **A count in a class, a group or a lookbehind.** `/[0-9]{2,1}/`, `/(ab){5,2}/` and `/(?<=a{5,2})b/` print the message: the two numbers are checked wherever the count stands.
- **Another language's habit.** Perl compiles `a{5,2}` without an error, and warns under `use warnings` that the quantifier can never match. PHP refuses the pattern.

## What PHPRegex reports

- `regex.quantifier.invalid_range` for `/a{5,2}/`: `Invalid quantifier range "{5,2}": min > max.` The library gives the same offset as PHP, and the hint: `Write the smaller count first: "{2,5}".`

[Open `/a{5,2}/` in the playground](/#pattern=%2Fa%7B5%2C2%7D%2F&subject=aaa): the Diagnostics tab shows the code, its message, and what PHP says.

## Related

- [Number too big in {} quantifier](number-too-big-in-quantifier.md): the other message of a count PCRE2 refuses.
- [Chapter 4: Quantifiers & Greediness](../../tutorial/04-quantifiers.md): `{n}`, `{n,}` and `{n,m}`.
- [Quantifier does not follow a repeatable item](quantifier-does-not-follow-a-repeatable-item.md): what a `{…}` prints when nothing precedes it.
