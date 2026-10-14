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

**1. The shared kernel under `Parser\Internal`.** 88 files outside
`src/Parser` import `PHPRegex\Parser\Internal\*`, in 14 of the 15 other
packages (`LibraryPcre` alone is used by 48 external files). All of it is
`@internal` ("a minor release may change them"), which makes the marker
misleading in practice: changing one signature silently breaks half the
library. deptrac cannot see the problem — its Parser collector matches the
whole `^PHPRegex\Parser\` prefix, so a new `Internal` import passes
everywhere. The same leak runs sideways:
`src/Linter/AnalysisService.php:24` (`Optimizer\Internal\LayoutSplicer`),
`src/Psalm/Internal/MatchesType.php:19` (`CaptureKey`, `CaptureLayout`).
*Decision*: promote the load-bearing classes to a named internal-contract
layer with its own deptrac rules and a frozen allowlist, or publish them in
the public surface with the same BC promise as the rest. Effort: M.

**2. One traversal engine.** Two coexist: the visitor trio
(`NodeVisitorInterface` + `AbstractNodeVisitor` + `AbstractTraversingVisitor`,
~630 lines, an `accept()` override on every node class, ~330 `->accept(`
call sites) and the closure-based `NodeWalker` (3 call sites in src/
`NodeFinder` ×2, `Optimizer`); `AbstractTraversingVisitor` has zero
subclasses in src/. `PatternLinter` (778 lines) reimplements child traversal
across its ~20 overrides, and its class-keyed dispatch map is the walker's
enter callback rewritten by hand. Every new PCRE2 construct costs one
interface method, two base-class methods and one override per consumer.
*Decision*: keep one public seam — the walker (folds like `PatternPrinter`
become internal match statements), or the trio (then delete the walker).
Effort: L.

**3. One capture-group numbering model.** Three walks with three
vocabularies: `Linter\Rule\GroupIndex` (the richest — branch reset,
subroutine targets; `@internal`, and one layer too high),
`Parser\Analysis\GroupNumbering` (thin — numbers and names) and
`CaptureShapeAnalyzer` (a third participation model). `PatternLinter`
hand-rolls a fourth instanceof pre-pass on top. *Fix*: one numbering service
in Parser, the layer that owns the AST, consumed by linter, validator, HIR,
generator and bridges. Effort: M.

**4. Custom lint rules: promised and withheld.** `docs/extending.md:18`
lists "Custom lint rules" under what you can extend, and its Pattern 1
(:412) teaches a bare visitor with no rule id, severity or registration path
— while `LintRuleInterface`, `LintRuleRegistry` and
`PatternLinter::withRegistry` are `@internal`, and
`docs/reference/backward-compatibility.md:137` says lint rules are not
extension points in 2.0. Two contract documents contradict each other.
*Decision*: publish the rule contract (interface, one registry entry point,
a versioned `RuleViolation`), or remove the promise from the guide. Not both
messages at once. Effort: S to close the doc lie, M to open the seam.

**5. "The library gave up" must not read as "the pattern is invalid".**
`RegexParser::validate()` catches `ResourceLimitException` and
`RecursionLimitException` (`RegexParser.php:188`) and funnels both into
`ValidationErrorCategory::Syntax`: a false verdict about the developer's
pattern caused by our own budgets. The Psalm bridge re-derives the missing
distinction by hand at its own boundary (`PregCallAnalyzer.php:797`); the
Redos package already models it right (`RedosProof::BudgetExceeded`). Both
limit exceptions also extend `ParserException`, so a documented
`catch (ParserException)` for grammar errors intercepts budget exhaustion.
*Fix*: a Limit/Unknown outcome threaded through `validate()`, `LintService`
and the three lint commands; the limit exceptions move off the grammar
branch. Effort: S-M.

**6. Suggestions are data, not prose.** "Did you mean" strings are appended
to messages (`Validator.php:1237`, `getNameSuggestions()`), while the
structured fields exist end to end (`RegexException::$hint`,
`ValidationResult::$hint`, `Diagnostic::$suggestion`) and no parser
suggestion ever reaches the LSP as a quick fix. Related: B8 below (hints
null on the errors users actually hit). *Fix*: one typed suggestion field
produced by the parser and projected everywhere. Effort: S.

**7. Share the DFA cache.** The versioned key exists
(`LanguageSolver::dfaCacheKey`: cache version + `PcreTarget` + engine +
solver options) but the wiring discards it: `AnalysisService` constructs new
`PatternLinter` (:359), `RedosAnalyzer` (:415 and :566 — its automata path
runs with a null cache) and `Optimizer` (:674) per pattern occurrence, and
the automata lint rules each hold a private `new InMemoryDfaCache()` rebuilt
per pattern. `InMemoryDfaCache` is unbounded and absent from `StaticCaches`,
so `clearCaches()` never reaches it. *Fix*: one shared `DfaCacheInterface`
on `AnalysisService` plus a bounded implementation registered in
`StaticCaches`. The single highest-performance change available before the
freeze. Effort: S-M.

**8. A tiered AST cache.** `PsrCacheAdapter::load()` unserializes on every
hit (`PsrCacheAdapter.php:52`), so a Redis pool turns every parse into a
network round trip; no front tier exists anywhere in `src/Parser/Cache/`.
`ArrayCache` bounds by entry count (1024), not bytes. `LanguageQuestions`
keeps a hidden static parser (`LanguageQuestions.php:62`) detached from the
judged `PcreTarget` and never cleared. *Fix*: compose a bounded process-wide
front with the PSR/filesystem back at `RegexParser` construction. Effort:
S-M.

**9. Byte-level tokens are about to freeze as the contract.** The lexer
emits one token per literal byte and the parser one node per byte
(~30 µs/byte measured; a 2000-character literal parses in ~60 ms), and
`Regex::tokenize()` documents the per-byte offsets as public API. The
run-length alternative already exists inside the lexer (`GROUP_BODY_TEXT`,
`Lexer.php:112`). *Decision*: merge literal runs, at least internally,
before the freeze, or accept per-byte as the forever contract. Effort: M —
offsets feed printers and diagnostics, so this is the touchiest item here.

**10. One pattern-carrier contract.** `PatternFunctionRegistry`
(`@internal`, Linter) knows the native `preg_*`, the interop presets
(composer/pcre, nette/utils, spatie/regex, laravel-str), namespaced drop-ins
and config customs — while the PHPStan rule hardcodes its own 8-function
map (`RegexPatternRule.php:71`) and Psalm sees `preg_*` only. A `Preg::match`
call is linted by `regex lint` and invisible to PHPStan. *Fix*: make the
registry (plus the presets and the attribute scanner) the one public,
versioned contract every surface consumes; deptrac already allows
PHPStan → Linter. Effort: M.

**11. Facade or packages: pick the front door.** `Toolkit\Regex` (637
lines, 24 verbs) is the wired service everywhere, while deptrac lets every
bridge reach every package directly — and they do (`OptimizerOptions`,
`RedosSeverity`, `RegexParser` imported in `src/Symfony`). The README
headlines equivalence/intersection/subset, but the facade has none of those
verbs, Toolkit does not require `regex-automata`, and `deptrac.yaml:80`
grants it an allowance nothing uses: three artifacts tell three stories
about the library's headline capability. *Decision*: "the packages are the
contract, the facade is the convenience layer" — write it down, collapse the
dead allowance, and either add the four solver verbs or fix the marketing.
Effort: S for the rules, M with the solver verbs.

**12. De-triplicate the lint commands.** 1,453 lines across three command
hierarchies (`src/Cli/Command/LintCommand.php` 529, Symfony 471, Laravel
453), with the literal line `ForkedWorkerPool::canFork() ? (new
CpuCoreCounter())->count() : 1` copied in all three, each importing
`Linter\Internal` to guess the job count. Baselines, interop presets and
rule toggles exist CLI-only because the shared layer (`LintSummary`,
`ForkedWorkerPool`, `CpuCoreCounter`) is `@internal` under Linter and
re-hosted per surface. *Fix*: an `AnalysisService::autoWorkers()` plus a
public runner owning collect → analyze → baseline → stats; each bridge keeps
its flags and its rendering. Related: C9 (the double parse), E3 (command
divergence). Effort: M. *Decision*, same item: `src/Cli` also carries its
own console runtime (Application 397 lines, Output 365, HelpCommand 761,
SelfUpdater 306) to keep the phar dependency-free; shipping symfony/console
inside the phar, as phpstan and php-cs-fixer do, deletes most of it. Decide
before the CLI surface is called final.

**13. Generator: merge or complete.** The package holds three classes, its
only consumer outside itself is the facade, and the part that makes samples
trustworthy — the 32-attempt verification loop against the real engine —
lives in `Toolkit\Regex::generate()` (`Regex.php:438`): installed alone,
regex-generator produces unverified samples. *Decision*: fold Generator into
Toolkit, or move the verification loop into `SampleGenerator` so the package
stands alone. Moving the namespace after the freeze is a BC break. Effort:
S either way.

**14. A machine-readable AST export.** The tree has no serializer beyond
the cache-private `AstSerializer` (PHP `serialize()`, `@internal`); every
result object is `JsonSerializable` except the tree itself. A stable JSON
AST plus a schema (the precedent ships: `regex.schema.json`) serves the
playground, LSP clients and snapshot testing. Additive and zero BC risk.
*Decision*: in 2.0 or 2.1 — deferring costs nothing except designing it
against the frozen node set. Effort: M.

**15. mbstring makes some verdicts platform-relative.** Verdicts are
stamped with the PCRE2 target, but case-insensitivity analysis folds with
the host's mbstring tables (`Validator.php:3619`,
`UselessIFlagRule.php:258`, `OverlappingAlternationRule.php:252`,
`LastCodeUnit.php:599`), which drift independently of the chosen target:
two machines can disagree on /i verdicts with no trace. *Fix*: inventory
which verdicts are host-relative, then stamp the Unicode data in the
analysis metadata or document the relativism. Effort: S-M.

**16. No deprecation channel exists.** `src/` carries no `@deprecated` tag
and no runtime deprecation notice, and several items above retire a seam
that is already load-bearing. Without a channel, nothing accidental can ever
be retired after the freeze. *Fix*: pick the pattern now (a small helper, or
plain `trigger_deprecation`), wire it, and mark the seams retired by items
2, 11 and 13 as its first users. Effort: S.

**17. The split packages have never been installed alone.** Sixteen repos
are pushed by the split workflow with `self.version` cross-requires, and
"each package works alone" is checked only by manifest meta-tests: no job
ever resolves one `php-regex/regex-*` standalone, and the split repos carry
no CI. A green monorepo can publish an uninstallable package unnoticed.
*Fix*: one CI job that path-repositories a split checkout and installs
`php-regex/regex-parser` alone in a bare project — before the first `v2.*`
tag exists to be uninstallable. Effort: S.

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

## Extraction gaps left on purpose

- `f(...$args, pattern: '/x/')`: PHP uses `/x/`, both extractors read nothing.
- An alias written with a tab, a newline or a comment before `as` does not open
  the file for a wrapper preset.
- The language server reads `#[RegexPattern]` under `paths` minus `exclude` and
  never in `vendor/`, while the lint reads `vendor/` and configured paths
  whatever `exclude` says.

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

**C6 — A `jobs` below 1 means four different things.** *Verify*:
`regex lint src --jobs=0` is a usage error (`LintArgumentParser.php`), as is
`regex:lint --jobs 0` in Symfony; Laravel's `--jobs=0` means "count the CPUs";
`"jobs": 0` in `regex.json` silently becomes "count the CPUs"
(`LintArguments.php`) though the schema says `minimum: 1`. *Fix*: refuse it
everywhere, or document "0 = auto" everywhere. Effort: S.

**C7 — An undocumented ignore marker.** `LintService::shouldIgnoreIssue`
honours `// @regex-lint-ignore` next to the documented `@regex-ignore` and
`@regex-ignore-next-line`; the help and the docs never name it. *Fix*: drop it
or document it. Effort: XS.

**C8 — Nothing names a worker stuck on one file.** A worker alive but stuck on
one pattern leaves the bar still while the clock runs. *Fix*: after N seconds
without progress, name the first file of the oldest chunk in flight. Effort: S.

**C9 — The lint run leaves time on the table.** Measured on a 300k-file
corpus with 10 workers: `suggestOptimizations` parses every pattern a second
time (`AnalysisService`), and runs as a pass of its own after the lint pass
where one pass per pattern could do both; the console report takes about 4 s
to render. *Fix*: share the parse between the two analyses, then one pass.
Effort: M.

**C10 — The worker count ignores memory.** In a container with a memory limit
and no CPU quota, `regex lint` starts one worker per host CPU, each a copy of
the main process. *Fix*: cap the default by the cgroup memory limit over the
main process's size. Effort: S.

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
