---
description: "A quantifier on a lookaround: with a minimum of zero PCRE may skip the assertion, so (?=\\d)? constrains nothing. Remove the quantifier or the assertion."
head_title: "Quantified lookahead in PHP regex: (?=\\d)?"
parent: /reference/rules/
rule_category: "Quantifiers"
rule_order: 12
rule_ids:
  - regex.lint.quantifier.assertion
---
# Quantified Assertion

**Identifier:** `regex.lint.quantifier.assertion`

**When it triggers:** A lookaround carries a quantifier. With a minimum of zero PCRE tries
the rest of the pattern with and without the assertion, so it constrains nothing; with a
minimum of one or more, PCRE checks it once. A lookaround that captures is left alone:
`(?=(\w+))?` still sets its group when it holds.

**Example:**
```php
// WARNING: the lookahead may be skipped
preg_match('/(?=\d)?\w/', 'a');  // 1

// PREFERRED
preg_match('/(?=\d)\w/', 'a');   // 0
```

**Fix:** Remove the quantifier, or the assertion.

---

**Try it.** [Open `/(?=\d)?\w/` in the playground](/#pattern=%2F%28%3F%3D%5Cd%29%3F%5Cw%2F&subject=a): the finding is in the Diagnostics tab.
