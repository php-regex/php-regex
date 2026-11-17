# TODO

## Left from the second pass before 2.0.0

The bugs of the second pass are fixed in #403; these were left out of it.

- `readAlike()` and the rewrite check read `(?(c)X)` and `(?(c)X|)` alike,
  while a lookbehind tells them apart on `c`: the normalized form carries
  no "`|` written". No rewrite flips that `|` since #403, so nothing wrong
  ships; carrying it moves the cache fingerprint and the PHP 7.4 twin.
- Error offsets: an unclosed `\p{` in an extended class,
  `(?[[\p{-\Q\cé]])` 11 against 13; the `a` option of 10.43+ at level 251,
  `(?^a[` 254 against 255; check the code of `(?(+0)`.
- `CharSetAnalyzer` reads a class written with hex escapes as an unknown
  set, so the ReDoS heuristic rates `[\xC2-\xDF][\x80-\xBF]` loops
  critical.
- `redos --repeat` takes a count that fits but exhausts the memory
  (10^10): cap it, or refuse it with a message.
- `SecurityPatternNormalizer` trims an `access_control` path, which Symfony
  does not: `"^/a\\\n"` becomes an invalid pattern and is skipped.
- Trailing-slash redirects are modelled only where the raw paths share
  nothing, and not across disjoint schemes, where Symfony redirects before
  it checks the scheme.
- A HEAD-only route before a GET route of the same path is an overlap; the
  security side would call it a carve-out and say nothing.

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


## Counted benchmarks

`tests/Benchmark/Tally/` is counted by Tally in CI, in the gating job
`Benchmarks (counted)`, from #390 on.

- Once the counted job has run on a few pull requests: drop the ratio of
  `assertLinearTime()` (the counted growth benchmarks are the measure)
  and keep its budget as a tripwire on every host, which shortens the
  suite. The same for the advisory table job of PHPBench.
