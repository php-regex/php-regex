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
   That path has never run on a real release (`v1.3.0` carries no
   checksum): tag `2.0.0-RC1` first (`bin/release 2.0.0-RC1`, a GitHub
   pre-release), check both assets, `gh attestation verify regex.phar --repo
   php-regex/php-regex` and the phar on PHP 8.1, then tag 2.0.0.
5. After the tag: `bin/status` and Packagist show `v2.0.0` for all seventeen
   packages (they require each other at `self.version`: one missing tag
   leaves the others uninstallable), and the "Latest" release carries the
   2.0 `regex.phar`, which `self-update` and `releases/latest` serve.
6. `bin/bump --next 2.0.1`.


## Phar provenance: the GitHub side

`.github/workflows/phar.yml` builds, attests and attaches the phar on a
version tag. By hand: if Settings → Actions → General restricts the actions
allowed, allow `actions/attest-build-provenance` and `shivammathur/setup-php`.
After the 2.0.0 tag, check that the release went from draft to published
with both assets, and that `gh attestation verify regex.phar --repo
php-regex/php-regex` passes on the downloaded phar.


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



## Symfony Flex recipe

Flex guesses `PHPRegexSymfonyBundle` or `SymfonyBundle`, never
`PHPRegexBundle`, so `composer require php-regex/regex-symfony` registers
nothing (the guide says so). By hand: open a recipe PR on
symfony/recipes-contrib once 2.0.0 is on Packagist.

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

## PHPRegex in phpstan-src: what the proof of concept still owes

