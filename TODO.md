# TODO

## Release 2.0.0

In this order.

1. **Repoint `yoeunes/regex-parser` on Packagist** to a repository holding
   1.x only, before anything is tagged. It still reads
   `php-regex/php-regex` and lists `2.x-dev` beside `1.x-dev`, so a `v2.0.0`
   tag would release the 1.x package as 2.0.0 (`bin/status` reports it ✗
   until then; `UPGRADE-2.0.md` promises it stays on 1.x). Afterwards, its
   dev channel lists `1.x-dev` only.
2. **The bump commit.** `bin/bump 2.0.0` sets `Regex::VERSION` and
   `Regex::VERSION_ID`, dates the CHANGELOG section, and moves the install
   story from `2.x-dev` to the stable release: `docs/_data/product.yml`
   says `stable: true`, which the install include every page and article
   reads; `README.md` and `docs/llms.txt` lose the two `composer config`
   lines, the `:2.x-dev` suffix and the "2.0 is coming" section; and the
   `2.0.0-DEV` sample outputs of the docs report `2.0.0`. Read the diff,
   then commit it on its own branch. `bin/release` refuses a final version
   whose docs still install from the dev channel.
3. **Before the tag**, check with `bin/status` that each of the seventeen
   split repositories carries a fresh 2.x head and a crawled dev version.
   The split pushes are not atomic: between the first and the last webhook
   after the tag, a `^2.0` resolution fails on missing siblings.
4. **Release candidate first.** The release path has never run on a real
   release (`v1.3.0` carries no checksum). `bin/release --dry-run
   2.0.0-RC1`, then `bin/release 2.0.0-RC1` (a GitHub pre-release): it
   drafts the release and pushes the tag; the phar workflow builds the
   phar, checks that its version is the tag's and that `regex.phar lsp`
   answers `initialize`, attests it, attaches it and its checksum, then
   publishes the draft; the split workflow tags the seventeen packages.
   Check both assets, `gh attestation verify regex.phar --repo
   php-regex/php-regex`, and the phar on PHP 8.1.
5. **Tag 2.0.0** the same way. Then `bin/status` and Packagist show
   `v2.0.0` for all seventeen packages (they require each other at
   `self.version`: one missing tag leaves the others uninstallable), and the
   "Latest" release carries the 2.0 `regex.phar`, which `self-update` and
   `releases/latest` serve.
6. `bin/bump --next 2.0.1`.
7. Submit `src/Linter/regex.schema.json` to schemastore.org (`fileMatch`:
   `regex.json`, `regex.dist.json`) for editor support without setup: it
   needs the stable public URL the tag gives it.


## Architecture pass before the tag

Four seams read on 2026-11-01, none a BC break; one pull request each
(#199, #200, #201, #202), to merge in this order. (A fifth, the optimizer's solver running without the DFA
cache, was a false reading: the rewrite checks build priority automata and
part automata of their own and never ask the cache, as
`AnalysisServiceDfaCacheTest` already proves.)

1. **Default parsers resolved per call.** `RedosAnalyzer::analyze()`,
   `LanguageSolver::parser()`, `TrivialMatchClassifier`,
   `RedosRepairer::repair()` and `SampleGenerator::generate()` fall back to
   `RegexParser::create()` on each call, so an instance built without a
   parser gets a fresh parser and a fresh AST cache every time. Resolve the
   default once per instance; the public constructors keep their defaults.
2. **The printer exposes its state to its layout.** `PatternPrinter` is a
   stateful visitor whose `setFlags()` existed only for `PatternLayout`,
   which set the modifiers around a group's body by hand. The printer now
   prints such a body under the modifiers it is given and puts its own
   back, so the setter goes. `resetState()` stays: it is the lifecycle of a
   visitor reused between patterns, as a reset service has in a container.
3. **The ReDoS profiler sorts node types by hand.** `RedosProfiler` is a
   visitor, yet `nullableStatus()` and `firstTriedStep()` are recursive
   `instanceof` chains over the whole node set (38 checks). Each becomes a
   visitor of its own, tested against the engine on its own: a node is
   nullable when the engine matches it on the empty string.
