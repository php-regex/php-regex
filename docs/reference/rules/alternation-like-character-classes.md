---
description: "A | inside [...] is a literal character, so [error|failure] matches one character of those words: write (error|failure) for the alternation."
head_title: "| is literal inside a PHP regex character class"
parent: /reference/rules/
quick_fix: "Write an alternation, (error|failure)"
quick_fix_order: 19
rule_category: "Character Classes"
rule_order: 5
rule_ids:
  - regex.lint.charclass.suspiciousPipe
---
# Alternation-like Character Classes

**Identifier:** `regex.lint.charclass.suspiciousPipe`

**When it triggers:** A character class contains `|` alongside many letters, suggesting an alternation typo.

**Example:**
```php
// WARNING: | is literal inside []
preg_match('/[error|failure]/', $input);

// PREFERRED: Use alternation
preg_match('/(error|failure)/', $input);
```

**Fix:** Replace the class with an alternation group when you intend multi-character words.

---

**Try it.** [Open `/[error|failure]/` in the playground](/#pattern=%2F%5Berror%7Cfailure%5D%2F&subject=error): the finding is in the Diagnostics tab.
