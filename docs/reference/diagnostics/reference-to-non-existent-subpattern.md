---
description: "A backreference or a subroutine call names a group the pattern does not have: \\2 with one group, a misspelt name, a group removed since. Each form, and the fix."
head_title: "\"reference to non-existent subpattern\" in PHP"
parent: /reference/diagnostics/
engine_family: "Compilation failed"
engine_order: 8
quick_fix: "Point at a group the pattern has"
quick_fix_order: 5
engine:
  message: "Compilation failed: reference to non-existent subpattern at offset 5"
  pattern: "/(a)\\2/"
codes:
  - regex.backref.missing_group
  - regex.backref.missing_named_group
  - regex.subroutine.missing_group
  - regex.subroutine.missing_named_group
  - regex.backref.zero
---
# Reference to non-existent subpattern

A backreference or a subroutine call names a group the pattern does not have.

```php
preg_match('/(a)\2/', $value);
// preg_match(): Compilation failed: reference to non-existent subpattern at offset 5
```

`\2` refers to group 2. The pattern has one group, `(a)`, so PCRE2 has nothing for the reference to point at. `preg_replace()` and the other functions print the same message under their own name.

## Why PHP prints it

A reference is resolved when the pattern compiles. The group it names must exist, by number or by name.

**A number above the count of groups.**

```php
preg_match('/(a)(?:b)\2/', $value);
// preg_match(): Compilation failed: reference to non-existent subpattern at offset 10
```

The second group was made non-capturing. `(?:b)` gets no number, and `\2` points past the last group.

**A name the pattern does not have.**

```php
preg_match('/(?<word>\w+)\s+\k<Word>/', $value);
// preg_match(): Compilation failed: reference to non-existent subpattern at offset 18
```

`\k<Word>` and `(?<word>…)` differ by one letter, and a name is case-sensitive.

**A subroutine call to a missing group.**

```php
preg_match('/(a)(?&b)/', $value);
// preg_match(): Compilation failed: reference to non-existent subpattern at offset 6
```

`(?&name)`, `(?P>name)` and `(?1)` call a group as a subroutine. The group must exist as well.

## How to fix it

Point the reference at a group the pattern has, or give the group the name the reference uses.

```php
// The reference points at the only group
preg_match('/(a)\1/', 'aa');
// matches "aa"

// The name spelled as the group spells it
preg_match('/(?<word>\w+)\s+\k<word>/', 'hello hello');
// matches "hello hello"

// The subroutine calls the group the pattern has
preg_match('/(a)(?1)/', 'aa');
// matches "aa"
```

A number moves when a group is added before it: in `/(a)(x)(b)\2/`, `\2` refers to `(x)`. A name stays on its group. For `/(a)(?:b)\2/`, make the group capturing again or renumber the reference.

## Where it hides

- **A group removed since.** `/((a))\3/` is refused after a third group was deleted. `/((a))\2/` compiles.
- **A conditional on a missing group.** `/(a)(?(2)b|c)/` tests group 2, and the group must exist.
- **A relative reference that reaches too far back.** `/(a)\g{-2}/` prints the message: the pattern has one group.
- **A reference before its group.** `/\1(a)/` compiles, since the group exists later in the pattern. The reference is read while the group is still empty, and the pattern finds nothing on `aa`.
- **`\g{0}` written for a recursion.** `/(a)\g{0}/` prints the message. The whole-pattern recursion is `\g<0>`.

## What PHPRegex reports

- `regex.backref.missing_group` for `/(a)\2/`: `Backreference to non-existent group: \2.`
- `regex.backref.missing_named_group` for `/(?<a>x)\k<b>/`: `Backreference to non-existent named group: "b". Did you mean: a?` The suggestion is the hint.
- `regex.subroutine.missing_group` for `/(a)(?2)/`: `Subroutine call to non-existent group: 2.`
- `regex.subroutine.missing_named_group` for `/(a)(?&b)/`: `Subroutine call to non-existent named group: "b".` The hint is: `Name a group "b" with (?<b>...), or call a group the pattern names.`
- `regex.backref.zero` for `/(a)\g{0}/`: `Backreference \g{0} is not valid.` The hint is: `Use \g<0> for recursion to the whole pattern.`

The library gives the same offset as PHP for each of them.

[Open `/(a)\2/` in the playground](/#pattern=%2F%28a%29%5C2%2F&subject=aa): the Diagnostics tab shows the code, its message, and what PHP says.

## Related

- [Chapter 7: Backreferences, Subroutines & Recursion](../../tutorial/07-backreferences-recursion.md): group numbers, names and subroutine calls.
- [Undefined backreference](../rules/undefined-backreference.md): the lint id for the same defect.
- [Useless backreferences](../rules/useless-backreferences.md): a reference written before its group.
- [Backreference written inside a character class](../rules/backreference-written-inside-a-character-class.md): `\1` in a class is an octal escape, not a reference.
- [Syntax error in subpattern name (missing terminator?)](syntax-error-in-subpattern-name.md): when a name cannot be read.
