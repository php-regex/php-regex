CHANGELOG
=========

2.0
---

 * First release as its own package, split from `yoeunes/regex-parser`;
   see the [main changelog](https://github.com/php-regex/php-regex/blob/2.x/CHANGELOG.md).
 * `Regex::create()` reads `redos_ignored_patterns` itself and hands the
   patterns to the analyzer `redos()` and `analyze()` use; its options are
   the closed shape `RegexOptionsArray`, the parser's `OptionsArray` and
   that key. A facade built by `Regex::fromOptions()` skips no pattern.
 * `Regex::create()` takes the `locale` option, and `Regex::withTarget()`
   gives a facade for another target with every other option.
 * `Regex::transpile()` takes a `PHPRegex\Transpiler\Dialect` case or its
   name in its `$dialect` argument (1.x `$target`), and reads it before the
   pattern: a name no dialect answers to throws `InvalidRegexOptionException`.
