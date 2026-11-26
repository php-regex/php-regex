---
description: "An inline flag that sets or unsets a modifier already in that state, as (?i) under the i modifier: what the rule reads, and how (?xx) differs from x."
head_title: "Redundant inline flag in a PHP regex"
parent: /reference/rules/
rule_category: "Inline Flags"
rule_order: 1
rule_ids:
  - regex.lint.flag.redundant
---
# Inline Flag Redundant

**Identifier:** `regex.lint.flag.redundant`

**When it triggers:** An inline flag sets/unsets a modifier already in the desired state. `(?xx)` is an option of its own, under which a class drops its spaces and tabs: it is redundant where `(?xx)` holds already, not under the `x` modifier, which is `x` however many times it is written, and `(?x)` after `(?xx)` takes it off, so `/(?xx)(?x)[a b]/` and `/(?xx)[a b]/x` are not reported.

**Example:**
```php
// WARNING: Redundant inline flag
preg_match('/(?i)foo/i', $input);  // Global i already set

// PREFERRED: Remove redundancy
preg_match('/foo/i', $input);
```

---

**Try it.** [Open `/(?i)foo/i` in the playground](/#pattern=%2F%28%3Fi%29foo%2Fi&subject=FOO): the finding is in the Diagnostics tab.
