---
description: "Under --report-dynamic, a pattern argument with nothing static is reported as regex.lint.source.dynamic: no rule ran on it. Validate it where it enters."
head_title: "Regex pattern built at run time: not linted"
parent: /reference/rules/
rule_category: "Sources"
rule_order: 2
rule_ids:
  - regex.lint.source.dynamic
---
# Pattern Built at Run Time

**Identifier:** `regex.lint.source.dynamic` (info)

**When it triggers:** Under `regex lint --report-dynamic`, or `"reportDynamic": true` in
`regex.json`, on a pattern argument of which the file proves nothing: a parameter, the result
of a method, a property the lint does not read, or a text that does not start with a literal,
whose delimiter is then unknown. No rule ran on it: the issue says so, at the argument,
instead of leaving the call out silently. Without the option the run only counts these
arguments, as the `dynamic` figure of its `Not verifiable` line.

**Message:** `The pattern is built at run time: it is not verified.`

**Example:**
```php
public function matches(string $pattern, string $text): bool
{
    return 1 === preg_match($pattern, $text);  // reported at $pattern
}
```

It is an info and never fails a run. It is no lint rule: `checks.lint.rules`, `--enable-rule`
and `--disable-rule` do not name it, and `--report-dynamic` turns it on with the two below.
In the JSON report its result has no `pattern`; the argument is under `expression`, as the
file writes it.

**Fix:** None is needed when the pattern is meant to come from elsewhere: validate it where
it enters the program, with `Regex::create()->validate($pattern)` for a pattern a user or a
configuration gives. A marker on the line silences the issue,
`// @regex-ignore regex.lint.source.dynamic`. When the text is in the file after all,
[the CLI guide](../../guides/cli.md#patterns-held-in-a-constant-a-variable-or-a-property) lists
what the lint follows and what it does not.
