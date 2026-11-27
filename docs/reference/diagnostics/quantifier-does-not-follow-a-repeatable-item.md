---
description: "A *, +, ? or {n} follows an anchor, a \\K, or another quantifier: there is nothing to repeat. The shapes it takes, and what to write for each."
head_title: "Quantifier does not follow a repeatable item"
parent: /reference/diagnostics/
engine_family: "Compilation failed"
engine_order: 4
engine:
  message: "Compilation failed: quantifier does not follow a repeatable item at offset 3"
  pattern: "/\\b+a/"
codes:
  - regex.quantifier.nothing_to_repeat
---
# Quantifier does not follow a repeatable item

A quantifier follows an anchor, and an anchor matches no character: there is nothing to repeat.

```php
preg_match('/\b+a/', $value);
// preg_match(): Compilation failed: quantifier does not follow a repeatable item at offset 3
```

`\b` is a word boundary. It holds no character for `+` to repeat, so PCRE2 refuses the pattern. The offset is that of the quantifier. `preg_replace()` and the other functions print the same message under their own name.

## Why PHP prints it

PCRE2 repeats the item that stands before a quantifier. An item that matches no character, or no item at all, cannot be repeated.

**An anchor, an assertion or a verb.**

```php
preg_match('/^*/', $value);
// preg_match(): Compilation failed: quantifier does not follow a repeatable item at offset 2
```

`^`, `$`, `\b` and `\B` match a position, and `(*FAIL)` and `\K` match no character either. `/a$+/` and `/\K+/` are refused the same way.

**A branch that starts with the quantifier.**

```php
preg_match('/a|*b/', $value);
// preg_match(): Compilation failed: quantifier does not follow a repeatable item at offset 3
```

After `|` the branch is empty. A quantifier in first position, as in `/*/`, gives the same message.

**Another quantifier.**

```php
preg_match('/a*?+b/', $value);
// preg_match(): Compilation failed: quantifier does not follow a repeatable item at offset 4
```

A `?` or a `+` after a quantifier makes it lazy or possessive: `a*?` and `a*+` are valid. A third character has nothing to repeat.

A lookaround is a group, so it takes a quantifier: `/(?=a)*/` compiles.

## How to fix it

Put the quantifier after the item it repeats, or escape it to match the character itself.

```php
// The quantifier after the letter: a word boundary, then a run of a
preg_match('/\ba+/', 'abc');
// matches "a"

// A literal *, escaped
preg_match('/^\*/', '*note');
// matches "*"

// A lazy quantifier, then a literal +
preg_match('/a*?\+b/', 'aa+b');
// matches "aa+b"

// The alternatives grouped, then repeated
preg_match('/^(?:a|b)*$/', 'abba');
// matches "abba"
```

For `/a*?+b/`, drop the `?` if the possessive `a*+` was meant.

## Where it hides

- **A quantifier character meant as text.** `/^* /` was written for a Markdown bullet, and PHP refuses it at offset 2. Write `/^\* /`.
- **A generated pattern.** `'/^' . $prefix . '+/'` reads `/^+/` when `$prefix` is empty.
- **An inline flag before the quantifier.** `/(?i)*/` prints the message at offset 5: the flags are not an item to repeat.
- **One quantifier character too many.** `/a**/` and `/a{1,2}{2}/` print the message.
- **Another language's habit.** Perl accepts `\b+`. PCRE2 refuses it.

## What PHPRegex reports

- `regex.quantifier.nothing_to_repeat` for `/\b+a/`: `Quantifier "+" cannot be applied to assertion or verb "\b" at position 3.` With no item before the quantifier, as in `/a|*b/`, the message is `Quantifier without target at position 3.` Both come with the hint: `Put the quantifier after the item it repeats, or escape it with a backslash to match it literally.`

[Open `/\b+a/` in the playground](/#pattern=%2F%5Cb%2Ba%2F&subject=abc): the Diagnostics tab shows the code, its message, and what PHP says.

## Related

- [Chapter 4: Quantifiers & Greediness](../../tutorial/04-quantifiers.md): greedy, lazy and counted repeats.
- [Chapter 3: Anchors & Boundaries](../../tutorial/03-anchors-boundaries.md): `^`, `$` and `\b`.
- [Quantified assertion](../rules/quantified-assertion.md): a lookaround that carries a quantifier constrains nothing.
- [Numbers out of order in {} quantifier](numbers-out-of-order-in-quantifier.md): when the two counts of a `{…}` repeat are swapped.
