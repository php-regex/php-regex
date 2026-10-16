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

## Development tooling defects

Both small; neither blocks the 2.0.0 tag, both make `task` and `composer`
disagree about what the checks are.

1. **`coverage` declares the wrong success marker.** In `taskfile.dist.yaml`,
   the `coverage` task touches `.task/coverage.success` but its `generates:`
   lists `.task/phpbench.success` — the marker of another task — so the
   checksum cache answers for a task it never ran. Fix the declaration.
2. **deptrac sits outside the taskfile.** `composer lint` ends with
   `@deptrac` and `tools/deptrac/` is configured, but `task install` and
   `task update` never install that tool and `task lint` never runs it;
   only the CI `deptrac` job installs it today. A contributor who ran
   `task install` alone cannot run `composer deptrac`, and `task lint` no
   longer equals `composer lint`. Add it to the three tasks.

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

PHP 8.6 GA is scheduled 2026-11-19 (RC3 was 2026-10-08, RC4 is due
2026-10-22). The core is already covered: the required 8.6 CI rows run the
whole tree, and GA 8.6 bundles PCRE2 10.44, which `PcreTarget` already
maps. What is left:

1. Before the 2.0.0 tag: `phpstan.dist.neon` analysis range
   `max: 80599` → `80699` — the comment two lines above already claims
   "8.2 through 8.6".
2. `engines-bundled`: a `{ php: '8.6-rc', pcre: '10.44' }` row (the
   rolling docker alias exists; no stable tag yet) plus the conditional
   `--ignore-platform-req=php+` on its tool installs — without the flag the
   row dies before judging any engine. `engines-latest`: add 8.6 on macOS
   the same way.
3. `preg_grep()` returns `false` on PCRE error in 8.6: widen the modeled
   return to `array|false` in the PHPStan and Psalm bridges. The 8.6-gated
   regression test and the `KNOWN_PHP_VERSIONS` extension land in one
   commit, or the gate meta-test goes red.
4. Walk the trim family for the new default `\f` stripping (66 files; the
   sharpest is `ParserOptions:265`, which trims the user-supplied
   `php_version`), then sweep the 8.6 deprecations (return-in-finally,
   `let`/`is`/`_` identifiers, `__debugInfo` array|null, and the rest).
5. Decide the deprecation policy before GA: documented display-only, or a
   listener failing only `E_DEPRECATED` whose frame lands under `src/`.
   Never native `failOnDeprecation`: the weekly-re-resolved bridge vendors
   deprecate for reasons that are not this library's.
6. A scheduled-only 8.7 canary (never gating PRs; no analysis and no
   coverage — both impossible on 8.7 today), with `pcre.jit=0` as the
   known workaround if the JIT crash fires.
7. On GA day itself: rename the `8.6-rc` row to `'8.6'` (the `-rc` docker
   aliases vanish that day, an unrenamed row breaks CI), re-verify the
   bundle stayed 10.44. Until then, watch `ext/pcre/pcre2lib` on the 8.6
   branch and master: a late bump would reopen the engine maps.

---

## Symfony Flex recipe

Flex guesses `PHPRegexSymfonyBundle` or `SymfonyBundle`, never
`PHPRegexBundle`, so `composer require php-regex/regex-symfony` registers
nothing (the guide says so). By hand: open a recipe PR on
symfony/recipes-contrib once 2.0.0 is on Packagist.

## Optimizer memory (branch `optimizer-memory`)

Built, not merged (3 commits: `caa60095ae`, `97cb4ed5f7`, `8c6eff101b`).
- Determinization charges every transition it writes, and the check of a
  suggested rewrite has its own 200,000 budget: Symfony's 4.2 KB URL
  pattern went from 1,005 MB to 104 MB.
- The lint now checks a rewrite under `x` the way the optimizer does: same
  `$matches` first, then the language. On the corpus, 0 suggestions lost,
  3 gained (`{0,1}` → `?` in Symfony-derived URL patterns; 12,031 subjects
  each through the engine, no difference).
- `InMemoryDfaCache` is held to 150,000 transitions as well as 1,000
  automata: 101 MB → 7.5 MB on ~3,500 patterns, same hit rate.
Full suite green on the branch. Left: the merge into 2.x. Follow-ups:
- both checks model `preg_match` from offset 0 only: `/^[\s\S]|a/` and
  `/^[\s\S]|b/` are proven equivalent, yet `preg_match_all` on "xa" finds 2
  matches with one and 1 with the other. No known rewrite produces such a
  pair, but `preg_match_all`, `preg_replace` and `preg_split` match from
  every offset: the safety net should model that, or the suggestion should
  name the calls it was proven for;
- a rewrite proven by the match check alone is not proven once its text is
  spliced into a larger pattern (an inline `(?x)` route requirement);
- `LanguageSolver::matchEquivalent()`'s docblock calls it "stronger than
  `equivalent()`", which is false for whole-string languages (`/a|ab/`
  against `/a/`);
- about 265 bytes per transition: one check at the 200,000 budget still
  costs ~70 MB, close to PHP's default 128 MB `memory_limit` for embedders.

## Extraction coverage (branch `extraction-coverage`)

