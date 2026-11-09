CHANGELOG
=========

2.0
---

 * First release as its own package, split from `yoeunes/regex-parser`;
   see the [main changelog](https://github.com/php-regex/php-regex/blob/2.x/CHANGELOG.md).
 * `SolverOptions::maxTransitionsProcessed` bounds the determinization and
   minimization of lookaround bodies too: `/(?=[ab]*a[ab]{8})/` under a budget
   of 100 is refused with a `ComplexityException` instead of building the
   hundreds of subset states its assertion needs.
 * `TrivialMatchClassifier::classify()` stops at 5,000 bytes, as
   `matchedLiteral()` does, while it combines the strings of a sequence: a
   pattern of twenty nested `(?:…){4}` around `a`, 4^20 bytes spelled out, no
   longer exhausts memory, and the answer is `null`.
