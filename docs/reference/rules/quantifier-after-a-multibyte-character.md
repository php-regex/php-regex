---
description: "Without the u modifier a quantifier after a multibyte character repeats its last byte alone, so é+ is not one or more letters: add /u. An error of the lint."
head_title: "Quantifier after a multibyte character, no /u"
parent: /reference/rules/
rule_category: "Bytes Without /u"
rule_order: 2
rule_ids:
  - regex.lint.unicode.quantifiedMultibyteWithoutU
---
# Quantifier After a Multibyte Character

**Identifier:** `regex.lint.unicode.quantifiedMultibyteWithoutU`

**When it triggers:** A quantifier follows a character of two bytes or more, and the
pattern has neither `/u` nor `(*UTF)`. The quantifier repeats the last byte only.

**Example:**
```php
// ERROR: + repeats \xA9, the last byte of "é"
preg_match('/^é+$/', 'éé');      // 0
preg_match('/^é+$/', "é\xA9");   // 1

// PREFERRED
preg_match('/^é+$/u', 'éé');     // 1
preg_match('/^(?:é)+$/', 'éé');  // 1, still byte mode
```

**Fix:** Add `/u`, or group the character so the quantifier takes all of it.

---

**Try it.** [Open `/^é+$/` in the playground](/#pattern=%2F%5E%C3%A9%2B%24%2F&subject=%C3%A9%C3%A9): the finding is in the Diagnostics tab.
