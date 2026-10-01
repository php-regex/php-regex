# Diagnostics Cheat Sheet

Fast fixes for the most common PhpRegex diagnostics. Use this as a quick reference when you encounter an issue.

## Quick Fix Index

| Diagnostic                                                                  | Quick Fix                   |
|-----------------------------------------------------------------------------|-----------------------------|
| [Lookbehind is unbounded](#lookbehind-is-unbounded)                         | Add bounds or use lookahead |
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
| [Concatenated quantifiers](#concatenated-quantifiers)                       | Tighten quantifier          |
| [Useless flag](#useless-flag)                                               | Remove flag                 |
| [Invalid delimiter](#invalid-delimiter)                                     | Use proper delimiter        |

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

// FIX 3: Use lookahead instead
preg_match('/(?=(a+))b\1/', $input);
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
// ERROR: (a+)+ can explode
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
preg_match('/(?:.*)+x/', $input);

// FIX 1: Make atomic or possessive
preg_match('/(?>.*)x/', $input);
preg_match('/.*+x/', $input);

// FIX 2: Use specific character class
preg_match('/[^x]*x/', $input);  // If matching until 'x'
```

---

## Overlapping alternation branches

**Problem:** One branch is a prefix of another inside repetition.

```php
// ERROR: 'a' and 'aa' overlap
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

// FIX: Use a quantifier instead
preg_match('/foo?/', $input);
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
```

---

## Invalid delimiter

**Problem:** The delimiter character is not valid.

```php
// ERROR: Space not valid as delimiter
preg_match('/^pattern $/', $input);

// FIX 1: Use valid delimiter
preg_match('/^pattern$/', $input);

// FIX 2: Escape the delimiter
preg_match('!^pattern$!', $input);

// FIX 3: Use different delimiter
preg_match('#^pattern$#', $input);
```

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
| Rule reference        | [docs/reference.md](../reference.md)            |
| Diagnostics deep dive | [docs/reference/diagnostics.md](diagnostics.md) |
| ReDoS patterns        | [docs/REDOS_GUIDE.md](../REDOS_GUIDE.md)        |
| API reference         | [docs/reference/api.md](api.md)                 |

---

Previous: [FAQ and Glossary](faq-glossary.md) | Next: [Reference Index](README.md)
