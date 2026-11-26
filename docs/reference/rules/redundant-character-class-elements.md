---
description: "A character class with duplicate elements or overlapping ranges, as [a-zA-Za-z]: what the rule reports, and the clean class to write instead."
head_title: "Redundant elements in a PHP regex character class"
parent: /reference/rules/
rule_category: "Character Classes"
rule_order: 1
rule_ids:
  - regex.lint.charclass.redundant
---
# Redundant Character Class Elements

**Identifier:** `regex.lint.charclass.redundant`

**When it triggers:** A character class contains redundant elements or overlapping ranges.

**Example:**
```php
// WARNING: Redundant elements detected in character class.
// ↳ Redundant elements: range 'a'-'z' (overlaps 'a'-'z')
preg_match('/[a-zA-Za-z]/', $input);

// PREFERRED: Remove duplicates
preg_match('/[a-zA-Z]/', $input);

// WARNING: Redundant elements detected in character class.
// ↳ Redundant elements: range 'c'-'d' (covered by range 'a'-'f')
preg_match('/[a-fc-d]/', $input);

// PREFERRED: Use clean ranges
preg_match('/[a-f]/', $input);

// WARNING: Redundant elements detected in character class.
// ↳ Redundant elements: ranges 'a'-'m' and 'k'-'z' overlap: merge them into 'a'-'z'
preg_match('/[a-mk-z]/', $input);

// PREFERRED: One range
preg_match('/[a-z]/', $input);
```

The hint (`↳`) names what to change: a repeated range is named with the one it repeats, a range another one covers is named with the range that covers it, and two ranges that only partly overlap, which are both needed as written, come with the range to merge them into.

**Fix:** Remove duplicates or merge ranges.

---

**Try it.** [Open `/[a-zA-Za-z]/` in the playground](/#pattern=%2F%5Ba-zA-Za-z%5D%2F&subject=a): the finding is in the Diagnostics tab.
