---
description: "Two or more literal spaces in a row are hard to count: a counted repeat,  {n}, says how many. A style rule, off by default."
head_title: "Count literal spaces in a PHP regex: {n}"
parent: /reference/rules/
rule_category: "Literals"
rule_order: 1
rule_ids:
  - regex.lint.literal.multipleSpaces
---
# Multiple Spaces

**Identifier:** `regex.lint.literal.multipleSpaces` (off by default)

**When it triggers:** Two or more literal spaces in a row are hard to count; ` {n}` says how
many. A style rule: turn it on with `"literal.multipleSpaces": true` under
`checks.lint.rules`. Only spaces written bare are counted: under `x` or `xx`, or after
`(?x)`, they are not literal, and quoted (`\Q  \E`), escaped or class spaces are not bare. A
quantifier on the last space enters the count: the tip for `/a  +/` is ` {2,}`.

**Example:**
```php
// INFO: how many spaces?
preg_match('/a   b/', 'a   b');   // 1

// PREFERRED
preg_match('/a {3}b/', 'a   b');  // 1
```

**Fix:** Write the space once with its count.

---

**Try it.** [Open `/a   b/` in the playground](/#pattern=%2Fa%20%20%20b%2F&subject=a%20%20%20b): the rule is off by default, so the Diagnostics tab shows the findings of the rules that are on.
