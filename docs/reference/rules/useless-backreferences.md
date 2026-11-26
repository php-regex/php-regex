---
description: "A backreference written before its group can be set, or to a group that is always empty, as \\1(a): why it adds no constraint, and where to move it."
head_title: "Useless backreference in a PHP regex"
parent: /reference/rules/
rule_category: "Backreferences"
rule_order: 1
rule_ids:
  - regex.lint.backref.useless
---
# Useless Backreferences

**Identifier:** `regex.lint.backref.useless`

**When it triggers:** A backreference is used before its capturing group can be set or when the group is guaranteed to be empty.

A backreference inside a group that a subroutine call runs again is not reported: the call may run it after the group has captured, as in `/^(?:(a)|(\1))(?2)$/`. A recursive pattern (`(?R)`, `(?0)`) is not checked by this rule.

Nor is a backreference that a loop around it and its group runs after an earlier iteration has captured, where an iteration may skip the reference through another branch or an optional item: `/^(a|b\1)+$/`, the PCRE2 manual's example, matches `aba`, and `/^(?:x(a)|\1)+$/` matches `xaa`. Where every way through the loop passes the reference first, as in `/^(a\1)+$/`, no iteration ever sets the group, and the reference is reported.

**Example:**
```php
// WARNING: Backreference appears before the group closes
preg_match('/\1(a)/', $input);

// WARNING: Capturing group is always empty
preg_match('/(\b)a\1/', $input);

// PREFERRED: Move the backreference after the group
preg_match('/(a)\1/', $input);
```

**Fix:** Move the backreference after the capturing group or remove it if it adds no constraint.

---

**Try it.** [Open `/\1(a)/` in the playground](/#pattern=%2F%5C1%28a%29%2F&subject=aa): the finding is in the Diagnostics tab.
