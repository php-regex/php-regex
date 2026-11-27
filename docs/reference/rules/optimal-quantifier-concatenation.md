---
description: "Two adjacent quantified tokens where one set is a subset of the other, as \\d+\\w+, can be tightened or dropped: what the rule proves before it speaks."
head_title: "Adjacent quantifiers in PHP regex: \\d+\\w+"
parent: /reference/rules/
quick_fix: "Tighten the smaller quantifier, or drop it"
quick_fix_order: 21
rule_category: "Quantifiers"
rule_order: 5
rule_ids:
  - regex.lint.quantifier.concatenation
---
# Optimal Quantifier Concatenation

**Identifier:** `regex.lint.quantifier.concatenation`

**When it triggers:** Two adjacent quantified tokens can be simplified because one character set is a subset of the other.
The rule speaks only when it knows the subset holds: a token that may take a character above
ASCII fits only a dot, a negated class of ASCII members or, without `u`, `\D`, `\W` and `\S`
(`/^\h{1,3}[\t ]+\z/` stays silent, `\h` takes `"\xA0"`), and under `i` a negated class must
hold both cases of its letters (`/^A?[^a]*\z/i` stays silent). Without `u`, under another
locale's tables than PCRE2's own, both tokens are read every byte as those tables give them,
unless the pattern reads a character caselessly where the engine's paths read some case
otherwise: under glibc's `tr_TR.ISO-8859-9`, which pairs `i` with `\xDD` and `I` with
`\xFD`, `/i+I+$/i` stays silent, its two tokens taking no character in common, and
`/i+\xDD+$/i` is reported; under `fr_FR.ISO8859-1`, so is `"/\xC9+\xE9+$/i"`, with the
bytes written as they are. Under `u` an ASCII letter written alone, `i`, `\x69`, `[i]` or
`[^i]`, takes the cases those tables give it, where a class of several characters takes
Unicode's: under glibc's `tr_TR.UTF-8`, which gives `i` and `I` no other case, `/i+I+$/iu`
stays silent and `/i+[ij]+$/iu` is reported. Nor does it speak of a token
PCRE2 makes possessive before an atomic group, or a possessive repeat of a group, that may
match nothing: dropped or fixed to a count, it gives back what PCRE2 took from the token
before it, and `/[ab]{2,}a?(?:b){0,2}+a/` finds nothing in `aaa` where
`/[ab]{2,}(?:b){0,2}+a/` finds it.

**Example:**
```php
// WARNING: \d is a subset of \w
preg_match('/\d+\w+/', $input);

// PREFERRED: Keep the superset quantifier unbounded
preg_match('/\d\w+/', $input);

// WARNING: \d* before \w* can be dropped — \w covers it
preg_match('/\d*\w*/', $input);

// PREFERRED: Drop the whole quantified term
preg_match('/\w*/', $input);
```

An atom guarded by a negative lookahead, as in `(?:.(?!x))*`, does not match every character of its class: `.(?!x)` refuses a character followed by `x`. Such a pair is reported only when the rewrite keeps every guard true, so `/^(?:.(?!x))*.{1,3}$/` is not.

**Fix:** Tighten the smaller quantifier to its minimum, or — when it can already match zero times — drop the whole quantified term.

---

**Try it.** [Open `/\d+\w+/` in the playground](/#pattern=%2F%5Cd%2B%5Cw%2B%2F&subject=ab12cd): the finding is in the Diagnostics tab.
