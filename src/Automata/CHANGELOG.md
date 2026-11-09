CHANGELOG
=========

2.0
---

 * First release as its own package, split from `yoeunes/regex-parser`;
   see the [main changelog](https://github.com/php-regex/php-regex/blob/2.x/CHANGELOG.md).
 * `matchEquivalent()` counts at every state it adds against `maxNfaStates`,
   and counts building its alphabet against `maxTransitionsProcessed`: a
   literal of fifteen thousand distinct characters under `u` is refused in
   under a second instead of being compared for more than a minute, and one of
   a thousand reaches the default work budget.
 * `TrivialMatchClassifier::classify()` stops at 5,000 bytes, as
   `matchedLiteral()` does, while it combines the strings of a sequence: a
   pattern of twenty nested `(?:…){4}` around `a`, 4^20 bytes spelled out, no
   longer exhausts memory, and the answer is `null`.
