CHANGELOG
=========

2.0
---

 * First release as its own package, split from `yoeunes/regex-parser`;
   see the [main changelog](https://github.com/php-regex/php-regex/blob/2.x/CHANGELOG.md).
 * A sample, and every piece built on the way to it, stops at 1,048,576 bytes
   before it is concatenated: a chain of doubling backreferences throws
   `SampleGenerationException` instead of exhausting memory.
 * `TestCaseGenerator` checks every case against the running engine and drops
   the ones it contradicts (`/foo|bar/` no longer lists `bar` as
   non-matching); a pattern the engine refuses has no case.
