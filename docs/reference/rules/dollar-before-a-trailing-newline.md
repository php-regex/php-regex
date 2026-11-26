---
description: "Without the D modifier, $ also matches just before a newline that ends the subject, so \"123\\n\" passes /^\\d+$/. Write \\z or add D. The rule is off by default."
head_title: "$ matches before a final newline in PHP regex"
parent: /reference/rules/
rule_category: "Anchors"
rule_order: 3
rule_ids:
  - regex.lint.anchor.dollarNewline
---
# Dollar Before a Trailing Newline

**Identifier:** `regex.lint.anchor.dollarNewline` (info, off by default)

**When it triggers:** Without the `D` modifier and without `m`, `$` matches at the end of the
subject and also just before a newline that ends it: `/^\d+$/` accepts `"123\n"`. The rule
reports a `$` that ends a pattern anchored at its start, the shape of a whole-value check. The
alternative holding the `$` starts with `^` (read without `m`) or `\A`, or the `A` modifier
anchors it; each anchored alternative is checked on its own, so `/^a$|^b$/` is reported twice,
and `/^a|b$/` not at all. Nothing but comments may follow the `$`, and no subroutine call may run
it from elsewhere. The rule speaks only when the trailing newline makes a difference: what
precedes the `$` cannot end with a newline (`/^a\s*$/`, `/^[^,]+$/` and `/^.+$/s` take the
newline themselves and are left alone), and no lookahead in the pattern may read the newline the
`$` leaves. Under `(*CRLF)` the newline is `\r\n`, under `(*CR)` a `\r`; under `(*ANY)`, whose
newlines lie above ASCII, the rule stays silent. `\Z` is PCRE's explicit spelling of "the end, or
before a final newline" and is not reported; neither is a `$` under `m`, set as a modifier or
inline, which asks for line ends.

The rule is off by default: most anchored patterns end with `$`, and most of them read values
that never carry a trailing newline. It is recommended for code that validates untrusted input,
where `"admin\n"` passing `/^[a-z]+$/` can reach a header, a log line or a file name. Turn it on
with `"anchor.dollarNewline": true` under `checks.lint.rules` in `regex.json`. PHPStan and the
language server have no switch for one rule: they follow the defaults and do not report it.

**Message:** `"$" also matches before a newline that ends the subject, so a value with a trailing newline passes.`

**Example:**
```php
// INFO: the trailing newline passes the check
preg_match('/^\d+$/', "123\n");   // 1

// PREFERRED
preg_match('/^\d+\z/', "123\n");  // 0
preg_match('/^\d+$/D', "123\n");  // 0
```

**Fix:** Write `\z` to match at the very end only, or add the `D` modifier.

---

**Try it.** [Open `/^\d+$/` in the playground](/#pattern=%2F%5E%5Cd%2B%24%2F&subject=123%0A): the rule is off by default, so the Diagnostics tab shows the findings of the rules that are on.
