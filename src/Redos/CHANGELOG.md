CHANGELOG
=========

2.0
---

 * First release as its own package, split from `yoeunes/regex-parser`;
   see the [main changelog](https://github.com/php-regex/php-regex/blob/2.x/CHANGELOG.md).
 * `RedosAnalysis::$searchCost`, a `RedosSearchCost`: the witness (prefix,
   run, breaker) of a quadratic unanchored search when one attempt is proven
   linear (`search_cost` in JSON).
