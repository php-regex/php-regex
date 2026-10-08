CHANGELOG
=========

2.0
---

 * First release as its own package, split from `yoeunes/regex-parser`;
   see the [main changelog](https://github.com/php-regex/php-regex/blob/2.x/CHANGELOG.md).
 * Diagnostics run the validator too: a pattern that parses but that PCRE
   refuses (an unbounded lookbehind, a reference to a missing group) is
   published with that error, and no lint issue beside it.
