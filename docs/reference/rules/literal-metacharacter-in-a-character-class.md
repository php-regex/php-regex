---
description: "Inside [...] a * , + or ? is a literal character, so [\\w*] matches a word character or a star: the quantifier was likely meant outside the class."
head_title: "Literal metacharacter in a PHP regex class"
parent: /reference/rules/
rule_category: "Character Classes"
rule_order: 6
rule_ids:
  - regex.lint.charclass.literalMetachar
---
# Literal Metacharacter in a Character Class

**Identifier:** `regex.lint.charclass.literalMetachar`

**When it triggers:** A small character class holds a shorthand (`\w`, `\d`, `\s` or their negations) next to an unescaped `*`, `+` or `?`. Inside `[...]` these are literal characters, so `[\w*]` matches a word character or a `*`: the quantifier was likely meant outside the class. It is not reported when the metacharacter is written with a backslash, as `\*` in `[\w\*]`, which says the literal is meant, in a negated class such as `[^\s+]`, or in a class that lists three elements or more besides it, such as `[\w+.-]`, which builds a set on purpose.

**Example:**
```php
// WARNING: "*" is a literal character inside a character class, not a quantifier
preg_match('/^[\w*]$/', '*');    // 1: the class matches a "*"

// PREFERRED: Quantify the shorthand
preg_match('/^\w*$/', '*');      // 0

// PREFERRED: Escape the literal with a backslash when it is meant
preg_match('/^[\w\*]$/', '*');   // 1, not reported
```

**Fix:** Move the quantifier out of the class, or escape the character with a backslash when the literal is meant.

---

**Try it.** [Open `/^[\w*]$/` in the playground](/#pattern=%2F%5E%5B%5Cw%2A%5D%24%2F&subject=%2A): the finding is in the Diagnostics tab.
