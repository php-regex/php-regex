CHANGELOG
=========

2.0
---

 * First release as its own package, split from `yoeunes/regex-parser`;
   see the [main changelog](https://github.com/php-regex/php-regex/blob/2.x/CHANGELOG.md).
 * The possessive rewrite leaves a repetition greedy before a lookaround,
   which reads nothing yet looks at the characters the repetition would
   give back: `/a+(?!b)/` matches `a` in `aab`, `/a++(?!b)/` nothing. Such a
   rewrite, which the check of the result refused, no longer takes the
   other rewrites of the pattern with it: under the possessive option
   `/x\s+(?!y)/s` loses its useless `s`, and
   `/^[a-z](?:[a-z0-9_](?!__))*[a-z0-9]+$/` becomes
   `/^[a-z](?:[0-9_a-z](?!__))*[0-9a-z]+$/`. The last `\s*` of
   `#^(?:([=<>!]=?|<>)\s*)?((?:\d*\.)?\d+)\s*(K|M|G|)B?$#Di` stays greedy:
   the empty branch lets `B` or the end follow it, which the first
   characters of the other branches left out.
 * The possessive rewrite reads an escape that names an ASCII character as
   that character: `/^[\x30-\x39]+x$/` becomes `/^[\x30-\x39]++x$/`, as
   `/^[0-9]+x$/` becomes `/^\d++x$/`; it was left greedy.
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
