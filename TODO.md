# TODO

## Before merging #408 to #411

- None of the four has been reviewed yet; read them through, in stack
  order, before merging the first.
- #410 reports a trailing-slash redirect that takes part of a later
  route's paths: `/admin` declared before a catch-all is now an overlap
  (`GET /admin/`), listed with `--show-overlaps`. #403 declined it because
  it flags every static route declared before a catch-all. Keep it, or
  restore the carve-out in `RouteConflictAnalyzer` before merging.
- Once #408 is merged, its own pull request: the JavaScript and HTML
  pattern targets print a lookbehind of more than one length holding a
  capture as is, and JavaScript reads it right to left, so the capture
  differs. `/(?<=a?(a{1,2})b)c\1/` on `aabca`: PCRE captures `a` and finds
  `ca`, JavaScript captures `aa` and finds nothing. Refuse such a
  lookbehind. Raised on #408, older than it.
- Once #410 is merged, drop the three entries it settles under "Left from
  the second pass": the trimmed `access_control` path, the partial
  trailing-slash redirect, and the `s` flag of symfony/http-foundation 6.4.

## Before 2.0.0, a pull request of its own

- The ReDoS analysis under another locale's tables (an `LC_CTYPE` other
  than C) reads a negated class in ASCII only, and misses quadratic
  patterns: `"/^(x)[^a]+\xC3+\1$/"` (a raw 0xC3) is rated safe under
  fr_FR.ISO8859-1, where PCRE takes 32, 124 and 500 steps on `x` then 8,
  16 and 32 0xC3; `"/^(x)[^é]+\x90+\1$/"` the same. Under those tables,
  read the bytes above ASCII of literals and complements, and leave unknown
  what the tables decide (`\w`, the cases). Clip the gaps of
  `ByteCharSet::complement()` to its bound in the same change, not before:
  alone, that makes the second pattern safe too. The linter's overlap
  fallback reads such a literal as its byte, and
  `CharSetAnalyzerTest::test_read_in_ascii_a_set_stops_there` pins the
  ASCII read: both move with it. Spec first. From the review of #407.

## Psalm 7.11