4. **The facade rebuilds its services per call.** `Toolkit\Regex` is a
   long-lived service in both bridges, yet `redos()`, `optimize()`,
   `transpile()` and `compatibility()` construct a new analyzer, optimizer,
   transpiler or checker on each call. Build each stateless service once
   per facade.

## PHP 7.4 twin

After the 2.0.0 tag, publish `php-regex/regex-parser-php74` once more from
the tagged commit, from the `twin` artifact of a green "php74 twin"
workflow run. The published `6eb39fa` is the build of 2.x at caea95400d.

Before that, the sure-match facts of #198 need a published twin: the build
of `always-matching-pattern` at 001b9b5526 was verified on PHP 7.4 and
committed on a clone of the mirror as `11e3725`, which waits for a push to
the mirror's `2.x`. The PHPStan fork (`read-regex-shapes-through-phpregex`,
one commit on upstream 2.3.x) already names that hash in `composer.json`;
once the mirror holds it, `composer update php-regex/regex-parser-php74`
there, amend, force-push, and refresh the description of its pull request
with the #14490 line (the return type of `preg_match()` on a pattern sure
to match).


## PHP 8.6 GA, 2026-11-19

1. That day, rename the `8.6-rc` rows of `engines-bundled` and
   `engine-claims` (`.github/workflows/engines.yml`), and the `8.6-rc` key of
   `EngineClaims::IMAGES` (`tests/TestUtils/EngineClaims.php`), to `'8.6'`:
   the `-rc` docker aliases vanish that day and an unrenamed row breaks CI.
   The `startsWith(matrix.php, '8.6')` conditions already match either
   spelling, and `engines-latest` needs no change. Check that the renamed
   image still bundles PCRE2 10.44, and if it ships mbstring again, remove
   the "Install multibyte support" step.
2. Once the tools' dependencies admit 8.6, drop it from the
   `--ignore-platform-req=php+` cases of `.github/actions/setup-php-project`
   and of `engines-bundled`.
3. Until then, watch `ext/pcre/pcre2lib` on the PHP-8.6 branch and on
   master: a late PCRE2 bump, or a downgrade like 8.5's at its release cut,
   reopens the engine maps. If it moves, re-run the engine-scoped corpus rows
   first.


## Blog articles

- Publish the four drafts (`catastrophic-backtracking-in-php`,
  `re2-vs-pcre2`, `which-pcre2-ships-with-which-php`,
  `why-pcre2-is-a-separate-library`): flip `published`, and add an
  `## Articles` section to `docs/llms.txt` with a line for each.
- Before the 8.6 GA, re-check the 8.6 row of the version matrix in
  `which-pcre2-ships-with-which-php` against the PHP-8.6 branch: the article
  promises it.
- Next articles, in priority order: variable-length lookbehind across
  PHP 8.2–8.4, the capture shapes of `$matches`, why `pcre.backtrack_limit`
  does not protect under the PCRE2 JIT, regex equivalence with proofs,
  named groups with branch reset, measured data from the corpus,
  transpiling to JavaScript with a proof, `preg_match()` versus
  `str_contains()` measured, Symfony route requirements audited.


## PHPStan pull request

When the pull request is opened, state the parse cost in its description.
Measured over the 395 distinct literal patterns of the `preg_*` calls in
PHPStan and its vendor (PHP 8.4, median of five runs), with the twin
PHPStan ships: validating takes 97 ms and the capture shape 57 ms, against
88 ms for everything with the parser PHPStan generates today; the first
pattern of a process costs 30 ms against 10 ms, class loading included,
and the peak memory is 6 MB higher; a second read of a pattern is free; a
run over twelve regex-heavy files, those patterns included, takes the
same time with either parser (3.7 s), and PHPStan's benchmark suite calls
no `preg_*` function. The capture shape computes eight facts per group
and per arm, and nothing cheaper was found: memoising the length
calculator or the ACCEPT check, or walking the letter check once, each
measured under 3 %.


## PHPStan fork: preg_quote() placeholders

