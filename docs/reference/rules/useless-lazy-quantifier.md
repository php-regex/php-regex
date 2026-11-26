---
description: "A lazy quantifier that writes the same matches as its greedy form on every subject, as /(a+?)b/: a style rule the automata prove before they speak."
head_title: "PHP regex lazy quantifier that changes nothing"
parent: /reference/rules/
rule_category: "Quantifiers"
rule_order: 10
rule_ids:
  - regex.lint.quantifier.uselessLazy
---
# Useless Lazy Quantifier

**Identifier:** `regex.lint.quantifier.uselessLazy` (off by default)

**When it triggers:** A lazy quantifier writes the same `$matches` as its greedy form on every
subject: `/(a+?)b/` and `/(a+)b/` stop at the same `b`, since the run cannot take one, and a
fixed count such as `a{3}?` has nothing to choose. The automata prove the two patterns
equivalent before the rule speaks, from any offset (a pattern with a start anchor is asked
again with the anchor failing). It stays silent where the lazy marker matters
(`/(a+?)a/` on `aaa` captures `a`, the greedy form `aa`), under `U`, where `+?` is the greedy
one, where nothing that can fail follows a variable count (`quantifier.lazyEnd` reports that),
in a pattern that may match the empty string (after an empty match, `preg_match_all()`,
`preg_replace()` and `preg_split()` try again for a non-empty one, where the two forms part:
`/(\d+?,?)??/` finds `1` and `2` in `12`, the greedy form `12`) or that sets a
`(*LIMIT_MATCH=…)`, `(*LIMIT_DEPTH=…)` or `(*LIMIT_HEAP=…)`, which the lazy form, backtracking
more, may reach first, and past the eighth question it asks the automata about in a pattern. A style rule: turn it
on with `"quantifier.uselessLazy": true` under `checks.lint.rules`.

**Example:**
```php
// INFO: lazy for nothing
preg_match('/(\d+?)\D/', 'ab12x', $m);  // "12x", "12"

// PREFERRED: the same $matches
preg_match('/(\d+)\D/', 'ab12x', $m);   // "12x", "12"
```

**Fix:** Drop the `?`.

---

**Try it.** [Open `/(\d+?)\D/` in the playground](/#pattern=%2F%28%5Cd%2B%3F%29%5CD%2F&subject=ab12x): the rule is off by default, so the Diagnostics tab shows the findings of the rules that are on.
