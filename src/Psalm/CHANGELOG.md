CHANGELOG
=========

2.0
---

 * First release: `Plugin`, which types `$matches` of `preg_match()` and
   `preg_match_all()` from the pattern and reports invalid patterns as
   `InvalidRegexPattern`; see the
   [main changelog](https://github.com/php-regex/php-regex/blob/2.x/CHANGELOG.md).
 * `<redos>true</redos>` reports the ReDoS findings of the PHPStan
   extension at the `preg_*` calls, from the same analysis and in its words,
   from `<redosThreshold>` on, `high` by default: `RegexRedos` for one
   attempt (`regex.redos`), `RegexRedosSearch` for the cost of a search
   (`regex.redos.search`), the loop of the functions that look for every
   match included. A constant subject is not reported.
 * The callback of `preg_replace_callback()` keeps the shape of its matches
   on Psalm 7.7 and later, which type that callback with a provider of their
   own, asked before the plugins: the plugin's is asked first, and a call it
   cannot read is left to Psalm's, where `$m` was `array<array-key, string>`.
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
 * Narrows `$matches` and the subject of `preg_match()` on Psalm 7.11 and
   later, which answer the call with their own reading of its pattern and
   no longer read the plugin's condition: the plugin writes its condition,
   beside Psalm's, into the cache Psalm reads a condition from. Psalm reads
   a few forms without that cache, and leaves `$matches` with its own type
   there: a condition inside a `case` of a `switch`, and a whole `||` or
   `&&` negated. Where a pattern anchored at both ends tells Psalm which
   characters the subject holds, `$matches` keeps Psalm's type, not `[]`,
   where the call returned 0. A baseline made on Psalm 7.11 or later should
   be regenerated.
 * `$matches` keeps Psalm's type where the plugin's condition would speak of
   another value than the one the variable holds, and typed it as a shape,
   or as `array<never, never>`, that PHP does not leave: where the right
   operand of a `&&` or a `||` writes the variable again, as in
   `preg_match('/(x)/', $s, $m) && !preg_match('/(a)(b)?/', $t, $m)`, the
   left call's condition goes, the right one's stays; where a loop body
   changes the pattern, the condition the pass before set goes; and on
   Psalm 6, where `if ($found = preg_match(…))` is false, which Psalm 6
   read as where it is true.
