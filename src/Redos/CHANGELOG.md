CHANGELOG
=========

2.0
---

 * First release as its own package, split from `yoeunes/regex-parser`;
   see the [main changelog](https://github.com/php-regex/php-regex/blob/2.x/CHANGELOG.md).
 * `RedosAnalysis::$searchCost`, a `RedosSearchCost`: the witness (prefix,
   run, breaker) of a quadratic unanchored search when one attempt is proven
   linear (`search_cost` in JSON).
 * A witness through a lookaround is checked on the running PCRE2 before it
   proves a class: its attempt must fail, and reach the loop through the
   lookarounds on its way; a lookbehind at the start leads the witness.
 * The confirmed replay tries each witness once more at 5,000 bytes or just
   past, where PCRE2 stops looking for the code unit an anchored pattern
   requires.
