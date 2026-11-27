---
description: "An unbounded quantifier around .* makes a PHP pattern backtrack heavily: the atomic and possessive forms, and a negated class instead."
head_title: "(?:.*)+ in PHP regex: dot-star under a quantifier"
parent: /reference/rules/
quick_fix: "Make it possessive, or use a negated class"
quick_fix_order: 13
rule_category: "Quantifiers"
rule_order: 2
rule_ids:
  - regex.lint.dotstar.nested
---
# Dot-Star in Quantifier

**Identifier:** `regex.lint.dotstar.nested`

**When it triggers:** An unbounded quantifier wraps a dot-star, which can cause extreme backtracking.

Without `/s` (or an inline `(?s)`), a dot cannot cross a newline, so `/(?:.*\n)+x/` is not reported: each iteration ends at the next newline and the input splits one way. With `/s` or `(?s)`, the same pattern is reported. Unlike nested quantifiers, a loop at the very end of the pattern is still reported, as `/(?:.*)+/` in the example below. With the ReDoS analysis on, a pattern the analysis proves linear drops the issue, inline options such as `(?s)` or `(?s:…)` included (see [Nested Quantifiers](nested-quantifiers-redos-risk.md)).

**Example:**
```php
// RISKY: .* in repetition can backtrack heavily
preg_match('/(?:.*)+/', $input);

// SAFER: Make the dot-star atomic or possessive
preg_match('/(?>.*)+/', $input);
preg_match('/.*+/', $input);  // If no outer repetition is needed

// BETTER: Use negated character class
preg_match('/[^"]*/', $input);  // For double-quoted strings
```

**Fix:** Make it atomic/possessive or replace `.*` with a specific class — and verify the rewrite still matches everything you need.

---

**Try it.** [Open `/(?:.*)+/` in the playground](/#pattern=%2F%28%3F%3A.%2A%29%2B%2F&subject=hello): the finding is in the Diagnostics tab.
