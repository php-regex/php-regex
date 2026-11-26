---
description: "The (.|\\n) anti-pattern for matching any character: the dot already matches all but the newline, so the s flag or [\\s\\S] does it in one step."
head_title: "(.|\\n) in PHP regex: use the s flag instead"
parent: /reference/rules/
rule_category: "Alternation"
rule_order: 4
rule_ids:
  - regex.lint.alternation.dotNewline
---
# Dot or Newline Alternation

**Identifier:** `regex.lint.alternation.dotNewline`

**When it triggers:** An alternation reads `(.|\n)`, the anti-pattern for "any character including newlines". The dot already matches every character but a newline, so the branch adds nothing but a slower, two-way choice.

**Example** (real `regex lint` output):
```
      → /(.|\n)/
    WARN Alternation (.|\n) is an anti-pattern for matching any character including newlines.
         ↳ Use the "s" (PCRE_DOTALL) flag to make "." match newlines, or use [\s\S] instead of (.|\n).
```

**Fix:** Use the `s` flag so the dot crosses newlines, or write `[\s\S]`.

---

**Try it.** [Open `/(.|\n)/` in the playground](/#pattern=%2F%28.%7C%5Cn%29%2F&subject=a%0Ab): the finding is in the Diagnostics tab.
