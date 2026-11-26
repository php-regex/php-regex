---
description: "Under --report-dynamic, a pattern argument that holds a part built at run time is reported as regex.lint.source.dynamicPart: quote a text with preg_quote()."
head_title: "Regex pattern around a part built at run time"
parent: /reference/rules/
rule_category: "Sources"
rule_order: 3
rule_ids:
  - regex.lint.source.dynamicPart
---
# Pattern Around a Part Built at Run Time

**Identifier:** `regex.lint.source.dynamicPart` (info)

**When it triggers:** Under `regex lint --report-dynamic`, on a pattern argument that starts
with a literal and holds a part the file does not prove, other than a `preg_quote()` call. The
part decides whether the pattern compiles and what it matches (`.*`, `a|`, an unclosed group),
so no rule ran on the rest either. Without the option the run counts these arguments as the
`around a dynamic part` figure of its `Not verifiable` line.

**Message:** `The pattern holds a part built at run time: it is not verified.`

**Example:**
```php
public function startsWith(string $prefix, string $text): bool
{
    return 1 === preg_match('/^' . $prefix . '\d+$/', $text);  // reported at '/^'
}
```

**Fix:** When the part is a text to match as it is, pass it through `preg_quote()` with the
delimiter of the pattern. When it is a fragment of a pattern on purpose, nothing is to fix:
validate the whole pattern where it is built, or silence the line with
`// @regex-ignore regex.lint.source.dynamicPart`.