Everything below happens in the phpstan-src checkout
`~/projects/php-regex/phpstan-src`, branch `read-regex-shapes-through-phpregex`;
nothing changes in this repository. `RegexExpressionHelper::resolvePatternConcat()`
(`src/Type/Regex/RegexExpressionHelper.php`, the single choke point — its callers
are `RegexArrayShapeMatcher.php:726`, `PregMatchDynamicReturnTypeExtension.php:83`
and `RegularExpressionPatternRule.php:92`) replaces every `preg_quote()` with
`(?:.*)`, sound only where the pattern reads the quoted text as literal
characters. Six positions are not that: in a class, where
`'/[' . preg_quote($x, '/') . ']/'` folds to a class over the characters of the
placeholder; after `\`, where the escaped `(` eats the placeholder's and the
group never opens; after `(?`, where `'/(?<' . preg_quote($x, '/') . '>a)/'`
folds to a group name PCRE refuses, so a valid call is reported invalid; inside
`\Q…\E`; in braces; and before a quantifier or a comment an empty text leaves to
what precedes it.

`src/Psalm/QuotedPattern.php` in this repository is the contract to port:
`(?:[\s\S]*)` for any text, as `.*` misses `"\n"` without `s`; each quoted part
re-read in place with the probes `''`, `'\\\\E'`, `'0'`, `'n'`, `','`, `"'"`,
`'\.'`, `'\000'` (the forms `preg_quote()` emits) and the other parts empty;
each probe must parse, keep the exact group list (`CaptureShapeAnalyzer`), and
show its characters as literal nodes outside every class between the exact
offsets; `[*+?{#]` or `(?#` right after an empty text withholds; the first and
the last parts must carry the delimiters, as `preg_quote()` escapes the
delimiter only when it is given; any failure withholds, never guesses. A
withheld check returns a type with no constant strings, so the three callers
degrade; the probe logic sits in a class beside `RegexGroupParser`, which shows
how the pinned `php-regex/regex-parser-php74` build is consumed — anything new
must exist in that pinned build. The prose contract is "Patterns built with
`preg_quote()`" in `docs/guides/psalm.md`.

Red first, under `~/projects/php-regex/phpstan-src`: the six positions assert
the plain fallback type in `tests/PHPStan/Analyser/nsrt/preg_match_shapes.php`,
beside the existing `preg_quote` rows (386-422), which keep their types — a
quoted part in literal position loses nothing; and the `(?<` case leaves
`RegularExpressionPatternRuleTest` silent on a valid call. Green through
`make tests`, `make phpstan`, and the 329-pattern engine parity test. A separate
follow-up folds `preg_quote()` of a constant string to the literal it returns,
as `CallTypes::quote` does.


## Distribution

- A composite GitHub Action that installs the phar and runs `regex lint`
  with `--format=github`, so a workflow needs one `uses:` line.
- Phive: a GPG-signed `regex.phar` with its `.asc` beside it on each
  release, so `phive install php-regex/regex` works.


## Open questions

- The Psalm and PHPStan bridges require `regex-linter` whole, for the
  target resolution and the PHP-source helpers they import. Those need a
  home below it, a new split package or a lower one; dropping the
  `require` afterwards is never a break.
- Four tests (`ValidationMemoTest`, `RegexApiTest` twice,
  `RegexGenerateTest`) use `/(?:a[\x{100}-\x{10FFFF}\pL]{0}){1900}/u` as a
  pattern only the engine refuses. Counting a class of every character past
  255 plus a property exactly, one of the lengths "Patterns too large to
  compile" in `docs/concepts/pcre.md` says the library does not know for
  sure, makes the library refuse it too. The counting is ready (no difference from pcre2test 10.45–10.49 over
  some 210,000 cases); the fixture needs another pattern, and on 10.45 and
  later there may be none.
- `AlphaAssertionBodyTest` reads `(*pla:[a])` bodies at 1,000 then 4,000
  units and holds the ratio under the line a shared runner allows (11):
  PHP 8.1 on a loaded runner read 15 once (0.29 s then 4.39 s), where PHP
  8.4 reads 4.9 locally (0.15 s then 0.72 s) and 8,000 units take 2.5
  times 4,000. The read grows a little faster than its text; whether the
  growth is the validator's or the garbage collector's is not measured.
- The language server reads the file it analyses on its own: a pattern
  held in a constant of another file is the lint's. Reporting it at the
  call in the open document needs the argument's range, which the
  occurrence does not carry; decide whether to add it.
