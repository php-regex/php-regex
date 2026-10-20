CHANGELOG
=========

2.0
---

 * First release as its own package, split from `yoeunes/regex-parser`;
   see the [main changelog](https://github.com/php-regex/php-regex/blob/2.x/CHANGELOG.md).
 * Four assertions: `assertPatternMatches()` runs one guarded match attempt
   and judges a capture expectation against the pattern's own groups,
   `assertPatternDoesNotMatch()` asserts no match, `assertPatternCompiles()`
   asks the running engine, and `assertPatternIsLinear()` asks the ReDoS
   analysis for a proof.
 * `MatchesPattern`, `DoesNotMatchPattern`, `CompilesPattern` and
   `IsLinearPattern` constraints, so every assertion composes with
   `assertThat()` and boolean composition. `DoesNotMatchPattern` fails on a
   pattern that does not parse or an engine refusal, which `LogicalNot`
   around `MatchesPattern` would pass as "not a match".
 * Offsets are bytes, and one match attempt is one `preg_match()` call —
   both said in every failure the assertions render.
 * Requires `php-regex/regex-parser` and `php-regex/regex-redos` only: the
   assertions read patterns through the parser and the ReDoS analyzer, not
   through the `Regex` facade.
