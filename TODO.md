# TODO

## Composer manifests: the pre-tag review

The manifests are ready; what remains is on the release side:

- `yoeunes/regex-parser` lists a phantom `2.x-dev` in its dev channel
  (a stale crawl from the old name): after the repoint, check the dev
  channel lists 1.x only.
- The seventeen split pushes are not atomic: between the first and the
  last webhook after the tag, a `^2.0` resolution fails on missing
  siblings. Before `bin/release`, confirm every split repo carries a
  fresh 2.x head and a crawled dev version (`bin/status` reads both
  Packagist channels now); after, v2.0.0 on every stable endpoint.
- Post-tag: submit `regex.schema.json` to schemastore.org (`fileMatch`:
  `regex.json`, `regex.dist.json`) for zero-config editor support — it
  needs a stable public URL, so it waits for the tag.

## Release 2.0.0

The code is ready; what is left is the maintainer's, in this order.

1. **Repoint `yoeunes/regex-parser` on Packagist** to a repository holding
   1.x only, before anything is tagged: it still reads the monorepo, so a
   `v2.0.0` tag would release the 1.x package as 2.0.0 (`bin/status` reports
   it ✗ until then; `UPGRADE-2.0.md` promises it stays on 1.x).
2. **The bump commit.** `bin/bump 2.0.0` sets `Regex::VERSION`,
   `Regex::VERSION_ID` and dates the CHANGELOG section. In the same commit,
   the install story moves from `2.x-dev` to the stable release:
   - `docs/_includes/install-prerelease.html` (23 pages, the four articles
     through its `inline=true` form): drop the two `composer config` lines
     and the `:{{ site.data.product.release }}-dev` suffix, written once in
     its `install_commands` capture;
   - outside the include: `docs/index.md` (hero install block),
     `docs/packages.md`, `docs/quick-start.md`, `README.md` ("2.0 is coming"
     and "Getting started"), `docs/llms.txt`;
   - `docs/_data/product.yml`: `version_label`;
   - the `PHPRegex 2.0.0-DEV` sample outputs in `docs/guides/cli.md`,
     `docs/tutorial/README.md`, `docs/tutorial/08-performance-redos.md`,
     `docs/tutorial/09-testing-debugging.md` and the articles
     `docs/_articles/which-pcre2-ships-with-which-php.md` and
     `docs/_articles/why-pcre2-is-a-separate-library.md`, and the
     `"version":"2.0.0-DEV"` of the `initialize` answer in
     `docs/guides/lsp.md`;
   - `tests/Documentation/PackageSelectionDocumentationTest.php` and
     `tests/Documentation/ArticleInstallIncludeTest.php` pin the prerelease
     commands: they move with them.
3. Before the tag: `php-regex/regex-phpunit` exists on GitHub (empty, with
   the metadata of the sixteen others) and the split fills it on the next
   push of 2.x. Then submit it on Packagist and register its webhook like the
   sixteen others: without one, Packagist learns of the tag only through its
   two-week crawl. If `SPLIT_TOKEN` is limited to selected repositories, add
   `regex-phpunit` to it before that push.
4. Push 2.x and watch the first CI run on 2.x after the push, the PHP 8.1
   prefer-lowest cell in particular; once it passes, `bin/release --dry-run
   2.0.0` and `bin/release 2.0.0`: it drafts the GitHub release and pushes
   the tag; the phar workflow builds the phar, checks that its version is
   the tag's and that `regex.phar lsp` answers `initialize`, attests it,
   attaches it and its checksum, then publishes the draft; the split
   workflow tags the seventeen packages.
5. After the tag: `bin/status` and Packagist show `v2.0.0` for all seventeen
   packages (they require each other at `self.version`: one missing tag
   leaves the others uninstallable), and the "Latest" release carries the
   2.0 `regex.phar`, which `self-update` and `releases/latest` serve.
6. `bin/bump --next 2.0.1`.

## Split token: let it write workflows

`SPLIT_TOKEN` may not write workflows: the push of the hardened
`src/*/.github/workflows/close-pull-request.yml` (no default permissions,
`pull-requests: write` for its job, the action named by commit, a timeout;
commit ced976d0b2) was refused by every read-only repository, so the
seventeen copies are back to their earlier text. Give the token the
`workflow` scope (classic) or "Workflows: read and write" (fine-grained),
then restore the hardened copy in all seventeen packages, put `src/*/.github`
back among the zizmor inputs of `.github/workflows/workflows.yml` and `/src/*`
among the github-actions directories of `.github/dependabot.yml`.

