---
description: "A lookahead that contradicts what follows it, as (?=a)b or (?!a)a, can never hold: what the rule reads, and where it stays silent."
head_title: "Impossible lookahead in PHP regex: (?=a)b"
parent: /reference/rules/
rule_category: "Lookarounds"
rule_order: 1
rule_ids:
  - regex.lint.lookaround.impossible
---
# Impossible Lookaround

**Identifier:** `regex.lint.lookaround.impossible`

**When it triggers:** A lookahead contradicts what the pattern reads after it: `(?=a)b` asks
for an `a` where the pattern reads a `b`, `(?!a)a` forbids the `a` it reads. The automata
decide it: what follows the lookahead runs through the enclosing groups and quantifiers
(`/(?:x(?=a))+b/` is reported: another `x` or the `b` comes next, never an `a`), read for a
few items. A contradiction in one alternative is reported though the pattern still matches
through another (`/(?:x(?=a)|y)b/`). The rule stays silent where it cannot read: a
backreference, a lookbehind or `\K` after the lookahead, a group a subroutine call runs from
elsewhere, a pattern past the automata's work cap or past the eighth lookahead it asks them
about, and in UTF mode a word boundary, `\w` or a
Unicode property near the lookahead. Without `/u`, a byte read caselessly has the case the target's PCRE2 gives it: under `(*UCP)` the case of the character of the same value from 10.35, the tables' before, so `/(*UCP)(?!\xC9)\xE9/i` is reported for PHP 8.0 and later and not for PHP 7.4 with PCRE2 10.34, which matches it on `\xE9`. Under another locale's tables the rule stays silent where the engine's paths read a byte apart: under macOS's `tr_TR.ISO8859-9` the JIT matches `"/(?=\xFD)i/i"` on `I` and the interpreter does not, and the interpreter alone matches `/(?![\xFDI])I/` on `I`, as PCRE2 reads a class of a byte and its other case as the byte, caseless. A repeated lookahead is
`regex.lint.quantifier.assertion`'s, and an empty negative lookahead `(?!)` fails on purpose. The rule stays silent in a pattern that sets an ASCII option (`(?a)`, `(?aD)`, …) or `(?xx)`, or sets `r` beside a `(?^)`: the flags it reads do not say which of them is in force.

**Example:**
```php
// WARNING: a digit is required where a letter is read
preg_match('/(?=\d)[a-z]+/', 'a1');  // 0

// WARNING: the "a" is forbidden, then read
preg_match('/(?!a)a/', 'aa');         // 0
```

**Fix:** Fix the lookahead or what follows it, or remove the alternative that cannot match.

---

**Try it.** [Open `/(?=\d)[a-z]+/` in the playground](/#pattern=%2F%28%3F%3D%5Cd%29%5Ba-z%5D%2B%2F&subject=a1): the finding is in the Diagnostics tab.
