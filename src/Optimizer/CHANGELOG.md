CHANGELOG
=========

2.0
---

 * First release as its own package, split from `yoeunes/regex-parser`;
   see the [main changelog](https://github.com/php-regex/php-regex/blob/2.x/CHANGELOG.md).
 * `Modernizer` takes the `PcreTarget` the pattern runs for, null when its
   release and locale are unknown, and writes `\s` for `[\t\n\x0B\f\r ]` under
   a target whose locale is `C` or `POSIX`. `RedosRepairer` takes a parser
   and a DFA cache, and builds its analyzer and its solver from the parser:
   the pattern and its rewrites are read for one target. `repair()` returns
   no repair, and `firstRepair()` null, for a pattern that does not lex or
   parse.
 * The group of a dot repeated before `\R`, and of `\R` repeated before a
   dot, stays as written: PCRE2 makes the repeat of a dot possessive before
   `\R` as if the two shared no character, and leaves the repeat of a group
   alone. `/x(?:.)*\R/` became `/x.*\R/`, which finds nothing in `"x\r"`
   where the pattern finds it, and `/x(?:\R)?./` became `/x\R?./`, with and
   without the JIT. A pattern that holds such a repeat is no longer
   rewritten where the check of the rewrite reads the two alike.
 * The brackets of a possessive repeat of a group, or of an atomic group,
   that may match nothing stay as written after a repeated character that
   could give back to what follows: PCRE2 makes that character possessive,
   as if the group could not be skipped, and `/a+(?:b)?+a/` finds nothing in
   `aa`, where `/a+b?+a/`, which the check proved alike and the optimizer
   wrote, finds `aa`. The pattern now ships as written, and so do
   `/a+(?:b|c)?+a/`, which became `/a+[bc]?+a/`, and `/a+(?>(?:b)?)a/`,
   which became `/a+(?>b?)a/`. `/a+(?:b)?+c/` still becomes `/a+b?+c/`.
   Such a rewrite is checked by the automata whatever `verifyWithAutomata`
   says, and the `Modernizer` keeps the group: it wrote `/a+(?:b)?+a/` as
   `/a+b?+a/`.
 * The group around a repeat of the byte 0xFF before `\w`, or of `\w`
   before that byte, stays as written without `u`: PCRE2 makes the repeat
   possessive once the group is out, as if 0xFF were no word character,
   and under fr_FR.ISO8859-1, where `\w` takes it, `/\xFF+\w/`, which the
   check proved alike and the optimizer wrote for `/(?:\xFF)+\w/`, finds
   nothing in `\xFF\xFF`, where `/(?:\xFF)+\w/` finds it. `/(?:\xFF)+?\w/`,
   `/(?:\xFF)?\w/`, `/(?:\w)+\xFF/` and `/(?:\w)+?\xFF/` stay as well. The
   rewrite ships only under a target that names a locale whose tables
   leave 0xFF out of `\w`, as el_GR.ISO8859-7 does, and such a rewrite is
   checked by the automata whatever `verifyWithAutomata` says. The
   `Modernizer` keeps the groups of such a pattern, whatever locale it is
   given, the empty group in `/\xFF+?(?:)\w/` too, at whose end PCRE2
   stops the lazy repeat: it wrote `/(?:\xFF)+\w/` as `/\xFF+\w/`, and
   `/\xFF+?(?:)\w/` as `/\xFF+?\w/`, which finds nothing in `\xFF\xFF`
   under fr_FR.ISO8859-1.
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
 * The possessive rewrite leaves every repetition greedy inside a group a
   call runs, `(?1)`, `(?-1)`, `(?+1)`, `(?&n)`, `(?P>n)` or `\g<n>`, a
   group a DEFINE holds included, and everywhere in a pattern that calls
   itself whole, `(?R)`, whatever follows it: PCRE2's JIT, which PHP runs
   by default, misses matches of a possessive quantifier there that its
   interpreter finds. `/(a++)c(?1)d/` finds no match in `acacad` with the
   JIT, from PCRE2 10.34 to 10.49, where `/(a+)c(?1)d/` matches `acad`, so
   `/(a+)c(?1)d/`, `/(a+b?)c(?1)d/`, `/(x(?1)?ca+b?)c/` and
   `/(?&n)c(?(DEFINE)(?<n>a+b?))/` stay as written. A group in a DEFINE
   that no call runs runs nowhere: `/(?(DEFINE)(?<n>a+b?))a/` now becomes
   `/(?(DEFINE)(?<n>a++b?))a/`. A repetition outside every group a call
   runs reads what follows it as before: `/a+;(x)(?1)/` becomes
   `/a++;(x)(?1)/`.
 * The check of the result runs the body of a group where each call of it
   stands, a group a DEFINE holds included, under the options in force
   where the DEFINE stands: `/(?(DEFINE)(?<n>a|b))x(?&n)/` becomes
   `/(?(DEFINE)(?<n>[ab]))x(?&n)/`, a rewrite the check left unproven. The
   calls the check proves a rewrite in read what a DEFINE holds: `^` in a
   group it holds asks for the search past the start of the subject. A
   group that calls itself is read through every call where the rewrite
   changes a quantifier outside every group a call runs:
   `/a+b(x(?1)?y)/` now becomes `/a++b(x(?1)?y)/`, where the check
   refused it.
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
   `/(?i)a+b?c/` now becomes `/(?i)a++b?c/`, and `/(?i:a+b?)c/` becomes
   `/(?i:a++b?)c/`, while `/(?i)a+b?A/` stays greedy.
   An option setting that names `U`, under which a plain quantifier is
   lazy, and a non-atomic lookaround, past whose end the engine reads from
   where it starts, still keep every quantifier of the pattern as written.
 * The possessive rewrite reads what follows a repetition that ends its
   sequence, or stands alone in its group, its branch or the pattern: what
   follows the group at every place it runs, the alternation or the
   conditional, up to the end of the pattern. `/(a+)c/` now becomes
   `/(a++)c/`, and `/x[0-9]+/` becomes `/x\d++/`,
   while `/(a+)a/`, `/(?:a+){2}/` and `/(a+)\1/` stay greedy. A repeated
   group that ends its sequence stays greedy, as it gives back whole runs.
   The next run of a group that may run again follows the repetition that
   ends it, as it did from a count of two: `/(b+|ba)*c/` stays greedy, and
   `/(?:a+b?)+c/` is no longer offered `a++`, which the check of the result
   refused.
 * The possessive rewrite keeps every repeated group greedy in a pattern
   that sets a limit on what PCRE2 spends on a match, `(*LIMIT_MATCH=n)`,
   `(*LIMIT_DEPTH=n)`, its old name `(*LIMIT_RECURSION=n)`, or
   `(*LIMIT_HEAP=n)`: without the JIT a possessive group may spend more of
   it than the greedy one, and `/(*LIMIT_MATCH=4)c(?:ab)+e/`, which matches
   `cababe`, is no longer rewritten `/(*LIMIT_MATCH=4)c(?:ab)++e/`, which
   gives up on it. A repeated character or class spends no more of any of
   them, and is still made possessive there:
   `/(*LIMIT_MATCH=10)^[a-z]+(?:-[a-z]+)*$/` now becomes
   `/(*LIMIT_MATCH=10)^[a-z]++(?:-[a-z]++)*$/`, where the check of the
   result refused the possessive group offered with them.
 * The possessive rewrite makes a repeated group possessive only where
   every run of its body matches one way, characters and literals in a
   row, and reads what follows the repeat against the character a run
   starts with, as the repeat gives back whole runs: `/(?:b|ba)*c/` and
   `/(?:ab)+ab/` are no longer offered `(?:b|ba)*+c` and `(?:ab)++ab`,
   which miss `bac` and `abab`, while `/(?:ab)+b/` now becomes
   `/(?:ab)++b/`. The possessive group the check of the result refused no
   longer takes the other possessives of the pattern with it:
   `/[a-z]+(?:-[a-z]+)*$/` now becomes `/[a-z]++(?:-[a-z]++)*$/`, and
   `/(?:b|ba)*c[0-9]+;/` becomes `/(?:b|ba)*c\d++;/`, where they shipped
   without a possessive.
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
