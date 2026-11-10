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
3. **Before the tag**, check with `bin/status` that each of the nineteen
   split repositories carries a fresh 2.x head and a crawled dev version.
   The split pushes are not atomic: between the first and the last webhook
   after the tag, a `^2.0` resolution fails on missing siblings.
   `php-regex/regex-infection` is not on Packagist yet: submit
   `https://github.com/php-regex/regex-infection` and add its webhook
   first, or `bin/status` keeps reporting it ✗.
4. **Release candidate first.** The release path has never run on a real
   release (`v1.3.0` carries no checksum). `bin/release --dry-run
   2.0.0-RC1`, then `bin/release 2.0.0-RC1` (a GitHub pre-release): it
   drafts the release and pushes the tag; the phar workflow builds the
   phar, checks that its version is the tag's and that `regex.phar lsp`
   answers `initialize`, attests it, attaches it and its checksum, then
   publishes the draft; the split workflow tags the nineteen packages.
   Check both assets, `gh attestation verify regex.phar --repo
   php-regex/php-regex`, and the phar on PHP 8.1.
5. **Tag 2.0.0** the same way. Then `bin/status` and Packagist show
   `v2.0.0` for all nineteen packages (they require each other at
   `self.version`: one missing tag leaves the others uninstallable), and the
   "Latest" release carries the 2.0 `regex.phar`, which `self-update` and
   `releases/latest` serve.
   Run `uses: php-regex/php-regex@v2.0.0` once on a test repository: the
   action downloads that phar and checks its checksum.
6. `bin/bump --next 2.0.1`.
7. Submit `src/Linter/regex.schema.json` to schemastore.org (`fileMatch`:
   `regex.json`, `regex.dist.json`) for editor support without setup: it
   needs the stable public URL the tag gives it.


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

Two verdicts leak through the placeholder even where the quoted text is read
as literal characters, so the port above does not cover them (seen 2026-11-10,
PHP 8.4, PCRE2 10.49). `PregMatchDynamicReturnTypeExtension` reads the
placeholder as the pattern: `preg_match('/' . preg_quote($x, '/') . '/', $s)`
is typed `1|false` and `=== 0` on it is reported as always false, where `/abc/`
returns 0 on `zzz`. `RegularExpressionPatternRule` compiles the placeholder:
`'/(?<=' . preg_quote($x, '/') . ')\d+/'` is reported as
`length of lookbehind assertion is not limited`, where `/(?<=id\=)\d+/`
compiles; PHPStan 2.3.5 reports it too. As `PregCallAnalyzer::typeAroundQuotes()`
does here, the placeholder stands for the groups only: the return type and the
validity rule read a pattern with a quoted part as a pattern that is not
constant. Red first: a new row keeps `0|1|false` for that `preg_match()`, and
the lookbehind call leaves `RegularExpressionPatternRuleTest` silent. Under `x`
a quoted space is not a literal either (`/^a b$/x` matches `ab`, not `a b`), and
the probes hold no whitespace.


## Pull requests to merge before the tag

- #234: a class read alone before a syntax error is no longer measured
  against the whole pattern; the linear-time test of `(*pla:[a])` bodies
  gets its margin back on a loaded runner.
- The playground bundle predates the explanation wording of #235: run
  `bin/playground-bundle` and commit it; its reflow then changes nothing
  and can go.
- #237: the root `action.yml` (`uses: php-regex/php-regex@v2.0.0`) and the
  Phive signature. Before the release candidate, create the release key,
  set `PHAR_GPG_PRIVATE_KEY` and `PHAR_GPG_PASSPHRASE` as secrets of the
  repository, and publish the key's fingerprint beside the Phive line of
  `docs/guides/cli.md`; without the secret a release goes out unsigned.
- #238: the language server reads a constant of another file and reports
  its pattern at the argument naming it.


## Pull request checks in stages

- #333 to merge: `ci.yml` runs the checks of a pull request in three
  stages. Its own run (2026-11-10) read the suite on the PHP 8.4 Linux
  cell in 461 s where it took 1,100 s under Xdebug, and 440 runner
  minutes where a run took about 520. Then delete the `ci-stages` branch.
- Only "Every stage ran. Failed: Tests (stage 3)." was read on GitHub. A
  stop at stage 1 and at stage 2 is covered by the tests of the verdict
  script alone: after the merge, push a branch that breaks PHPStan, then
  one that breaks a test, and run `gh workflow run ci.yml --ref <branch>`
  on each.
