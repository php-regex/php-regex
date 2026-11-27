---
description: "PCRE2 refuses two groups with one name unless duplicates are allowed. Rename one, or set (?J) or the J modifier when both are meant, as in an alternation."
head_title: "\"two named subpatterns have the same name\" in PHP"
parent: /reference/diagnostics/
engine_family: "Compilation failed"
engine_order: 11
quick_fix: "Rename a group, or allow duplicates with (?J)"
quick_fix_order: 6
engine:
  message: "Compilation failed: two named subpatterns have the same name (PCRE2_DUPNAMES not set) at offset 12"
  pattern: "/(?<n>a)(?<n>b)/"
codes:
  - regex.group.duplicate_name
---
# Two named subpatterns have the same name

Two groups of the pattern carry the name `n`, and the second one is refused.

```php
preg_match('/(?<n>a)(?<n>b)/', $value);
// preg_match(): Compilation failed: two named subpatterns have the same name (PCRE2_DUPNAMES not set) at offset 12
```

A name gives its group a key in `$matches`, here `$matches['n']`. PCRE2 compiles one name for one group, and checks each name as it meets it: the offset, counted from the opening delimiter, ends the name that repeats. The parenthetical names the setting that would allow it, duplicate names. `(?J)` at the start of the pattern, or `J` after the closing delimiter, allows it, and the pattern compiles.

## Why PHP prints it

A name is a key of `$matches`. PCRE2 refuses two groups under one key until you allow duplicates.

**A typo.** The second group was meant to have another name:

```php
preg_match('/(?<year>\d{4})-(?<year>\d{2})/', $date);
// preg_match(): Compilation failed: two named subpatterns have the same name (PCRE2_DUPNAMES not set) at offset 23
```

**An alternation.** Two branches describe the same thing, and the code that reads `$matches['minute']` wants one key for both:

```php
preg_match('/(?<hour>\d{1,2}):(?<minute>\d{2})|(?<minute>\d{2})/', $time);
// preg_match(): Compilation failed: two named subpatterns have the same name (PCRE2_DUPNAMES not set) at offset 44
```

The syntax of the name changes nothing: `(?P<n>a)(?<n>b)` and `(?'n'a)(?'n'b)` print the same message.

## How to fix it

```php
// Another name for the second group
preg_match('/(?<first>a)(?<second>b)/', $value);

// Both are meant: duplicates allowed, at the start of the pattern
preg_match('/(?J)(?<hour>\d{1,2}):(?<minute>\d{2})|(?<minute>\d{2})/', $time, $matches);

// Or after the closing delimiter
preg_match('/(?<hour>\d{1,2}):(?<minute>\d{2})|(?<minute>\d{2})/J', $time, $matches);

// A branch reset group: both groups are one group, and no option is needed
preg_match('/(?|(?<n>a)|(?<n>b))/', $value);
```

`(?J)` allows every duplicate of the pattern, a typo included. It fits two alternatives: on `12:30`, `$matches['minute']` holds `30`, and on `45` it holds `45`, since the group of the branch that matched fills the key. When both groups match, the second one is the one kept: `/(?J)(?<n>a)(?<n>b)/` on `ab` gives `$matches['n']` equal to `b`.

## Where it hides

- **Two pieces joined.** Each piece named its group `id`: `/(?<id>\d+)-(?<id>\w+)/` is refused at the second.
- **Another syntax.** `(?P<n>a)(?<n>b)` writes the name in two of the three syntaxes PCRE2 reads, and the name still repeats.
- **Another language.** Ruby accepts `(?<n>a)(?<n>b)`, and JavaScript accepts `(?<n>a)|(?<n>b)`: both are refused here without `(?J)`.
- **A name that differs by case.** `(?<n>a)(?<N>b)` compiles: `n` and `N` are two names.

## What PHPRegex reports

- `regex.group.duplicate_name` for `/(?<n>a)(?<n>b)/`: "Duplicate group name "n" at position 12." The library names the group, gives its position, and adds a hint: "Rename one of the groups, or allow duplicate names with (?J)."

[Open `/(?<n>a)(?<n>b)/` in the playground](/#pattern=%2F%28%3F%3Cn%3Ea%29%28%3F%3Cn%3Eb%29%2F&subject=ab): the Diagnostics tab shows the code, its message, and what PHP says.

## Related

- [Syntax error in subpattern name (missing terminator?)](syntax-error-in-subpattern-name.md): the message of a group name left unterminated.
- [Regex in PHP](../../guides/regex-in-php.md): the `J` modifier in the list of flags.
- [Groups & Alternation](../../tutorial/05-groups-alternation.md): named groups and alternation from the start.
- [Diagnostics and Error Messages](../diagnostics.md): the layers of validation, and every code.
