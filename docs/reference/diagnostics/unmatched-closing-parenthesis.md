---
description: "A ) closes no group: it is meant as a literal, or the group it closed was removed. Escape it as \\), put it in a class, or restore the opening parenthesis."
head_title: "\"unmatched closing parenthesis\" in PHP regex"
parent: /reference/diagnostics/
engine_family: "Compilation failed"
engine_order: 3
engine:
  message: "Compilation failed: unmatched closing parenthesis at offset 4"
  pattern: "/abc)/"
codes:
  - regex.group.unmatched_close
---
# Unmatched closing parenthesis

A `)` closes no group: none is open when PCRE2 reads it.

```php
preg_match('/abc)/', $value);
// preg_match(): Compilation failed: unmatched closing parenthesis at offset 4
```

The pattern opens no group, so the `)` has nothing to close. The offset is that of the `)` itself. `preg_replace()` and the other functions print the same message under their own name.

## Why PHP prints it

A `)` that is neither escaped nor inside a class closes the group opened by the last `(`. When no group is open, PCRE2 stops at it.

**A literal `)` not escaped.**

```php
preg_match('/Price \(USD)/', $value);
// preg_match(): Compilation failed: unmatched closing parenthesis at offset 12
```

Both parentheses belong to the text, and only the first is escaped. It opens no group, so the second closes none.

**One `)` too many.**

```php
preg_match('/(abc))/', $value);
// preg_match(): Compilation failed: unmatched closing parenthesis at offset 6
```

The first `)` closes the group. The second finds nothing left to close.

**A `(` removed, its `)` left.**

```php
preg_match('/ab)cd/', $value);
// preg_match(): Compilation failed: unmatched closing parenthesis at offset 3
```

The group that stood before `b` was deleted or replaced, and its closing parenthesis stayed.

## How to fix it

Escape a parenthesis that belongs to the text, or restore the group.

```php
// A literal ) escaped
preg_match('/abc\)/', 'abc)');
// matches "abc)"

// A class holds the parenthesis as a character
preg_match('/[)]/', 'abc)');
// matches ")"

// The opening parenthesis restored
preg_match('/(abc)/', 'abc');
// matches "abc"
```

`/Price \(USD\)/` escapes both and matches `Price (USD)`. Restoring the `(` adds a group: a numbered backreference that follows moves to another group.

## Where it hides

- **An emoticon in the text to match.** `/:)/` prints the message at offset 2. Write `/:\)/`.
- **An optional parenthesis held by a class.** In `/[(]?\d{3})/` the `(` is a character of a class and opens no group, so the `)` closes nothing. Write `/[(]?\d{3}[)]?/`.
- **A call to match, written `name()`.** `/name\()/` escapes the opening parenthesis alone, and the closing one is unmatched. Escape both, as in `/name\(\)/`.
- **A generated pattern.** `'/ab' . $part . ')cd/'` builds `/ab)cd/` when `$part` lost its `(`.

## What PHPRegex reports

- `regex.group.unmatched_close` for `/abc)/`: `Unmatched closing parenthesis at position 4.` The position is the offset PHP gives. The library adds no hint.

[Open `/abc)/` in the playground](/#pattern=%2Fabc%29%2F&subject=abc): the Diagnostics tab shows the code, its message, and what PHP says.

## Related

- [Missing closing parenthesis](missing-closing-parenthesis.md): the message of a group left open.
- [Chapter 5: Groups & Alternation](../../tutorial/05-groups-alternation.md): capturing, non-capturing and named groups.
- [Chapter 2: Character Classes & Escapes](../../tutorial/02-character-classes.md): where a parenthesis is a plain character.
- [Unrecognized character after (? or (?-](unrecognized-character-after-group-open.md): when `(?` is followed by a character PCRE2 cannot read.
