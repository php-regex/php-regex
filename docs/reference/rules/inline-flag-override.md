---
description: "An inline flag that explicitly unsets a global modifier, as (?-i:foo) under the i modifier: why the rule reports it, and how to scope the flag."
head_title: "Inline flag overrides a PHP regex modifier"
parent: /reference/rules/
rule_category: "Inline Flags"
rule_order: 2
rule_ids:
  - regex.lint.flag.override
---
# Inline Flag Override

**Identifier:** `regex.lint.flag.override`

**When it triggers:** An inline flag explicitly unsets a global modifier.

**Example:**
```php
// WARNING: Unset global flag
preg_match('/(?-i:foo)/i', $input);

// CONSIDER: Scope the flag instead
preg_match('/(?i:foo)bar/', $input);
```

---

**Try it.** [Open `/(?-i:foo)/i` in the playground](/#pattern=%2F%28%3F-i%3Afoo%29%2Fi&subject=foo): the finding is in the Diagnostics tab.
