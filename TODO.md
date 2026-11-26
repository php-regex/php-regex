# TODO

## Before the tag

- Decide what a target that names no locale means for classes above ASCII
  without u: `matchEquivalent('/\w/', '/[a-zA-Z0-9_]/')` answers alike,
  reading C's tables, while `/\w/` matches `\xE9` under en_IE.ISO8859-1;
  the possession beside 0xFF already reads such a target as running under
  any tables, and the mutation judge reads plain classes the C way. The
  reading 2.0.0 ships is the contract.
- Since #450 the proof of a linear search gives up past 1,024 paths at
  once, and three patterns of the corpus traded "safe (proven)" for a
  guess. The semver.org pattern (`FOSSBilling`,
  `src/core/System/Version.php`, and the same pattern between `/^` and
  `$/`) reads "Potential backtracking (heuristic)", MEDIUM, and
  `regex.lint.quantifier.nested` adds "can cause catastrophic
  backtracking", where PCRE2 10.49 spends 352 steps on the costliest
  subject of 120 characters a search found, and 174 on one of 60. Two
  patterns of `mpdf` (`src/Shaper/Indic.php`, lines 313 and 317), whose
  repeats are all bounded (`?`, `{0,2}`, `{0,3}`, `{0,4}`), get the same
  warning for 67 steps at most. Ship the guess, or find what the count
  takes for paths there and keep the rule quiet where no repeat is
  unbounded.
- Names and shapes #481 left as they are, each to settle before the tag or
  to keep for all of 2.x:
  - `OptimizerOptions::$word` rewrites nothing today, as its docblock says:
    `/[a-zA-Z0-9_]+/` becomes `/[0-9A-Z_a-z]+/`, not `/\w+/`. Drop the
    option from the class, `regex.json`, the bridges' configurations and
    the PHPStan parameters, or make it rewrite under the C locale. The same
    example asks whether `canonicalizeCharClasses` should be on by default.
  - Two cache contracts, two vocabularies: `CacheInterface::load()` and
    `write()`, `DfaCacheInterface::get()` and `set()`. And
    `RemovableCacheInterface` removes nothing one by one since 2.0: it
    clears and counts.
  - `RedosAnalysis` and the four solver results carry `$pcreVersion`, the
    engine that ran, not the target judged. Give them the `PcreTarget`
    now, or both names live side by side from 2.1.
  - `ComplexityException` says with one class that a pattern is outside
    what the automata read and that a budget ran out, and tells them apart
    through `getDiagnostic()`, an untyped array.
  - `max_recursion_depth` and `RecursionLimitException` name the nesting
    depth of groups (`regex.nesting.too_deep`), where "recursion" already
    names `(?R)` and `pcre.recursion_limit`.
  - Plumbing that still reads as public: `PatternInfoAnalyzer::analyzePattern()`,
    `RangeDiff::judgedWith()` and `notice()`,
    `OptimizerOptions::fromCamelCaseArray()` and `Redos\IgnoreList`.

## Release 2.0.0

`v2.0.0-RC1` is out (2026-11-14, a pre-release from `a680f3979a`): the
phar, its signature and its checksum are attached and attested, the twenty
split repositories carry the tag, and Packagist serves it for each of
them. `2.x` keeps reporting `2.0.0-RC1` until the final bump:
`bin/bump --next` refuses a development version below a pre-release.
`yoeunes/regex-parser` is abandoned on Packagist in favour of
`php-regex/php-regex`, and lists the `v2.*` tags of the monorepo too.

In this order.

1. **The final bump.** `bin/bump 2.0.0` sets `Regex::VERSION` and
   `Regex::VERSION_ID`, dates the CHANGELOG section, and moves the install
   story from `2.x-dev` to the stable release: `docs/_data/product.yml`
   says `stable: true`, which the install include every page and article
   reads; `README.md` and `docs/llms.txt` lose the two `composer config`
   lines, the `:2.x-dev` suffix and the "2.0 is coming" section; and the
   sample outputs of the docs report `2.0.0`. Read the diff, then commit
   it on its own branch. `bin/release` refuses a final version whose docs
   still install from the dev channel. Run `bin/playground-bundle` on that
   branch too, after `wasm/pcre2-trace/build.sh` (it stops without the
   trace module), so that the home runs the library of the release: the
   bundle in `docs/assets/playground/` was built on 2026-11-26, from
   `2.0.0-RC1`.
2. **Before the tag**, check with `bin/status` that each of the twenty
   split repositories (the nineteen packages and the PHP 7.4 twin) carries
   a fresh 2.x head and a crawled dev version, and that every workflow
   `bin/release` reads answers for HEAD: a `TODO.md` commit pushed on
   `2.x` alone needs `gh workflow run spelling.yml --ref 2.x`.
   The split pushes are not atomic: between the first and the last webhook
   after the tag, a `^2.0` resolution fails on missing siblings.
3. **Tag 2.0.0**: `bin/release --dry-run 2.0.0`, then `bin/release
   2.0.0`, and check the assets, `gh attestation verify regex.phar --repo
   php-regex/php-regex` and the phar on PHP 8.1, as for the RC. Then
   `bin/status` and Packagist show `v2.0.0` for all nineteen packages
   (they require each other at `self.version`: one missing tag leaves the
   others uninstallable) and for the twin, and the "Latest" release
   carries the 2.0 `regex.phar`, which `self-update` and `releases/latest`
   serve.
   Run `uses: php-regex/php-regex@v2.0.0` once on a test repository: the
   action downloads that phar and checks its checksum.
4. `bin/bump --next 2.0.1`.
