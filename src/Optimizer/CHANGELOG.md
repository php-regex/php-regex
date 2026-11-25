CHANGELOG
=========

2.0
---

 * First release as its own package, split from `yoeunes/regex-parser`;
   see the [main changelog](https://github.com/php-regex/php-regex/blob/2.x/CHANGELOG.md).
 * The brackets of a possessive repeat of a group, or of an atomic group,
   that may match nothing stay as written after a repeated character that
   could give back to what follows: PCRE2 makes that character possessive,
   as if the group could not be skipped, and `/a+(?:b)?+a/` finds nothing in
   `aa`, where `/a+b?+a/`, which the check proved alike and the optimizer
   wrote, finds `aa`. The pattern now ships as written, and so do
   `/a+(?:b|c)?+a/`, which became `/a+[bc]?+a/`, and `/a+(?>(?:b)?)a/`,
   which became `/a+(?>b?)a/`. `/a+(?:b)?+c/` still becomes `/a+b?+c/`.
 * The possessive rewrite leaves a repetition greedy before a lookaround,
   which reads nothing yet looks at the characters the repetition would
   give back: `/a+(?!b)/` matches `a` in `aab`, `/a++(?!b)/` nothing. The
   lookaround may stand past the group the repetition ends, after items
   that may match nothing: `/(a+b?)(?!c)/` matches `a` in `aac`. It may
   stand past a scan substring too, which what follows backtracks into:
   `/^(aac)(*scs:(1)(a+b?))(?=\2ac)/` matches `aac` in `aacaac` with
   group 2 given back to `a`, and `/^(aac)(*scs:(1)(a+b?))(?=\2ac)[0-9]/` now becomes
   `/^(aac)(*scs:(1)(a+b?))(?=\2ac)\d/`. Such a
   rewrite, which the check of the result refused, no longer takes the
   other rewrites of the pattern with it: under the possessive option
   `/x\s+(?!y)/s` and `/(a+b?)(?!c)/s` lose their useless `s`, and
   `/^[a-z](?:[a-z0-9_](?!__))*[a-z0-9]+$/` becomes
   `/^[a-z](?:[0-9_a-z](?!__))*[0-9a-z]+$/`. The last `\s*` of
   `#^(?:([=<>!]=?|<>)\s*)?((?:\d*\.)?\d+)\s*(K|M|G|)B?$#Di` stays greedy:
   the empty branch lets `B` or the end follow it, which the first
   characters of the other branches left out.
 * The possessive rewrite reads past the group a repetition ends when the
   items after it in the group may match nothing: `/(a+b?)a/`,
   `/(?:a+b?)*a$/` and `/(?:[0-9]+b?)1/` keep their repetition greedy, which
   the check of the result refused, and `/(a+b?)a[0-9]/` becomes
   `/(a+b?)a\d/`. The body of a group that must run again follows its own
   end: `/(?:a+b?){2}c/` gives an `a` back to its second run on `aac`. `$`
   and `\Z` read as the newline they may stand before, so `/^(\d+\s*)$/`
   still becomes `/^(\d++\s*)$/`, and `/[0-9]+\s*$/` now becomes
   `/\d++\s*$/`.
 * The possessive rewrite reads what follows a group at every place it
   runs: where it stands, and after each call that runs it, `(?1)`,
   `(?-1)`, `(?+1)`, `(?&n)`, `(?P>n)`, `\g<n>`, or `(?R)` for the whole
   pattern, which gives back to what follows the call.
   `/(?&n)a(?(DEFINE)(?<n>a+b?))/` is no longer offered `a++b?`, which the
   check of the result refused before the rewrite was made a second time
   without the possessive step. A group in a DEFINE runs only where it is
   called: `/(?(DEFINE)(?<n>a+b?))a/` now becomes
   `/(?(DEFINE)(?<n>a++b?))a/`. A group called inside a script run stays
   greedy, as the check of the run reads what the call matched.
 * The check of the result runs the body of a group where each call of it
   stands, and proves the possessive the rewrite offers there:
   `/(a+b?)c(?1)d/` now becomes `/(a++b?)c(?1)d/` with the first rewrite,
   where the check refused it and the rewrite made a second time without
   the possessive step shipped the pattern unchanged. A group a DEFINE
   holds runs there too, under the options in force where the DEFINE
   stands: `/(?&n)c(?(DEFINE)(?<n>a+b?))/` now becomes
   `/(?&n)c(?(DEFINE)(?<n>a++b?))/`, and `/(?(DEFINE)(?<n>a|b))x(?&n)/`
   becomes `/(?(DEFINE)(?<n>[ab]))x(?&n)/`, a rewrite the check left
   unproven. The calls the check proves a rewrite in read what a DEFINE
   holds: `^` in a group it holds asks for the search past the start of
   the subject. A group that calls itself and a call of the whole pattern
   are read through every call, what may follow the group after each
   return included: `/(x(?1)?ca+b?)c/` now becomes `/(x(?1)?ca++b?)c/`,
   and `/(?&n)c(?(DEFINE)(?<n>x(?&n)?ca+b?))/` gets `ca++b?`, where the
   check refused them. A group repeated there stays greedy:
   `/x(?R)?(?:ab)++c/` runs out of the JIT stack deep in the calls where
   `/x(?R)?(?:ab)+c/` matches.
 * Under the possessive option, a rewrite whose possessive quantifiers the
   check of the result refuses is made again without them, and the other
   rewrites ship: the solver proves no possessive in a pattern that holds a
   lookaround or `\b`, and `/\d+x(?!y)/s`, `/(?=x)\d+y/s` and `/\d+y\b/s`
   now lose their useless `s`, as they do without the option. The second
   rewrite runs only on such a refusal.
 * The possessive rewrite reads an escape that names an ASCII character as
   that character: `/^[\x30-\x39]+x$/` becomes `/^[\x30-\x39]++x$/`, as
   `/^[0-9]+x$/` becomes `/^\d++x$/`; it was left greedy.
 * The possessive rewrite reads the flags in force where each item stands,
   and an option setting no longer keeps it out of the whole pattern:
   `/(?&n)(?i)c(?(DEFINE)(?<n>a+b?))/` now becomes
   `/(?&n)(?i)c(?(DEFINE)(?<n>a++b?))/`, and
   `/(?i:(?(DEFINE)(?<n>A+b?)))(?&n)c/` becomes
   `/(?i:(?(DEFINE)(?<n>A++b?)))(?&n)c/`, while `/(?i)a+b?A/` stays greedy.
   An option setting that names `U`, under which a plain quantifier is
   lazy, and a non-atomic lookaround, past whose end the engine reads from
   where it starts, still keep every quantifier of the pattern as written.
 * The possessive rewrite reads what follows a repetition that ends its
   sequence, or stands alone in its group, its branch or the pattern: what
   follows the group at every place it runs, the alternation or the
   conditional, up to the end of the pattern. `/(a+)c/` now becomes
   `/(a++)c/`, `/(?&n)x(?(DEFINE)(?<n>[0-9]+))/` becomes
   `/(?&n)x(?(DEFINE)(?<n>\d++))/`, and `/x[0-9]+/` becomes `/x\d++/`,
   while `/(a+)a/`, `/(?:a+){2}/` and `/(a+)\1/` stay greedy. A repeated
   group that ends its sequence stays greedy, as it gives back whole runs.
   The next run of a group that may run again follows the repetition that
   ends it, as it did from a count of two: `/(b+|ba)*c/` stays greedy, and
   `/(?:a+b?)+c/` is no longer offered `a++`, which the check of the result
   refused.
 * `RedosRepairer::firstRepair()` returns the repair `repair()` offers first,
   or null, and stops at the first rewrite certified that writes the same
   `$matches`: the rewrites after it are not proven, nor is the optimizer
   asked for its own. The PHPStan rule asks for that repair alone.
 * `Modernizer` keeps a literal's value as decoded and leaves its escaping to
   the printer: a backslash written as a character to match stays one,
   `[^/\\]` no longer loses its `\\`.
 * `Modernizer` keeps a non-capturing group that holds an alternation:
   `/^(?:admin|user)$/` stays as written instead of becoming
   `/^admin|user$/`, which matched `admin-evil`.
 * `Modernizer` keeps a quantified non-capturing group unless it holds one
   item: `/(?:a+)?b/` stays as written instead of becoming `/a+?b/`, a lazy
   `a+` that no longer matched `b`, and `/(?:a*)*b/` no longer becomes
   `/a**b/`, which does not compile.
 * `Modernizer` keeps a non-capturing group that sets an option with no
   colon: `/(?:(?i)a)b/` stays as written instead of becoming `/(?i)ab/`,
   which also matched `aB`, and `/(?:(?n)a)(b)/` keeps its group 1.
 * `Modernizer` reads `(*UCP)` from the tree's opening options, not from the
   source text: `/(*UTF)(*UCP)^[0-9]$/` keeps `[0-9]` once the tree was
   rebuilt or modernized twice, where `\d` also matched `٣` (U+0663).
 * `OptimizerOptions` exports `OptimizerOptionsArray`, the closed shape of
   the array `optimize()` takes, so PHPStan checks each key and value.
 * `RedosRepairer` tries the rewrites of the quantifiers the analysis blames
   first, then every other as before; it proves each rewrite without the
   heuristics and the search cost of a full analysis, and keeps no tree of a
   rewrite in the parser: `/(?:x+…){0}^(?:a+)+$/` with 300 `x+` gets the same
   three repairs in under half the time, 11 MB at its peak where it took 49.
