# TODO

## Release 2.0.0

The code is ready; what is left is the maintainer's, in this order.

1. **Repoint `yoeunes/regex-parser` on Packagist** to a repository holding
   1.x only, before anything is tagged: it still reads the monorepo, so a
   `v2.0.0` tag would release the 1.x package as 2.0.0 (`bin/status` reports
   it ✗ until then; `UPGRADE-2.0.md` promises it stays on 1.x).
2. **The bump commit.** `bin/bump 2.0.0` sets `Regex::VERSION`,
   `Regex::VERSION_ID` and dates the CHANGELOG section. In the same commit,
   the install story moves from `2.x-dev` to the stable release:
   - `docs/_includes/install-prerelease.html` (17 pages): drop the two
     `composer config` lines and the `:{{ site.data.product.release }}-dev`
     suffix;
   - outside the include: `docs/index.md` (hero install block),
     `docs/packages.md`, `docs/quick-start.md`, `README.md` ("2.0 is coming"
     and "Getting started"), `docs/llms.txt`;
   - `docs/_data/product.yml`: `version_label`;
   - the `PHPRegex 2.0.0-DEV` sample outputs in `docs/guides/cli.md`,
     `docs/tutorial/README.md`, `docs/tutorial/08-performance-redos.md` and
     `docs/tutorial/09-testing-debugging.md`;
   - `tests/Documentation/PackageSelectionDocumentationTest.php` pins the
     prerelease commands: it moves with them.
3. Push 2.x, wait for CI to pass, then `bin/release --dry-run 2.0.0` and
   `bin/release 2.0.0`: it drafts the GitHub release and pushes the tag; the
   phar workflow builds and attests the phar, attaches it and its checksum,
   then publishes the draft; the split workflow tags the sixteen packages.
4. After the tag: `bin/status` and Packagist show `v2.0.0` for all sixteen
   packages (they require each other at `self.version`: one missing tag
   leaves the others uninstallable), and the "Latest" release carries the
   2.0 `regex.phar`, which `self-update` and `releases/latest` serve.
5. `bin/bump --next 2.0.1`.

## Phar provenance: the GitHub side

`.github/workflows/phar.yml` builds, attests and attaches the phar on a
version tag. By hand: if Settings → Actions → General restricts the actions
allowed, allow `actions/attest-build-provenance` and `shivammathur/setup-php`.
After the 2.0.0 tag, check that the release went from draft to published
with both assets, and that `gh attestation verify regex.phar --repo
php-regex/php-regex` passes on the downloaded phar.

## Upstream PHPStan fixes (fork `php-regex/phpstan-src`)

PHPStan types `preg_match()` `$matches` with its own Hoa grammar
(`resources/RegexGrammar.pp`). A third-party extension cannot override it:
PHPStan's type-specifying extension always runs first and `$matches` out-types
are unioned (checked on 2.3.1). So the fixes go upstream, as small PRs: one
grammar fix plus one regression test each, official PCRE2 and PHP manual
references in the description, never a large rewrite.

Four branches are ready in the local `../phpstan-src` checkout, one commit each
on `2.3.x` (62ed20078), not pushed. To do: `git push origin <branch>`, then
open each PR against `phpstan/phpstan-src:2.3.x`.

1. `regex-grammar-backreference`: *Parse back references in regex patterns*
   (`\1`, `\g{-1}`, `\k<name>`… were read as literals: `/(a)(\1)/` typed
   group 2 as `'1'`).
2. `regex-grammar-short-escapes`: *Parse \x with fewer than two hexadecimal
   digits and short \0 escapes in regex patterns* (`/(\x4)/` gave `'x4'`,
   `/(\0)/` gave `''`).
3. `regex-grammar-single-letter-property`: *Parse single-letter Unicode
   properties like \pL in regex patterns* (`/(\pL)/u` gave `'pL'`).
4. `regex-lookaround-zero-width`: *Do not count regex lookarounds as matched
   characters* (`/(?=a)/` gave `non-empty-string`, `/\d+(?=px)/`
   `non-falsy-string` for `'0'`).

PRs 2 and 3 touch adjacent grammar lines: rebase the second once the first is
merged.

Checked before publishing: every test pattern behaves the same on PCRE2 10.35
(PHP 7.4) to 10.49; the regex tests pass on PHP 8.2, 8.3 and 8.5; on 11,628
constant `preg_match()` patterns from `corpus/`, with subjects from
`SampleGenerator`, no branch rejects a real match result that 2.3.x accepted
(2.3.x already rejects 209).

