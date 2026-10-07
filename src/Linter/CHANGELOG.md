CHANGELOG
=========

2.0
---

 * First release as its own package, split from `yoeunes/regex-parser`;
   see the [main changelog](https://github.com/php-regex/php-regex/blob/2.x/CHANGELOG.md).
 * The lint command reads the file of `--baseline` and `--generate-baseline`
   after a space too (`--baseline base.json`); either option with no file is a
   usage error.
 * `regex.lint.redos.search`: the quadratic cost of an unanchored search whose
   every attempt is proven linear, under the ReDoS check, at severity `medium`;
   `redos.search` in the rules map of `regex.json` turns it off.
 * `regex.lint.quantifier.emptyRepeat`: an unbounded quantifier on an item
   that can match the empty string, `(a*)*`, `(?:a|b?)+`, at severity
   `warning`, on by default; silent where `alternation.empty`,
   `quantifier.assertion`, `quantifier.nested` or `dotstar.nested` reports the
   same repeat, even when the rules map turns that rule off.
 * `regex.lint.anchor.alternationPrecedence`: an anchor on the first or last
   alternative while another alternative has none, `/^a|b|c$/`, at severity
   `warning`, on by default.
 * `regex.lint.quantifier.possessiveImpossible`: a possessive repeat that
   takes every character the next atom could read, `/a*+a/`, at severity
   `warning`, on by default.
 * `regex.lint.anchor.impossible.boundary`: `\b` between two word or two
   non-word characters, `\B` between a word and a non-word character,
   `/a\bb/`, at severity `warning`, on by default.
 * `regex.lint.lookaround.impossible`: a lookahead what follows it
   contradicts, `/(?=a)b/`, at severity `warning`, on by default.
 * `regex.lint.group.empty`: an empty `(?:)` or `(?>)` that can go, at
   severity `warning`, on by default; never one a quantifier repeats, nor one
   that keeps an escape from a digit (`(a)\1(?:)0`) or braces from a count
   (`a{(?:)2}`).
 * `regex.lint.charclass.single`: a class of one character, `[a]`, at
   severity `style`, off by default.
 * `regex.lint.literal.multipleSpaces`: two or more literal spaces in a row,
   at severity `style`, off by default.
 * `regex.lint.quantifier.lazyToClass`: `".*?"` where `"[^"\n]*"` matches the
   same text, at severity `perf`, off by default.
 * The rules off by default are `unicode.shorthandWithoutU`,
   `charclass.single`, `literal.multipleSpaces` and `quantifier.lazyToClass`;
   `true` for its id in the rules map of `regex.json` turns one on.
 * `regex.lint.quantifier.lazyEnd` also reports a lazy quantifier followed
   only by items that may match nothing and hold no test that can fail,
   `/a+?b*/`.
 * `regex.lint.group.redundant` no longer reports an empty group (now
   `group.empty`), a group that keeps an escape from a digit (`(a)\1(?:0)`) or
   braces from a count (`a{(?:2)}`), or a group a quantifier repeats around
   anything but one character (`(?:\Qab\E)+`, `(?:^)+`); `(?:a)+` is still
   reported.
