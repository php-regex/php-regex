---
description: "The s modifier only moves the dot: in a PHP pattern with no unescaped dot outside a character class it changes nothing, and the linter reports it."
head_title: "PHP regex s flag (DOTALL) does nothing here"
parent: /reference/rules/
rule_category: "Flags"
rule_order: 1
rule_ids:
  - regex.lint.flag.useless.s
---
# Useless Flag 's' (DOTALL)

**Identifier:** `regex.lint.flag.useless.s`

**When it triggers:** The pattern sets the `s` (DotAll) modifier but contains no unescaped dot outside a character class. The `s` flag only affects `.`: an escaped `\.` and a `.` inside `[...]` are literal dots, which it leaves alone.

**Message:** `Flag 's' is useless: the pattern contains no unescaped dot outside a character class.`

**Visual Explanation:**
```
/^\d+\.\d+$/s
  -> no unescaped dot outside a class
  -> s has no effect
```

**Example:**
```php
// Warning: DotAll does nothing because there is no dot
preg_match('/^user_id:\d+$/s', $input);

// Preferred: Remove unnecessary flag
preg_match('/^user_id:\d+$/', $input);
```

**Fix:** Drop the flag or introduce a dot if intended.

**Read more:**
- [PHP: Pattern Modifiers (`s` / DOTALL)](https://www.php.net/manual/en/reference.pcre.pattern.modifiers.php)

---

**Try it.** [Open `/^user_id:\d+$/s` in the playground](/#pattern=%2F%5Euser_id%3A%5Cd%2B%24%2Fs&subject=user_id%3A42): the finding is in the Diagnostics tab.