- Psalm 7.11.0 (vimeo/psalm#14161) answers `preg_match()` in its
  `AssertionFinder` with its own `MatchesPattern` assertion, and no longer
  calls `processCustomAssertion()` for it: the assertions a plugin sets on
  the call are dropped, and `php-regex/regex-psalm` types no `$matches`
  (88 failures; users fall back to `array<array-key, string>`). The tests
  stay on `vimeo/psalm` below 7.11 in `tools/phpunit` and
  `tools/paratest` (#407). Fix upstream: merge the plugin's assertions with
  `MatchesPattern` in the `preg_match` branch of `processFunctionCall()` and
  in `scrapeComparisonAssertions()`, with a test. Lift the pin once the fix
  is released; until then 2.x itself runs red on the newest Psalm.

## Left from the second pass before 2.0.0

The bugs of the second pass are fixed in #403; these were left out of it.

- `SecurityPatternNormalizer` trims an `access_control` path, which Symfony
  does not: `"^/a\\\n"` becomes an invalid pattern and is skipped.
- A trailing-slash redirect that takes only part of a later route's paths,
  while the two routes also share paths, is not reported: `/admin` before a
  `.*` catch-all (GET `/admin/` is redirected). It needs a three-way
  language operation the solver lacks, and would flag every static route
  declared before a catch-all. On disjoint schemes the overlap is reported
  only when the solver's shortest example lies outside the earlier route's
  paths: https `/{x<a|a/|long>}` before http `/{y<a|a/|long/>}` loses
  `/long/` unreported (example `/a/`). Both need a search over three
  languages, (swapped(E) ∩ L) \ E, a new `LanguageSolver` operation
  (declined on #403 by the maintainer, 2026-11-17). An earlier route ending
  on a variable, beside shared paths on disjoint schemes, is counted among
  the skipped pairs: its redirect is PCRE's ordered choice. One whose last
  requirement cannot end on "/" (the default `[^/]++`) could be read.
- symfony/http-foundation 6.4 compiles an `access_control` path as
  `'{'.$regexp.'}'`, without `s`; 8.1 adds it. The analyzer always reads
  with `s`, so on 6.4 it can disagree where `.` meets a decoded newline.

## Engine reports

Found while fixing the bugs of #400; each is PCRE2's, not the library's.

- PCRE2 10.49's JIT returns 0 for `/é.{2,}é|(?<=x)A/u` on `xA`, and 1
  without the JIT: report it to PCRE2.
- PCRE2 10.49's JIT returns 0 for `/(?<=(?:a|bc)|\b)x/` on `x`, and 1
  without the JIT; `/(?<!(?:a|bc)|\b)x/` gives the reverse.
- `(*NOTEMPTY)` and `(*NOTEMPTY_ATSTART)` are ignored under the JIT, PHP's
  default, and honoured without it: `preg_match('/(*NOTEMPTY)a*/', 'b')`
  returns 1 with the JIT and 0 with `pcre.jit=0`. Report it to PCRE2.


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
   bundle in `docs/assets/playground/` still dates from 2026-11-10.
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
5. Submit `src/Linter/regex.schema.json` to schemastore.org (`fileMatch`:
   `regex.json`, `regex.dist.json`) for editor support without setup: it
   needs the stable public URL the tag gives it.


## PHP 7.4 twin

`build/php74` is committed, and `bin/split` publishes it as
`php-regex/regex-parser-php74` with the packages (#387): its `2.x` follows
every push, each release tags it, and `bin/status` checks it with the
others. It carries the sure-match facts of #198. Nothing is left to do for
it at the release.

- The PHPStan fork (`read-regex-shapes-through-phpregex`, one commit on
  upstream 2.3.x) names `11e3725`, never published, in `composer.json`:
  pin the twin's `v2.0.0-RC1` (`d8d59a12`) there, `composer update
  php-regex/regex-parser-php74`, amend, force-push to the fork, and refresh
  the description of the fork's pull request (php-regex/phpstan-src#1) with
  the #14490 line (the return type of `preg_match()` on a pattern sure to
  match).


## PHPStan proof of concept

The proof of concept stays on the fork, as php-regex/phpstan-src#1: no pull
request goes to `phpstan/phpstan-src`.

In `~/projects/php-regex/phpstan-src`, branch `read-regex-shapes-through-phpregex`:
`2fddb71bf` reads a quoted text in place (the shape, the return type and the
validity rule) and the commit after it folds `preg_quote()` of a constant
string to its literal; both sit on top of the PR commit, not pushed. Squash
them into the PR commit or keep them apart, then push to the fork.

State the parse cost in the description of the fork's pull request.
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


## Linter precision

- PHPStan's own `regexp.pattern` rule reports the php/pie loop too: it
  does not narrow a union of constant strings by `$v[0] === '|'`. A small
  pull request to its type specifier, with an nsrt test, would fix it.
- `/(?:(*sr:ö)|è)+/u` raises `regex.lint.quantifier.emptyRepeat`, though
  `(*sr:ö)` cannot match empty.
- Where no PCRE probe can be written for a branch (a script run, a class
  holding every delimiter), `OverlappingAlternationRule` compares first
  bytes, so under `u` a shared UTF-8 lead byte reads as an overlap:
  `/(?:(*sr:ö)|è)+/u` is reported. Keeping only the ASCII part of the byte
  sets in that fallback under `u` drops it; the fallback is kept today, as
  `AlternationOverlapPastAsciiTest::test_a_branch_pcre_cannot_be_asked_about_is_read_by_its_bytes`
  pins it. Decide.
- The same rule leaves unjudged a branch behind lookaheads that differ by
  the way taken, `(?:(?=a)|(?=b))x`, or a negative lookahead of a repeated
  atom, `(?!a+)`; it takes `(?!a$)` to refuse `a` outright. Each can only
  hide an overlap.
- `CharSetAnalyzer::firstChars()` reads a start-of-pattern verb,
  `(*CASELESS_RESTRICT)`, `(*UTF)` or `(*UCP)`, as an item of unknown set,
  so the characters a whole pattern starts with come back unknown behind
  it: `/(*UCP)a/` against `/a/`.


## Optimizer precision

- The possessive rewrite (`possessive: true`) does not read the characters
  after a group whose last items may match nothing: it offers `/(a+b?)a/` as `/(a++b?)a/`,
  `/(?:a+b?)*a$/` as `/(?:a++b?)*a$/` and `(?:[0-9]+b?)1` as
  `(?:\d++b?)1`, which the engine tells apart (`(a+b?)\1` and
  `(*scs:(1)(a+b?))\2c` too). The result check refuses them, so nothing
  wrong ships, but the refusal drops the pattern's other rewrites:
  `/(a+b?)a[0-9]/` stays as written, while `/(a+b?)[0-9]/` becomes
  `/(a++b?)\d/`. Reading past the group would also stop
  `/^(\d+\s*)$/` → `/^(\d++\s*)$/`, which the solver proves today.
- With `possessive: true`, the solver refuses every possessive in a
  pattern that holds a lookaround or `\b`, wherever it stands:
  `/\d+x(?!y)/s`, `/(?=x)\d+y/s` and `/\d+y\b/s` come back unchanged, the
  `s` the default options drop included. On a refusal, `Optimizer::optimize()` could
  rewrite again without the possessive step: one more rewrite and check,
  only when a refusal happens.
- `PossessiveRewrites::closes()` counts the non-atomic `(*napla:` among
  the groups nothing after them backtracks into. Harmless today: its `*`
  reads as an inline flag, which keeps the possessive rewrite out of the
  whole pattern.


## Counted benchmarks

`tests/Benchmark/Tally/` is counted by Tally in CI, in the gating job
`Benchmarks (counted)`, from #390 on.

- Once the counted job has run on a few pull requests: drop the ratio of
  `assertLinearTime()` (the counted growth benchmarks are the measure)
  and keep its budget as a tripwire on every host, which shortens the
  suite. The same for the advisory table job of PHPBench.
