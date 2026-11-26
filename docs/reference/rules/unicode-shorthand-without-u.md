---
description: "Without the u modifier, \\w, \\d and \\s match ASCII only, so é is no \\w match: a style rule, off by default, and what /u changes."
head_title: "\\w, \\d, \\s match ASCII only without /u"
parent: /reference/rules/
rule_category: "Bytes Without /u"
rule_order: 5
rule_ids:
  - regex.lint.unicode.shorthandWithoutU
---
# Unicode Shorthand Without /u

**Identifier:** `regex.lint.unicode.shorthandWithoutU` (off by default)

**When it triggers:** A `\w`, `\d`, `\s` or one of their negations runs without `/u`: each matches ASCII only, so `é` is no `\w` match. A style rule: turn it on with `"unicode.shorthandWithoutU": true` under `checks.lint.rules`, or `--enable-rule=unicode.shorthandWithoutU` on the command line.

**Example** (real `regex lint` output):
```
      → /\w+/
    INFO Shorthand "\w" matches only ASCII without /u flag.
         ↳ Add /u flag for Unicode support, or use \p{L} for letters.
```

```php
preg_match('/^\w+$/', 'é');    // 0: é is no ASCII word character
preg_match('/^\w+$/u', 'é');   // 1
```

**Fix:** Add `/u`, or write the property you mean (`\p{L}`).

---

**Try it.** [Open `/^\w+$/` in the playground](/#pattern=%2F%5E%5Cw%2B%24%2F&subject=%C3%A9): the rule is off by default, so the Diagnostics tab shows the findings of the rules that are on.
