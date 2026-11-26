---
description: "A positive lookahead that ends a lookahead can hold its body: (?=a(?=b)) asserts what (?=ab) does. A style rule, off by default, and its exceptions."
head_title: "Lookaround inside a lookaround in PHP regex"
parent: /reference/rules/
rule_category: "Lookarounds"
rule_order: 2
rule_ids:
  - regex.lint.lookaround.redundant
---
# Lookaround Inside a Lookaround

**Identifier:** `regex.lint.lookaround.redundant` (off by default)

**When it triggers:** A positive lookahead ends a lookahead, positive or negative: nothing in
the outer lookahead reads past it, so its body written in its place asserts the same,
`(?=a(?=b))` what `(?=ab)` asserts and `(?!a(?=b))` what `(?!ab)` asserts. The same holds for
a positive lookbehind that starts a lookbehind: `(?<=(?<=a)b)` is `(?<=ab)`. Each alternative
of the outer body is read on its own (`(?=x|a(?=b))` is `(?=x|ab)`), and comments may follow the
inner lookahead. The tip keeps a group around a body that holds alternatives or sets an option,
`(?=a(?:b|c))` for `(?=a(?=b|c))`, so that neither reaches what surrounds it. The rule stays
silent on an inner negative lookaround (`(?=a(?!b))` also holds at the end of the subject, where
`(?=a[^b])` does not), on a lookahead that does not end its parent or a lookbehind that does not
start it, on a lookaround of the other direction, a quantified one, a non-atomic one on either
side (`(*napla:...)`), a body holding a verb, a callout or `\K`, and on an inner lookbehind whose
body holds alternatives, which nested in the outer body would need PCRE2 10.43 when their
lengths differ. A style rule: turn it on with `"lookaround.redundant": true` under
`checks.lint.rules`.

**Message:** `"(?=b)" ends a lookahead, which can hold its body: "(?=ab)" asserts the same.`

**Example:**
```php
// STYLE: the inner lookahead adds nothing
preg_match('/\w+(?=,(?= ))/', 'a, b', $m);  // "a"

// PREFERRED
preg_match('/\w+(?=, )/', 'a, b', $m);      // "a"
```

**Fix:** Write the tip's rewrite.

---

**Try it.** [Open `/\w+(?=,(?= ))/` in the playground](/#pattern=%2F%5Cw%2B%28%3F%3D%2C%28%3F%3D%20%29%29%2F&subject=a%2C%20b): the rule is off by default, so the Diagnostics tab shows the findings of the rules that are on.
