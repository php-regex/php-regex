CHANGELOG
=========

2.0
---

 * First release: `Plugin`, which types `$matches` of `preg_match()` and
   `preg_match_all()` from the pattern and reports invalid patterns as
   `InvalidRegexPattern`; see the
   [main changelog](https://github.com/php-regex/php-regex/blob/2.x/CHANGELOG.md).
 * `@regex-target` narrows the versions a call is judged for;
   `InvalidRegexTarget` reports the markers that keep no version or cannot
   be read.
 * A guard on `PHP_VERSION_ID` or `version_compare(PHP_VERSION, ...)` around
   a call narrows the versions it is judged for, read from the file as
   `regex lint` reads it; Psalm's own narrowing of the constant is not read.
 * A PHP below 7.3, which ships PCRE1, is judged as `regex lint` judges it:
   refused when `<phpVersion>` or Psalm's own PHP version names it, judged
   from the lowest PHP on PCRE2 of the `composer.json` Psalm read it from.
   The package requires `php-regex/regex-linter`, which reads the target.
