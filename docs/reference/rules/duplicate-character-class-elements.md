---
description: "The same character written twice in a character class: how the rule reads ranges, shorthands and escapes, and the class that says it once."
head_title: "Duplicate elements in a PHP regex class"
parent: /reference/rules/
rule_category: "Character Classes"
rule_order: 2
rule_ids:
  - regex.lint.charclass.duplicateChars
---
# Duplicate Character Class Elements

**Identifier:** `regex.lint.charclass.duplicateChars`

**When it triggers:** A character class contains an element that is fully covered by another element (e.g., `\d` plus `0-9`).

**Example:**
```php
// WARNING: \d already includes 0-9
preg_match('/[\d0-9]/', $input);

// WARNING: \w already includes A-Z
preg_match('/[\wA-Z]/', $input);

// PREFERRED: Remove the redundant element
preg_match('/[\d]/', $input);
preg_match('/[\w]/', $input);
```

**Fix:** Remove the redundant character class element.

---

**Try it.** [Open `/[\d0-9]/` in the playground](/#pattern=%2F%5B%5Cd0-9%5D%2F&subject=5): the finding is in the Diagnostics tab.
