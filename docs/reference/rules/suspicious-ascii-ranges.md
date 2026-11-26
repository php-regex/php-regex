---
description: "The range A-z spans the ASCII punctuation between Z and a: write [A-Za-z] for the letters alone. What the rule reports, and how to fix it."
head_title: "[A-z] in PHP regex also matches punctuation"
parent: /reference/rules/
rule_category: "Character Classes"
rule_order: 4
rule_ids:
  - regex.lint.charclass.suspiciousRange
---
# Suspicious ASCII Ranges

**Identifier:** `regex.lint.charclass.suspiciousRange`

**When it triggers:** A character range spans the ASCII gap between `Z` and `a` (e.g. `[A-z]`), which includes `[ \ ] ^ _ `.

**Example:**
```php
// WARNING: Includes non-letters between Z and a
preg_match('/[A-z]/', $input);

// PREFERRED: Use two ranges
preg_match('/[A-Za-z]/', $input);
```

**Fix:** Split into `[A-Z]` and `[a-z]`, or combine as `[A-Za-z]`.

---

**Try it.** [Open `/[A-z]/` in the playground](/#pattern=%2F%5BA-z%5D%2F&subject=%5B): the finding is in the Diagnostics tab.