- To decide, which macOS and Windows cells a pull request runs: on #333
  every Linux and Windows cell had answered 15 minutes after stage 3
  began, and the five macOS cells of `engines.yml` took two hours, each
  waiting for one of the five macOS runners the organization has at once,
  shared with every other open pull request. Leaving some of them to the
  weekly run is a choice of PCRE coverage: the latest PCRE2 is read
  nowhere else.
- To decide, whether coverage and the mutation score gate the matrix:
  they run beside the reference and stop nothing. At 19 minutes the
  coverage job is the longest of a pull request.
- To decide, `CI` as the one required check of `2.x`: a pull request of
  prose alone does not start `ci.yml`, and a required check that never
  reports stays pending. It needs a second workflow reporting `CI` on the
  paths `ci.yml` ignores, or the check stays optional.
- To decide, a `max-parallel` on the matrix of `tests.yml` and
  `engines.yml`, so that one pull request does not take the sixty runners
  when several are open.
- The two phar builds (`phar.yml`, `packages.yml`, "Setup PHP to build")
  call setup-php without `coverage: none` and still load Xdebug; the job
  takes twenty seconds, so it costs little. `RunnerTimeTest` could then
  hold every setup-php step to naming its driver.
- The download of `box` by setup-php itself fails on a 504 as the release
  assets did, and no `--retry` reaches it.


## Open questions

