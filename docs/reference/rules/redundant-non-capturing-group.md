---
description: "A non-capturing group around one atom changes nothing, as x(?:a)y: when the linter reports it, and the groups that are needed after all."
head_title: "Redundant non-capturing group (?:…) in PHP regex"
parent: /reference/rules/
quick_fix: "Remove the group"
quick_fix_order: 17
rule_category: "Groups"
rule_order: 1
rule_ids:
  - regex.lint.group.redundant
---
# Redundant Non-Capturing Group

**Identifier:** `regex.lint.group.redundant`

**When it triggers:** A non-capturing group wraps a single atomic token without changing precedence.
An empty group is `regex.lint.group.empty`'s, and a group that keeps an escape apart from
the digit after it is not redundant: `(a)\1(?:0)` matches `aa0`, `(a)\10` does not, and
`(?:\N){U+41}` reads `{U+41}` as text where `\N{U+41}` is the code point under `/u`, and does
not compile without it. Neither is a group that keeps braces from reading as a quantifier, `a{(?:2)}` (it matches `a{2}`,
`a{2}` matches `aa`), nor a group a quantifier repeats around anything but one character:
without the group, `(?:\Qab\E)+` repeats the `b` alone, `(?:é)+` without `/u` the last byte
of the letter, and `(?:^)+` does not compile. `(?:a)+` is reported: `a+` is the same. A group
whose brackets decide which repeats PCRE2 makes possessive stays too: PCRE2 makes a character
repeated before an atomic group, or a possessive repeat of a group, that may match nothing
possessive, as if the group could not be skipped, so `/a+(?:b)?+a/` finds nothing in `aa`
where `/a+b?+a/` finds it, and `/(?:a)+(?:b)?+a/` finds `aa` where `/a+(?:b)?+a/` does not.
The engine reads those brackets on its way from the repeated character to the group it may
skip, no further than the first character that must be there: `(?:b)` stays in
`/a+c?(?:b)?+a/`, and is reported in `/a+x(?:b)?+a/`, as `(?:\\)` is in
`/(?:\\)?define [a-z]++ (?:,x)*+ /`. A group repeated more than once is read again for each
round, `(?:b)` in `/(?:(?>(?:b)?)a+){2}/` staying for the `a+` of the round before, and a
pattern that holds a subroutine call is read whole: there a group stays wherever a repeat
stands before such a group. A group inside a group a call runs that repeats something some
number of times, or anywhere in a pattern that calls itself whole, stays wherever it stands:
PCRE2 makes such a repeat possessive by the brackets around it, and its JIT then misses
matches, so `/(a+b)c(?1)d/` finds nothing in `abcabcabd` where `/((?:a)+b)c(?1)d/` and
`/(a+(?:b))c(?1)d/` find `abcabd`, and `/x(?R)a|a*/` finds the empty string in `xaa` where
`/x(?R)a|(?:a)*/` finds `xaa`. `(?:a)` is still reported in `/(?:a)+b(?1)(c)/`, outside the
group the call runs.

**Example:**
```php
// WARNING: the group wraps one token, `a`
preg_match('/x(?:a)y/', $input);

// PREFERRED: Remove the group
preg_match('/xay/', $input);
```

**Fix:** Remove the unnecessary group.

---

**Try it.** [Open `/x(?:a)y/` in the playground](/#pattern=%2Fx%28%3F%3Aa%29y%2F&subject=xay): the finding is in the Diagnostics tab.
