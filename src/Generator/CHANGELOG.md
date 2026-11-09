CHANGELOG
=========

2.0
---

 * First release as its own package, split from `yoeunes/regex-parser`;
   see the [main changelog](https://github.com/php-regex/php-regex/blob/2.x/CHANGELOG.md).
 * Lookaheads in a row, each drawing what follows it again, stop at 128
   nested draws and 131,072 copied sequence entries: thousands of `(?=a)` no
   longer exhaust memory.
 * `TestCaseGenerator` checks every case against the running engine and drops
   the ones it contradicts (`/foo|bar/` no longer lists `bar` as
   non-matching); a pattern the engine refuses has no case.