Built and committed on the branch (`0781e8d708`), not merged. Before the merge:

1. Mutation testing and the review are done. A second, failure-hunting
   review confirmed 14 defects, being fixed: two crashes of the token
   strategy on half-typed source (an unclosed option array, unbalanced
   parentheses before a `.`), constant folding with no size bound (a 1 KB
   file exhausts memory), quadratic resolution of a variable assigned many
   times, writes the in-file rules missed (a parent or an outside trait
   writing a protected or private property, `array_walk()` on an object,
   `Closure::bind`, by-reference generators), values assigned after the last
   read counted as patterns, a Symfony `Regex` options array read through a
   constant reading every value, Nette `utf8` given by position, `define()`
   of a name PHP already defines, keyword-named arguments breaking parity,
   and PHP warnings reaching the LSP's stdout.
2. Measure `bin/regex lint corpus/` again (xdebug off, `--jobs=1`) against
   2.x, then run the full suite and commit on the branch.

Decisions for the maintainer:

- **Performance budget.** The target was ≤ +10 % time and memory on the
  corpus. Measured at the end (`bin/regex lint corpus/ --jobs=1`, 2.x at
  17b1eaeec5 against the branch): 353 s → 430 s wall-clock (+22 %), 1.99 GB
  → 2.96 GB peak (+49 %), 5,602 → 6,552 reported results. The extraction
  itself costs a few percent; the rest is analysing the ~4,600 distinct
  patterns now found, and the memory is the URL pattern the
  `optimizer-memory` branch bounds (to be measured again after both merges).
  Proposed: accept it as the cost of the coverage.
- **An `interop` list made only of unknown names** (`["laravel-str"]`)
  falls back to native calls only, with a warning. Documented in
  `UPGRADE-2.0.md`, but a 2.x-dev user with that list loses their presets.
- **PHPStan** passes over an unknown preset name silently, where the CLI,
  the bridges and the LSP warn.
- **`#<n>$param` in function specs** is accepted from configuration but not
  documented: document it, or accept it from declarations only.
- **`composer-pcre`** opens any file spelling `Preg::`/`Regex::` without the
  namespace check the newer presets have; adding it opens fewer files but
  changes `PatternFunctionRegistryTest`'s pinned cases.
- **`PHPRegexServiceProvider::extraction()`** is a new public static marked
  `@internal`, on the model of `regexOptions()`.

## `#[RegexPattern]` on more than one parameter

`PatternAttributeScanner::markedParameter()` returns the first parameter
marked `#[RegexPattern]` only: in `between(#[RegexPattern] string $from,
#[RegexPattern] string $to)`, `$to` is never read, by the CLI, the LSP or the
bridges. 2.x at 824626f637 behaves the same. Next: return every marked
parameter, one spec each, with both strategies agreeing.

## The editor skips patterns with some delimiters

`RegexFinder::isValidRegexDelimiter()` accepts `/ ~ # @ ! %` and the bracket
pairs only, so the LSP shows nothing for `preg_match('|(a|', $s)` (a
compilation error PHP reports) or for `+`/`"`-delimited patterns, which
`regex lint` reads. It predates the extraction work. Next: accept every
delimiter PHP accepts (any non-alphanumeric, non-backslash, non-space byte)
and keep only the "is this a pattern at all" guard the editor needs.

## PHP 8.1 floor (branch `php81`)

Built, not merged (12 commits). Every package, the root one included,
requires `>=8.1`; the Symfony bridge supports `^6.4|^7.4|^8.0`, the Laravel
bridge `^10.37|^11.0|^12.0|^13.0`. Full suite green on PHP 8.1 with the
lowest versions (Symfony 6.4.0, Laravel 10.37.3, PHPUnit 10.5) and on 8.4
with the highest. PHP 8.1's bundled PCRE2 10.39 is judged with its own
rules (exact property names only, no `sc:`/`scx:`, no 4-letter codes, no
Bidi or binary properties, `\p{Script}` meaning the script, not its
extensions), grounded on `php:8.1-cli` (50/50 rows agree). Left:
- the merge into 2.x; conflicts expected with the extraction branch in
  `PackageManifestTest`, `PublishedConfigTest` and bridge tests;
- watch the first GitHub run of the new `tests-php81` and prefer-lowest
  cells (checked locally with act only);
- the "script only" meaning before 10.40 reaches the solver, not yet the
  other consumers of the HIR (ReDoS pairs, transpiler, `PatternInfo`);
- PCRE2 10.39 also has matching bugs 10.40 fixed (`/[Aa]{2}/` misses `aa`):
  engine defects the library does not model, documented.
Decisions for the maintainer: `docs/reference/backward-compatibility.md`
promises the 8.1 floor and the framework ranges; Laravel 10 and 11 carry an
unfixed advisory (PKSA-mdq4-51ck-6kdq) that Composer blocks by default, and
the test tooling allows it.

## `(?xx)` and a space inside a class range, before PCRE2 10.48

The library refuses `(?xx)[\d -z]` for every target, while PCRE2 10.44
accepts it; only the test is gated at 10.48. Decide whether the verdict
follows the target version.

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
