---
description: "The name of a named group is not closed by > or ', or holds a character a name cannot: a space, a hyphen, a digit first. The rules of a name, and the fix."
head_title: "\"syntax error in subpattern name\" in PHP regex"
parent: /reference/diagnostics/
engine_family: "Compilation failed"
engine_order: 10
engine:
  message: "Compilation failed: syntax error in subpattern name (missing terminator?) at offset 7"
  pattern: "/(?<name abc)/"
codes:
  - regex.group.name_unterminated
  - regex.group.name_invalid
  - regex.group.name_expected
---
# Syntax error in subpattern name (missing terminator?)

The name of a group runs past the point where PCRE2 expected its end.

```php
preg_match('/(?<name abc)/', $value);
// preg_match(): Compilation failed: syntax error in subpattern name (missing terminator?) at offset 7
```

`(?<` opens a named group, and the name lasts until `>`. A space is not allowed in a name, so PCRE2 stops reading after `name` and finds no `>` there. The offset is where it stopped. `preg_replace()` and the other functions print the same message under their own name.

## Why PHP prints it

A name holds letters, digits and `_` only. It ends with the character that opened it: `>` after `(?<` or `(?P<`, `'` after `(?'`.

**A character a name cannot hold.**

```php
preg_match('/(?<first-name>x)/', $value);
// preg_match(): Compilation failed: syntax error in subpattern name (missing terminator?) at offset 8
```

A space, a hyphen or a dot ends the name before its terminator. Write `first_name`.

**A terminator missing or mismatched.**

```php
preg_match('/(?<x)/', $value);
// preg_match(): Compilation failed: syntax error in subpattern name (missing terminator?) at offset 4
```

The `>` is missing before the `)` that closes the group. `(?'name>x)` closes a name opened by `'` with a `>`, and prints the same message.

**A name that starts with a digit.**

```php
preg_match('/(?<1abc>x)/', $value);
// preg_match(): Compilation failed: subpattern name must start with a non-digit at offset 4
```

The message differs, and the cause is the name again. A name starts with a letter or `_`.

## How to fix it

Close the name with the character that opened it, and keep to letters, digits and `_`.

```php
// The name closed by >
preg_match('/(?<name>abc)/', 'abc');
// matches "abc"

// An underscore where the name held a hyphen
preg_match('/(?<first_name>abc)/', 'abc');
// matches "abc"

// A name that starts with a letter
preg_match('/(?<year_2024>x)/', 'x');
// matches "x"
```

## Where it hides

- **A name built at run time.** `'/(?<' . $key . '>x)/'` builds `/(?<first-name>x)/` when `$key` is an array key with a hyphen, and `/(?<2024>x)/` when it starts with a digit.
- **An empty name.** `'/(?<' . $key . '>x)/'` with `$key = ''` gives `/(?<>x)/`, which prints `subpattern name expected at offset 3`.
- **An accented name without the `u` modifier.** `"/(?<ann\xC3\xA9e>x)/"` prints the message. With `/u` the name compiles.
- **A space in a reference.** `/(?<word>\w+)\s+\k< word>/` prints `subpattern name expected at offset 18`.
- **A name in braces.** `/(?{a}x)/` prints `unrecognized character after (? or (?-`: a `}` closes no name in PHP.

## What PHPRegex reports

- `regex.group.name_unterminated` for `/(?<name abc)/`: `Invalid group name "name abc": the name ends at position 7, and nothing closes it there.`
- `regex.group.name_invalid` for `/(?<1abc>x)/`: `Invalid group name "1abc": names must contain only word characters and must not start with a digit.` The hint is: `Start a group name with a letter or "_", then use letters, digits and "_" only.`
- `regex.group.name_expected` for `/(?<>x)/`: `Expected group name at position 3.`

The library gives the same offset as PHP for each of them, and a hint only for the name that starts with a digit.

[Open `/(?<name abc)/` in the playground](/#pattern=%2F%28%3F%3Cname%20abc%29%2F&subject=abc): the Diagnostics tab shows the code, its message, and what PHP says.

## Related

- [Chapter 5: Groups & Alternation](../../tutorial/05-groups-alternation.md): named groups.
- [Reference to non-existent subpattern](reference-to-non-existent-subpattern.md): the message when the name is well formed and no group has it.
- [Two named subpatterns have the same name](two-named-subpatterns-have-the-same-name.md): the message of a name used twice.
- [Unrecognized character after (? or (?-](unrecognized-character-after-group-open.md): when `(?` is followed by a character PCRE2 cannot read.
