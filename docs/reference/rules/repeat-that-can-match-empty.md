---
description: "An unbounded quantifier on an item that can match the empty string, as (a*)*, ends on an empty iteration and leaves an empty capture: how to fix it."
head_title: "(a*)* in PHP regex: a repeat that matches empty"
parent: /reference/rules/
rule_category: "Quantifiers"
rule_order: 7
rule_ids:
  - regex.lint.quantifier.emptyRepeat
---
# Repeat That Can Match Empty

**Identifier:** `regex.lint.quantifier.emptyRepeat`

**When it triggers:** An unbounded quantifier (`*`, `+`, `{n,}`) repeats an item that can
match the empty string: `(a*)*`, `(?:a|b?)+`, `(?:a{0,2})+`. PCRE ends the loop on an empty
iteration, which matches nothing more. When every way the item matches the empty string goes
through a capturing group, that last, empty iteration sets the capture to the empty string,
and the message says so; in `(?:(a)|b?)*` the empty way goes around the group, and `$m[1]`
keeps its `a`, and a capture inside a lookaround keeps what the lookaround read:
`(?:x|(?=(a)))*` on `xa` captures `a`. An item that fails rather than match the empty string is sound:
`(?:a|(*FAIL))*` and `(?:a|(?!))*` are not reported. Where another rule already reports the
same repeat (an empty alternative, a quantified lookaround, a nested quantifier), this one
stays silent, as long as the configuration turns that other rule on: `(?:a*)*b` with
`quantifier.nested` off is reported by this rule.

**Example:**
```php
// WARNING: the capture ends empty
preg_match('/(a*)*/', 'aaa', $m);  // $m is ["aaa", ""]

// PREFERRED
preg_match('/(a*)/', 'aaa', $m);   // $m is ["aaa", "aaa"]
```

**Fix:** Make the repeated item read at least one character, or drop the outer quantifier.
The tip gives a rewrite only where one matches the same text: `a*` for `(?:a*)+`, `(?:a?)*`
or `(?:a{0,2})+`. Elsewhere, as for `(?:a|b?)+` (which is `(?:a|b)*`) or `(?:a{0})+` (which
reads nothing), the issue comes without a tip.

---

**Try it.** [Open `/(a*)*/` in the playground](/#pattern=%2F%28a%2A%29%2A%2F&subject=aaa): the finding is in the Diagnostics tab.
