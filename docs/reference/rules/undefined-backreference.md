---
description: "A numbered or named backreference to a group the pattern does not have, as (a)\\2: why PHP refuses to compile it, and what to write instead."
head_title: "Backreference to a group that does not exist"
parent: /reference/rules/
rule_category: "Backreferences"
rule_order: 2
rule_ids:
  - regex.lint.backref.undefined
---
# Undefined Backreference

**Identifier:** `regex.lint.backref.undefined`

**When it triggers:** A numbered or named backreference points to a group the pattern does not have: `\2` with a single group, `\k<name>` with no group of that name. A `\NN` of two digits or more that names no group and starts with an octal digit is an octal escape instead (`\11` is a tab), and is not reported. Validation refuses the same defect first (`regex.backref.missing_group`, `regex.backref.missing_named_group`), so `regex lint` reports the pattern as invalid; the lint id is what the linter reports on a parsed tree.

**Example:**
```php
// FAIL (validation): Backreference to non-existent group: \2.
preg_match('/(a)\2/', $input);

// PREFERRED: refer to a group that exists
preg_match('/(a)\1/', $input);
```

**Fix:** Number or name the group the reference points to, or drop the reference.

---

**Try it.** [Open `/(a)\2/` in the playground](/#pattern=%2F%28a%29%5C2%2F&subject=aa): the Diagnostics tab shows what PHP prints and the code PHPRegex gives it.