The fork `php-regex/phpstan-src` carries the proof of concept as pull
request #1, a research branch: one commit on `2.3.x`, where PHPStan now
reads patterns with a parser generated by phplrt (phpstan-src#6851), and
the branch reads them with PHPRegex instead. `poc/` replays the patterns the
two parsers read differently.

1. Publish the twin again, from the `twin` artifact of a green twin
   workflow run, and re-pin the pull request on it: the pinned `6c1dcd8`
   throws a TypeError on PHP 7.4 for a branch reset of one branch
   (`/(?|(a))/`, `array_intersect_key()` with one array), which the
   generator now rewrites; the new pin also brings the capture shapes of
   this pass (`\K`, conditionals, `(*ACCEPT)`, the product of the splits).
2. On that pin, `RegexGroupParser` reads `$node->bounds` instead of
   `QuantifierBounds::parse($node->quantifier)`, and may pass its target to
   `new CaptureShapeAnalyzer($target)` so `(?(VERSION…)` conditions fold.
3. Once `2.0.0` is tagged and the twin reaches Packagist, replace the
   `2.x-dev#<hash>` pin with the exact version (the precedent is
   `ondrejmirtes/phplrt-runtime`, pinned to an exact release).
4. Rebuild the phar from the pull request on its last twin pin and run it
   on `php:7.4-cli` and `php:8.4-cli`.
5. Re-read php-src master after the 8.6 GA (2026-11-19) in case a
   release-cut PCRE2 downgrade like 8.5's happens.
6. Split the single commit into stacked pull requests once the maintainer
   asks for it.

## Decisions the maintainer owns

- The Psalm and PHPStan bridges without the whole linter: the target
  resolution and the PHP-source helpers they import need a home below
  `regex-linter`, a new split package or a lower one. Removing a `require`
  is never a break.
- `RepeatRewrites::MAX_COUNT` (the optimizer) repeats the 65535 limit of
  `PcreNumber`: reading it from there needs `PcreNumber` public.
- Before PCRE2 10.45 the engine misses a match past a lookahead:
  `/(?=x)a?x/` does not match `"x"` on 10.42 and 10.44, JIT or not; 10.45
  fixed the first and last code unit read with lookaheads (#444).
  `generate()` gives `"x"` for a 10.44 target. Whether a target before 10.45
  is judged by that defect is a call to make.
- Coverage (98.92% of statements, 2026-10-27): dead regions proven
  unreachable (`RangeChecks:163,183`, `CompiledLength:963,1642`) — delete
  rather than test; and whether tests may hand-build trees to reach the ~50
  guards only such trees reach (printer raw literals, HIR and transpiler
  empty shapes, railroad and highlighter guards).
- Four tests use `/(?:a[\x{100}-\x{10FFFF}\pL]{0}){1900}/u` as a pattern only
  the engine refuses (`ValidationMemoTest`, `RegexApiTest` ×2,
  `RegexGenerateTest`). Counting a class of every character past 255 plus a
  property exactly — the last item of "Known differences" in
  `docs/concepts/pcre.md` but the DEFINE quirk — makes the library refuse it
  too: the counting is ready (0 differences from pcre2test 10.45–10.49 over
  210,000 cases), the fixture needs another pattern, and on 10.45+ there may
  be none.
- `ConfirmationRunner` (public) runs under the process locale when called
  directly; `RedosAnalyzer` runs it under the target's. Whether its
  constructor takes a locale.

## Left by the 2026-10-27 pass, each reproduced

- JavaScript still refuses empty repetitions that
  agree only through what follows them (`/(?:a|(?=b)|.)*\z/`), through
  alternatives that never apply at one position, or through a body that
  only matches empty (`/^(){3,5}/`).
- Nullability is computed in four copies (`PossessiveRewrites`,
  `RedosProfiler`, `PnfaBuilder`, `LengthRangeCalculator`) that disagree on
  ~1,160 of 175,625 nodes of the repository's patterns (`DEFINE`,
  `(*ACCEPT)` sequences, class-set operations, script runs): one source,
  checked against the engine, then every copy reading it.
- The ReDoS heuristics' rule on a repeated body that can match empty fires
  on `/((?(1)|b))+/`, which PCRE2 reads in constant steps. A lookahead body
  that stops at once, `(?=a+?)`, or holds where the loop could go on,
  `(?!a+)` before `a`, keeps the polynomial proof from the linear one: the
  model has no cut scoped to a body.
- On the 8.1 build the CI runners use (a setup-php compile), a child that
  dies of memory exhaustion prints no envelope from its shutdown handler;
  the official 8.1 build does. Reproduce with that build, then make it
  print (the reserve and the past-the-buffers write are already in).
- No CI job runs PHP 8.1's bundled PCRE2 10.39 (the 8.1 jobs link 10.42):
  run the whole suite on `php:8.1-cli` once, then add that cell to the
  Engines workflow so the rows held to 10.40 readings stay gated.
- A local check of the twin without containers runs only the PHP side:
  every Homebrew PHP (7.3, 7.4, 8.2, 8.3, 8.4) links the same PCRE2 10.49,
  so the release differences stay with the twin workflow. `build/verify/
  run.sh` takes docker images only; a mode taking a local PHP binary
  would compare the twin on PHP 7.4 to the original on the same engine,
  with no engine-dependent exemption.

- About forty guard and marker tests (`GuardedTargetLintTest`,
  `TargetMarkerLintTest`, `PsalmPluginTargetTest` and their bridge twins)
  skip wherever the running PHP's minor links another PCRE2 than the one it
  bundles, this machine's PHP 8.4 on 10.49 included: their rows mix the
  bundled releases' labels and refusals. Written through `PcreRule` version
  by version, they would run on every engine; the bundled cells of the
  Engines workflow run them meanwhile.

## Found by the 2026-10-27 audit pass, each reproduced

The literal-fragment builder, the repairer and the confirmed mode first:
they answer wrong, not just incomplete.

- The delimiter tables are ASCII where PHP reads the locale: under this
  machine's default `LC_CTYPE` (a UTF-8 locale), `"\xFF\xFF"` is judged
  VALID — PHP refuses it, "Delimiter must not be alphanumeric, backslash,
  or NUL byte", because `isalnum()` answers true for the high bytes — and
  `"\xA0/a/"` is judged INVALID where PHP accepts and matches `"a"`
  (`isspace(0xA0)`). The judged engine's locale decides both tables; the
  library's own `locale` option reads them for classes but not for the
  delimiter scan (`PatternParser.php:40,50,202-207`, `Text/Ascii.php`).
  glibc UTF-8 locales agree with the library; BSD/macOS ones do not.

### Smaller points, each reproduced once


### Probed hard, nothing found

Worth recording so the next pass does not re-walk them: the automata's
partial-match language agreed with `preg_match` on every subject up to
length 4 over 28 hand-built anchor patterns and 600 generated ones
(19,200 differential checks, the 120 regular-subset refusals aside); the
`r` modifier is gated PHP 8.4 + PCRE2 10.43 and `n` leaves zero capture
groups exactly as the engine does; `regex.schema.json`'s rule list and
defaults match `RuleCatalog` entry for entry; the PHPStan bridge fires on
invalid literal patterns with no false positive on valid ones;
`format()`/`compact()` round-trip and preserve `[ ]`, `[#]`, `\ `, `\Q…\E`
and `(?(1)…)` layouts; the JSON-RPC framing caps and drains refused
frames; `optimize()` itself produced no divergent rewrite over ~520
patterns × ~95 subjects under five option configs; `CaptureShapeAnalyzer`
matched the engine on branch reset, `(?J)`, recursion, conditionals,
`\K`, `(*ACCEPT)` and `(*MARK)`; the transpiled JS/Python outputs matched
PCRE2 on 40+ differential subjects including the Kelvin, ſ and İ/ı case
folds; and the HTML explainer escapes markup injection.

