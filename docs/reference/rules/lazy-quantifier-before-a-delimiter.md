---
description: "A lazy dot followed by one closing character, as /\".*?\"/, backtracks at each step where a negated class reads the run at once. A perf rule, off by default."
head_title: "Lazy dot before a delimiter: faster as a class"
parent: /reference/rules/
rule_category: "Quantifiers"
rule_order: 9
rule_ids:
  - regex.lint.quantifier.lazyToClass
---
# Lazy Quantifier Before a Delimiter

**Identifier:** `regex.lint.quantifier.lazyToClass` (off by default)

**When it triggers:** A lazy dot, `.*?` or `.+?`, is followed by one closing character and
nothing that can fail comes after it: `/".*?"/` backtracks one character at a time where
`/"[^"\n]*"/` reads the run at once, and the two write the same `$matches` on every subject.
The tip spells the class: `[^"\n]*` without `s`, `[^"]*` under `s`. The automata prove the
two patterns equivalent before the rule speaks; it stays silent when something follows the
closing character (in `/".*?"x/` on `"a"b"x` the lazy dot crosses the middle quote, the class
cannot), and under a newline convention other than `\n` (`(*CRLF)`, `(*ANYCRLF)`, `(*CR)`...),
where the dot stops at another character, and past the eighth lazy dot it asks the automata
about in a pattern. A perf rule: turn it on with
`"quantifier.lazyToClass": true` under `checks.lint.rules`.

**Example:**
```php
// INFO: backtracks at each character
preg_match('/<.*?>/', '<a><b>', $m);       // "<a>"

// PREFERRED: same match, read at once
preg_match('/<[^>\n]*>/', '<a><b>', $m);  // "<a>"
```

**Fix:** Write the negated class the tip gives.

---

**Try it.** [Open `/<.*?>/` in the playground](/#pattern=%2F%3C.%2A%3F%3E%2F&subject=%3Ca%3E%3Cb%3E): the rule is off by default, so the Diagnostics tab shows the findings of the rules that are on.
