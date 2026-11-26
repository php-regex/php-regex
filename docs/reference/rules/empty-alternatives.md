---
description: "An alternation with an empty branch, as a trailing |, matches the empty string: why the rule reports it, and the quantifier to write instead."
head_title: "Empty alternative in a PHP regex: a|"
parent: /reference/rules/
rule_category: "Alternation"
rule_order: 2
rule_ids:
  - regex.lint.alternation.empty
---
# Empty Alternatives

**Identifier:** `regex.lint.alternation.empty`

**When it triggers:** An alternation contains an empty branch (e.g., trailing `|` or `||`).

**Example:**
```php
// WARNING: Empty alternative
preg_match('/a|/', $input);

// PREFERRED: Use a quantifier
preg_match('/a?/', $input);
```

**Fix:** Replace the empty alternative with a quantifier or an explicit empty group if intentional.

---

**Try it.** [Open `/a|/` in the playground](/#pattern=%2Fa%7C%2F&subject=a): the finding is in the Diagnostics tab.
