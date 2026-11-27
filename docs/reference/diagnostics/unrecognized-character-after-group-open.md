---
description: "A (? is followed by a letter PCRE2 does not read there: a misspelt construct, or a syntax of another engine. What may follow (?, and the fix for each."
head_title: "\"unrecognized character after (?\" in PHP regex"
parent: /reference/diagnostics/
engine_family: "Compilation failed"
engine_order: 9
engine:
  message: "Compilation failed: unrecognized character after (? or (?- at offset 3"
  pattern: "/(?qabc)/"
codes:
  - regex.group.syntax
---
# Unrecognized character after (? or (?-

After `(?`, PCRE2 finds a character that opens none of its group syntaxes.

```php
preg_match('/(?qabc)/', $value);
// preg_match(): Compilation failed: unrecognized character after (? or (?- at offset 3
```

A `(` followed by `?` starts a special group, and `q` is neither a flag nor the start of a construct. PCRE2 stops at it. The offset is the character it could not read. `preg_replace()` and the other functions print the same message under their own name.

## Why PHP prints it

Of the letters, only `i`, `m`, `s`, `x`, `n`, `r`, `a`, `J` and `U` are flags after `(?`, and the same letters follow `(?-` to turn them off. `P` and `R` start the forms `(?P<name>`, `(?P=name)`, `(?P>name)` and `(?R)`. The other constructs begin with a sign, as `:` in `(?:`, `=` in `(?=`, `<` in `(?<name>` or a digit in `(?1)`.

**A flag PCRE2 does not have.**

```php
preg_match('/(?u)abc/', $value);
// preg_match(): Compilation failed: unrecognized character after (? or (?- at offset 3
```

`u` is a modifier of PHP, read after the closing delimiter. PCRE2 reads no flag `u`: write `/abc/u`.

**A `(?P` form.**

```php
preg_match('/(?P{name}x)/', $value);
// preg_match(): Compilation failed: unrecognized character after (?P at offset 4
```

After `(?P`, PCRE2 accepts `<`, `>` and `=` only. Here the message names `(?P`.

**A name in other brackets.**

```php
preg_match('/(?{a}x)/', $value);
// preg_match(): Compilation failed: unrecognized character after (? or (?- at offset 3
```

A name goes between `<` and `>`, as in `(?<a>x)`, or between two `'`, as in `(?'a'x)`. The brace opens nothing.

## How to fix it

Write the construct PCRE2 reads for the one you meant. With `/u` after the closing delimiter, `\w` takes an accented letter.

```php
// The modifier after the closing delimiter
preg_match("/^\w+$/u", "\xC3\xA9");
// matches "\xC3\xA9"

// A non-capturing group: the colon restored
preg_match('/^(?:foo|bar)$/', 'bar');
// matches "bar"

// A named group
preg_match('/(?<year>\d{4})/', '2024');
// matches "2024"
```

## Where it hides

- **A `:` dropped from `(?:`.** `/^(?foo|bar)$/` prints the message at offset 4: `f` is no flag.
- **A literal `(` before an escape.** `/^(?\d{3})?$/` was an optional area code. The parenthesis is text: write `/^\(?\d{3}\)?$/`.
- **A comment with a space for `#`.** `/(? comment)/` prints the message at offset 3. A comment is `(?#comment)`.
- **A modifier of PHP written inline.** `(?X)`, `(?A)`, `(?D)` and `(?S)` are modifiers, not inline flags.
- **Another language's habit.** `(?{ code })` is a construct of Perl, and `(?u)` is a flag in Python and Perl. PCRE2 has neither.

## What PHPRegex reports

- `regex.group.syntax` for `/(?qabc)/`: `Invalid group modifier syntax at position 3.` For `/(?P{name}x)/`, the message is `Invalid syntax after (?P at position 4: "<", ">" or "=" is expected.` The library gives the same offset as PHP, and no hint.

[Open `/(?qabc)/` in the playground](/#pattern=%2F%28%3Fqabc%29%2F&subject=abc): the Diagnostics tab shows the code, its message, and what PHP says.

## Related

- [Chapter 5: Groups & Alternation](../../tutorial/05-groups-alternation.md): non-capturing and named groups.
- [Chapter 6: Lookarounds & Assertions](../../tutorial/06-lookarounds.md): the groups that start with `(?=`.
- [Syntax error in subpattern name (missing terminator?)](syntax-error-in-subpattern-name.md): when `(?<` is followed by a name PCRE2 cannot read.
- [Unknown modifier](unknown-modifier.md): the message of a letter after the closing delimiter.
- [The PCRE syntax page on php.net](https://www.php.net/manual/en/reference.pcre.pattern.syntax.php): the section on subpatterns.
