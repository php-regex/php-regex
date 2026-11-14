# TODO

## Release 2.0.0

In this order.

1. **The bump commit.** `bin/bump 2.0.0` sets `Regex::VERSION` and
   `Regex::VERSION_ID`, dates the CHANGELOG section, and moves the install
   story from `2.x-dev` to the stable release: `docs/_data/product.yml`
   says `stable: true`, which the install include every page and article
   reads; `README.md` and `docs/llms.txt` lose the two `composer config`
   lines, the `:2.x-dev` suffix and the "2.0 is coming" section; and the
   `2.0.0-DEV` sample outputs of the docs report `2.0.0`. Read the diff,
   then commit it on its own branch. `bin/release` refuses a final version
   whose docs still install from the dev channel. Run `bin/playground-bundle`
   on that branch too, after `wasm/pcre2-trace/build.sh` (it stops without the
   trace module), so that the home runs the library of the release: the
   bundle in `docs/assets/playground/` still dates from 2026-11-10.
2. **Before the tag**, check with `bin/status` that each of the nineteen
   split repositories carries a fresh 2.x head and a crawled dev version.
   The split pushes are not atomic: between the first and the last webhook
   after the tag, a `^2.0` resolution fails on missing siblings.
3. **Release candidate first.** The release path has never run on a real
   release (`v1.3.0` carries no checksum). `bin/release --dry-run
   2.0.0-RC1`, then `bin/release 2.0.0-RC1` (a GitHub pre-release): it
   drafts the release and pushes the tag; the phar workflow builds the
   phar, checks that its version is the tag's and that `regex.phar lsp`
   answers `initialize`, attests it, attaches it and its checksum, then
   publishes the draft; the split workflow tags the nineteen packages.
   Check both assets, `gh attestation verify regex.phar --repo
   php-regex/php-regex`, and the phar on PHP 8.1.
4. **Tag 2.0.0** the same way. Then `bin/status` and Packagist show
   `v2.0.0` for all nineteen packages (they require each other at
   `self.version`: one missing tag leaves the others uninstallable), and the
   "Latest" release carries the 2.0 `regex.phar`, which `self-update` and
   `releases/latest` serve.
   Run `uses: php-regex/php-regex@v2.0.0` once on a test repository: the
   action downloads that phar and checks its checksum.
5. `bin/bump --next 2.0.1`.
6. Submit `src/Linter/regex.schema.json` to schemastore.org (`fileMatch`:
   `regex.json`, `regex.dist.json`) for editor support without setup: it
   needs the stable public URL the tag gives it.


## PHP 7.4 twin

The twin lives at https://github.com/php-regex/regex-parser-php74.

After the 2.0.0 tag, publish `php-regex/regex-parser-php74` once more from
the tagged commit, from the `twin` artifact of a green "php74 twin"
workflow run. The published `6eb39fa` is the build of 2.x at caea95400d.

Before that, the sure-match facts of #198 need a published twin. The
build once committed as `11e3725` was never pushed and its clone is gone;
#198 is in 2.x since 649d5ccd74, so a twin from a green run on 2.x carries
it (the head of the twin on GitHub is still the build of caea95400d). The
PHPStan fork (`read-regex-shapes-through-phpregex`, one commit on upstream
2.3.x) names `11e3725` in `composer.json`: once the twin is published, pin
the new hash there, amend, force-push to the fork, and refresh the
description of the fork's pull request (php-regex/phpstan-src#1) with the
#14490 line (the return type of `preg_match()` on a pattern sure to
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


## Pull request checks

- Make `CI` the required check of `2.x` in the branch protection: a pull
  request of prose alone reports it (seen on #369), and the protection
  requires no check yet.
