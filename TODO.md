# TODO

## Release 2.0.0

In this order.

0. **The release key.** Create it, set `PHAR_GPG_PRIVATE_KEY` and
   `PHAR_GPG_PASSPHRASE` as secrets of the repository, and publish the
   key's fingerprint beside the Phive line of `docs/guides/cli.md`: without
   the secret a release goes out unsigned.
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
   whose docs still install from the dev channel. Run `bin/playground-bundle`
   on that branch too, after `wasm/pcre2-trace/build.sh` (it stops without the
   trace module), so that the home runs the library of the release: the
   bundle in `docs/assets/playground/` still dates from 2026-11-10.
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

In `~/projects/php-regex/phpstan-src`, branch `read-regex-shapes-through-phpregex`:
`2fddb71bf` reads a quoted text in place (the shape, the return type and the
validity rule) and the commit after it folds `preg_quote()` of a constant
string to its literal; both sit on top of the PR commit, not pushed. Squash
them into the PR commit or keep them apart, then push.


## Pull request checks

- Make `CI` the required check of `2.x` in the branch protection: a pull
  request of prose alone reports it (seen on #369), and the protection
  requires no check yet.
