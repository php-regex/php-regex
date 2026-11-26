---
description: "Inside a class, \\1 to \\9 is an octal escape, not a backreference: [\\1] is the byte \\x01. What the rule reports, and how to write the class."
head_title: "\\1 inside a PHP regex class is octal"
parent: /reference/rules/
rule_category: "Character Classes"
rule_order: 7
rule_ids:
  - regex.lint.charclass.backrefAsOctal
---
# Backreference Written Inside a Character Class

**Identifier:** `regex.lint.charclass.backrefAsOctal`

**When it triggers:** A class holds `\1`-`\9` while a group of that number exists. Inside a class the escape is octal, not a backreference: `[\1]` is the byte `\x01`. Without a group of that number the escape is plain octal and nothing is reported.

**Example** (real `regex lint` output):
```
      → /(a)[\1]/
    WARN Suspicious \1 inside character class: this is octal (\x01), not a backreference to group 1.
         ↳ Backreferences do not work inside character classes. If you intended to match the same text as group 1, move
           the check outside the class.
```

**Fix:** Match the same text as the group outside the class (`(a)\1`), or write the byte you mean (`[\x01]`).

---

**Try it.** [Open `/(a)[\1]/` in the playground](/#pattern=%2F%28a%29%5B%5C1%5D%2F&subject=a%01): the finding is in the Diagnostics tab.
