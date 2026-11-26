---
description: "A Unicode property, \\p{…} or \\P{…}, without the u modifier compiles but covers only the first 256 code points: add /u. An error of the lint."
head_title: "Unicode property \\p{L} without the u flag"
parent: /reference/rules/
rule_category: "Bytes Without /u"
rule_order: 3
rule_ids:
  - regex.lint.unicode.propertyWithoutU
---
# Unicode Property Without /u

**Identifier:** `regex.lint.unicode.propertyWithoutU`

**When it triggers:** A Unicode property (`\p{…}` or `\P{…}`) is used, and the pattern has
neither `/u` nor `(*UTF)`. The property then reads one byte at a time, as a code point
below 256, so it covers only the first 256 code points.

**Example:**
```php
// ERROR: "é" is the bytes \xC3 \xA9, and \xA9 is no letter
preg_match('/^\p{L}+$/', 'é');   // 0

// PREFERRED
preg_match('/^\p{L}+$/u', 'é');  // 1
```

**Fix:** Add `/u`.

---

**Try it.** [Open `/^\p{L}+$/` in the playground](/#pattern=%2F%5E%5Cp%7BL%7D%2B%24%2F&subject=%C3%A9): the finding is in the Diagnostics tab.
