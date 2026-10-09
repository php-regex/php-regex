---
description: "One fix per common PHPRegex diagnostic: unbounded lookbehinds, bad backreferences, nested quantifiers, useless flags and delimiter traps."
---
# Diagnostics Cheat Sheet

Fast fixes for the most common PHPRegex diagnostics. Use this as a quick reference when you encounter an issue.

## Quick Fix Index

| Diagnostic                                                                  | Quick Fix                   |
|-----------------------------------------------------------------------------|-----------------------------|
| [Lookbehind is unbounded](#lookbehind-is-unbounded)                         | Add bounds or use `\K`      |
| [Backreference to non-existent group](#backreference-to-non-existent-group) | Check group numbers/names   |
| [Duplicate group name](#duplicate-group-name)                               | Use unique names            |
| [Invalid quantifier range](#invalid-quantifier-range)                       | Swap min/max                |
| [Nested quantifiers detected](#nested-quantifiers-detected)                 | Use atomic groups           |
| [Dot-star in repetition](#dot-star-in-repetition)                           | Use atomic/possessive       |
| [Overlapping alternation branches](#overlapping-alternation-branches)       | Atomic or simplify          |
| [Duplicate alternation branch](#duplicate-alternation-branch)               | Remove duplicate            |
| [Empty alternative](#empty-alternative)                                     | Use `?` quantifier          |
| [Redundant non-capturing group](#redundant-non-capturing-group)             | Remove group                |
| [Suspicious ASCII range](#suspicious-ascii-range)                           | Split A-Z and a-z           |
| [Alternation-like character class](#alternation-like-character-class)       | Use (foo\|bar)              |
| [Useless backreference](#useless-backreference)                             | Move or remove              |
| [Concatenated quantifiers](#concatenated-quantifiers)                       | Tighten or drop quantifier  |
| [Useless flag](#useless-flag)                                               | Remove flag                 |
| [Invalid delimiter](#invalid-delimiter)                                     | Use proper delimiter        |
| [Delimiter inside a class or comment](#delimiter-inside-a-class-or-comment) | Escape it or change delimiter |
| [Pattern Too Long](#pattern-too-long)                                       | Raise the limit or shorten  |

---

## Lookbehind is unbounded

**Problem:** PCRE requires lookbehinds to have a bounded maximum length.

```php
// ERROR
preg_match('/(?<=a+)b/', $input);

// FIX 1: Use a fixed length
preg_match('/(?<=a{100})b/', $input);

// FIX 2: Use a bounded quantifier (variable length needs PCRE2 10.43+, PHP 8.4)
preg_match('/(?<=a{1,100})b/', $input);

// FIX 3: Restart the match after the run
preg_match('/a+\Kb/', $input);  // matches "b" on "aaab"
```

---

## Backreference to non-existent group

**Problem:** The backreference points to a group that doesn't exist.

```php
// ERROR: \2 doesn't exist (only one group)
preg_match('/(\w+)\2/', $input);

// FIX 1: Use correct group number
preg_match('/(\w+)\1/', $input);

// FIX 2: Add the missing group
preg_match('/(\w+)(\w+)\2/', $input);  // Now \2 exists

// For named backreferences
// ERROR: No group named 'name'
preg_match('/(?<name>\w+)\k<other>/', $input);

// FIX: Use correct name
preg_match('/(?<name>\w+)\k<name>/', $input);
```

---

## Duplicate group name

**Problem:** Named groups must have unique names (unless `(?J)` is set).

```php
// ERROR: 'id' appears twice
preg_match('/(?<id>\w+)(?<id>\d+)/', $input);

// FIX 1: Use unique names
preg_match('/(?<id>\w+)(?<number>\d+)/', $input);

// FIX 2: Enable J flag for duplicates
preg_match('/(?J)(?<id>\w+)(?<id>\d+)/', $input);
```

---

## Invalid quantifier range

**Problem:** Quantifier minimum exceeds maximum.

```php
// ERROR: {5,2} is invalid
preg_match('/\d{5,2}/', $input);

// FIX: Swap to {2,5}
preg_match('/\d{2,5}/', $input);
```

---

## Nested quantifiers detected

**Problem:** Nested variable quantifiers can cause ReDoS.

```php
// WARNING: (a+)+ can explode
preg_match('/(a+)+b/', $input);

// FIX 1: Use atomic group
preg_match('/(?>a+)+b/', $input);

// FIX 2: Use possessive quantifier
preg_match('/(a++)+b/', $input);

// FIX 3: Simplify (often equivalent)
preg_match('/a+b/', $input);
```

---

## Dot-star in repetition

**Problem:** `.*` inside `+` or `*` can cause extreme backtracking.

```php
// RISKY: .* in + repetition
preg_match('/(?:.*)+/', $input);

// FIX 1: A possessive dot-star reads the run once, no outer repetition
preg_match('/.*+/', $input);

// FIX 2: Use a specific character class
preg_match('/[^x]*x/', $input);  // Reads up to the next 'x' at once
```

---

## Overlapping alternation branches

**Problem:** One branch is a prefix of another inside repetition.

```php
// WARNING: 'a' and 'aa' overlap
preg_match('/(a|aa)+b/', $input);

// FIX 1: Use atomic group
preg_match('/(?>a|aa)+b/', $input);

// FIX 2: Simplify
preg_match('/a+b/', $input);
```

---

## Duplicate alternation branch

**Problem:** The same alternative appears more than once.

```php
// WARNING: Duplicate branch
preg_match('/(foo|foo)/', $input);

// FIX: Keep one copy
preg_match('/foo/', $input);
```

---

## Empty alternative

**Problem:** An alternation includes an empty branch (e.g., trailing `|`).

```php
// WARNING: Empty alternative
preg_match('/foo|/', $input);

// FIX: Use a quantifier instead — the whole branch becomes optional
preg_match('/(?:foo)?/', $input);
```

---

## Redundant non-capturing group

**Problem:** Group wraps single token without changing behavior.

```php
// WARNING
preg_match('/(?:foo)/', $input);

// FIX: Remove group
preg_match('/foo/', $input);
```

**When groups ARE needed:**
```php
// Needed: Change precedence
preg_match('/(?:foo|bar)baz/', $input);  // Groups foo|bar

// Needed: Apply quantifier to multiple
preg_match('/(?:foo)+/', $input);  // Repeats "foo"
```

---

## Suspicious ASCII range

**Problem:** `[A-z]` spans ASCII punctuation between `Z` and `a`.

```php
// WARNING
preg_match('/[A-z]/', $input);

// FIX
preg_match('/[A-Za-z]/', $input);
```

---

## Alternation-like character class

**Problem:** `|` is literal inside `[]`, so `[error|failure]` matches single characters.

```php
// WARNING
preg_match('/[error|failure]/', $input);

// FIX
preg_match('/(error|failure)/', $input);
```

---

## Useless backreference

**Problem:** The backreference is used before its group is set or the group is always empty.

```php
// WARNING: Backreference before group closes
preg_match('/\1(a)/', $input);

// FIX: Move the backreference
preg_match('/(a)\1/', $input);
```

---

## Concatenated quantifiers

**Problem:** Adjacent quantifiers can be simplified when one set is a subset of the other.

```php
// WARNING: \d is a subset of \w
preg_match('/\d+\w+/', $input);

// FIX: Tighten the smaller quantifier
preg_match('/\d\w+/', $input);

// WARNING: \d* before \w* can be dropped — \w covers it
preg_match('/\d*\w*/', $input);

// FIX: Drop the whole quantified term
preg_match('/\w*/', $input);
```

---

## Useless flag

**Problem:** Flag has no effect on the pattern.

```php
// WARNING: 's' flag (DotAll) does nothing
preg_match('/^\d+$/s', $input);

// FIX: Remove unused flag
preg_match('/^\d+$/', $input);

// WARNING: 'm' flag does nothing
preg_match('/foo/m', $input);

// FIX: Remove or add anchors
preg_match('/foo/', $input);  // or
preg_match('/^foo$/m', $input);  // with anchors

// WARNING: 'i' flag does nothing
preg_match('/^\d{4}-\d{2}-\d{2}$/i', $input);

// FIX: Remove
preg_match('/^\d{4}-\d{2}-\d{2}$/', $input);

// WARNING: 'D' flag does nothing (no $, or every $ under m)
preg_match('/^\d+\z/D', $input);

// WARNING: 'x' flag does nothing (no whitespace, no # comment)
preg_match('/^\d+$/x', $input);
```

---

## Invalid delimiter

**Problem:** The pattern opens with a character PHP refuses as a delimiter: alphanumeric, a backslash or a NUL byte.

```php
// ERROR: "a" is alphanumeric and cannot delimit
preg_match('a{2}b{2}a', $input);
// PHP warning: Delimiter must not be alphanumeric, backslash, or NUL byte

// FIX 1: Use a non-alphanumeric delimiter
preg_match('/a{2}b{2}a/', $input);

// FIX 2: Use a delimiter the pattern does not contain
preg_match('#a{2}b{2}a#', $input);
```

A space inside the pattern is not a delimiter problem: `/^pattern $/` is valid
and matches `"pattern "` — the space is a literal character of the pattern.

---

## Delimiter inside a class or comment

**Problem:** PHP ends the pattern at the first delimiter that is not escaped, even inside `[...]`, `(?#...)` or `\Q...\E`.
What follows becomes modifiers, so the call fails with a warning and returns `null` or `false`.

```php
// ERROR: the "/" in "://" and in the class ends the pattern ("Unknown modifier")
preg_replace('/([[:alnum:]]+):\/\/([[:alnum:]#?/&=]+)/i', '<$0>', $text);

// FIX 1: Escape every delimiter, classes included
preg_replace('/([[:alnum:]]+):\/\/([[:alnum:]#?\/&=]+)/i', '<$0>', $text);

// FIX 2: Use a delimiter the pattern does not contain
preg_replace('#([[:alnum:]]+)://([[:alnum:]\#?/&=]+)#i', '<$0>', $text);
```

---

## Pattern Too Long

**Problem:** Pattern exceeds configured maximum length.

```php
use PHPRegex\Toolkit\Regex;

// ERROR: Pattern too long
preg_match('/very long pattern.../', $input);

// FIX 1: Increase limit (if appropriate)
$regex = Regex::create(['max_pattern_length' => 500000]);

// FIX 2: Shorten the pattern
// Consider splitting or simplifying
```

---

## Where to Look Next

| Topic                 | Resource                                        |
|-----------------------|-------------------------------------------------|
| Rule reference        | [Lint rule reference](rules.md)                 |
| Diagnostics deep dive | [Diagnostics and error messages](diagnostics.md) |
| ReDoS patterns        | [ReDoS guide](../guides/redos.md)               |
| API reference         | [API reference](api.md)                         |
