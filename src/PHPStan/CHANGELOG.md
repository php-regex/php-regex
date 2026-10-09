CHANGELOG
=========

2.0
---

 * First release as its own package, split from `yoeunes/regex-parser`;
   see the [main changelog](https://github.com/php-regex/php-regex/blob/2.x/CHANGELOG.md).
 * PHPStan's `phpVersion: {min, max}` is read whole: each later PHP up to
   `max` where a rule changes validates the patterns too, reported as
   `regex.invalidForTarget`.
 * `RegexPatternArgumentRule` checks the constant pattern passed to a
   parameter marked `#[PHPRegex\Parser\Attribute\RegexPattern]` or
   `#[JetBrains\PhpStorm\Language('RegExp')]`, in calls to functions, static
   and instance methods and constructors: `regexp.pattern` for a pattern the
   running engine refuses, `regex.invalidForTarget`, then lint and ReDoS as
   for `preg_*()` calls. On a PHPStan older than 2.1.31 that offers no
   parameter attributes, no parameter is read.
 * `regex.redos.search`, `Quadratic search (ReDoS): <pattern>`: the quadratic
   cost of an unanchored search whose every attempt is proven linear, under the
   ReDoS setting, from `threshold: medium`.
 * `regex.replacement.undefinedGroup`, always reported: a constant replacement
   of `preg_replace()` or `preg_filter()` refers to a group the pattern does
   not have, or names a group (`${name}`), which PHP never substitutes. A
   pattern and a replacement that both vary are not paired value by value: a
   reference is reported only when no possible pattern defines its group.
