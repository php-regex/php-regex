CHANGELOG
=========

2.0
---

 * First release as its own package, split from `yoeunes/regex-parser`;
   see the [main changelog](https://github.com/php-regex/php-regex/blob/2.x/CHANGELOG.md).
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
