# TODO

## Before merging #411

- Review #411, which audits `Assert\Regex` and its `htmlPattern`
  (`RegexConstraintAnalyzer`), then cover the mutants its diff leaves: the
  `Coverage and Mutations` job stops at a covered MSI of 64%, under the
  90% it requires.

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
- The Psalm plugin types `$matches` where nothing matched, on every Psalm
  it narrows on. After
  `if (preg_match('/(x)/', $s, $m) && !preg_match('/(a)(b)?/', $t, $m)) { return; }`,
  `$m` is `array<never, never>`, where PHP leaves `['ab', 'a', 'b']` for
  `('x', 'ab')`: Psalm drops the left operand's clauses on a variable the
  right one writes, and the plugin's conditions reach that formula; without
  the plugin, `$m` stays `array<array-key, string>`. On Psalm 6, the `else`
  of `if ($found = preg_match('/(a)(b)?/', $s, $m))` and the body of
  `if (!($found = preg_match(…))) { … return; }` type `$m` as the shape.
  Pin both forms on every Psalm row of the plugin's job.

## Psalm 7.11

- Psalm 7.11.0 (vimeo/psalm#14161) and later no longer read the condition
  the plugin sets on `preg_match()`. The branch
  `psalm-711-preg-match-assertions` (not committed yet) writes that
  condition into the assertion cache Psalm reads first, lifts the `<7.11`
  pin in `tools/phpunit` and `tools/paratest`, and adds Psalm 7.10 and
  7.12 rows to the plugin's job: open its pull request.
- A loop that changes the pattern between two passes keeps the shape of
  the first: with `$p = '/(a)(b)?/'` before a `foreach` whose body runs
  `if (preg_match_all($p, $s, $n)) { … }` then `$p = '/(x)(y)(z)/'`, `$n`
  is typed from `/(a)(b)?/` on every Psalm; `preg_match()` does the same
  up to Psalm 7.10. Psalm clears the condition cache between the passes,
  not the assertions set on the call.

## Playground on PHP 8.6

- Merge #418: the Step through tab on every engine of the select (one
  PCRE2 trace module per bundled release, 10.35 to 10.44).
- Offer PHP 8.6 in the playground and the Run buttons. php-wasm 0.2.0
  stops at 8.5.2 and its Makefile refuses 8.6, so the engine is built from
  php-wasm's v0.2.0 sources with 8.6.0 added (its php-src patch ported:
  six files moved, `zend_free_internal_arg_info()` takes a second
  argument), with mbstring, and served from `docs/` beside a manifest of
  sha256s. 8.6 cannot be stood in by 8.5: it refuses `\C` under u, keys
  MARK once and fixes `preg_grep()`. The branch `php-86-playground-engine`
  (on top of #418) has the 8.6.0 options row; the engine itself needs
  Docker at 4 CPU and 8 GB or more.

## Engine reports

Found while fixing the bugs of #400; each is PCRE2's, not the library's.

- PCRE2 10.49's JIT returns 0 for `/é.{2,}é|(?<=x)A/u` on `xA`, and 1
  without the JIT: report it to PCRE2.
- PCRE2 10.49's JIT returns 0 for `/(?<=(?:a|bc)|\b)x/` on `x`, and 1
  without the JIT; `/(?<!(?:a|bc)|\b)x/` gives the reverse.
- `(*NOTEMPTY)` and `(*NOTEMPTY_ATSTART)` are ignored under the JIT, PHP's
  default, and honoured without it: `preg_match('/(*NOTEMPTY)a*/', 'b')`
  returns 1 with the JIT and 0 with `pcre.jit=0`. Report it to PCRE2.


## PCRE2 10.50

10.50 is due on 2026-11-30. On 2026-11-21, 10.49 is the newest release, and
the 140 patterns PCRE2's development branch added to `testinput2` and
`testinput5` since then all get from the library the verdict 10.49 gives.

- Once 10.50 is out, move the vendored suite to it
  (`tests/Fixtures/Pcre2/README.md`), and add 10.50 to
  `release-outcomes.json` and to the `pcre2test` builds of `engines.yml`.
  Error 204 reads "requires" where 10.49 writes "require", the wording
  `Pcre2TestdataExtractorTest` quotes.
- PCRE2#1072, in the 10.50 milestone: `/(?<=a(?(DEFINE)(?<=b+)))x/`
  compiles on 10.49, and the library agrees (`DefineSkippedInLookbehind`,
  from 10.35). Should 10.50 measure a lookbehind inside a DEFINE inside a
  lookbehind, it refuses that pattern with error 125, and the feature needs
  a last release.
- PCRE2#549, in the 10.51 milestone (due 2027-01-31), points every compile
  error offset at the failing character. The library gives PCRE2's offsets
  release by release: expect offset gates.

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


## PHPStan proof of concept

The proof of concept stays on the fork, as php-regex/phpstan-src#1: no pull
request goes to `phpstan/phpstan-src`.

- The PHPStan fork (`read-regex-shapes-through-phpregex`, one commit on
  upstream 2.3.x) names `11e3725`, never published, in `composer.json`:
  pin the twin's `v2.0.0-RC1` (`d8d59a12`) there, `composer update
  php-regex/regex-parser-php74`, amend, force-push to the fork, and refresh
  the description of the fork's pull request (php-regex/phpstan-src#1) with
  the #14490 line (the return type of `preg_match()` on a pattern sure to
  match).
- In `~/projects/php-regex/phpstan-src`, branch
  `read-regex-shapes-through-phpregex`: `2fddb71bf` reads a quoted text in
  place (the shape, the return type and the validity rule) and the commit
  after it folds `preg_quote()` of a constant string to its literal; both
  sit on top of the PR commit, not pushed. Squash them into the PR commit
  or keep them apart, then push to the fork.
- State the parse cost in the description of the fork's pull request,
  measured over the 395 distinct literal patterns of the `preg_*` calls in
  PHPStan and its vendor (PHP 8.4, median of five runs), with the twin
  PHPStan ships: validating takes 97 ms and the capture shape 57 ms,
  against 88 ms for everything with the parser PHPStan generates today;
  the first pattern of a process costs 30 ms against 10 ms, class loading
  included, and the peak memory is 6 MB higher; a second read of a pattern
  is free; a run over twelve regex-heavy files, those patterns included,
  takes the same time with either parser (3.7 s), and PHPStan's benchmark
  suite calls no `preg_*` function. The capture shape computes eight facts
  per group and per arm, and nothing cheaper was found: memoising the
  length calculator or the ACCEPT check, or walking the letter check once,
  each measured under 3 %.


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
