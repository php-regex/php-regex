---
description: "A class holding one character, as [a], reads the same without the brackets: a style rule, off by default, and the cases where the brackets stay."
head_title: "Character class of one character in PHP regex"
parent: /reference/rules/
rule_category: "Character Classes"
rule_order: 8
rule_ids:
  - regex.lint.charclass.single
---
# Single-Character Class

**Identifier:** `regex.lint.charclass.single` (off by default)

**When it triggers:** A class holds one character, `[a]`, which reads the same without the
class. A style rule: turn it on with `"charclass.single": true` under `checks.lint.rules`.
A class holding one metacharacter (`[.]`, `[*]`, and under `x`, `[#]` or any white space `x`
skips: a space, tab, line feed, vertical tab, form feed, carriage return or next line, and
under `/u` U+200E, U+200F, U+2028 and U+2029) is the clearer escape and is left alone, as are a negated class `[^a]`, the delimiter `[\/]`, an escape a
class reads otherwise (`[\b]` is a backspace, `[\1]` an octal escape where `\1` is a
reference), a multibyte character without `/u`, whose bytes `[é]` reads one at a time, and a
character that would join the text around the class: `\1[0]` is not `\10`, `a{[2]}` not
`a{2}`. The message quotes the class as written, `[\Qa\E]`. `[aa]` is
`regex.lint.charclass.redundant`'s.

**Example:**
```php
// INFO: the class adds nothing
preg_match('/x[a]y/', 'xay');  // 1

// PREFERRED
preg_match('/xay/', 'xay');    // 1
```

**Fix:** Write the character alone.

---

**Try it.** [Open `/x[a]y/` in the playground](/#pattern=%2Fx%5Ba%5Dy%2F&subject=xay): the rule is off by default, so the Diagnostics tab shows the findings of the rules that are on.
