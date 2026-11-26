---
description: "The x modifier drops whitespace and # comments: a PHP pattern that holds neither gains nothing from it, and the linter reports the flag."
head_title: "PHP regex x flag: when it changes nothing"
parent: /reference/rules/
rule_category: "Flags"
rule_order: 5
rule_ids:
  - regex.lint.flag.useless.x
---
# Useless Flag 'x' (Extended)

**Identifier:** `regex.lint.flag.useless.x`

**When it triggers:** The pattern sets the `x` modifier, which only drops whitespace and `#` comments, but holds no whitespace and no `#` comment. A `#` in a class, escaped, or in a `(?#...)` group opens no comment. Whitespace is looked for in the pattern as written: a space in a class or after a backslash, which `x` keeps, silences the rule too, and so does a no-break space, which `x` drops under a UTF-8 `LC_CTYPE` locale. A pattern holding both a `#` and a NUL byte is left alone: under `(*NUL)` a NUL ends a comment.

**Message:** `Flag 'x' is useless: the pattern contains no whitespace and no # comment.`

**Example:**
```php
// Warning: nothing for x to drop
preg_match('/^\d{4}-\d{2}$/x', $input);

// Preferred
preg_match('/^\d{4}-\d{2}$/', $input);
```

**Fix:** Drop the flag, or lay the pattern out with spaces and comments.

---

**Try it.** [Open `/^\d{4}-\d{2}$/x` in the playground](/#pattern=%2F%5E%5Cd%7B4%7D-%5Cd%7B2%7D%24%2Fx&subject=2026-11): the finding is in the Diagnostics tab.
