CHANGELOG
=========

2.0
---

 * First release as its own package, split from `yoeunes/regex-parser`;
   see the [main changelog](https://github.com/php-regex/php-regex/blob/2.x/CHANGELOG.md).
 * `Modernizer` keeps a literal's value as decoded and leaves its escaping to
   the printer: a backslash written as a character to match stays one,
   `[^/\\]` no longer loses its `\\`.
 * `Modernizer` keeps a non-capturing group that holds an alternation:
   `/^(?:admin|user)$/` stays as written instead of becoming
   `/^admin|user$/`, which matched `admin-evil`.
 * `Modernizer` reads `(*UCP)` from the tree's opening options, not from the
   source text: `/(*UTF)(*UCP)^[0-9]$/` keeps `[0-9]` once the tree was
   rebuilt or modernized twice, where `\d` also matched `٣` (U+0663).
 * `OptimizerOptions` exports `OptimizerOptionsArray`, the closed shape of
   the array `optimize()` takes, so PHPStan checks each key and value.
