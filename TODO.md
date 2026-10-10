# TODO

## Release by hand

1. **`SPLIT_TOKEN`** — give the fine-grained token *Workflows: read and
   write* as well as *Contents*: GitHub refuses a push that creates or
   changes a file under `.github/workflows/` without it, and every split
   package carries `close-pull-request.yml`. The first push of
   `regex-rector` and `regex-psalm` failed for that reason and was made over
   SSH; a change to that workflow file would fail every split the same way.
2. **The `v2.0.0` tag** — `yoeunes/regex-parser` and `php-regex/php-regex`
   read the same repository, so a `v2.0.0` tag on the monorepo becomes a
   stable 2.0.0 of both, and a bare `composer require yoeunes/regex-parser`
   would install 2.x. Mark `yoeunes/regex-parser` abandoned in favour of
   `php-regex/regex-toolkit`, or point it at a repository holding 1.x only,
   before the tag.
3. **Packagist** — check that every package receives its updates on each
   push (the GitHub integration on the Packagist account, or a webhook per
   repository): without them Packagist crawls a repository about once a
   week.

## Report the PCRE2 JIT crash upstream

Not filed yet. No issue about it existed on
[PCRE2Project/pcre2](https://github.com/PCRE2Project/pcre2/issues) when it was
found (September 2026). Once filed, link the issue from
[docs/concepts/pcre.md](docs/concepts/pcre.md#a-known-jit-crash) and from the
CHANGELOG entry.

Draft:

> **JIT: segfault on a backreference to a group set in a non-atomic lookahead, under a repeated branch reset**
>
> ```
> /(?|(\*)(*napla:(.+))|()(?=\S_(\2?)))+_/
>     *a_
> ```
>
> `pcre2test -jit` crashes on this subject (SIGSEGV); without `-jit` the
> answer is "No match". Found from testinput2 line 6281:
> `^(?|(\*)(*napla:\S*_(\2?+.+))|(\w)(?=\S*_(\2?+\1)))+_\2$` with
> `*a_cb2a1_a_1!_a_Z1a!_Z_Z`.
>
> Reproduced with 10.40, 10.42, 10.45, 10.47, 10.49 and main (ef110b8), built
> with the JIT, on arm64 (macOS) and x86_64 (Linux), and in PHP with
> `pcre.jit=1` (8.2 and 8.5 with 10.42, 8.4 with 10.49, the official
> `php:8.4-cli` image with 10.44).
>
> Every part is needed: the repeated branch reset `(?|...)+`, the non-atomic
> lookahead `(*napla:...)` that captures group 2 in the first branch, and the
> lookahead that captures `(\2?)` as group 2 in the second. Under lldb the
> fault is `EXC_BAD_ACCESS` in the JIT code, in the byte compare loop of the
> `\2` backreference, which seems to read through a stale capture pointer.

## Report upstream to Psalm

- Psalm 6.19's type combiner turns `numeric-string|'a'` into `numeric-string`
  (order-dependent); the Psalm plugin emits no `numeric-string` because of it.
- Psalm's `preg_match_all` stub types `MARK` wrongly under
  `PREG_OFFSET_CAPTURE`.

## Fix the example scripts

Each script under `examples/` requires
`__DIR__.'/../vendor/autoload.php'`, which resolves to `examples/vendor/` — a
directory that does not exist. The invocation documented in
`examples/README.md` (`php examples/basic/validate.php` from the project root)
therefore fatals on the first try. One-line fix per script: point at the
repo-root autoloader.

## Align the feature support matrix with the solver

`docs/reference/feature-support-matrix.md` (lines 28 and 41-42) and the
`LanguageSolver` docblock still show lookarounds as refused; the solver has
read plain lookarounds since f66a0669
(`src/Automata/Transform/LookaroundProduct.php`, documented in
`docs/reference/logic-solver.md`).

## Type the literal path of the Toolkit facade

`src/Toolkit/Regex.php:517-601` receives its own `LiteralSet` as `mixed` and
probes it with `property_exists`/`method_exists`, and
`determineConfidenceLevel()` (line 587) returns a raw `'high'|'medium'|'low'`
string where the rest of the library uses string-backed enums. One
`instanceof` would type the whole path.

## Ship a ready-made validation rule

No Laravel `Rule` and no Symfony Validator `Constraint` combines `validate()`
and `redos()` for "a user submits a regex" forms; the extractors only read
such rules for linting.

## Refresh SECURITY.md for 2.x

The supported-versions table lists 1.x only, and no 1.x branch exists in this
repository anymore; disclosure is email-only, with no GitHub Private
Vulnerability Reporting configured.

## Give the phar a build provenance

`bin/release` builds `bin/regex.phar` locally and derives the `.sha256` from
that same build: no CI-built artifact, no signing, no attestation. A compiled
354 KB `regex.phar` also sits tracked in git history (export-ignored at
`.gitattributes:75`) — decide whether it stays.

## Mutation-test the extraction work

Infection's initial test run crashes before any mutant runs: `Parse error:
Unterminated comment starting line 85` in
`tools/phpunit/vendor/phpstan/phpstan/phpstan.phar/vendor/hoa/event/Bucket.php`.
Neither `-d auto_prepend_file=` on the child runs nor
`--exclude-filter=PHPStan` avoids it, so some other test loads the phar under
Infection's include interceptor. Find that test, then mutate the extraction
lines (`--git-diff-lines --git-diff-base=368a87e1`, up to 9b98938a).

## Extraction gaps left on purpose

- `f(...$args, pattern: '/x/')`: PHP uses `/x/`, both extractors read nothing.
- An alias written with a tab, a newline or a comment before `as` does not open
  the file for a wrapper preset.
- Laravel `regex:lint` with `php-regex.paths` null and no path argument passes
  null as the linted paths (TypeError, older than the extraction work).
- The language server reads `#[RegexPattern]` under `paths` minus `exclude` and
  never in `vendor/`, while the lint reads `vendor/` and configured paths
  whatever `exclude` says; group imports of the attribute are missed there.

---

## UX review — claims to verify before anything is implemented

Everything below came out of a single read-and-run pass over the repository:
each claim names the command that shows it or the lines that carry it, and
none of it has been checked on a second machine, another PHP version or
another PCRE2 release.

**None of these items is a task.** Each one is a claim to confirm first. Run
the verification, read the cited lines, and only then decide whether it is
worth a change; where the claim no longer holds, drop the item and say so
instead of working around it. Items that overlap a section above are marked
*see also* and are not repeated there.

The runs quoted here used PHP 8.4.26 with PCRE2 10.49, from the repository
root, with `php src/Cli/bin/regex` (the CLI) and `vendor/autoload.php` (the
API).

### A. The first contact

**A1 — The five example scripts fatal on their first line.** Each script
requires `__DIR__.'/../vendor/autoload.php'`, which resolves to
`examples/vendor/`, a directory that does not exist, so the invocation
`examples/README.md` documents fatals immediately.
*Verify*: `php examples/basic/parse.php`, and the same for `basic/validate.php`
(:18), `advanced/redos-analysis.php` (:20), `real-world/email-validator.php`
(:18), `symfony/route-analyzer.php` (:17) — `Failed opening required
'.../examples/basic/../vendor/autoload.php'`.
*Fix*: point each at the repository-root autoloader. Effort: XS.
*See also*: "Fix the example scripts" above.

**A2 — Nothing runs the examples, so they rot.** *Verify*:
`grep -rn 'examples/' tests/` returns rule and fixture matches only, no test
that executes a script. *Fix*: one test that runs the five scripts and asserts
each exits 0. Effort: S.

**A3 — `examples/README.md` points at four dead documentation paths.** The
pages moved when the site was restructured. *Verify*: `ls docs/QUICK_START.md
docs/reference.md docs/REDOS_GUIDE.md docs/COOKBOOK.md` — the first three are
missing. *Fix*: repoint at the live pages. Effort: XS.

**A4 — The install story is dev-only, for use cases that run in production.**
Every installation snippet reads `composer require --dev
php-regex/php-regex:2.x-dev`, while half the documented use cases (validating a
pattern a user submitted, generating samples) run in production code.
*Verify*: `grep -rn 'composer require --dev' docs/_includes/ docs/ README.md`.
*Fix*: state when the library is a runtime dependency and install it without
`--dev`. Effort: XS.

**A5 — The PHAR's `latest` channel still serves the 1.x CLI.**
*Verify*: read `docs/quick-start.md` where it says so, and compare with the
version the PHAR prints. *Fix*: documentation now, the tag later. Effort: XS.

### B. The PHP API

**B1 — `parse()` does not validate, and nothing says so.** A pattern PCRE
refuses still produces an AST, and `parse()` is the first method anyone calls
on a library called RegexParser. *Verify*:
`php -r 'require "vendor/autoload.php"; use PHPRegex\Toolkit\Regex; $r = Regex::create(); foreach (["/a{2,1}/", "/\\p{Foo}/", "/(?1)/"] as $p) { $r->parse($p); var_dump($r->validate($p)->isValid); }'`
— three trees, three `false`. Also `php src/Cli/bin/regex parse '/a{2,1}/'`
prints `Parse : OK`.
*Fix*: `Regex::parse($p, validate: true)`, or at least a documented note plus a
dedicated entry point that validates. Effort: S (doc) / M (API).
*Decision*: the last moment to change the behaviour is before the 2.0 tag.

**B2 — A validation result has no human rendering.** The message, the offset,
the caret snippet and the hint are separate fields; the caller stitches them
together, and the CLI ships its own renderer marked `@internal`.
*Verify*: `grep -n 'function render\|function format\|__toString'
src/Parser/Validation/ValidationResult.php` — nothing.
*Fix*: one `render()`/`__toString()`, reused by the CLI and the bridges, so the
message stops being assembled in three places. Effort: S.

**B3 — Two conventions for result objects in the same surface.**
`AnalysisReport` exposes `$report->errors` as a property *and* `$report->errors()`
as a method; `ValidationResult` has eight public properties, five getters and
two deprecated methods. The documentation shows the properties.
*Verify*: read `src/Toolkit/AnalysisReport.php:30-83` and
`src/Parser/Validation/ValidationResult.php:22-100`.
*Fix*: pick properties, retire the rest before the 2.0 tag. Effort: S.
*Decision*.

**B4 — Weak types at the public boundary.** `AnalysisReport::$lintIssues` is
documented `array<mixed>` (it is `RuleViolation[]`); `LintReport` and the
Symfony reports are `@phpstan-type` array shapes; `literals()` returns
`confidence` as a raw string and `literalSet` as an object the caller reaches
into. *Verify*: `grep -n 'lintIssues' src/Toolkit/AnalysisReport.php`;
`grep -n '@phpstan-type' src/Linter/LintReport.php
src/Symfony/Security/SecurityReport.php`.
*Fix*: typed lists and one enum for the confidence. Effort: M.
*See also*: "Type the literal path of the Toolkit facade" above.

**B5 — The two format parameters disagree.** `explain('console')` throws while
`highlight('console')` works; `highlight('text')` and `highlight()` with any
unknown string silently return console output; `transpile()` takes a free
string and its error does not list the valid targets, while `ParserOptions`
does list the valid option keys.
*Verify*: `php -r 'require "vendor/autoload.php"; use PHPRegex\Toolkit\Regex; $r = Regex::create(); $r->explain("/\\d+/", "console");'` throws;
`$r->highlight("/\\d+/", "bogus");` returns console output;
`$r->transpile("/\\d+/", "bogus");` throws without listing the targets.
*Fix*: one enum per parameter, and errors that name the accepted values.
Effort: XS.

**B6 — `analyze()` takes no options.** It always runs the ReDoS analysis in
theoretical mode and accepts no threshold, while `redos()` has four
parameters. *Verify*: read `src/Toolkit/Regex.php:168-233`. Effort: XS.

**B7 — The IDE completion set is incomplete.** `.phpstorm.meta.php` ships
`regex_option_keys` without `pcre_version`, which `ParserOptions::VALID_OPTIONS`
accepts, so the IDE suggests a wrong set. *Verify*: read
`.phpstorm.meta.php:5-14` against `src/Parser/ParserOptions.php`.
*Fix*: add the missing key. Effort: XS.

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

**C1 — `--no-visuals` does not remove the lint banner, and the footer carries
a hardcoded line.** `regex lint <dir> --no-visuals` still prints the Runtime,
Target, Processes, PCRE JIT, Backtrack and Recursion rows, and the
"no patterns found" path ends with `Cache: 0 hits, 0 misses` written literally,
after the star plea. *Verify*: `php src/Cli/bin/regex lint <dir> --no-visuals`
with a directory holding no patterns; read
`src/Cli/Command/LintOutputRenderer.php:113-119` and the call at
`src/Cli/Command/LintCommand.php:276`.
*Fix*: gate the banner and the footer on the visuals setting, and print real
cache statistics or none. Effort: XS.

**C2 — Usage errors go to stdout, not stderr.** For every command but `lint`,
an error is written to stdout, so a redirection captures the message where the
output belongs. *Verify*:
`php src/Cli/bin/regex diagram '/a/' --format=svg --output=/nonexistent/x.svg > f.svg; cat f.svg`
— the error text sits inside `f.svg`, exit code 2. Compare
`php src/Cli/bin/regex lint <dir> --format=bogus`, which writes to stderr. The
CLI guide documents stderr. *Fix*: route all of them through
`Output::writeError`. Effort: S.

**C3 — `--format` means five different things, and the help lies about one.**
The values are `console|json` (analyze, lint, redos, transpile), `text|html`
(explain), `text|svg` (diagram), `dot|mermaid` (graph) and `cli|html`
(highlight); `--json` exists on six of the fourteen commands.
*Verify*: `php src/Cli/bin/regex help highlight | grep format` advertises
`console`, while `php src/Cli/bin/regex highlight '/a/' --format=console`
answers `Error: Invalid format: console` and the real values are `cli|html|auto`.
*Fix*: one vocabulary, and `--json` on every command. Effort: M.

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

**D5 — The VS Code story contradicts itself, and no extension ships.** The LSP
guide says no editor setting points at an arbitrary server and shows
scaffolding to write by hand; the package README points at
`.vscode/settings.json` and `lsp.servers`. A VS Code user gets three dead ends.
*Verify*: read `docs/guides/lsp.md` against `src/LanguageServer/README.md`, and
`find . -name 'package.json' -o -name '*.vsix' | grep -v node_modules` — nothing.
*Fix*: pick one story. Effort: S (documentation) / M (ship a client).

**D6 — The diagnostic ranges are approximate.** Every lint rule is reported
over a single character, and the parse and validation ranges are computed in
file coordinates with an offset that runs past the closing delimiter, the
quote and the comma. *Verify*: read
`src/LanguageServer/Converter/DiagnosticConverter.php:41-50` and
`src/LanguageServer/Handler/TextDocumentHandler.php:233-237`, or run the server
and publish a document. Effort: S.

### E. The Symfony and Laravel bridges

**E1 — Laravel `regex:lint` crashes on a null `php-regex.paths`.** *Verify*:
`src/Laravel/Command/LintCommand.php:113-114,548-552`, against the Symfony
guard at `src/Symfony/Command/LintCommand.php:104-110,171`. *See also*:
"Extraction gaps left on purpose". Effort: XS.

**E2 — No ready-made rule for a pattern a user submits.** There is no Symfony
`Constraint` and no Laravel `Rule` anywhere; the existing extractor only reads
the native `Regex` constraint for linting. *Verify*:
`grep -rn 'extends Constraint\|implements Rule' src/` — nothing;
`src/Symfony/Extractor/ValidatorPatternSource.php:20`. *See also*: "Ship a
ready-made validation rule" above. Effort: M.

**E3 — The command surfaces diverge between bridges.** `compare` takes
`--method intersection|subset|equivalence` on the CLI and Symfony, and Laravel
hardwires equivalence and offers `--format=json` instead; `analyze` and
`security` exist only in Symfony; `regex:routes` is `setHidden(true)` in
Symfony while it is the flagship command in Laravel; the short options `-j` and
`-t` are missing in Laravel; Symfony still carries the `debug:compare` alias.
*Verify*: compare `src/Cli/Command`, `src/Symfony/Command` and
`src/Laravel/Command` for the same verb.
*Fix*: a shared command and flag specification. Effort: M.

**E4 — Linux parallelism is silently off in Laravel.** `detectCpuCount()` reads
the `/usr/bin/nproc` binary and then `/proc/self/status` without parsing either,
and always returns 1; only the `sysctl` branch works, so a Linux run analyses
with one worker and says nothing.
*Verify*: read `src/Laravel/Command/LintCommand.php:517-537`, next to the
Symfony equivalent. Effort: S.

**E5 — The bridge reports are internal array shapes.** Symfony's report objects
are readonly but marked `@internal` over phpstan-typed arrays, and Laravel
builds raw arrays and encodes them. Nothing programmatic can render or consume
them without depending on internals.
*Verify*: `grep -rn '@internal' src/Symfony/Analyzer/`;
`src/Laravel/Command/RoutesCommand.php:112`.
*Fix*: stable typed objects, or a documented JSON schema. Effort: M.

### F. Documentation and packaging

**F1 — No local playground or REPL.** The documentation home sends readers to
regex101 for exploration; nothing in the repository runs a pattern
interactively. *Verify*: `docs/README.md`. Effort: M, optional.

**F2 — Sixteen packages and no "start here".** The README table lists every
package, and nothing tells a reader who only wants `validate()` which one to
install. *Verify*: the package table in `README.md`. *Fix*: one line at the top
naming the entry point. Effort: XS.

**F3 — `SECURITY.md` still lists 1.x only.** *See also*: "Refresh SECURITY.md
for 2.x" above. *Verify*: read `SECURITY.md`. Effort: XS.
