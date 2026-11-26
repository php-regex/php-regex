---
description: "An empty non-capturing or atomic group, (?:) or (?>), matches the empty string and changes nothing: what to remove, and the exceptions that matter."
head_title: "Empty group (?:) in a PHP regex"
parent: /reference/rules/
rule_category: "Groups"
rule_order: 2
rule_ids:
  - regex.lint.group.empty
---
# Empty Group

**Identifier:** `regex.lint.group.empty`

**When it triggers:** A non-capturing or atomic group is empty, `(?:)` or `(?>)`: it matches
the empty string and changes nothing. An empty capturing group `()` is a placeholder that
numbers a group and is left alone, as are empty lookarounds, which have rules of their own.
So is a `(?:)` that keeps an escape apart from the digit after it, the form the library's own
printer writes: `(a)\1(?:)0` is not `(a)\10`, and `\01(?:)2` is not the newline `\012`; one
that keeps braces from a count, `a{(?:)2}`, `a{1,(?:)2}` or `a{(?:),2}` (`{,2}` is a
quantifier since PCRE2 10.43); and one a quantifier repeats: `a(?:)?` matches `a` alone,
`a?` the empty string too. An unbounded repeat of an empty group, `(?>)+`, is
`regex.lint.quantifier.emptyRepeat`'s. So is an empty group PCRE2 enters to choose which
repeats it makes possessive: `/a+(?>(?:)|b)a/` finds `aa`, where `/a+(?>|b)a/` finds nothing,
and one inside a group a call runs that repeats something, or in a pattern that calls itself
whole: with the JIT, `/(a+(?:)c)x(?1)d/` finds `acxacd` in `acxacxacd`, `/(a+c)x(?1)d/` nothing.

**Example:**
```php
// WARNING: the group does nothing
preg_match('/a(?:)b/', 'ab');  // 1

// PREFERRED
preg_match('/ab/', 'ab');      // 1
```

**Fix:** Remove the group.

---

**Try it.** [Open `/a(?:)b/` in the playground](/#pattern=%2Fa%28%3F%3A%29b%2F&subject=ab): the finding is in the Diagnostics tab.
