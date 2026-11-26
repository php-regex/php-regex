---
description: "An anchor binds tighter than |: in ^a|b|c$ only a is anchored at the start and c at the end. Group the alternatives when you meant all of them."
head_title: "Anchors and alternation in PHP regex: ^a|b|c$"
parent: /reference/rules/
rule_category: "Anchors"
rule_order: 2
rule_ids:
  - regex.lint.anchor.alternationPrecedence
---
# Anchor Precedence in Alternation

**Identifier:** `regex.lint.anchor.alternationPrecedence`

**When it triggers:** An anchor binds tighter than `|`: `/^a|b|c$/` is `^a`, or `b` anywhere,
or `c$`. The rule speaks of intent and never says the pattern is wrong. It fires when the
first alternative of the pattern starts with `^`, `\A` or `\G` (past the option settings,
verbs and comments before it), or the last one ends with `$`, `\z` or `\Z`, and some
alternative has an anchor on neither side. The anchor may sit inside a group that changes
nothing about where the alternative matches (`(?:b$)`, `(b$)`, `(?>b$)`, a one-alternative
`(?|b$)`) or be all a positive lookaround asserts (`b(?=$)`). An alternation whose every
alternative is anchored on one side is left alone, as the trim idiom `/^\s+|\s+$/` is, and so
is an alternative that is the anchor alone (`/a|$/`: "or the end"). As in SonarPHP, an anchor anywhere
but the start of the first alternative and the end of the last shows the anchors are placed
alternative by alternative, and the rule stays silent (`/^ +| +$|,/`, `/^a|b|^c/`), as it does
under the `A` modifier, which anchors every alternative at the start. An alternative of verbs
and comments only, as `(*FAIL)`, is not counted, and an empty one (`/^a|/`) is
`regex.lint.alternation.empty`'s. A verb that ends the match
attempt on backtracking, accepts at once or fails (`(*COMMIT)`, `(*PRUNE)`, `(*SKIP)`,
`(*ACCEPT)`, `(*FAIL)`), in an alternative that reads something, leaves the rule silent: it can
keep the engine from the other alternatives (`/(*COMMIT)^a|b/` and `/^(*COMMIT)a|b/` never
match `b`), and a failing verb would carry into the grouped form, which then matches nothing.
A `(*SKIP:n)` that no `(*MARK:n)` names is ignored by the engine, and by the rule. The tip
keeps the options before the anchor (`(?i)^(?:a|b)`), keeps text
quoted with `\Q...\E` quoted (`^(?:a|\Qb)\E)` for `/^a|\Qb)\E/`), and leaves out the comments
ending each alternative, so that it compiles under `/x`. A group holding the anchor alone
leaves with it (`^(?:a|b)` for `/(?:^)a|b/`), but a capturing group stays, keeping its number,
and a group setting `m` moves as written (`(?m:^)(?:a|b)`).

**Example:**
```php
// WARNING: "b" matches anywhere
preg_match('/^a|b|c$/', 'xbx');      // 1

// PREFERRED, if the anchors are meant for every alternative
preg_match('/^(?:a|b|c)$/', 'xbx');  // 0
```

**Fix:** Group the alternatives, as the tip shows, when the anchors are meant for all of them.

---

**Try it.** [Open `/^a|b|c$/` in the playground](/#pattern=%2F%5Ea%7Cb%7Cc%24%2F&subject=xbx): the finding is in the Diagnostics tab.
