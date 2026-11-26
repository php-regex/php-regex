---
description: "A possessive repeat never gives a character back, so /a*+a/ and /\\d++5/ can never match: how the rule proves it, and what to write instead."
head_title: "a*+a never matches: possessive quantifier in PHP"
parent: /reference/rules/
rule_category: "Quantifiers"
rule_order: 8
rule_ids:
  - regex.lint.quantifier.possessiveImpossible
---
# Impossible Possessive Quantifier

**Identifier:** `regex.lint.quantifier.possessiveImpossible`

**When it triggers:** A possessive repeat (`a*+`), or a greedy one inside an atomic group
(`(?>a*)`), takes every character the atom right after it could read, and never gives one
back: `/a*+a/`, `/\d++5/` and `/a*+A/i` never match through there. Decided on atoms that
read exactly one character, under the flags in force at each, the automata comparing their
sets: `/a*+A/` matches `A`, `/\w++é/` matches `aé` in byte mode, `/\w++é/u` never does. A
bounded repeat stops at its bound (`/^a{0,3}+a$/` matches `aaaa`) and an atom that may match
nothing (`/a*+a?/`) can step aside: neither is reported. Past the eighth repeat it asks the
automata about in a pattern, the rule stays silent for the rest of it. The rule stays silent in a pattern that sets an ASCII option (`(?a)`, `(?aD)`, …) or `(?xx)`, or sets `r` beside a `(?^)`: the flags it reads do not say which of them is in force.

**Example:**
```php
// WARNING: the repeat takes every digit, the "5" included
preg_match('/\d++5/', '12345');  // 0

// PREFERRED
preg_match('/\d+5/', '12345');   // 1
```

**Fix:** Make the repeat greedy, or exclude from its set what must follow it.

---

**Try it.** [Open `/\d++5/` in the playground](/#pattern=%2F%5Cd%2B%2B5%2F&subject=12345): the finding is in the Diagnostics tab.
