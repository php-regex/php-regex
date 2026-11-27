---
description: "One literal alternative that is a prefix of another, as (a|aa)+b: the backtracking it causes, and the atomic group or simplification that fixes it."
head_title: "(a|aa)+ in PHP regex: overlapping branches"
parent: /reference/rules/
quick_fix: "Use an atomic group, or merge the branches"
quick_fix_order: 14
rule_category: "Alternation"
rule_order: 3
rule_ids:
  - regex.lint.alternation.overlap
---
# Overlapping Alternation Branches

**Identifier:** `regex.lint.alternation.overlap`

**When it triggers:** One literal alternative is a prefix of another. Under `i` the texts are compared in one case: every letter under `/u`, and without it the letters the tables of the target's locale pair, the ASCII ones under `C`. In PHP, `"/(?:\xC9x|\xE9x)+/i"`, with raw bytes, is reported under `fr_FR.ISO8859-1`, where the Latin-1 tables pair `\xC9` with `\xE9`, and not under `C`. A byte is paired as the target's PCRE2 release reads the tables, and one they pair one way alone keeps its own case. An escape that names one character, `\x41`, `\x{E9}`, `\101`, `\o{101}`, `\N{U+E9}` or `\cA`, is literal text, as it is to the engine: `/(?:\x41x|ax)+/i` is reported as `/(?:Ax|ax)+/i` is, with the same message, `Alternation branches "Ax" and "ax" overlap.`, and `'/(?:\xC9x|\xE9x)+/i'`, written with escapes, under `fr_FR.ISO8859-1` as the raw bytes are. Each character is read in the case the options in force where it stands give it: an option setting at the start of a branch, `(?-i)`, or a group around it, `(?-i:…)`, reads the branch in its own case, so `/(?:(?-i)Ax|(?-i)ax)+/i` is not reported, and the setting holds in the branches after its own, as PCRE reads it. Two characters meet when they are the same or, where either is read caselessly, one letter in two cases: `/(?:(?i:A)x|ax)+/` is reported, `/(?:(?i:A)x|aX)+/` is not. Under `/u` an ASCII letter keeps its own case where the tables of the target's locale give it no other, as PCRE2 compares a letter written alone: under glibc's `tr_TR.UTF-8`, which gives `i` and `I` none, `/(?:i|I)+$/iu` is not reported; `k` and `s`, which Unicode pairs with the Kelvin sign and the long s, keep both cases.

**Visual Explanation:**
```
Pattern: /(a|aa)+b/
Input: "aaaaab"
The engine can split the a's as: a+a+a+a+a or aa+a+a+a, ...
Result: overlapping paths trigger heavy backtracking
```

**Example:**
```php
// VULNERABLE: Overlapping branches in repetition
preg_match('/(a|aa)+b/', $input);

// SAFER: Use atomic groups
preg_match('/(?>a|aa)+b/', $input);

// SIMPLER: Just use a+
preg_match('/a+b/', $input);  // Often equivalent
```

**Fix:** Use atomic groups or simplify the pattern.

---

**Try it.** [Open `/(a|aa)+b/` in the playground](/#pattern=%2F%28a%7Caa%29%2Bb%2F&subject=aaaab): the finding is in the Diagnostics tab.
