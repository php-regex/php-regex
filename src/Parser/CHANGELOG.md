CHANGELOG
=========

2.0
---

 * First release as its own package, split from `yoeunes/regex-parser`;
   see the [main changelog](https://github.com/php-regex/php-regex/blob/2.x/CHANGELOG.md).
 * An empty script run, `(*sr:)` or `(*asr:)`, parses as a `ScriptRunNode`.
 * The `locale` option and `PcreTarget::$locale` name the `LC_CTYPE` locale
   the patterns run under, the runtime's by default: the size verdict
   counts exactly the classes whose length follows its character tables,
   read from the engine once per process. `PcreTarget::withLocale()` and
   `key()`, and `RegexParser::withTarget()`, a parser for another target
   with every other option.
 * `Attribute\RegexPattern`: marks a parameter that receives a regex pattern, for
   the tools that read it (`regex lint`).
 * `RegexParser::validate()` keeps the results of the last 1024 patterns an
   instance validated, and `clearCaches()` empties them: a pattern validated
   again costs a lookup.
 * `ArrayCache` drops the tree read or written longest ago, where it dropped
   the one written first.
 * `PatternInfoAnalyzer` takes an optional `PcreTarget`, the running PHP's
   PCRE2 by default: its release decides which lookbehinds `maxLookbehind`
   counts, as PCRE2 measures them.
 * `PatternInfoAnalyzer::analyzePattern()` reads the facts of a delimited
   pattern with a `RegexParser`, at the parser's target, and throws what the
   parser refuses there, as `Regex::info()` does.
