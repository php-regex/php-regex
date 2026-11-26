---
description: "A capturing group that is empty wherever the pattern matches, as $1 in /^\\w+(\\d*)$/: how the automata prove it, and how to narrow what precedes it."
head_title: "A capture group that always captures nothing"
parent: /reference/rules/
rule_category: "Groups"
rule_order: 3
rule_ids:
  - regex.lint.group.alwaysEmptyCapture
---
# Capture That Never Captures Text

**Identifier:** `regex.lint.group.alwaysEmptyCapture`

**When it triggers:** A capturing group is empty or unset wherever the pattern matches: in
`/a+(a*)/` the greedy `a+` takes every `a`, so `$1` is always `""`; in `/^\w+(\d*)$/` `\w+`
takes the digits. The automata prove it: the pattern with the group's body removed writes the
same `$matches` on every subject, from offset 0 and, for a pattern with a start anchor, again
with the anchor failing, as it does where `preg_match_all()` goes on (`/^.|.(c)/s` fills `$1`
there). It stays silent on a group with nothing in it (`()` is a marker), on a group in a branch
reset (`(?|...)`), whose number another alternative may fill, in a pattern that may match the empty string (`preg_match_all()` tries again after an
empty match, where another path may fill the group), where `quantifier.lazyEnd` already says
why the group stays empty (`/^L_(.*?)/`), in a repeat of zero copies, which defines a group a call
may reach (`/(b){0}/`), on a group
a reference or a call reads, which the automata do not follow, and past the eighth question it
asks them about in a pattern.

**Example:**
```php
// WARNING: $1 is always ""
preg_match('/^(\w+)(\d*)$/', 'abc123', $m);  // ["abc123", "abc123", ""]

// PREFERRED
preg_match('/^([a-z]+)(\d*)$/', 'abc123', $m);  // ["abc123", "abc", "123"]
```

**Fix:** Remove the group, or narrow what precedes it so the group can read text.

---

**Try it.** [Open `/^(\w+)(\d*)$/` in the playground](/#pattern=%2F%5E%28%5Cw%2B%29%28%5Cd%2A%29%24%2F&subject=abc123): the finding is in the Diagnostics tab.
