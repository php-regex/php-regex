CHANGELOG
=========

2.0
---

 * First release as its own package, split from `yoeunes/regex-parser`;
   see the [main changelog](https://github.com/php-regex/php-regex/blob/2.x/CHANGELOG.md).
 * A sample, and every piece built on the way to it, stops at 1,048,576 bytes
   before it is concatenated: a chain of doubling backreferences throws
   `SampleGenerationException` instead of exhausting memory. A group fitted to
   substring scans can still join a piece of up to that size to its text for
   each scan that reads it, to check them.
 * Lookaheads in a row, each drawing what follows it again, stop at 128
   nested draws and 131,072 copied sequence entries: thousands of `(?=a)` no
   longer exhaust memory.
 * The repeats that add nothing to a sample, as a reference to a group that
   captured nothing, stop at 1,048,576 over the draws of one sample:
   `/()(?:\1{65535}){100}/` throws `SampleGenerationException` in about two
   seconds instead of returning `""` after thirteen. Repeats that add text are
   not counted.
 * `TestCaseGenerator` checks every case against the running engine and drops
   the ones it contradicts (`/foo|bar/` no longer lists `bar` as
   non-matching); a pattern the engine refuses has no case.
 * `TestCaseGenerator` builds no case longer than 1,048,576 bytes, the longest
   sample: `/^(?:a{65535}){6000}$/`, which the engine compiles, has no matching
   case instead of one of 393 MB that exhausted memory.
   A sequence joins each case once, when it ends: a child after a long start
   copied it again, 2.8 seconds for two thousand repeats after 983,025 bytes,
   15 milliseconds now.
