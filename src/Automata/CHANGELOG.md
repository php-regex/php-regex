CHANGELOG
=========

2.0
---

 * First release as its own package, split from `yoeunes/regex-parser`;
   see the [main changelog](https://github.com/php-regex/php-regex/blob/2.x/CHANGELOG.md).
 * `rewritesAlikeInPlace()` fills at most 65,536 cells, across all the sites
   of the two patterns, when it pairs their parts by those both keep, and
   throws a `ComplexityException` past them: the optimizer keeps a pattern of
   six thousand optional parts behind a lookbehind as written instead of
   reaching a peak of nearly 1 GB to prove its rewrite.
 * `TrivialMatchClassifier::classify()` stops at 5,000 bytes, as
   `matchedLiteral()` does, while it combines the strings of a sequence: a
   pattern of twenty nested `(?:…){4}` around `a`, 4^20 bytes spelled out, no
   longer exhausts memory, and the answer is `null`.
