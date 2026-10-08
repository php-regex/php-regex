CHANGELOG
=========

2.0
---

 * First release as its own package, split from `yoeunes/regex-parser`;
   see the [main changelog](https://github.com/php-regex/php-regex/blob/2.x/CHANGELOG.md).
 * `TestCaseGenerator` checks every case against the running engine and drops
   the ones it contradicts (`/foo|bar/` no longer lists `bar` as
   non-matching); a pattern the engine refuses has no case.
