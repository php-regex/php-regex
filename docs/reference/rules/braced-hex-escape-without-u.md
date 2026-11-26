---
description: "A braced escape \\x{...} that names a code point above U+FF does not compile in byte mode: PCRE2 refuses it without /u. An error of the lint, and the fix."
head_title: "Braced hex escape \\x{100} without the u flag"
parent: /reference/rules/
rule_category: "Bytes Without /u"
rule_order: 4
rule_ids:
  - regex.lint.unicode.bracedHexWithoutU
---
# Braced Hex Escape Without /u

**Identifier:** `regex.lint.unicode.bracedHexWithoutU`

**When it triggers:** A braced escape `\x{...}` names a code point above U+FF, and the pattern has neither `/u` nor `(*UTF)`. Unlike the three rules above, the pattern does not even compile in byte mode — PCRE2 refuses it — so validation reports it first, and the lint id names the same defect on a parsed tree, at error severity.

**Example:**
```php
// FAIL (validation): Invalid code point "\x{100}":
//      without the "u" flag, a character is at most \xFF.
preg_match('/\x{100}/', $input);

// PREFERRED
preg_match('/\x{100}/u', $input);
```

**Fix:** Add `/u`.

---

**Try it.** [Open `/\x{100}/` in the playground](/#pattern=%2F%5Cx%7B100%7D%2F&subject=): the Diagnostics tab shows what PHP prints and the code PHPRegex gives it.
