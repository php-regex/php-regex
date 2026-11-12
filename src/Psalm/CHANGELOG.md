CHANGELOG
=========

2.0
---

 * First release: `Plugin`, which types `$matches` of `preg_match()` and
   `preg_match_all()` from the pattern and reports invalid patterns as
   `InvalidRegexPattern`; see the
   [main changelog](https://github.com/php-regex/php-regex/blob/2.x/CHANGELOG.md).
 * `<locale>` names the `LC_CTYPE` locale the patterns run under, the one
   of the PHP running Psalm by default.
 * What the running PCRE2 answers about Unicode classes is kept in Psalm's
   cache directory, in `php-regex/`, for the next run: `--clear-cache`
   removes it, `--no-cache` keeps none.
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
 * `<phpVersion>` takes a Composer constraint (`>=8.1`) besides a single
   version: the range is judged as given, at the versions `regex lint`
   judges, from its lowest PHP on PCRE2 and at every later PHP where a rule
   of the library changes. Only a refusal is reported, as
   `InvalidRegexPattern` for the PHP that refuses the pattern: the lint's
   warning that a later PHP reads a pattern otherwise is not. A guard or a
   marker narrows the range. In `psalm.xml`, `<` and `>` are escaped:
   `<phpVersion>&gt;=8.1</phpVersion>`.
   A constraint with no lowest version, or one that allows no PHP on PCRE2,
   stops Psalm with the lint's error. `require.php` is never read as a range:
   without a range in `<phpVersion>`, one version is judged.
 * Runs on Psalm 7 as on Psalm 6.19 and later: the assertions the plugin
   sets on a `preg_match()` or `preg_match_all()` call reach the node data
   Psalm 7 reads, behind the view it hands a plugin, and the callback of
   `preg_replace_callback()` is typed with the constructor Psalm 7 keeps.
