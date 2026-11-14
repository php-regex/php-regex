CHANGELOG
=========

2.0
---

 * First release as its own package, split from `yoeunes/regex-parser`;
   see the [main changelog](https://github.com/php-regex/php-regex/blob/2.x/CHANGELOG.md).
 * An atomic group or a possessive repeat over greedy unbounded repeats of
   one run, each taken at most once at least, is read as the longest run it
   commits to: `/^(?:a+)++$/` is proven linear where its body was kept with
   every way through it, and a ReDoS repair now offers the possessive on the
   outer loop, `/^(?:a+)++$/` for `/^(?:a+)+$/`. A body that splits a run
   into many copies, `(?:a+){30,}+`, is kept as written: PCRE tries every
   way to split it before it fails.
 * The heuristics read a body repeated `{0}` as PCRE runs it: never where it
   stands. One holding no group gives no hotspot, as `x+x+` in
   `/(?:x+x+){0}^(?:a+)+$/` gave two, which a repair then tried first; the
   groups one holds run only through a call, and are read on their own, as
   those of a `DEFINE` block are.
 * The search-cost witness builds only the sixteen pairs of alphabet classes it
   keeps, and charges their choice to the analysis budget: a run whose class
   six hundred later letters split no longer builds every ordered pair of the
   classes, 105 MB, before keeping sixteen.
 * The search-cost replay counts under the backtrack and recursion limits of
   the `ConfirmationOptions` given, not up to ten million steps under the
   process's recursion limit; where they stop the count, or `ini_set()` is
   disabled or refuses one, an exact witness is reported with `replayed: null`.
 * Where `ini_set()` refuses a PCRE limit (fixed by `php_admin_value`), the
   confirmation and the replay of a proof are skipped, their evidence
   `engine limits unavailable`, as where `ini_set()` is disabled: the refusal
   no longer counts as a run that did not reproduce.
 * Under `U`, `(?U)` or `(?U:…)`, an optional recursion such as `(?R)?` is
   lazy and tried last: it no longer reads as an immediate recursion loop
   that hides the backtracking after it, `/(?R)?(a+)+$/U` is reported as
   the exponential pattern PCRE cannot finish.
 * An ignored pattern written with modifiers, `/(a+)+b/i`, skips that
   pattern only: the same body under the same modifiers, in any order and
   between any delimiters. Any other entry still skips its body under any
   modifiers.
 * `IgnoreList` is public: it reads a ReDoS ignore list the way the
   analysis does, and the lint reads its own with it.
 * A proof of a linear attempt counts the work inside its lookaheads: a loop
   that reads, on the same characters, into a loop of a lookahead's body (as
   in `/^(?:(?=a+)a)+$/`, quadratic) refuses the proof, and `abstractions`
   lists `lookaround at offset N read again at each step of a loop`.
 * The structural heuristics read a lookaround as running on its own, and
   report a lookaround holding an unbounded repeat inside a loop.
 * A `RedosAnalysis` that proves one attempt linear carries no `findings`,
   `hotspots` or `recommendations`, and its `vulnerablePart`, `trigger`,
   `falsePositiveRisk`, `suggestedRewrite` and `culpritNode` are `null`: the
   structural heuristics no longer run once the proof settles the verdict.
 * `RedosAnalysis::jsonSerialize()` carries `upper_bound_degree`, the
   `$upperBoundDegree` it left out.
 * The structural heuristics measure a capture with the parser's
   `LengthRangeCalculator`: `(\cA)` is one character, as `(\x01)` is, `\R`
   one or two, a lookaround none. A script run can match nothing only when
   its body can, and a `DEFINE` block is analysed outside the loops around it.
 * A Unicode class is read as the union of its members, each member read
   from the engine once per process, a negated one as the rest of the
   alphabet and every general category from one read: the analysis of a
   pattern holding Unicode classes reads far fewer sets, each with the JIT.
 * `RedosAnalysis::$searchCost`, a `RedosSearchCost`: the witness (prefix,
   run, breaker) of a quadratic unanchored search when one attempt is proven
   linear (`search_cost` in JSON).
 * A witness through a lookaround is checked on the running PCRE2 before it
   proves a class: its attempt must fail, and reach the loop through the
   lookarounds on its way; a lookbehind at the start leads the witness.
 * The confirmed replay tries each witness once more at 5,000 bytes or just
   past, where PCRE2 stops looking for the code unit an anchored pattern
   requires.
 * `ConfirmationOptions` throws `InvalidRegexOptionException` for a value
   below its floor, as `RedosOptions` does, where it clamped it.
