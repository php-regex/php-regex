---
description: "A quantifier that matches exactly once, as {1}, changes nothing: why the linter reports it, and the {1} that keeps an escape apart from a digit."
head_title: "PHP regex {1} quantifier is useless"
parent: /reference/rules/
rule_category: "Quantifiers"
rule_order: 3
rule_ids:
  - regex.lint.quantifier.useless
---
# Useless Quantifier

**Identifier:** `regex.lint.quantifier.useless`

**When it triggers:** A quantifier that matches exactly once (e.g., `{1}` or `{1,1}`). A possessive one, `{1}+`, is an atomic group that never gives back, and is not reported: `/(a|ab){1}+ab/` does not match `"ababx"`, which `/(a|ab)ab/` does. Nor is a count that keeps an escape apart from the digit after it: `/(a)\1{1}0/` matches `"aa0"`, `/(a)\10/` does not, and `/\x4{1}1/` is not `/\x41/`. The tip names the count as written, a lazy `{1}?` with its `?`: `/a{1}?b/` is `/ab/`, not `/a?b/`.

**Example:**
```php
// WARNING: {1} does not change the match
preg_match('/a{1}/', $input);

// PREFERRED: Remove the quantifier
preg_match('/a/', $input);
```

**Fix:** Remove the `{1}` quantifier.

---

**Try it.** [Open `/a{1}/` in the playground](/#pattern=%2Fa%7B1%7D%2F&subject=a): the finding is in the Diagnostics tab.