## Phar provenance: the GitHub side

`.github/workflows/phar.yml` builds, attests and attaches the phar on a
version tag. By hand: if Settings → Actions → General restricts the actions
allowed, allow `actions/attest-build-provenance` and `shivammathur/setup-php`.
After the 2.0.0 tag, check that the release went from draft to published
with both assets, and that `gh attestation verify regex.phar --repo
php-regex/php-regex` passes on the downloaded phar.

## Upstream PHPStan fixes: push the branches and open the PRs

Six branches are ready in the local `../phpstan-src` checkout (fork
`php-regex/phpstan-src`), one commit each on `2.3.x` (62ed20078), not pushed:
one grammar fix in `resources/RegexGrammar.pp` and one nsrt regression test
each, the official PCRE2 and PHP manual references in the commit message. To
do, for each: `git push origin <branch>`, then open the PR against
`phpstan/phpstan-src:2.3.x`.

1. `regex-grammar-backreference`: *Parse back references in regex patterns*.
2. `regex-grammar-short-escapes`: *Parse \x with fewer than two hexadecimal
   digits and short \0 escapes in regex patterns*.
3. `regex-grammar-single-letter-property`: *Parse single-letter Unicode
   properties like \pL in regex patterns*.
4. `regex-lookaround-zero-width`: *Do not count regex lookarounds as matched
   characters*.
5. `regex-grammar-octal-brace-escape`: *Parse \o{...} octal escapes in regex
   patterns* (`/(\o{141})/` typed its group as `'o'` repeated 141 times; it
   matches `'a'`).
6. `regex-grammar-subroutine-call`: *Parse subroutine calls like \g<1> in
   regex patterns* (`/(a)(\g<1>)/` typed group 2 as `'g<1>'`).

Branches 2, 3 and 5 touch adjacent grammar lines: rebase each later one once
the earlier is merged. Branch 6 merges cleanly with all the others.

## PHP 8.6 closeout and the experimental 8.7 cell

The closeout is built: the analysis range reaches 8.6, both engine matrices
carry 8.6, the `preg_grep()` array-to-false boundary is pinned by tests, the
trim family keeps its pre-8.6 character set on every PHP, a scoped listener
fails the suite only on deprecations under `src/`, and an experimental 8.7
cell of the tests workflow watches the future without gating anything.
Left, dated:

1. **2026-11-19, GA day** — rename the `8.6-rc` rows of `engines-bundled`
   and `engine-claims` (`.github/workflows/engines.yml`), and the `8.6-rc`
   key of `EngineClaims::IMAGES` (`tests/TestUtils/EngineClaims.php`), to
   `'8.6'`: the `-rc` docker aliases vanish that day and an unrenamed row
   breaks CI. The
   `startsWith(matrix.php, '8.6')` conditions already match either
   spelling, and `engines-latest` needs no change. Re-verify the renamed
   image still bundles 10.44, and if it ships mbstring again, try removing
   the "Install multibyte support" step. Once the tools' dependencies admit
   8.6, drop it from the `--ignore-platform-req=php+` cases of
   `.github/actions/setup-php-project` and of `engines-bundled`.
2. Until then, watch `ext/pcre/pcre2lib` on the PHP-8.6 branch and master:
   a late PCRE2 bump would reopen the engine maps. If it moves, re-run the
   engine-scoped corpus rows before anything else.

---

## Symfony Flex recipe

Flex guesses `PHPRegexSymfonyBundle` or `SymfonyBundle`, never
`PHPRegexBundle`, so `composer require php-regex/regex-symfony` registers
nothing (the guide says so). By hand: open a recipe PR on
symfony/recipes-contrib once 2.0.0 is on Packagist.

## Class and reference errors still reported wrong

Each checked with pcre2test 10.32 to 10.49 and the php:X-cli images.

- `(a)\g{1-}` compiles for the library on every PHP 8 target; PCRE2 refuses
  it at offset 5 (7 from 10.47). `\g{--1}` and `\g{-+1}` have the right
  offset but say `regex.backref.relative` where PCRE2 says "subpattern name
  expected".
- A reversed range before a range the parser refuses, in a closed class:
  `[z-a\d-b]` is refused at 3 (4 from 10.47) by PCRE2, later by the library.
- An empty `\Q\E` before an item PCRE2 refuses hides it when a range the
  parser refuses follows: `[\Q\E[:foo:]\d-z]` is offset 7 (12 from 10.45).