Left for later PRs, each verified against the engine:

- `\x30`, `\060` and `\o{60}` match `'0'` but are typed non-falsy; `\o{}` is
  not parsed (needs that fix first).
- `\Q...\E` is not parsed (`/\Q(a)\E(b)/` sees two groups); a grammar-only fix
  is not possible.
- Inline `(?x)`, `(?-x)` and `[#]` under `x` (upstream issue #11746).
- Inline `(?i)` keeps lowercase constants (`/(?i)(a)/` gives `'a'`).
- Groups inside a negative lookaround are typed as set (`/x(?!(a))(b)/`
  gives `'a'`, the engine `''`).
- A quantifier with a minimum of one over a maybe-empty atom is non-empty
  (`/((?:a?)+)/`).
- `\g<1>` and `\g'1'` (subroutine calls) are read as literals.

Parked, too large for upstream review: local branches
`regex-negative-lookaround-groups`, `regex-quoted-sequence`,
`regex-extended-mode`.

## Declared-type tightening (shapes where keys are knowable)

Audited 2026-10-15: of the 214 `mixed` occurrences in `src/`, ~71 are real
narrowing; the rest are honest boundaries that stay (decode fronts, host
contracts, validator inputs, protocol slots). Four units, in order:

1. Before the 2.0.0 tag — the native signatures that cannot move after it:
   drop the native `: mixed` on `CalloutNode::accept()` and
   `DefineNode::accept()` (every other node class is untyped and inherits
   `@return T` from the interface); narrow `$phpVersionRange` from `mixed`
   to `int|array|null` on the four constructors (`PatternChecker` ×2,
   `RegexPatternRule`, `RegexPatternArgumentRule`); the Lexer matches
   docblocks at `Lexer.php:1230/1280/1753` become
   `array<int|string, string|null>` (what `LibraryPcre`'s `@param-out`
   already guarantees) plus the dead inline ignore at `:1764` goes;
   `Heatmap.php:31` becomes `array<Hotspot>`; the dead public surface
   (`Message::$result`/`$error`, `Response::error()`'s `$data`,
   `sortResults`) is removed once verified unreferenced.
2. Consumer shapes: `OptimizerOptionsArray` on the public `optimize()`
   entry points; the `phpRegex` neon parameter typed once and imported
   across the PHPStan bridge; the explicit `regexOptions` subsets on the
   Symfony lint command and the Laravel provider; lift the stale
   `src/Laravel/*` analysis exclusion (illuminate is installed in
   `tools/phpunit/vendor`).
3. Internal docblock sweep (post-tag safe): LanguageServer diagnostics and
   handler-entry params, the nine unapplied Linter aliases,
   `LintDefaultsBuilder::build()`'s return shape (pays the CLI, LintArguments
   and LSP lines at once), the Symfony JSON report and Laravel compare
   literals.
4. Deferred unless wanted: JsonDumper leaf shapes transcribed from
   `ast.schema.json`.

Never type the validator inputs (`ParserOptions::fromArray` and friends):
their runtime checks and rotten-data tolerance are the contract. A
`noNewMixed` rule in the shipped plugin (flag off by default, baseline
frozen by reason code, counting meta-test) lands with unit 2.

## PHP 8.6 closeout and 8.7 canary

The closeout is built: the analysis range reaches 8.6, both engine matrices
carry 8.6, the `preg_grep()` array-to-false boundary is pinned by tests, the
trim family keeps its pre-8.6 character set on every PHP, a scoped listener
fails the suite only on deprecations under `src/`, and a scheduled 8.7 canary
watches the future without gating anything. Left, dated:

1. **2026-11-19, GA day** — rename the `8.6-rc` row in `engines-bundled` to
   `'8.6'`: the `-rc` docker aliases vanish that day and an unrenamed row
   breaks CI. The `startsWith(matrix.php, '8.6')` conditions already match
   either spelling, and `engines-latest` needs no change. Re-verify the
   renamed image still bundles 10.44, and if it ships mbstring again, try
   removing the "Install multibyte support" step.
2. Until then, watch `ext/pcre/pcre2lib` on the PHP-8.6 branch and master:
   a late PCRE2 bump would reopen the engine maps. If it moves, re-run the
   engine-scoped corpus rows before anything else.

---

## Symfony Flex recipe

Flex guesses `PHPRegexSymfonyBundle` or `SymfonyBundle`, never
`PHPRegexBundle`, so `composer require php-regex/regex-symfony` registers
nothing (the guide says so). By hand: open a recipe PR on
symfony/recipes-contrib once 2.0.0 is on Packagist.

