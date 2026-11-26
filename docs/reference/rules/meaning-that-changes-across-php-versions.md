---
description: "A pattern every PHP of a range accepts but a later one parses into another meaning, as a{,3} from PCRE2 10.43: the compat.meaningChanges warning, and the fix."
head_title: "A PHP regex that changes meaning across versions"
parent: /reference/rules/
rule_category: "Compatibility & Limitations"
rule_order: 1
rule_ids:
  - regex.lint.compat.meaningChanges
---
# Meaning That Changes Across PHP Versions

**Identifier:** `regex.lint.compat.meaningChanges`

**When it triggers:** `regex lint` judges a project over the PHP range of its `composer.json`.
A pattern every version of the range accepts may still be parsed into another meaning by a
later one: `/a{,3}/` is the text `a{,3}` under PCRE2 before 10.43 (PHP 8.2 and 8.3) and `a`
zero to three times from 10.43 (PHP 8.4); `/a{ 2 }/` likewise. The warning names the first
version that reads the pattern otherwise, under `target` in the JSON report.
`"compat.meaningChanges": false` in `checks.lint.rules` turns it off.

The same warning names a change the release table knows that keeps the parse and moves what
the engine matches, with the witness each real engine gives. It is reported, never rewritten:

| from | the same tree, matched otherwise | witness |
|---|---|---|
| PCRE2 10.34 (PHP 7.4.6) | a possessive `{1}+` on a group makes it atomic; before, it was ignored | `/(a\|ab){1}+c/` finds `abc` in `"abc"` on 10.33, nothing from 10.34 |
| PCRE2 10.35 (PHP 8.0) | a group that holds a reference to itself may be backtracked into; before, it was atomic | `/^(a\|ab\|\1x)+b$/` finds `abb` in `"abb"` from 10.35 only |
| PCRE2 10.35 (PHP 8.0) | a repeated assertion runs as many times as its count asks; before, once | `/^(?=(\1?a)){2}/` captures `aa` in `"aa"` from 10.35, `a` before |
| PCRE2 10.35 (PHP 8.0) | `(*UCP)` without UTF folds the case of characters past ASCII | `/(*UCP)\xC9/i` matches `"\xE9"` from 10.35 only |
| PCRE2 10.40 (PHP 8.2) | a bare script name, `\p{Katakana}`, means its script extensions | U+30FB matches `/\p{Katakana}/u` from PHP 8.2 only |

The message names the first version of the range that matches the pattern the new way, and the
hint the spelling that keeps one reading on every PHP where one exists: `{1}` or `(?>...)` for
the possessive repeat, an atomic group around the group that refers to itself, one pass of the
assertion, both cases spelled in a class. A bare script name has none: `\p{sc:Katakana}` keeps
the script alone from PHP 8.2 on, and PHP 8.1 refuses it.

**Message:** `From PHP 8.4 (PCRE2 10.44) the pattern parses differently: the same text means something else there.`

**Fix:** Write the part that differs in a form every version reads alike: `a{0,3}` for the
quantifier, `a\{,3}` for the text.
