---
description: "A quantifier with a maximum of zero, as {0}, makes the element disappear: what to remove, and the repeats that still matter to PCRE2."
head_title: "PHP regex {0} quantifier removes the element"
parent: /reference/rules/
rule_category: "Quantifiers"
rule_order: 4
rule_ids:
  - regex.lint.quantifier.zero
---
# Zero Quantifier

**Identifier:** `regex.lint.quantifier.zero`

**When it triggers:** A quantifier with a maximum of zero (e.g., `{0}` or `{0,0}`), which makes the element disappear. A repeat whose removal changes the match is left alone, as the optimizer leaves it: one holding a group, which still takes its number and may be called (`(?<d>\d){0}(?&d)+`, the way to define a subpattern), a backreference or a conditional, and one holding an assertion, which still tells PCRE2 where a match may start (`/(?:x|(?=a)){0}\w/` never matches `"\tb"`, where `/\w/` does).

**Example:**
```php
// WARNING: {0} always matches zero occurrences
preg_match('/ab{0}c/', $input);

// PREFERRED: Remove the quantified element
preg_match('/ac/', $input);
```

**Fix:** Remove the quantified element or replace it with an empty pattern.

---

**Try it.** [Open `/ab{0}c/` in the playground](/#pattern=%2Fab%7B0%7Dc%2F&subject=ac): the finding is in the Diagnostics tab.
