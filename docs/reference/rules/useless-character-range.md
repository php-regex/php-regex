---
description: "A range that spans one character, as [a-a]: the author meant a character or a wider range. Write the character alone; two consecutive ones are left alone."
head_title: "Useless range in a PHP regex character class"
parent: /reference/rules/
rule_category: "Character Classes"
rule_order: 3
rule_ids:
  - regex.lint.range.useless
---
# Useless Character Range

**Identifier:** `regex.lint.range.useless`

**When it triggers:** A range spans one character, as `[a-a]`: the author meant a character or a wider range. A range of two consecutive characters, `[a-b]` or `[0-1]`, is correct as written and the list `[ab]` saves one character only, so it is not reported: a generated Unicode table holds them by the thousand.

**Example:**
```php
// WARNING: Range only spans one character
preg_match('/[a-a]/', $input);

// PREFERRED: Write the character alone
preg_match('/[a]/', $input);

// Left alone: two consecutive characters are correct as written
preg_match('/[a-b]/', $input);
```

**Fix:** Write the character alone, or widen the range to what was meant.

---

**Try it.** [Open `/[a-a]/` in the playground](/#pattern=%2F%5Ba-a%5D%2F&subject=a): the finding is in the Diagnostics tab.
