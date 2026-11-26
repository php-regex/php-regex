---
description: "A quantifier on a capturing group overwrites the capture at each iteration, so only the last one survives: repeat a non-capturing group instead."
head_title: "(a)+ in PHP regex: only the last capture survives"
parent: /reference/rules/
rule_category: "Groups"
rule_order: 4
rule_ids:
  - regex.lint.group.quantifiedCapture
---
# Quantified Capturing Group

**Identifier:** `regex.lint.group.quantifiedCapture`

**When it triggers:** A repeatable quantifier (`*`, `+`, `{n,}`…) sits on a capturing group: each iteration overwrites the capture, and only the last one survives. An unnamed group is reported at info severity, a named one at warning. The message names the group by its number, as PCRE counts it, or its name, and quotes it as written, on one line without the layout and the comments, thirty columns at most, so two findings on one pattern read apart: `capturing group 2 "(b)"` for `/(a)(b)+/`.

**Example:**
```php
// INFO: Quantified capturing group 1 "(a)" with "+":
//      only the last iteration's capture is retained.
preg_match('/(a)+/', 'aaa', $m);  // $m is ["aaa", "a"]

// PREFERRED: the repetition does not need to capture
preg_match('/(?:a)+/', 'aaa', $m);
```

**Fix:** Use a non-capturing group `(?:...)` for the repetition and capture the whole match, or restructure the pattern.

---

**Try it.** [Open `/(a)+/` in the playground](/#pattern=%2F%28a%29%2B%2F&subject=aaa): the finding is in the Diagnostics tab.
