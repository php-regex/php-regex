# TODO

## Before the tag — contract decisions left open (2026-10-14)

Each item below changes the public API or a cross-package contract, so it is
only cheap until `v2.0.0` is tagged: after the freeze, every one becomes a BC
break or a standing contradiction between two pieces of documentation. Work
them top to bottom, one at a time — settle the decision in writing first,
then implement test-first. Deferring an item is also a decision; it converts
the item from cheap to a BC break, so say so when it happens. The validation
carriers and the Flex recipe stay where they are (E2, G5 below): both are
additive and lose nothing by waiting past the tag.

### Checked and refused (do not re-litigate)

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
   `bin/release 2.0.0`: it drafts the GitHub release with the phar and its
   checksum, pushes the tag, then publishes the draft; the split workflow tags
   the sixteen packages.
4. After the tag: `bin/status` and Packagist show `v2.0.0` for all sixteen
   packages (they require each other at `self.version`: one missing tag
   leaves the others uninstallable), and the "Latest" release carries the
   2.0 `regex.phar`, which `self-update` and `releases/latest` serve.
5. `bin/bump --next 2.0.1`.

## Give the phar a build provenance

`bin/release` builds `bin/regex.phar` locally and attaches it to the GitHub
release with its SHA-256 checksum: no CI-built artifact, no signing, no
attestation. Decide whether the build moves to CI with provenance.

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

---

## After 2.0 — verified UX findings

Each item below was verified against the code (2026-10-10, PHP 8.4.26 with
PCRE2 10.49) and is additive: none changes a 2.0 contract.

### B. The PHP API

### C. The CLI

**C9 — The lint run leaves time on the table.** Measured on a 300k-file
corpus with 10 workers: `suggestOptimizations` parses every pattern a second
time (`AnalysisService`), and runs as a pass of its own after the lint pass
where one pass per pattern could do both; the console report takes about 4 s
to render. *Fix*: share the parse between the two analyses, then one pass.
Effort: M.

**C12 — One pattern holds `[3/3] Suggesting optimizations` for 11 s.** On
the 300k-file corpus the step sits at 99 % while one worker proves the
rewrite of craftcms's mobile user-agent alternation
(`src/web/Request.php:877`, about 1,600 bytes, a few hundred branches)
equivalent: `Optimizer::optimize()` takes 0.14 s without
`verifyWithAutomata` and 11.06 s with it, for 36 bytes saved. Next,
PrestaShop's bot list (`classes/Connection.php:126`) takes 3.2 s. Smaller
chunks do not help: the tail is the same with 16 or 64 chunks per worker.
*Fix*: find where the equivalence check spends its time on a wide
alternation of literals (the two sides differ only by factorized
prefixes), or give it a budget past which the suggestion is dropped as
unverified. Effort: M.

### D. The language server

**D2 — No ReDoS signal in the editor.** The pipeline is parse, validate, lint:
`/(a+)+$/` in a document yields the two lint findings and nothing critical. The
documentation says so, and the editor is then the only surface where a
catastrophic pattern looks harmless.
*Verify*: read `src/LanguageServer/Handler/TextDocumentHandler.php:240-266`.
*Fix*: optional analysis behind an initialization option. Effort: L.
*Decision*: worth the cost on large files, or not.

**D6 — The diagnostic ranges are approximate.** Every lint rule is reported
over a single character, and the parse and validation ranges are computed in
file coordinates with an offset that runs past the closing delimiter, the
quote and the comma. *Verify*: read
`src/LanguageServer/Converter/DiagnosticConverter.php:41-50` and
`src/LanguageServer/Handler/TextDocumentHandler.php:233-237`, or run the server
and publish a document. Effort: S.

### E. The Symfony and Laravel bridges

**E2 — No ready-made rule for a pattern a user submits.** There is no Symfony
`Constraint` and no Laravel `Rule` anywhere; the existing extractor only reads
the native `Regex` constraint for linting. *Verify*:
`grep -rn 'extends Constraint\|implements Rule' src/` — nothing;
`src/Symfony/Extractor/ValidatorPatternSource.php:20`. Effort: M.

**E3 — The command surfaces diverge between bridges.** `compare` takes
`--method intersection|subset|equivalence` on the CLI and Symfony, and Laravel
hardwires equivalence and offers `--format=json` instead; `analyze` and
`security` exist only in Symfony; `regex:routes` is `setHidden(true)` in
Symfony while it is the flagship command in Laravel; the short options `-j` and
`-t` are missing in Laravel.
*Verify*: compare `src/Cli/Command`, `src/Symfony/Command` and
`src/Laravel/Command` for the same verb.
*Fix*: a shared command and flag specification. Effort: M.

**E5 — The bridge reports are internal array shapes.** Symfony's report objects
are readonly but marked `@internal` over phpstan-typed arrays, and Laravel
builds raw arrays and encodes them. Nothing programmatic can render or consume
them without depending on internals.
*Verify*: `grep -rn '@internal' src/Symfony/Analyzer/`;
`src/Laravel/Command/RoutesCommand.php:112`.
*Fix*: stable typed objects, or a documented JSON schema. Effort: M.

### G. Left by the audit before the tag (2026-10-12)

Each item was verified at the tag candidate and is additive.

**G1 — A proven-safe pattern shows a heuristic hotspot.** `regex analyze '/a+/'`
prints `Status: safe (proven)` then `Hotspot: 0-2`, and the JSON carries a
`medium` finding next to `"severity": "safe"`: the severity comes from the
proof, the findings and hotspots from the heuristic profiler
(`docs/architecture.md`). *Fix*: hide the hotspot and label the findings as
hints when the proof says safe, or document that they are. Effort: S.

**G4 — Two route-constraint edges in Laravel.** The constraint is judged
between `/`-family delimiters, where the router uses `{…}`: a constraint with
an unbalanced brace, as `[{]`, passes the lint and fails the router. A
constraint ending in an escaped `\$` is cut as Symfony's sanitizer cuts it,
but may compile where the route does not. `regex:compare` prints its
counterexample unescaped. Effort: S.

**G5 — No Symfony Flex recipe.** Flex guesses `PHPRegexSymfonyBundle` or
`SymfonyBundle`, never `PHPRegexBundle`, so `composer require
php-regex/regex-symfony` registers nothing (the guide says so). *Fix*: a
recipe in symfony/recipes-contrib. Effort: S.

**G7 — The bridges' complexity threshold reports nothing.** Symfony's and
Laravel's `analysis.warning_threshold` reaches the analysis, but their
`regex:lint` never turns the complexity report on, as `regex lint` does under
`checks.complexity`. *Fix*: an `analysis` switch beside the threshold.
Effort: XS.
