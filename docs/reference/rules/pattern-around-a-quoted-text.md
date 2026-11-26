---
description: "Under --report-dynamic, a pattern built around a preg_quote() text is reported as regex.lint.source.quotedPart: nothing to fix: that text comes at run time."
head_title: "Regex pattern around a preg_quote() text"
parent: /reference/rules/
rule_category: "Sources"
rule_order: 4
rule_ids:
  - regex.lint.source.quotedPart
---
# Pattern Around a Quoted Text

**Identifier:** `regex.lint.source.quotedPart` (info)

**When it triggers:** Under `regex lint --report-dynamic`, on a pattern argument that starts
with a literal and whose every part the file does not prove is a `preg_quote()` call: written
in the pattern, assigned to a variable first, or given to `sprintf()`. The text is known at run
time only, so the pattern is not linted. Without the option the run counts these arguments as
the `around preg_quote()` figure of its `Not verifiable` line.

**Message:** `The pattern holds a preg_quote() text: it is not verified.`

**Example:**
```php
public function hasWord(string $word, string $text): bool
{
    return 1 === preg_match('/\b' . preg_quote($word, '/') . '\b/i', $text);  // reported at '/\b'
}
```

**Fix:** Nothing is wrong with the call. Silence the line with
`// @regex-ignore regex.lint.source.quotedPart` when the list should stay short.
