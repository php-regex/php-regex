---
description: "The same alternative written twice in an alternation, as (a|a): the rule that reports it, and the single literal or character class to write instead."
head_title: "Duplicate branch in a PHP regex alternation"
parent: /reference/rules/
rule_category: "Alternation"
rule_order: 1
rule_ids:
  - regex.lint.alternation.duplicateDisjunction
---
# Duplicate Alternation Branches

**Identifier:** `regex.lint.alternation.duplicateDisjunction`

**When it triggers:** The same alternative appears more than once.

**Example:**
```php
// WARNING: Duplicate branch
preg_match('/(a|a)/', $input);

// PREFERRED: Use a single literal
preg_match('/a/', $input);
```

**Fix:** Remove duplicates or use a character class.

---

**Try it.** [Open `/(a|a)/` in the playground](/#pattern=%2F%28a%7Ca%29%2F&subject=a): the finding is in the Diagnostics tab.
