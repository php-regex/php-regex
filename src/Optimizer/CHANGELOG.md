CHANGELOG
=========

2.0
---

 * First release as its own package, split from `yoeunes/regex-parser`;
   see the [main changelog](https://github.com/php-regex/php-regex/blob/2.x/CHANGELOG.md).
 * `Modernizer` keeps a literal's value as decoded and leaves its escaping to
   the printer: a backslash written as a character to match stays one,
   `[^/\\]` no longer loses its `\\`.
 * `OptimizerOptions` exports `OptimizerOptionsArray`, the closed shape of
   the array `optimize()` takes, so PHPStan checks each key and value.