- `RailroadLayoutTest::test_wide_layout_composition_scales_with_its_geometry`
  ("alternation") measures a time ratio against 11 and passes it on loaded
  runners (12.18 on Windows PHP 8.3 for #310, 11.19 on PHP 8.2 latest PCRE2
  for #247, 2026-11-10; neither touches the railroad layout). Count the
  work, or give the ratio the margin the other linear-time tests have.
  On #333 it read 16.5 to 21.3 on the PHP 8.1 prefer-lowest Ubuntu cell,
  beside `AlphaAssertionBodyTest` at 11.4 to 11.8 in the same process; the
  layout itself reads 4 to 7 in `php:8.1-cli` and `php:8.4-cli`. With
  Xdebug gone from the Linux cells the smaller read is some 25 ms there
  too, a thin base for a ratio: more children would steady it.
  On 2026-11-10 it failed on the PHP 8.1 prefer-lowest Ubuntu cell of every
  pull request that ran it to the end (12.2 to 16.0 on #247, #261, #307,
  #310 and #323) and read 13.4 on the PHP 8.3 prefer-lowest one (#277);
  `AlphaAssertionBodyTest` read 12.1 to 12.5 against 11 beside it on #261.
  None of these touches the layout or the lexer.

- `DisplayEscaperLinearTimeTest::test_escape_drops_many_comments_in_linear_time`
  (`(*ANYCRLF)`, 32,000 comments) measures a time ratio against 3.0 and
  read 3.01 on the PHP 8.5 "latest PCRE2" cell (2026-11-10, on #277, which
  does not touch the escaper). The same remedy: count the work, or give the
  ratio a margin.

- `ImpossibleBoundaryRuleTest::test_many_boundaries_under_u_are_linted_quickly`
  ("non-ASCII letter met again") measures wall time against 0.5 s and
  fails on the "latest PCRE2" cells (0.50 s and 0.87 s on PHP 8.2 and 8.3,
  2026-11-08, on #275 and #297, which do not touch the linter), as
  `AlphaAssertionBodyTest` does against 11 s. Bound the work the rule does,
  not the clock.

- `ForkedWorkerPoolTest::test_run_leaves_no_payload_file_when_several_workers_fail_at_once`
  expects the report to name `file0.php`, the first failing chunk, but two
  workers fail at once and either may be read first (`file6.php` on a macOS
  PHP 8.6 cell, 2026-11-09, on #240, which does not touch the pool). Assert
  one of the failing files, or order the report by chunk.

- `ForkedWorkerPoolTest::test_run_ends_a_busy_worker_whatever_the_parent_does_with_sigterm`
  measures wall time against 3 s and fails on a loaded runner (3.10 s and
  3.94 s on PHP 8.6 cells, 2026-11-08, on #294 and #319, which do not touch
  the worker pool). Bound what the worker did, not the clock, or give the
  ceiling the margin the macOS runner needs.

- The PCRE2 10.40 that PHP 8.2 bundles reports five patterns of
  `PatternTooLargeErrorOrderTest` otherwise than the library reads 10.40
  (`php:8.2-cli` and the Windows PHP 8.2 cell, 2026-11-09, on #271; PHP
  8.3's 10.42 agrees with the library). `/(?:(?:[^a]){5000}|(?:[^a]){4000}\k<n>(?:[^a]){1000})/`
  gives "reference to non-existent subpattern at offset 35" there, where the
  library and the 10.40 fixture give `regex.pattern.too_large` at 52, and
  `/(?:[^a]){8190}aaa\d|\k<n>/` "regular expression is too large at offset
  0" where the library gives the reference at 23. The test lists the five
  with the engine's answer (`PHP_BUNDLED_10_40_DIVERGENCES`). Find what the
  bundled 10.40 measures otherwise than pcre2test 10.40, read it so for a
  PHP 8.2 target, and empty the list.

- `RewriteVerificationTest::test_a_wide_alternation_is_judged_without_a_long_match_search`
  measures wall time against 2 s and fails on a loaded runner (2.37 s on
  PHP 8.4 Ubuntu, 2026-11-09, on #259, which does not touch the
  optimizer). Bound the work, not the clock, as for the tests above.

- The suite assumes `posix_geteuid()` on Linux and macOS: with
  `-d disable_functions=posix_geteuid`, the AST file cache tests
  (`FilesystemCacheTest`, `AstSerializerTest`, `FilesystemCacheSecurityTest`)
  and the class-set store tests fail, since neither store reads or writes
  where it cannot check the owner (raised on #306, 2026-11-09). Every CI
  cell has the function. Whether to run the suite on such hosts, and how
  without losing the Windows runs `#[RequiresPhpExtension('posix')]` would
  drop, is open.

- `format` writes a `#` comment ending in an escaped space with `\x20`
  when the comment carries no trailing-comment marker: on its own line
  (`/a` newline `# path\ ` newline `b/x` gives `# path\x20`), and past ten
  indent levels (#310). The match is the same, but the guide says comments
  are kept. Teaching `PatternLayout::finishLines()` where a comment starts
  on a line would keep them as written.

- `JsonFatalErrorTest::test_fatal_error_in_json_mode_prints_the_internal_envelope`
  exits 255 instead of 1 on a slow Windows runner (PHP 8.5, 2026-11-09, on
  #271, which does not touch the CLI): the one-second `--time-limit` runs out
  again inside the shutdown handler of `bin/regex`, which then never writes
  the JSON envelope. Give the handler room (reset the limit in it) or bound
  the work instead of the clock.

- Three real costs whose pull request was closed, its bound refusing
  legitimate input; the fix that keeps the answers is still to write. The
  ReDoS repair analyses one candidate per quantifier (#249): the quantifiers
  the analysis blames can only be tried first, as `/^\s*(?:\w+\s*)*$/` is
  repaired with no culprit and no hotspot named, and whether the others are
  then cut off at a count is open. Alternation overlaps compare every pair
  (#254): bucketing by first character is not enough when every branch
  starts alike, the pairs have to be ruled out by their shared prefix.
  Nested alphabetic assertion bodies are copied at each level (#276): read
  them in place.
- The solver works for 18 to 30 s before it refuses a pattern of many
  lookaheads with "NFA state limit exceeded (5000)":
  `LanguageSolver::compile()` takes 29 s on twelve lookaheads of Unicode
  classes, `/^(?=.*\p{Lu})(?=.*\p{Ll})(?=.*\p{N})...{12,}$/u`, and 18 s on
  forty of the shape `(?=.*a{2})`, under the default options and under a
  budget of 200,000 units alike (measured 2026-11-10, before and after
  #244, which bounds the bodies and not this). The state limit is reached
  late and `maxTransitionsProcessed` does not count the work done before
  it: find where the time goes and charge it. A password rule of four or
  six lookaheads is answered in 0.3 to 2.5 s.
- Without `u`, every set that leaves characters out (the dot, `[^a]`,
  `\D`, `\W`, `\S`) takes one byte in PCRE and one character in the
  JavaScript, `html-pattern` and Python output: `/^\S$/` fails U+00A0,
  two bytes, where `/^[^\t-\r ]$/` matches it in JavaScript (raised on
  #318, documented in the API reference). Whether to refuse those sets for
  the character targets, or advise `u`, is open.
- Capture shapes still double with each level of a `(?(DEFINE)` call
  graph after #255 (8.7 s at twenty levels): another visitor walks the
  calls again.

- The Psalm and PHPStan bridges require `regex-linter` whole, for the
  target resolution and the PHP-source helpers they import. Those need a
  home below it, a new split package or a lower one; dropping the
  `require` afterwards is never a break.
