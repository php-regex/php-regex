CHANGELOG
=========

2.0
---

 * First release as its own package, split from `yoeunes/regex-parser`;
   see the [main changelog](https://github.com/php-regex/php-regex/blob/2.x/CHANGELOG.md).
 * A PHP below 7.3, which ships PCRE1, is judged as `regex lint` judges it:
   refused when `phpRegex.phpVersion` or PHPStan's `phpVersion` names it,
   judged from the lowest PHP on PCRE2 of a range or of the `composer.json`
   PHPStan read it from.
 * PHPStan's `phpVersion: {min, max}` is read whole: each later PHP up to
   `max` where a rule changes validates the patterns too, reported as
   `regex.invalidForTarget`.
 * The `$phpVersionRange` constructor parameter of `RegexPatternRule` and
   `RegexPatternArgumentRule` is typed `int|array|null`, as PHPStan's
   `phpVersion` parameter is, where it took `mixed`.
 * `RegexPatternRule` exports `ConfigArray`, the shape of the `phpRegex`
   parameter, which both rules take as their `$config`.
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
 * The static methods of every preset `regex lint` reads are judged by
   default; `phpRegex.interop` takes the preset names of
   `extraction.interop` to read only some, and refuses an unknown one as it
   loads the configuration. A named argument is read under the name the library
   gives its pattern parameter.
 * A named argument is matched with its case, as PHP does: `PATTERN:` names
   no `$pattern`.
