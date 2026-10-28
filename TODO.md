# TODO

## Release 2.0.0

In this order.

1. **Repoint `yoeunes/regex-parser` on Packagist** to a repository holding
   1.x only, before anything is tagged. It still reads
   `php-regex/php-regex` and lists `2.x-dev` beside `1.x-dev`, so a `v2.0.0`
   tag would release the 1.x package as 2.0.0 (`bin/status` reports it ✗
   until then; `UPGRADE-2.0.md` promises it stays on 1.x). Afterwards, its
   dev channel lists `1.x-dev` only.
2. **The bump commit.** `bin/bump 2.0.0` sets `Regex::VERSION`,
   `Regex::VERSION_ID` and dates the CHANGELOG section. In the same commit,
   the install story moves from `2.x-dev` to the stable release:
   - `docs/_includes/install-prerelease.html`, which every install block of
     the docs and the articles reads: drop the two `composer config` lines
     and the `:{{ site.data.product.release }}-dev` suffix, written once in
     its `install_commands` capture;
   - outside the include: `docs/index.md` (hero install block),
     `docs/packages.md`, `docs/quick-start.md`, `README.md` ("2.0 is coming"
     and "Getting started"), `docs/llms.txt`;
   - `docs/_data/product.yml`: `version_label`;
   - the `2.0.0-DEV` sample outputs in `docs/guides/cli.md`, in the
     `initialize` answer of `docs/guides/lsp.md`, and in the articles
     `which-pcre2-ships-with-which-php.md` and
     `why-pcre2-is-a-separate-library.md`;
   - `tests/Documentation/PackageSelectionDocumentationTest.php` and
     `tests/Documentation/ArticleInstallIncludeTest.php` pin the prerelease
     commands: they move with them.
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


## GitHub settings for the phar

`.github/workflows/phar.yml` builds, attests and attaches the phar on a
version tag. If Settings → Actions → General restricts the actions allowed,
allow `actions/attest-build-provenance` and `shivammathur/setup-php`.


## PHP 7.4 twin

After the 2.0.0 tag, publish `php-regex/regex-parser-php74` once more from
the tagged commit, from the `twin` artifact of a green "php74 twin"
workflow run. The published `c525273` is the build of 2.x at dec0cb9e52.


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


## Open questions

- Seventeen packages, two of them small: `regex-generator` holds four files
  and `regex-toolkit` five. Merging two packages after the tag breaks the
  `composer require` of whoever installed the one that disappears, while
  splitting one later is additive; nobody installs them yet. Decide before
  the tag whether each keeps its repository, its Packagist entry and its CI.
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

