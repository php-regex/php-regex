---
description: "The m modifier only moves ^ and $: in a PHP pattern with neither anchor it changes nothing. What the rule reads, and how to fix the finding."
head_title: "PHP regex m flag is useless without ^ or $"
parent: /reference/rules/
rule_category: "Flags"
rule_order: 2
rule_ids:
  - regex.lint.flag.useless.m
---
# Useless Flag 'm' (Multiline)

**Identifier:** `regex.lint.flag.useless.m`

**When it triggers:** The pattern sets the `m` (multiline) modifier but contains no start/end anchors (`^` or `$`). `\A`, `\z` and `\Z` are not read by `m`, so they do not count.

**Message:** `Flag 'm' is useless: the pattern contains no ^ or $ anchor.`

**Visual Explanation:**
```
/search_term/m
  -> no ^ or $ anchors
  -> m has no effect
```

**Example:**
```php
// Warning: Multiline mode is unused because there are no anchors
preg_match('/search_term/m', $text);

// Preferred: Remove unnecessary flag
preg_match('/search_term/', $text);
```

**Fix:** Remove the flag or add anchors for per-line matching.

**Read more:**
- [PHP: Pattern Modifiers (`m` / MULTILINE)](https://www.php.net/manual/en/reference.pcre.pattern.modifiers.php)

---

**Try it.** [Open `/search_term/m` in the playground](/#pattern=%2Fsearch_term%2Fm&subject=search_term): the finding is in the Diagnostics tab.
