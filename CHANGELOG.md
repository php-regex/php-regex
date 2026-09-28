# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Removed
- `RegexParser\Node\UnicodeNode` and `NodeVisitorInterface::visitUnicode()`: no parser path ever produced the node — `\x{...}` and `\u{...}` escapes become a `CharLiteralNode` — so every visitor carried a method that could not be called. See [UPGRADING.md](UPGRADING.md).
- `RegexParser\ReDoS\ReDoSAnalyzerInterface`: implemented by nothing, `ReDoSAnalyzer` included.
- The `$phpVersionId` argument of `Lexer::__construct()`: tokenizing does not depend on the PHP version, and keying the compiled token patterns on it compiled the same two regexes once per version. See [UPGRADING.md](UPGRADING.md).

### Fixed
- Validation refused `\K` inside a lookbehind (`regex.lookbehind.keep_not_allowed`), though PHP compiles it: PHP enables `PCRE2_EXTRA_ALLOW_LOOKAROUND_BSK` by default, and PCRE2 allowed it there before 10.38. `/(?<=a\K)b/` validates now.
- Installing `nikic/php-parser` changed what `regex lint` reported: the AST extractor missed the patterns held in an array — `preg_replace(['/a/', '/b/'], ...)` and every `preg_replace_callback_array()` key — that the tokenizer already read. Both extractors now recognise the same calls, and the AST one also reads a pattern passed as a named argument.
- A single generated file of a couple of megabytes aborted a whole lint run: reading it into tokens exhausted the memory limit, and that fatal error cannot be caught, so every result collected so far was lost. Such a file is now skipped; raise `memory_limit` to have it analyzed.
- Validation refused every version condition, `(?(VERSION>=10.4)...)` included, though PCRE compiles it. It now accepts the two comparisons PCRE makes — `=` and `>=` — and reports the others as `regex.condition.version_operator` rather than as an unrecognised condition.
- `(*:label)` came back as `(*MARK:label)`.
- Recompiling rewrote two spellings the author had picked: `(?'name'x)` came back as `(?<name>x)`, and `(*sr:...)` as `(*script_run:...)`.
- A verb wrapping more than one level of brackets was not read: `(*atomic:((a)))` and `(*pla:((a)b(c)))` are valid PCRE and were refused.
- The `J` modifier reached further than PCRE lets it: `(?J:(?<n>a))(?<n>b)` and `(?:(?J)(?<n>a))(?<n>b)` were accepted, though the second name is written outside the group the modifier covers.
- The language server's quick fixes wrapped the new pattern in quotes without escaping, so applying one to a pattern holding a `'` left the file unparseable.
- `bin/regex-lsp --version` reported a version of its own instead of the library's.
- Recompiling a version condition produced a pattern PCRE refuses: `(?(VERSION>=10.4)y|n)` came back as `(?((?(VERSION>=10.4))y|n)`.
- `(?(VERSION=10.4)...)` is parsed; PCRE accepts that spelling alongside `VERSION>=`.
- A pattern that is not valid UTF-8 lost everything after a `\Q...\E` run or a `(?#...)` comment: the lexer read those two with a UTF-8 regex, and took PCRE's refusal for the end of the pattern. `/\Q\xFFabc\E]/` came back as `//`.
- `\N{U+0041}` inside a character class is parsed instead of rejected; PCRE accepts it.
- A duplicate group name was reported at the wrong offset when the pattern held an alternation or an inline flag group before it: `/(?<name>a)|(?<name>b)/` pointed at the `|`, and `/(?J)(?<name>a)(?-J)(?<name>b)/` inside the `(?-J)`. Both now point at the second group.
- Recompiling a recursion condition produced a pattern PCRE refuses: `(?(R)yes|no)` came back as `(?((?R))yes|no)`.
- Validation accepted escapes and character-class forms that PHP refuses to compile. It now reports them: an escaped letter PCRE does not know, such as `\i` (`regex.escape.unrecognized`); `\F`, `\l`, `\L`, `\u` (`\u0041` included) and `\U`, and a `\N{...}` that is neither a repeat count nor `\N{U+...}` (`regex.escape.unsupported`); `\o` without braces and bad or missing digits in `\o{...}`, `\x{...}` and `\N{U+...}` (`regex.octal.missing_brace`, `regex.octal.invalid_digit`, `regex.unicode.invalid_digit`, `regex.escape.digits_missing`); `\N{U+...}` without Unicode mode, however it is spelled; surrogate code points in Unicode mode (`regex.unicode.surrogate`); `\B`, `\R`, `\X`, `\N` and the other escapes a class cannot hold (`regex.charclass.invalid_escape`); POSIX items outside a class and collating elements (`regex.posix.outside_class`, `regex.posix.collating_element`); unknown POSIX names such as `[[:foo:]]` or `[[:ALPHA:]]`, which PCRE matches case-sensitively; and out-of-order ranges between escapes, such as `[\x5a-\x41]` (`regex.range.reversed`). A `]` that follows only `\E`, `\Q\E` or `^` at the start of a class is a member, so `[\E]a` is an unterminated class, and `[\E^a]` is negated. A leading `(*UTF)` now counts as Unicode mode, so `/(*UTF)\N{U+41}/` validates, and `/[^^]/` is no longer refused. Octal escapes above `\377` validate in Unicode mode (`/\400/u`, `/[\400]/u`), and a range from a multibyte character without Unicode mode is read byte by byte, as PCRE reads it, so `/[ÿ-é]/` validates. Spaces and tabs padding the digits of `\x{...}`, `\o{...}` and `\N{U+...}`, and before the `U+`, are read as PCRE2 10.48 reads them: `\x{ 41 }` is the character `A`, not five literals.
- Validation accepted group, verb and condition forms that PHP refuses to compile. The parser now refuses: a `)` left over after a verb name, which ends at the first `)`, so `(*:ab(d\)c)` is unbalanced; a verb, an atomic group or a script run as a condition, such as `(?(*ACCEPT)...)` or `(?(*CR)...)`; `(?P'name'...)` and `(?P"name"...)`, which PCRE spells `(?'name'...)`; a group name longer than 128 code units; two names for one group number in a branch reset; `\k` without a name, and an empty `\k<>`, `\k''` or `\k{}`; a bare word after `(?C`, such as `(?Cname)`; and a `-` after `(?^`. Validation now reports: `\X` inside a lookbehind (`regex.lookbehind.unbounded`); `(?-0)` and `(?+0)` (`regex.subroutine.relative_zero`); a conditional with more than two branches (`regex.conditional.too_many_branches`); a `(DEFINE)` group with more than one (`regex.define.too_many_branches`); `(*CR)`, `(*UTF)` and the other start-of-pattern settings anywhere but at the start (`regex.verb.misplaced`); `(*LIMIT_MATCH=)` without a number and `(*=name)` (`regex.verb.invalid`); `(*MARK)`, `(*MARK:)` and `(*:)` (`regex.verb.mark_name_missing`); and a version such as `10.0.0` in `(?(VERSION>=...)` (`regex.condition.version_syntax`). Some forms PHP compiles now validate: a mark name holding a `(`, such as `(*:a(b)`; the same name given twice to one group number in a branch reset, `(?|(?<a>A)|(?<a>B))`; and callout strings between `` ` ``, `'`, `^`, `%`, `#`, `$` or `{...}`, with a doubled delimiter standing for itself. `(?(*pla:a)yes|no)` is read as a conditional; it was read as a lookahead followed by `yes|no`. Two false refusals go as well: an empty callout string, `(?C"")`, `(?C'')` or `(?C{})`, which PCRE compiles; and `\X` inside a lookahead within a lookbehind, such as `(?<=(?=\X)a)b`, since a lookahead adds no length to the lookbehind.
- Validation refused patterns PHP compiles. These now validate: a lookbehind holding a call or a back reference to a group of bounded length, such as `(a)(?<=b(?1))` or `(a)(?<=b\1)`, and a repeated lookahead inside one, `(?<=(?=.)*)`; a repeated empty group, such as `(){3,5}` or `(?!)?`; the non-atomic assertions `(*napla:...)`, `(*naplb:...)`, their long names, `(?*...)` and `(?<*...)`, kept as non-atomic when the pattern is written back; named conditions in quotes, `(?('name')...)`, relative conditions, `(?(-1)...)` and `(?(+1)...)`, and `(?(R&name)...)`; group names in any script under `u`, such as `(?<nämed>b)`; the empty option settings `(?)` and `(?-)`; a callout before the assertion of a condition, `(?(?C1)(?=a)...)`; a repeated `(*ACCEPT)`; `\E` or `\Q\E` between `-` and the end of a range, `[a-\Ec]`; a repeated `\N`, `\N{4}`; a `)` inside a callout string, `(?C"a)b""c")`; and fixed-length lookbehinds longer than 255 characters, up to PCRE's 65535.

### Changed
- `max_lookbehind_length` (default 255) now only limits variable-length lookbehinds, as PCRE2's own `max_varlookbehind` does: `(?<=a{256})` validates, `(?<=a{0,256})` does not. A fixed-length lookbehind is limited by PCRE's ceiling of 65535 characters, which no option changes. Each top-level branch of a lookbehind is measured on its own.
- `Regex::CACHE_VERSION` is a fingerprint of the code that builds an AST rather than a number raised by hand: `task cache-version` writes it, `task lint` runs that, and the test suite fails while the constant and the code disagree. Cached ASTs written by an earlier state of `main` are ignored.
- `Regex::analyze()` only reports what the pattern itself causes: a failure that is not a RegexParser exception now surfaces instead of being written into the report as a pattern error.
- A cache that throws while reading is treated as a cache miss, the way a cache that throws while writing already was.

### Added
- PCRE2's official test suite (10.48, pinned) is replayed against `Regex::validate()` at compile level, under PHP's compile options. The compile verdict, the error offset and the patterns accepted although PHP refuses them are counted and published, with a fix plan, on the [PCRE2 conformance page](docs/reference/pcre2-conformance.md). Cases whose compile verdict differs on PCRE2 10.40, the engine of the oldest supported PHP, are set aside, and CI rebuilds both engines to re-check every recorded outcome. The test suite fails when a count moves without the page following.
- `regex lint` reads patterns from regex wrappers, not only from the native `preg_*` functions. `composer/pcre` (`Preg::`, `Regex::`) is recognised out of the box; `nette-utils`, `spatie-regex` and `laravel-str` are enabled with `--interop` or `extraction.interop`. A codebase that had migrated away from `preg_*` reported no patterns at all before.
- Project helpers carrying a pattern are declared with `--pattern-function` or `extraction.functions`, as `function` or `Some\Class::method`, with `#<index>` when the pattern is not the first argument and `#<index>:keys` when that argument is an array whose keys hold the patterns.
- Alphabetic assertion verbs (PCRE2 10.32+): `(*pla:...)`, `(*positive_lookahead:...)`, `(*nla:...)`, `(*plb:...)`, `(*nlb:...)`, `(*negative_lookbehind:...)`, `(*atomic:...)` parse as their classic lookaround / atomic group equivalents.
- Script run content is now parsed into the AST: `(*sr:(a+)+b)` is analyzed like any sub-pattern (its catastrophic backtracking is detected by the ReDoS engine, its length/complexity contribute to metrics).
- `Regex::parseTolerant()` — explicit tolerant parsing without the `parse($regex, true)` bool-flag union return.
- `OutputFormat` enum accepted by `Regex::explain()` and `Regex::highlight()` (strings still work).

### Deprecated
- `Regex::new()` (identical to `Regex::create()`); `ValidationResult::isValid()` and `getErrorMessage()` methods in favor of the public `$isValid` / `$error` properties.

### Added
- **Laravel bridge** (`RegexParser\Bridge\Laravel`) with package auto-discovery:
  - Service provider and `Regex` facade
  - Artisan commands: `regex:lint`, `regex:routes`, `regex:explain`, `regex:compare`, `regex:transpile`
  - Pattern extractors for route `where()` constraints and `regex:`/`not_regex:` validation rules
  - Publishable configuration (`config/regex-parser.php`)
  - No new runtime dependencies — `illuminate/*` packages are suggested only
- **Language Server Protocol (LSP) support** via `bin/regex-lsp` for real-time regex analysis in IDEs.
  - Real-time diagnostics (parse errors, validation issues, lint warnings)
  - Hover information with pattern explanations
  - Code actions for quick fixes (add `/u` flag, apply optimizations)
  - Completion support for regex syntax (character classes, anchors, quantifiers, groups, Unicode properties, POSIX classes, flags)
  - Works with VS Code, Neovim, Vim, Emacs, Sublime Text, Helix, Zed, and PhpStorm (via LSP4IJ plugin)
- **Unicode awareness lint checks** (3 new rules):
  - `regex.lint.unicode.shorthandWithoutU` (Style): Warns when `\w`, `\d`, `\s` used without `/u` flag
  - `regex.lint.unicode.propertyWithoutU` (Error): Error when `\p{L}` used without `/u` flag
  - `regex.lint.unicode.bracedHexWithoutU` (Error): Error when `\x{100}` used without `/u` flag for code points > U+FF
- **Configurable lint rules** via `checks.lint` in `regex.json`/`regex.dist.json`:
  - Enable/disable individual lint rules (32 rules available)
  - Configuration schema with all rule IDs and default values
  - CLI flags: `--lint`/`--no-lint`, `--enable-rule=<id>`, `--disable-rule=<id>`
- CLI `--redos` flag to explicitly enable ReDoS analysis (counterpart to `--no-redos`).

### Changed
- **BREAKING**: ReDoS analysis is now disabled by default for better performance. Enable explicitly via:
  - CLI: `--redos` flag or `checks.redos.enabled: true` in `regex.json`
  - PHPStan: `reportRedos: true` in rule configuration
  - Symfony: `regex_parser.redos.enabled: true` in bundle configuration
- **BREAKING**: `unicode.shorthandWithoutU` lint rule is now disabled by default to reduce noise. Most PHP codebases intentionally use ASCII-only matching. Enable via:
  - CLI: `--enable-rule=unicode.shorthandWithoutU`
  - Config: `checks.lint.rules.unicode.shorthandWithoutU: true` in `regex.json`
- **BREAKING**: Lint identifiers now use camelCase instead of snake_case for PHPStan compatibility:
  - `regex.lint.unicode.shorthand_without_u` → `regex.lint.unicode.shorthandWithoutU`
  - `regex.lint.alternation.duplicate_disjunction` → `regex.lint.alternation.duplicateDisjunction`
  - `regex.lint.charclass.duplicate_chars` → `regex.lint.charclass.duplicateChars`
  - `regex.lint.charclass.suspicious_range` → `regex.lint.charclass.suspiciousRange`
  - `regex.lint.charclass.suspicious_pipe` → `regex.lint.charclass.suspiciousPipe`
- Symfony bundle `redos.enabled` configuration option (defaults to `false`).
- Lint configuration `checks` section with nested ReDoS and optimization settings (schema + defaults).
- PHPStan extension support for `checks` configuration overrides.
- Lint check for backreference-as-octal in character classes: `[^\1]` is treated as octal `\x01`, not a backreference — warns when a corresponding capturing group exists.
- Lint check for literal metacharacters in character classes: `[\w+]` where `+`, `*`, `?` are literals, not quantifiers. Skips negated classes and multi-element sets (3+ other elements) to avoid false positives on URI schemes, Base64, etc.
- Lint check for `(.|\n)` anti-pattern: suggests using the `s` (DOTALL) flag or `[\s\S]` instead.
- Lint check for quantified capturing groups: `(?<name>\d+)+` warns that only the last iteration's capture is retained. Named groups report as Warning; anonymous groups report as Info.
- `CharSet::contains()` now uses binary search over sorted ranges, reducing lookup from O(n) to O(log n) on the determinization hot path.

### Changed
- Deprecated legacy lint config keys (`rules`, `redosMode`, `redosThreshold`, `redosNoJit`, `optimizations`, `minSavings`) in favor of `checks.*`.
- `SecurityAccessControlAnalyzer` now uses `MatchMode::FULL` instead of `MatchMode::PARTIAL`, avoiding redundant NFA self-loops since patterns are already manually wrapped with `.*` by `normalizeSearchPattern()`.
- `RegexSolver::findExample()` product-automaton BFS now uses integer pair keys instead of string concatenation, reducing allocation overhead during DFA intersection/subset/equivalence checks.

### Removed
- Dead `HelpfulExceptionTrait` (~430 lines, referenced nowhere).

### Added
- Recompiling a parsed AST gives the pattern back byte for byte, spelling included: optional escapes (`\{` or `{`, `[a-z\-]` or `[a-z-]`, `\]` outside a class), the backreference syntax that was used (`(?P=name)` is no longer rewritten to `\k<name>`), the way a code point was written (`\a` stays `\a`, `«` stays `«`), and assertion conditions (`(?(?<!x)y)` is no longer re-parenthesized). Text inside `\Q...\E` is still escaped, since the quoting is not kept. Comparing patterns — what the optimizer does to decide whether a pattern changed — still runs on the normalized form, so optimization suggestions are unaffected.
- Recompiling an AST gives the pattern back byte for byte under `/x`: the whitespace the modifier makes ignorable is read back from the source, so `/  a  b  /x` and documented multi-line patterns keep their layout instead of collapsing to `/ab/x`. `RegexNode` carries the body it was parsed from for that purpose; an AST built by hand, a pretty-printed compile and normalized output are unaffected.
- `/x` can be turned on from inside the pattern: `(?x)`, `(?x:...)`, `(?-x)` and `(?^x)` now drive extended mode in the lexer, the parser and the compiler, with PCRE's scoping — a bare `(?x)` holds until the end of the enclosing group and crosses `|`, while `(?x:...)` stops at its own `)`. Comments and ignorable whitespace were previously only recognised through the pattern-level `x` modifier.

### Fixed
- PCRE conformance, found by linting the corpus: `[[:^word:]]` is accepted (PCRE negates every POSIX class it supports, and only `word` was rejected), and a `#` comment under `/x` no longer lets its `[` or `(` be tokenized as regex syntax — `/a # [ x\nb/x` compiles in PCRE but was reported as an unclosed character class.
- `--format=json` no longer dies with "Failed to encode JSON" on byte-mode patterns: every string of the report, including the ones held by issue and optimization objects, is escaped the way the console renders them. The checkstyle and JUnit formatters used to silently drop such values, since `htmlspecialchars()` returns an empty string on invalid UTF-8.
- Reported patterns keep their non-ASCII characters instead of being rewritten as octal escapes. `/《붉은별》/iu` used to be printed as `/\343\200\212…/iu`, which is a different pattern under `/u` (`\343` is `ã`, so the `i` flag stops being useless) and could not be copied back into PHP. Control bytes are still escaped, and invalid UTF-8 still falls back to full byte escaping.
- Inline flag diagnostics no longer claim a flag is "already set globally" when it was set by an earlier inline flag group: `/^(?U)a(?U)b/` now reports the second `(?U)` against the first one.
- Optimizer suggestions no longer un-escape literal `\{n\}` sequences into quantifiers: `/^(\{0\}.+)/` was rewritten to the non-compiling `/^({0}.+)/`. Found by proving all 435 corpus optimization suggestions against PCRE + automata equivalence (253 proven equivalent, 0 divergent, 2 invalid — both this bug).
- `SolverOptions::$maxTransitionsProcessed` now defaults to a finite 1,000,000 instead of unlimited; pathological patterns could previously spin the determinization loop for tens of minutes.
- `--generate-baseline` combined with `--baseline` now writes a baseline covering the full report; previously issues suppressed by the old baseline silently vanished from the newly generated one.
- The AST cache key now always includes the effective PHP version, so a cache directory shared across PHP upgrades cannot serve ASTs parsed under a different version.
- Non-UTF-8 (byte-mode) patterns like `"@^[ \t!-~\x80-\xFF]*$@"` are now tokenized byte by byte and analyzed, exactly as PCRE compiles them without the `u` modifier, instead of being rejected with "Input string is not valid UTF-8". Combining invalid UTF-8 with the `u` flag still errors, like PCRE.
- Unicode conformance: `\o{...}` above 0xFF is accepted under `/u` (any valid codepoint) and rejected without it; `\N{U+hhhh}` is now actually lexed as a codepoint escape (it was silently parsed as literal text), requires the `u` flag like PCRE, and resolves its codepoint directly; `\p{L}` end positions are no longer off by two bytes; `\P{...}` compiles back byte-identically instead of being rewritten to `\p{^...}`.
- PCRE conformance (validated differentially against the real engine): quoted references `\g'1'` / `\k'name'` and relative subroutine calls `\g<-1>`, `\g<+1>`, `(?+1)` are now accepted; a quantifier after `\Q...\E` applies to the quoted literal (`/\Q+\E*/`); `\c` at end of pattern, empty `\x{}`, quantifiers above 65535 (`a{65536}`), invalid group names (`(?<a-b>x)`), and unbalanced bracket delimiters (`{a{b}`) are now rejected like PCRE does.
- Parser exceptions from named-Unicode validation and pattern-level flag errors now carry the pattern, position, and caret snippet like every other error.
- Visitors now cover every AST node type: `CompilerNodeVisitor` no longer silently drops `UnicodeNode` from compiled patterns; `MetricsNodeVisitor` counts class operations, control chars, script runs, and version conditionals; `ModernizerNodeVisitor` passes `UnicodeNode` through. A synthetic all-node exhaustiveness test guards every visitor against future node additions.
- CLI lint config now honors `verifyWithAutomata` when provided in optimization settings.
- **Visitor crashes on newer node types**: visitors with typed returns (`optimize()`, `literals()`, `generate()`, complexity scoring, length ranges, HTML explain, test-case generation) crashed with a `TypeError` on patterns containing control chars (`\cA`), class operations (`[a&&b]`), script runs (`(*sr:...)`), or version conditionals (`(?(VERSION>=...))`). `validate('/\cA/')` also wrongly reported valid patterns as invalid. All visitors now handle every node type; a new exhaustiveness test guards against future drift.
- **`ArrayCache` never returned cached ASTs**: it stored the raw cache payload string, which the facade discarded on every load — every parse re-parsed from scratch while hit counters incremented. Payloads are now decoded once at write time (shared `CachePayloadDecoder`, also used by the PSR-6/PSR-16 adapters).
- **Compiler double-wrapped POSIX classes**: `/[[:alpha:]]/` compiled to `/[[[:alpha:]]]/`, changing match semantics. Also affected ReDoS `vulnerablePattern` output.
- **Lint extractor "repaired" broken delimiters**: `preg_match("/foo#", ...)` — a runtime error in PHP — was silently extracted as `/foo/` and passed lint. Mismatched delimiters are now reported as errors.
- **ReDoS analysis skipped conditional conditions**: `/(?(?=(a+)+b)x|y)/` was rated safe; the condition lookaround is now analyzed (rated critical).
- **Sample generator produced non-matching samples**: negated classes always returned `'!'` (impossible for e.g. `/[^!"#]/`); lookbehind suffix hints used substring instead of suffix matching. `generate()` now verifies samples against the real engine and retries.
- **Backreference/octal disambiguation now follows PCRE**: `(a)\11` is the octal escape `\011` (TAB), not an invalid backreference; `\19` is octal `\1` + literal `9`; `\81` remains an error. Previously all multi-digit `\NN` were treated as backreferences and wrongly rejected.

### Changed
- **Unified quantifier parsing** via a new `QuantifierBounds` value object. Previously ~20 inline re-implementations disagreed: `{,5}` meant "0 to 5" to the validator but "exactly once" to the length/lint analyzers and "never" to the sample generator; `{ 2 }` (extended mode) was only understood by the validator. All visitors (length ranges, ReDoS, linter, sample/test-case generators, optimizer, explain, railroad) now share one canonical parser.
- **Stricter (PCRE-conformant) character class ranges**: a character type, POSIX class, or Unicode property can no longer be a range endpoint. Patterns like `[\w-_]`, `[\d-z]`, `[a-\d]` — which PCRE rejects at compile time — now fail to parse instead of being silently re-interpreted with a literal hyphen. Literal hyphens at class edges (`[-a]`, `[\d-]`) are unaffected.

### Documentation
- Added comprehensive LSP integration guide (`docs/guides/lsp.md`) with configuration for VS Code, PhpStorm (LSP4IJ), Neovim, Vim, Emacs, Sublime Text, Helix, and Zed.
- Updated CLI configuration examples to use `checks` and note deprecated keys.

### Tests
- Added comprehensive LSP server test suite (97 tests) covering Protocol, Handlers, Document management, and Converters.
- Added Unicode lint rules tests covering all shorthand patterns and Unicode properties.
- Added coverage for `checks` normalization and PHPStan `checks` overrides.
- Added data-provider coverage for backreference-as-octal, literal metacharacter, dot-newline anti-pattern, and quantified capturing group lint checks.
- Added `CharSetContainsTest` covering binary search: single points, ranges, empty/full sets, complement, union, intersection, subtraction, Unicode code points, and many-ranges stress test.
- Added `RegexSolverEdgeCaseTest` covering identical patterns, empty languages, case-insensitive equivalence, char class shorthands (`\d`, `\w`, `\s`), `.*` subset, quantifier equivalences, alternation commutativity/distributivity, both determinization algorithms, DFA cache reuse, and dotall flag equivalence.
- Added `DfaMinimizerRangesTest` covering range-based transitions, range preservation in minimized output, single-state DFA, and all-accepting state merging for both Hopcroft and Moore algorithms.
- Added `SecurityAccessControlMatchModeTest` verifying `MatchMode::FULL` correctness for prefix shadowing, unanchored paths, regex paths, disjoint paths/methods, equivalent/redundant rules, empty paths, and fully anchored paths.

## [1.3.0] - 2026-01-12

### Added
- Transpiler API (`Regex::transpile`) with JavaScript and Python targets plus CLI/Symfony commands.
- CLI `graph` command to export NFA diagrams (DOT/Mermaid).
- Unicode-aware automata mode for `/u` patterns with code point literals, dot, and character class handling.
- Effective alphabet optimization in DFA construction plus a new benchmark script for Unicode-heavy ranges.
- RegexLanguageSolver facade factory plus DFA cache interfaces for reuse across automata comparisons.
- Work-budget limits for automata processing via SolverOptions `maxTransitionsProcessed`.
- Facade precompile hook for warm DFA caching in high-volume analyzers.

### Changed
- Automata-based analyzers now generate Unicode-aware examples when working with `/u` patterns.
- CLI compare and Symfony route/security analyzers now use the facade and shared DFA caching.
- Optimizer can optionally verify language equivalence with automata via `verifyWithAutomata`.

### Documentation
- Documented automata work-budget limits and diagnostic payloads in the logic solver guide.
- Added correctness contracts and feature support matrix references.
- API reference now includes transpile and optimize equivalence verification options.

### Fixed
- Sort Symfony routes by specificity before conflict analysis to reduce false-positive overlaps.
- Symfony access_control analysis now uses search semantics to match preg_match behavior and catch prefix shadowing.
- Redundant character class detection now accounts for escaped literals (e.g. octal sequences).
- Runtime `/r` modifier probing now avoids static modifier validation failures on PHP 8.2/8.4.

### Benchmarks
- Added automata transform, DFA minimizer, and effective alphabet benchmark scripts.

### Tests
- Added unit coverage for automata solver, minimization, Unicode support, and transpiler targets.

## [1.2.0] - 2026-01-08

### Added
- Symfony `regex:routes` command to analyze routing conflicts and overlaps with ordering-aware suggestions.
- Automata-based route conflict analyzer for Symfony routing collections.
- Symfony `regex:security` command to analyze access_control shadowing and firewall ReDoS risks.
- Access-control security analyzer with critical shadowing detection.
- Firewall regex ReDoS checks for Symfony security firewalls.
- Symfony `regex:analyze` command to run bridge analyzers with JSON output and fail-on controls.
- Bridge analyzer registry with shared console/JSON formatting for Symfony diagnostics.
- Equivalence and redundancy detection notes for route and access_control conflicts.

### Documentation
- Documented Symfony bundle commands in the CLI guide.

### Tests
- Added unit coverage for route conflict analysis and the new Symfony command.
- Added unit coverage for Symfony security access_control and firewall analysis.

## [1.1.0] - 2026-01-07

### Added
- Automata-based regex logic solver (AST -> NFA -> DFA) with intersection, subset, and equivalence checks plus shortest counter-example search.
- CLI `compare` command and Symfony console `regex:compare` (alias `debug:compare`) to compare patterns.
- Regular-subset validator and ComplexityException for non-regular constructs.
- ReDoS benchmark tooling (`bin/redos-bench`) and CLI `redos` command with confirmation support.
- New lint rules ported from eslint-plugin-regexp for duplicate character class elements, useless ranges, zero quantifiers, redundant `{1}` quantifiers, empty alternatives, duplicate disjunctions, useless backreferences, and optimal quantifier concatenation.

### Changed
- CLI output and banner formatting refined for consistency across commands.
- Symfony bundle service wiring and lint command structure refactored for maintainability.
- Parser, lexer, regex facade, and node visitor refactors for clarity and stricter typing.
- Alternation duplicate warnings now use the `regex.lint.alternation.duplicateDisjunction` identifier.

### Fixed
- PHPStan type errors in CLI ReDoS command and progress bar type hints.
- Runtime `r` modifier tests now respect actual PCRE support across PHP versions.

### Documentation
- Added a logic solver deep dive and compare command usage to the docs.
- Updated reference and quick start documentation with new lint rules and compare examples.
- Refreshed corpus and ReDoS guide wording for clarity.

### Tests
- Added automata solver coverage including unsupported construct checks.
- Added data-provider coverage for the new lint rules and expanded corpus-based validation.

## [1.0.10] - 2026-01-04

### Added
- JSON schema for regex-parser configuration to provide validation and IDE autocompletion for config files.
- JSON schema validation for regex config to catch configuration errors early.
- Corpus update script (`corpus/update`) for automated test pattern collection from open-source projects.
- Git helper functions for corpus management and repository operations.
- Debug option to corpus update process to show git commands being executed.

### Changed
- ReDoS risk analysis improved with better clarity, control, and reporting.
- ReDoS analysis configuration refactored for improved maintainability.
- `canonicalizeCharClasses` optimization toggle for character class normalization (CLI config, PHPStan, Symfony bundle).
- Optimization output now preserves the original pattern body when only flags are removed, reducing escape-only diff noise.
- Symfony bundle now exposes default lint optimization settings via `regex_parser.optimizations`.
- Linter warnings for redundant character classes and redundant inline flags now include hints with actionable details.
- Corpus update process improved with better error handling and file cleanup.

### Fixed
- CLI analyze command output spacing issue resolved.
- PHPUnit output break fixed by capturing SelfUpdateCommand banner in test.

### Documentation
- Documented `canonicalizeCharClasses` in the CLI config and optimize API.

### Tests
- Improved test assertions for command output validation.
- Added data-provider coverage for canonicalization toggles, anchor conflict cases, and flag-only optimization outputs.
- Added data-provider coverage for redundant character class hints and redundant inline flag hints.
- Fixed linter test assertions for more reliable test validation.
- Added return type array shapes for `jsonSerialize()` methods.

## [1.0.9] - 2026-01-03

### Changed
- Unified CLI output styling across commands (banner, sections, badges, and pattern blocks) to match the lint command's look.
- Debug command now shows a syntax-highlighted pattern block alongside the heatmap output.
- Highlight/parse/analyze/validate/diagram/self-update/version/clear-cache/help outputs now use the shared console presentation style.

## [1.0.8] - 2026-01-03

### Changed
- Improved lint command output clarity: changed progress labels from "Collecting patterns" to "Scanning files" and added clear summary showing "Scanned X files, found Y patterns" to eliminate confusion about what progress bars represent.
- Removed redundant collection timing display from lint output.

### Fixed
- Fixed Lexer token offsets for `\xNN` escape sequences.
- Fixed Compiler output for non-ASCII characters when using the `/u` flag.
- Fixed cache key generation to include library versioning.
- Improved CLI pattern detection for non-standard delimiters.

## [1.0.7] - 2026-01-03

### Added
- Corpus-based test suite (`LinterNodeVisitorCorpusBugsTest`) to prevent regressions based on real-world patterns from open-source projects.
- Tests to verify suspicious ASCII range `A-z` detection (includes non-letter characters between Z and a).

### Fixed
- Corrected suspicious ASCII range detection to properly identify `A-z` as including non-letter characters (`[ \ ] ^ _ \` `) and recommend `A-Za-z` instead.
- Linter now correctly skips unparseable regex patterns in test extraction to prevent crashes.
- Improved corpus log generation to include accurate pattern column and file offset metadata.

## [1.0.6] - 2026-01-02

### Added
- Lint results now include pattern columns and file offsets to disambiguate multiple patterns on the same line.

### Changed
- Default lint excludes now skip `vendor`, `tests`, and `Fixtures` in the CLI and Symfony bridge configuration.
- Console and Symfony lint output now include column numbers when available.

### Fixed
- Inline flag linting now respects flags set by earlier inline modifiers in the same sequence.
- Alternation duplicate linting now ignores lookaround-only branches to avoid false positives.
- ReDoS empty-repeat severity is downgraded for recursive patterns with possess/atomic branches.

### Tests
- Added coverage for token-based and PHPStan extraction column/offset metadata, inline-flag sequencing, lookaround alternations, and Symfony route requirement patterns.

## [1.0.5] - 2026-01-02

### Added
- Python named group syntax preservation: `(?P<name>...)` syntax is now preserved when parsing and compiling regex patterns instead of being converted to `(?<name>...)`.
- Added `usePythonSyntax` property to `GroupNode` to track original named group syntax.
- Added `Style` and `Perf` severity levels to the `Severity` enum for categorizing lint issues.
- Added `severity` property to `LintIssue` class for categorizing issues by severity level.

### Changed
- Linter now only flags overlapping alternation warnings when the alternation is inside an unbounded quantifier (e.g., `+`, `*`, `{n,}`), reducing false positives for safe patterns like `/\r\n|\r|\n/` and `/^(978|979)/`.
- Improved heuristic for detecting alternations inside unbounded quantifiers to properly handle nested parentheses.

### Fixed
- Fixed false positive overlap warnings for patterns without quantifiers that don't pose ReDoS risk.
- Fixed pretty-print mode in `CompilerNodeVisitor` to output correct regex syntax for lookaheads (`(?=`), lookbehinds (`(?<!`), atomic groups (`(?>`), branch resets (`(?|`), and inline flags (`(?im:`).
- Built the `/r` modifier probe regex dynamically to avoid static regex validation false positives.

### Tests
- Updated `LinterRulesTest::test_alternation_overlap_warning` to use pattern with unbounded quantifier.
- Updated `OptimizerTest` to expect Python syntax preservation.
- Updated `CompilerNodeVisitorCoverageTest` to expect correct regex syntax in pretty-print mode.
- Reworked formatter and visitor coverage tests to assert behavior, and added coverage for JSON payload normalization and validator errors.

## [1.0.4] - 2026-01-02

### Added
- Added a formatter benchmark script to measure lint output throughput and memory usage.
- Added atomic-group tip suggestions for nested-quantifier and dot-star lint warnings.

### Documentation
- Documented formatter benchmark usage in the README and benchmarks guide.
- Documented the `suggestedPattern` lint issue field in diagnostics reference.

### Changed
- Optimizer now reuses a compiler visitor while resetting its state between string conversions.
- Lint output formatters now assemble output using buffered chunks for large reports.
- PHPStan pattern truncation now relies on a named constant.
- Suspicious ASCII range warnings now describe the ASCII-order endpoints of the reported range.
- Atomic-group lint suggestions are now validated before being emitted.
- Extended-mode optimization tips now diff against a normalized baseline to avoid mixing raw formatting with pretty-printed suggestions.

### Tests
- Added regression coverage to ensure non-alternation patterns do not trigger overlap warnings.
- Added ReDoS coverage for nested possessive quantifier patterns that should remain safe.
- Added coverage for compiler state resets between compilations.
- Added coverage for the PHPStan truncation default length.
- Added coverage for lint suggestion tips and the updated ASCII range message.
- Added coverage for escaped dollar literals, char-class group-like tokens, and extended-mode optimization baselines.
- Added coverage for invalid delimiter validation in lint analysis.

## [1.0.3] - 2026-01-02

### Added
- ReDoS analysis now detects empty-match repetitions and ambiguous adjacent quantifiers.
- Suggested rewrites are surfaced for the highest-severity ReDoS finding.
- Linter warns about suspicious ASCII ranges like `[A-z]` and alternation-like character classes such as `[error|failure]`.

### Changed
- Optimizer now respects Unicode mode when merging digit classes and cleans up unused multiline flags.
- Optimizer can reduce negated digit/word classes to `\D`/`\W` and applies safer auto-possessify rules.
- Linter now recognizes escaped literals and Unicode property classes when evaluating case-sensitive flags, and validates `\g{n}` backreferences and anchor assertions more consistently.
- Optimizer avoids creating new character-class ranges that start or end with `-` unless the range was explicit in the original pattern.
- Highlighter visitors now cover all AST nodes, preserve inline comment text, and emit richer HTML token classes with updated console colors.

### Fixed
- Lint output now properly escapes special characters in alternation branch literals to prevent display issues.

### Tests
- Expanded the ReDoS test suite with real-world patterns and mitigation cases.
- Added optimizer coverage for Unicode handling, negated classes, auto-possessify safety, and flag cleanup.
- Added a corpus-driven linter regression suite plus new unit coverage for Unicode escapes, property flags, anchor assertions, and `\g{}` backreferences.

## [1.0.2] - 2025-12-31

### Added
- Optimization option `minQuantifierCount` to control when repeats are compacted into `{n}`.

### Fixed
- Optimizer now preserves explicit fixed quantifiers (e.g., `\d{2}`) instead of expanding them into repeated literals.

## [1.0.1] - 2025-12-31

### Added
- Regression coverage for optimized character class ranges that include the delimiter.

### Fixed
- Character class compilation now preserves literal `[` where safe and escapes delimiters to prevent invalid ranges.
- Lexer tests updated to reflect literal `[` handling inside character classes.
- Linter anchor checks now reindex sliced sequences to satisfy static analysis.

## [1.0.0] - 2025-12-31

### Added
- **Parser & Lexer**: Full PCRE2-compliant recursive descent parser with lexer that produces a well-typed Abstract Syntax Tree (AST)
- **AST Nodes**: Complete node types including groups, alternations, quantifiers, lookarounds, character classes, subroutines, conditionals, callouts, verbs, and more
- **Regex Facade**: High-level API (`Regex::create()`) for all operations:
  - `parse()` - Parse regex into AST
  - `validate()` - Validate with detailed error codes, caret snippets, and optional runtime PCRE validation
  - `analyze()` - Comprehensive analysis including validation, ReDoS risk, optimizations, and explanations
  - `redos()` - Static ReDoS risk analysis returning `ReDoSAnalysis` with severity scoring and `isSafe()` check
  - `optimize()` - Pattern optimization with configurable rules
  - `explain()` - Human-readable regex explanations
  - `highlight()` - Syntax highlighting for console
  - `generate()` - Generate sample strings matching the pattern
  - `literals()` - Extract literal strings from patterns
- **AST Visitors**: Extensible visitor pattern for transformations:
  - `CompilerNodeVisitor` - Compile AST back to regex string
  - `OptimizerNodeVisitor` - Optimize patterns for performance
  - `ModernizerNodeVisitor` - Modernize legacy patterns
  - `LinterNodeVisitor` - Check patterns for issues
  - `ExplainNodeVisitor` / `HtmlExplainNodeVisitor` - Generate explanations
  - `ConsoleHighlighterVisitor` / `HtmlHighlighterVisitor` - Syntax highlighting
  - `LiteralExtractorNodeVisitor` - Extract literal strings
  - `SampleGeneratorNodeVisitor` - Generate matching samples
  - `ComplexityScoreNodeVisitor` - Calculate complexity scores
- **ReDoS Analyzer**: Static analysis engine for detecting Regular Expression Denial of Service risks with severity levels (CRITICAL, HIGH, MEDIUM, LOW) and actionable recommendations
- **CLI Tool**: Full-featured `vendor/bin/regex` command with subcommands:
  - `parse` - Parse and recompile patterns
  - `analyze` - Full pattern analysis
  - `debug` - Deep ReDoS analysis with heatmap
  - `diagram` - ASCII AST diagrams
  - `highlight` - Syntax highlighting
  - `validate` - Pattern validation
  - `lint` - Lint entire codebases for regex issues
  - `self-update` - PHAR self-update
- **CI/CD Integration**: Multiple output formats (console, JSON, GitHub, Checkstyle, JUnit)
- **Symfony Bridge**: `RegexParserBundle` with DI integration and console commands
- **PHPStan Integration**: Static analysis rule for detecting invalid regex patterns
- **Caching**: PSR-6 / PSR-16 compatible caching with `ArrayCache`, `FilesystemCache`, and stats
- **Configuration**: Comprehensive options for pattern length, lookbehind limits, recursion depth, PHP version targeting
- `explain` command to CLI tool for regex explanations
- `bin/phpunit` and `bin/infection` symlinks for easier tooling access
- `regex.phar` binary for standalone CLI usage

### Documentation
- **Quick Start Guide**: Getting started in 5 minutes
- **CLI Guide**: Complete command reference with examples
- **Regex Tutorial**: Comprehensive tutorial from basics to advanced
- **API Reference**: Complete PHP API documentation
- **ReDoS Guide**: Understanding and preventing ReDoS attacks
- **Cookbook**: Common patterns and recipes
- **Architecture Guide**: Internal design documentation
- **Extending Guide**: Custom visitors and integrations

### Fixed
- None (initial release)

### Changed
- Updated CI workflows to install PHPUnit tooling consistently across jobs
- Improved `.gitattributes` for cleaner distribution archives (excluded more dev files)
- Updated PHP-CS-Fixer config to preserve `@var` tags in `phpdoc_to_comment`
- Added `Fixtures/` exclusion in PHPLint configuration
- Completely rewrote README.md for better user onboarding and navigation

### Deprecated
- None

### Removed
- Deleted automated release workflow from GitHub Actions (manual releases for pre-versions)

### Breaking Changes
- None (initial release)