## Rewrite checks: what is left

The determinization budget, the shared rewrite check and the bounded DFA
cache are merged. Follow-ups:
- `Optimizer::__construct()` takes a `?DfaCacheInterface`, added in 2.0.0,
  that nothing reads any more: the check of a rewrite builds no DFA since
  the strings both sides match no longer prove one. Keep it or drop it
  before the tag.

## Extraction coverage: after the merge

Merged into 2.x. Left:
- the limits written down in `docs/guides/cli.md` (writes made outside the
  file, by Reflection or `Closure::bind()`, are not seen; a `do` without
  braces around a control structure is unknown to the token strategy only).

Decisions for the maintainer:
- **An `interop` list made only of unknown names** (`["laravel-str"]`)
  falls back to native calls only, with a warning. Documented in
  `UPGRADE-2.0.md`, but a 2.x-dev user with that list loses their presets.
- **PHPStan** passes over an unknown preset name silently, where the CLI,
  the bridges and the LSP warn.
- **`composer-pcre`** opens any file spelling `Preg::`/`Regex::` without the
  namespace check the newer presets have; adding it opens fewer files but
  changes `PatternFunctionRegistryTest`'s pinned cases.
- **`PHPRegexServiceProvider::extraction()`** is a new public static marked
  `@internal`, on the model of `regexOptions()`.

## PHP 8.1 floor: after the merge

Merged into 2.x: every package requires `>=8.1`, Symfony `^6.4|^7.4|^8.0`,
Laravel `^10.37|^11.0|^12.0|^13.0`, PCRE2 10.39 judged with its own rules.
Left:
- the first GitHub run on 2.x (03699c27fe) failed in the PHP 8.1
  prefer-lowest cell (PHPUnit 10.5.0 runs regexes on every assertion, one
  ran under a test's recursion limit), the 8.2 and 8.3 bundled-engine
  cells (the capture-shape report compared patterns with `r` their engine
  refuses), PHPStan on 8.5 and 8.6 (`$argv` in the 8.1 smoke test) and
  PHPCS (four test files): each is fixed on its own commit; watch the next
  run;
- PCRE2 10.39 also has matching bugs 10.40 fixed (`/[Aa]{2}/` misses `aa`):
  engine defects the library does not model, documented.
Decisions for the maintainer: `docs/reference/backward-compatibility.md`
promises the 8.1 floor and the framework ranges; Laravel 10 and 11 carry an
unfixed advisory (PKSA-mdq4-51ck-6kdq) that Composer blocks by default, and
the test tooling allows it.

## Which command regenerates the tracked corpus log

`var/log/corpus.log` is tracked and today regenerates with
`php bin/regex lint corpus/ --php-version=runtime`. `bin/corpus-lint`
judges each repository under its own composer.json floor instead, and
merges one log per repository. Decide which command the tracked file
follows, then run it after the next corpus update.

## The articles section: what is left

- The next articles, in priority order: variable-length lookbehind across
  PHP 8.2-8.4, capture shapes of `$matches`, why `pcre.backtrack_limit`
  does not protect under the PCRE2 JIT, regex equivalence with proofs,
  named groups with branch reset, measured data from the corpus,
  transpiling to JavaScript with a proof, `preg_match()` versus
  `str_contains()` measured, Symfony route requirements audited.
- Re-check the 8.6 row of the published version matrix against the
  PHP-8.6 branch before GA on 19 November 2026; the article promises it.
- The og:image URL is cache-busted by the build time (`head.html`), which
  re-caches social cards on every deploy; decide whether the manual `?v=`
  ratchet fits articles too.
- An articles pager (previous / next) once a second article exists.

## Checked and refused (do not re-litigate)

- A compile-once `Pattern` handle: parses are already cached per pattern +
  target; the handle would duplicate the frozen facade surface.
- nikic-style multi-error recovery: PCRE reports one error (the oracle the
  library is grounded on) and no internal consumer wants more; if anything,
  document the tolerant-mode truncation contract (`docs/reference/api.md:254`).
- One diagnostic god-DTO shared across packages: it couples Parser to lint
  and LSP vocabulary; keep the small boundary converters.
- An absolute-latency performance gate in CI: environment noise; keep the
  asymptotic tripwires already in the suite.
- "The AST is a dead end": wrong — `$ast->accept(new PatternPrinter())` is
  public and documented; only the formatting factories are internal.
