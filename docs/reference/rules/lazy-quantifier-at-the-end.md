---
description: "A lazy quantifier with nothing after it matches its minimum: why /id-\\d+?/ stops at one digit, and how to write what you meant."
head_title: "Lazy quantifier at the end of a PHP regex"
parent: /reference/rules/
rule_category: "Quantifiers"
rule_order: 6
rule_ids:
  - regex.lint.quantifier.lazyEnd
---
# Lazy Quantifier at the End

**Identifier:** `regex.lint.quantifier.lazyEnd`

**When it triggers:** A lazy quantifier, or a greedy one under `/U`, has nothing after it
in the pattern. The match ends as soon as it may, so the quantifier matches its minimum.
The same holds when only items that may match nothing follow it, and none of them holds an
anchor, a lookaround or another test that can fail: in `/a+?b*/` on `aab`, `a+?` takes one
`a`, `b*` matches nothing after it, and the match is `a`. A `$`, a `\b` or a lookahead after
the suffix lets the quantifier take more, and the rule stays silent.
Under `/U` the message says where the laziness comes from:
`Quantifier "+" is lazy under the U flag and ends the pattern, so it always matches its minimum.`

A quantifier inside a group that a subroutine call runs again (`(?1)`, `(?&name)`) is not
reported: where the call stands, something may follow it. In `/(?1)x(a+?)/`, the call takes
`aaa` from `aaaxa`. A recursive pattern (`(?R)`, `(?0)`) is not checked by this rule at all.

**Example:**
```php
// WARNING: +? stops after one digit
preg_match('/id-\d+?/', 'id-123', $m);  // $m[0] is "id-1"

// WARNING: under /U, + is lazy: each space is replaced on its own
preg_replace('/\s+/U', ' ', "a   b");    // "a   b"

// PREFERRED
preg_match('/id-\d+/', 'id-123', $m);   // "id-123"
preg_match('/id-\d+?$/', 'id-123', $m); // "id-123": something follows
```

**Fix:** Make the quantifier greedy, write its minimum, or anchor what must follow it.

---

**Try it.** [Open `/id-\d+?/` in the playground](/#pattern=%2Fid-%5Cd%2B%3F%2F&subject=id-123): the finding is in the Diagnostics tab.
