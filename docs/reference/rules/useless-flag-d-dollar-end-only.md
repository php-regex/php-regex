---
description: "The D modifier only stops $ from matching before a final newline: a PHP pattern with no $, or with every $ under m, does not need it."
head_title: "PHP regex D flag: when it changes nothing"
parent: /reference/rules/
rule_category: "Flags"
rule_order: 4
rule_ids:
  - regex.lint.flag.useless.D
---
# Useless Flag 'D' (Dollar End Only)

**Identifier:** `regex.lint.flag.useless.D`

**When it triggers:** The pattern sets the `D` modifier, which only stops a `$` from matching before a final newline, but no `$` is read without `m`: there is no `$` at all (`\Z` and a `$` in a class are not read by `D`), or every `$` is under `m`, set as a modifier or inline, which overrides `D`.

**Message:** `Flag 'D' is useless: the pattern contains no $ anchor.` or `Flag 'D' is useless: every $ anchor is read under m, which overrides D.`

**Example:**
```php
// Warning: no $ for D to read
preg_match('/^[a-z]+\z/D', $input);

// Warning: m overrides D on every $
preg_match('/^[a-z]+$/mD', $input);

// Preferred: drop the flag
preg_match('/^[a-z]+\z/', $input);
```

**Fix:** Drop the flag, or write `\z` and drop it.

---

**Try it.** [Open `/^[a-z]+\z/D` in the playground](/#pattern=%2F%5E%5Ba-z%5D%2B%5Cz%2FD&subject=hello): the finding is in the Diagnostics tab.
