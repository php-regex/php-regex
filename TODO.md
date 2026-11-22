# TODO

## ReDoS precision

- Under another locale's tables, the ReDoS heuristics and the overlap rule
  read the cases from them (#427) but leave `\d`, `\s` and `\w` without
  UCP unknown: under fr_FR.ISO8859-1, `"/(?:\\w|\xE9|)+/"` goes unreported,
  though the Latin-1 tables put `\xE9` in `\w`. Read them from
  `CharacterTables::bytesOf()` as the cases are.
- Where PCRE judges no branch, the overlap rule still reads a caseless
  byte above ASCII through PCRE's own tables: under fr_FR.ISO8859-1,
  `"/(?:\xC9|\xE9|)+/i"` goes unreported, though `/\xC9/i` matches `\xE9`
  there.
- Decide how to report the search of `preg_match_all()`, `preg_replace()`
  and `preg_split()`: after an empty match PHP tries again at the same
  offset with `NOTEMPTY_ATSTART | ANCHORED`, so a pattern that may match
  empty searches in the square of the subject there while `preg_match()`
  stays linear. `/(?:a+\d)?/` on 2,000, 4,000 and 8,000 `a`s: 1.7, 6.8
  and 26 ms through `preg_match_all()`, under 0.1 ms through
  `preg_match()` (PHP 8.4.28, PCRE2 10.49). A finding of its own, or a
  flag on the search cost the caller reads.
- `\K` at the end of a match under `(*NOTEMPTY)` is not read: the match it
  leaves empty is refused, so `/(*NOTEMPTY)a+\K/` gives back every `a+` at
  every offset, 29, 115 and 458 ms on 2,000, 4,000 and 8,000 `a`s with the
  JIT off, and gets no search-cost finding. The JIT, which ignores
  `(*NOTEMPTY)` (see Engine reports), answers at once there.

## Engine reports

Found while fixing the bugs of #400; each is PCRE2's, not the library's.

- `pcre2_jit_match()`, which PHP calls when the JIT is on, ignores
  `(*NOTEMPTY)` and `(*NOTEMPTY_ATSTART)`, where `pcre2_match()` honours
  them, through the JIT too: `preg_match('/(*NOTEMPTY)a*/', 'b')` returns 1
  with the JIT and 0 with `pcre.jit=0`, and `(*NOTEMPTY_ATSTART)a*` on `b`
  matches at offset 0 with the JIT and at 1 without. pcre2pattern says the
  items act as the options do for whichever function matches, and both
  options work with `pcre2_jit_match()`. No issue or pull request names it
  on 2026-11-22 (10.43 to master): report it to PCRE2, with the fix of
  copying the two flags into the options as `pcre2_match()` does.
- PCRE2's open pull requests fix the JIT's two other wrong answers, found
  on 10.49 and master: #1004 a zero-width branch of a variable-length
  lookbehind (`/(?<=a?b|\b)x/` on `x`, since 10.43) and #1005 the prefix
  scan (`/\x{100}.{2}|A/u` on `A`, since 10.45, so not in PHP's bundled
  10.44). Nothing to report: check that a release ships them.


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

- In `~/projects/php-regex/phpstan-src`, branch
  `read-regex-shapes-through-phpregex` is one local commit, `da58ce923a`,
  on the parent of the pushed `079eba6c9`: it pins the twin's `2.0.0-RC1`
  (composer.json named `11e3725`, never published, and the lock another
  commit) and folds in the two `preg_quote()` commits. The nsrt suite
  (1,903), the regex, rule and engine tests (340 patterns) and `make
  phpstan` pass with RC1. Before the force-push to the fork: run the full
  suite (`make tests`), which the description says passes; merge the
  second docblock of `RegexExpressionHelper::resolvePatternConcat()` into
  upstream's above it; then refresh the description of
  php-regex/phpstan-src#1: the RC1 line, the `preg_quote()` line, the
  #14490 line (the return type of `preg_match()` on a pattern sure to
  match; its own pattern keeps the 0) and the parse cost (395 literal
  patterns of PHPStan and its vendor, PHP 8.4, median of five runs:
  validating 97 ms and the capture shape 57 ms, against 88 ms for the
  generated parser; 30 ms against 10 ms for the first pattern, 6 MB more
  peak memory, a second read free, the same 3.7 s over twelve regex-heavy
  files; memoising the length calculator or the ACCEPT check, or walking
  the letter check once, each measured under 3 %).


## Linter precision

- `regex.lint.flag.useless.i` reads a byte above ASCII without u as
  caseless under C, but `(*UCP)` gives the bytes below 256 their Unicode
  case: `/(*UCP)\xE9/i` matches `\xC9` (PHP 8.4.28, PCRE2 10.49, with and
  without the JIT), yet the rule calls its i useless. Read those bytes as
  the code points of the same value under UCP, from the target's tables
  (`OtherCases`, as #430 does under u), with an oracle across releases.
- PHPStan's own `regexp.pattern` rule reports the php/pie loop too: it
  does not narrow a union of constant strings by `$v[0] === '|'`. The fix
  is committed, not pushed, in `~/projects/php-regex/phpstan-src-narrow-constant-string-offset`
  (branch `narrow-constant-string-offset`, `70e61331c` on upstream 2.3.x
  `9c990f5dd`): `IdenticalNarrowingHelper` keeps the constant strings whose
  byte at a constant offset is the literal, its `turbo-ext` mirror does
  the same, and an nsrt test covers both branches, negative and out of
  range offsets; `make tests` passes with and without the extension. Push
  it to the fork and open the pull request to phpstan/phpstan-src.
  phpstan-src#6614 (`substr()` or `$s[0]` against a longer string) is
  related, not the same.


## Optimizer precision

- The result check proves no possessive in a pattern that holds a call
  (`LanguageSolver::enters`): `/(a+b?)c(?1)d/`, whose possessive the
  rewrite now reads as safe wherever the group runs, is offered
  `a++b?`, refused, and rewritten again without the possessive step, so it
  ships unchanged. A check that reads calls would ship `/(a++b?)c(?1)d/`
  and save that second rewrite.
