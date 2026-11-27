---
description: "A [ opened a character class that no ] closes: a literal [ was not escaped, or the delimiter ends the pattern inside it. The fix for each cause."
head_title: "\"missing terminating ]\" in PHP regex"
parent: /reference/diagnostics/
engine_family: "Compilation failed"
engine_order: 1
quick_fix: "Close the class with ], or escape the ["
quick_fix_order: 8
engine:
  message: "Compilation failed: missing terminating ] for character class at offset 4"
  pattern: "/[a-z/"
codes:
  - regex.charclass.unclosed
---
# Missing terminating ] for character class

A `[` opened a character class, and no `]` closed it before the pattern ended.

```php
preg_match('/[a-z/', $value);
// preg_match(): Compilation failed: missing terminating ] for character class at offset 4
```

The slash after `z` closes the pattern, so the engine receives `[a-z`. It opens a class at the `[` and reaches the end without a `]`. The call returns `false`, and the offset is the end of the pattern. `preg_replace()` and the other functions print the same message under their own name.

## Why PHP prints it

PCRE2 ends a class at the first `]` that is neither escaped nor in first position. Three shapes leave the class open.

**A `]` in first position.**

```php
preg_match('/[]/', $value);
// preg_match(): Compilation failed: missing terminating ] for character class at offset 2
```

`[]` is not an empty class. PCRE2 takes the `]` for a character of the class, then waits for its end. `/[^]/` prints the same message.

**The closing `]` escaped.**

```php
preg_match('/[a-z\]/', $value);
// preg_match(): Compilation failed: missing terminating ] for character class at offset 6
```

The backslash makes the `]` a character of the class.

**A literal `[` not escaped.**

```php
preg_match('/See [note/', $value);
// preg_match(): Compilation failed: missing terminating ] for character class at offset 9
```

The bracket belongs to the text to match. For PCRE2 it opens a class that nothing closes.

## How to fix it

Close the class. When the bracket is text, escape it instead.

```php
// The class closed
preg_match('/[a-z]/', 'abc');
// matches "a"

// A literal ] in the class, then the class closed
preg_match('/[a-z\]]/', ']');
// matches "]"

// A literal [: no class opens
preg_match('/See \[note/', 'See [note');
// matches "See [note"
```

When `[]` meant any character, write `/./`, which stops at a newline, or `/[\s\S]/`, which does not.

## Where it hides

- **An empty variable in a generated pattern.** `'/[' . $chars . ']+'` builds `/[]+/` when `$chars` is empty, and PHP prints the message at offset 3.
- **A class closed after its group.** In `/([a-z)/` the `]` and the `)` are swapped. A `)` inside a class is a character, so the class never closes. `/([a-z])/` compiles.
- **A POSIX class with a bracket lost.** `/[[:alpha:]/` prints the message at offset 10: the class opened by the first `[` needs two `]`.
- **Another language's habit.** JavaScript accepts `[]` and `[^]` as classes. PHP refuses both with this message.

## What PHPRegex reports

- `regex.charclass.unclosed` for `/[a-z/`: `Unclosed character class "]" at end of input.` The library gives the same offset as PHP, and adds the hint: `Close the class with "]", or write "\[" to match a literal "[".`

[Open `/[a-z/` in the playground](/#pattern=%2F%5Ba-z%2F&subject=abc): the Diagnostics tab shows the code, its message, and what PHP says.

## Related

- [Chapter 2: Character Classes & Escapes](../../tutorial/02-character-classes.md): what a class holds, and where `]` and `-` are literal.
- [Range out of order in character class](range-out-of-order-in-character-class.md): the class closes, and a range in it runs backwards.
- [Missing closing parenthesis](missing-closing-parenthesis.md): the same message for a group.
- [No ending delimiter found](no-ending-delimiter.md): what `/[a-z\/` prints instead.
