---
description: "The last item of a lookahead, or the first of a lookbehind, repeated past its minimum changes nothing: a perf rule, off by default, and its exceptions."
head_title: "Quantifier at the edge of a lookaround in PHP"
parent: /reference/rules/
rule_category: "Quantifiers"
rule_order: 11
rule_ids:
  - regex.lint.lookaround.edgeQuantifier
---
# Repeat at the Edge of a Lookaround

**Identifier:** `regex.lint.lookaround.edgeQuantifier` (off by default)

**When it triggers:** A lookahead only checks that its body can match, so the last item of
the body repeated past its minimum changes nothing: `(?=a{2,6})` asserts what `(?=a{2})`
asserts, `(?=ab*)` what `(?=a)` asserts. The same holds for the first item of a lookbehind,
`(?<=a{2,6})`. The automata prove that the two bodies hold at the same positions before the
rule speaks. It stays silent on a body holding a capture (`(?=(a{2,6}))` keeps the whole run
in `$1`), a reference, a call, a verb, a callout or `\K`, on a lookbehind whose body tests a
position (`\z`, `$`, `\b`, a lookahead...: a lookbehind of variable length reads the end of the
subject at its own position, one of fixed length the real end, and `(?<=b{1,2}\z)a` matches
`ba` where `(?<=b\z)a` does not), in a non-atomic lookahead
(`(*napla:...)`), on a possessive repeat, which the automata do not read, and past the eighth
question it asks them about in a pattern. A perf rule: turn it on with
`"lookaround.edgeQuantifier": true` under `checks.lint.rules`.

**Example:**
```php
// INFO: the x past the first is never checked
preg_match('/\d+(?=px{1,3})/', '12pxx', $m);  // "12"

// PREFERRED
preg_match('/\d+(?=px)/', '12pxx', $m);       // "12"
```

**Fix:** Write the minimum count the tip gives.

---

**Try it.** [Open `/\d+(?=px{1,3})/` in the playground](/#pattern=%2F%5Cd%2B%28%3F%3Dpx%7B1%2C3%7D%29%2F&subject=12pxx): the rule is off by default, so the Diagnostics tab shows the findings of the rules that are on.
