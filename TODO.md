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

## Extraction gaps left on purpose

- `f(...$args, pattern: '/x/')`: PHP uses `/x/`, both extractors read nothing.
- An alias written with a tab, a newline or a comment before `as` does not open
  the file for a wrapper preset.
- The language server reads `#[RegexPattern]` under `paths` minus `exclude` and
  never in `vendor/`, while the lint reads `vendor/` and configured paths
  whatever `exclude` says; group imports of the attribute are missed there.

---

## After 2.0 — verified UX findings

Each item below was verified against the code (2026-10-10, PHP 8.4.26 with
PCRE2 10.49) and is additive: none changes a 2.0 contract.

### B. The PHP API

**B2 — A validation result has no human rendering.** The message, the offset,
the caret snippet and the hint are separate fields; the caller stitches them
together, and the CLI ships its own renderer marked `@internal`.
*Verify*: `grep -n 'function render\|function format\|__toString'
src/Parser/Validation/ValidationResult.php` — nothing.
*Fix*: one `render()`/`__toString()`, reused by the CLI and the bridges, so the
message stops being assembled in the eight-plus call sites that stitch it
today (three CLI commands, three bridge commands, the console formatter, the
LSP diagnostic build). Effort: S.

**B6 — `analyze()` takes no options.** It always runs the ReDoS analysis in
theoretical mode and accepts no threshold, while `redos()` has four
parameters. *Verify*: read `src/Toolkit/Regex.php:168-233`. Effort: XS.

**B8 — `hint` is null on the errors users actually hit.** Ten common mistakes
(an unclosed class, a lone quantifier, `{2,1}`, an over-long quantifier, a bad
range, a missing delimiter, a bad group name, a duplicate name, a non-existent
subroutine call, an unknown Unicode property) all returned `hint === null`; the
field is populated on two `Validator` paths only. The API reference advertises
it as a fix suggestion.
*Verify*: loop `validate()` over those patterns and print `$v->hint`;
`grep -n 'hint' src/Parser/Validation/Validator.php`.
*Fix*: populate the hints, or stop advertising the field. Effort: M.

### C. The CLI

**C4 — No "did you mean" for a near miss.** *Verify*:
`php src/Cli/bin/regex anlyze '/a+/'` — `Unknown command: anlyze` followed by
the whole help, exit 2. The configuration validator already has a suggester.
*Fix*: one Levenshtein helper shared by both. Effort: XS.

**C5 — The bare invocation dumps every command's options.** *Verify*:
`php src/Cli/bin/regex | wc -l` — around 125 lines mixing the global options
with the Lint, Diagram, Transpile, Analyze, Debug and ReDoS blocks, while
`help <command>` stays correctly scoped and nothing points at it.
*Fix*: print the command list plus a pointer to `help <command>`. Effort: XS.

### D. The language server

**D1 — The language server ignores the rules configured in `regex.json`.**
`TextDocumentHandler` builds `new PatternLinter()` with no configuration, so
`checks.lint.rules` is never read and the editor disagrees with `regex lint`; a
noisy rule cannot be turned off in the IDE at all.
*Verify*: read `src/LanguageServer/Handler/TextDocumentHandler.php:257` and
`src/LanguageServer/Handler/CodeActionHandler.php:161`;
`grep -rn 'LintConfig' src/LanguageServer/` finds the target lookup only
(`Server.php:257`). A live run with `checks.lint.rules` in the workspace root
reported no difference.
*Fix*: load the rules at initialize and hand them to the linter. Effort: S.

**D2 — No ReDoS signal in the editor.** The pipeline is parse, validate, lint:
`/(a+)+$/` in a document yields the two lint findings and nothing critical. The
documentation says so, and the editor is then the only surface where a
catastrophic pattern looks harmless.
*Verify*: read `src/LanguageServer/Handler/TextDocumentHandler.php:240-266`.
*Fix*: optional analysis behind an initialization option. Effort: L.
*Decision*: worth the cost on large files, or not.

**D3 — `vendor/` is invisible to the language server.** The scan reads `paths`
minus `exclude` and never `vendor/`, while the lint reads `vendor/`, so a
dependency's pattern is analysed on the command line and invisible in the
editor. *Verify*: `src/LanguageServer/Server.php:276`. *See also*: "Extraction
gaps left on purpose". Effort: S.

**D4 — `#[RegexPattern]` behind a group import is missed.** The pre-filter
looks for the literal `PHPRegex\Parser\Attribute`, which
`use PHPRegex\{Parser\Attribute\RegexPattern};` never contains.
*Verify*: read `src/LanguageServer/Document/PatternDeclarations.php:40`.
*Fix*: normalise the needles. Effort: XS.

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

**E4 — Dead CPU-detection tail in the Laravel lint command.** Linux gets a
real worker count today: `/proc/cpuinfo` is parsed
(`src/Laravel/Command/LintCommand.php:506-515`). What remains is the dead
tail at `:517-537` — it reads the `/usr/bin/nproc` binary without executing
it and trims `/proc/self/status` without parsing it, then returns 1 only when
cpuinfo is unreadable. Remove the tail and align the fallback with the
Symfony/CLI equivalents. Effort: XS.

**E5 — The bridge reports are internal array shapes.** Symfony's report objects
are readonly but marked `@internal` over phpstan-typed arrays, and Laravel
builds raw arrays and encodes them. Nothing programmatic can render or consume
them without depending on internals.
*Verify*: `grep -rn '@internal' src/Symfony/Analyzer/`;
`src/Laravel/Command/RoutesCommand.php:112`.
*Fix*: stable typed objects, or a documented JSON schema. Effort: M.

### F. Documentation

**F1 — No local playground or REPL.** The documentation home sends readers to
regex101 for exploration; nothing in the repository runs a pattern
interactively. *Verify*: `docs/README.md`. Effort: M, optional.

### G. Left by the audit before the tag (2026-10-12)

Each item was verified at the tag candidate and is additive.

**G1 — A proven-safe pattern shows a heuristic hotspot.** `regex analyze '/a+/'`
prints `Status: safe (proven)` then `Hotspot: 0-2`, and the JSON carries a
`medium` finding next to `"severity": "safe"`: the severity comes from the
proof, the findings and hotspots from the heuristic profiler
(`docs/architecture.md`). *Fix*: hide the hotspot and label the findings as
hints when the proof says safe, or document that they are. Effort: S.

**G2 — The bridges' lint footers still print a cache count.** The CLI dropped
it; `src/Symfony/Command/LintCommand.php` and `src/Laravel/Command/LintCommand.php`
still print `Cache: … hits, … misses`, a count of the run's own process. Effort: XS.

**G3 — A request after `shutdown` gets no answer from the language server.**
The protocol asks for -32600 Invalid Request; `ServerTest::test_shutdown_stops_the_loop`
pins the silence today. Effort: XS.

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

**G6 — A Symfony route requirement shows as a delimited pattern.** In the lint
console output `\d+` reads `→ /\d+/` while `[a-z]+` reads as typed.
Effort: XS.

**G7 — The bridges' complexity threshold reports nothing.** Symfony's and
Laravel's `analysis.warning_threshold` reaches the analysis, but their
`regex:lint` never turns the complexity report on, as `regex lint` does under
`checks.complexity`. *Fix*: an `analysis` switch beside the threshold.
Effort: XS.