- From 10.45 a range from a type ending in a `\Q` the pattern ends in,
  `[\d-\Q`, misses its `]` at the end of the pattern; the library says 4.
- Up to 10.44 `([a-z\E-\pL]` misses its `)` at 12: the library reads a range
  ending on `\pL` there (a quote after a range resets the range read).
- A `\c` ending a class left open hides an earlier error in it: `[[:foo:]\c`,
  `[z-a\c`.
- `\k{1 }` and `\k{1n }` up to 10.42: PCRE2 says "subpattern name must start
  with a non-digit" at offset 3 (from the backslash); `\k{ 1}` from 10.43 is
  reported one byte early.
- `\N{U+ 41}` without UTF from 10.47: offset 7, the library says 10.
- A `\p{` or `\P{` no `}` closes in a class hides an earlier error in it up
  to 10.44: `[z-aa-\p{L]` is "range out of order" at offset 3, the library
  says 8 (invalid range); on every release `([z-a\P{]` is offset 4 (5 from
  10.47), the library says 9 (malformed property).
- The lexer reads no POSIX item whose name holds a backslash, `[:\d-z:]`:
  from 10.45 `[[:\d-z:]]` is "unknown POSIX class name" at offset 9 and
  `[a-[:\d-z:]]` "invalid range in character class" at offset 11, where the
  library refuses the `\d-z` inside at 6 and 8.
- Without `u`, a range ending on a multibyte character ends on its first
  byte: `[a-é-a]` is "range out of order" on every release (offset 6, 7
  from 10.47), the library accepts it.
- Up to 10.44 a quoted hyphen starts a range: `([\Q-\E--\pL]` misses its `)`
  at 13 (the range `-` to `-`, then `\pL`), the library says 11.
- `\N{U+ 41}` without UTF from 10.47: offset 7, the library says 10; `[-[=a=]`
  up to 10.44: offset 2, the library says 3.
- `[-[=a=]` up to 10.44: offset 2, the library says 3.
- A name nothing closes in a call by name, `(?<n>a)(?&n )` or
  `(?<n>a)(?P>n )`, is "syntax error in subpattern name (missing
  terminator?)" from 10.33 to 10.46, at the offset the library gives, where
  the library says `regex.group.unclosed`. Without UTF, `(?<n>a)(?&é)` is
  "subpattern name expected" at 10 on every release (the library says
  `regex.group.unclosed`), and so are `(?<n>a)(?&١)` and `(?<n>a)(?P>١)`
  (the library says `regex.group.name_invalid`).
- From 10.47 a surrogate in braces the pattern ends in, `\x{D800 ` or
  `\N{U+D800 ` under UTF, is "disallowed Unicode code point" at the end of
  its digits (8 for `\x{D800 `); the library says
  `regex.unicode.invalid_digit`, one byte later on 10.47.
- From 10.47 a pattern too large, `(?:a){65535}`, is refused at offset 0;
  the library says 12.
- Up to 10.43 a reference name past 32 characters that nothing closes,
  `(?<n>a)\k<abbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb ` (33 characters), is
  "subpattern name is too long" at the end of the name (43); the library
  says `regex.group.name_unterminated` there, and one byte late for a padded
  `\k{` or `\g{` on 10.43. From 10.44 the missing terminator comes first.
- Under UTF the letters and digits of a name are those of the release's
  Unicode tables: `(?<a>x)\k<𖫀>/u` (U+16AC0, a digit from Unicode 14) is
  "subpattern name expected" on 10.33 to 10.37, where the library says
  `regex.group.name_invalid`; `(?<aࡰ>x)/u` is refused at 4 on 10.33 to
  10.38 and accepted by the library.

## The articles section: what is left

- The next articles, in priority order: variable-length lookbehind across
  PHP 8.2-8.4, capture shapes of `$matches`, why `pcre.backtrack_limit`
  does not protect under the PCRE2 JIT, regex equivalence with proofs,
  named groups with branch reset, measured data from the corpus,
  transpiling to JavaScript with a proof, `preg_match()` versus
  `str_contains()` measured, Symfony route requirements audited.
- Re-check the 8.6 row of the published version matrix against the
  PHP-8.6 branch before GA on 19 November 2026; the article promises it.
- Publishing the four drafts: flip `published`, add each article's line
  under `## Articles` in `docs/llms.txt`, and give each `/blog/<slug>` its
  entry in the sitemap navigation-coverage check, with the blog-index
  justification.
