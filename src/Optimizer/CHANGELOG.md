CHANGELOG
=========

2.0
---

 * First release as its own package, split from `yoeunes/regex-parser`;
   see the [main changelog](https://github.com/php-regex/php-regex/blob/2.x/CHANGELOG.md).
 * `Modernizer` keeps a non-capturing group that holds an alternation:
   `/^(?:admin|user)$/` stays as written instead of becoming
   `/^admin|user$/`, which matched `admin-evil`.
 * `OptimizerOptions` exports `OptimizerOptionsArray`, the closed shape of
   the array `optimize()` takes, so PHPStan checks each key and value.
