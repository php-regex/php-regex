# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.0.0] - Unreleased

2.0 is a new major line; 1.x lives on the `1.x` branch. [UPGRADE-2.0.md](UPGRADE-2.0.md) walks through every change below that needs one on your side.

### Backward-incompatible changes

- Everything under **Removed**, and the changes listed in [UPGRADE-2.0.md](UPGRADE-2.0.md).
- Every class moved from `RegexParser\` to `PHPRegex\`, under the package it ships in (`Parser`, `Explain`, `Optimizer`, `Generator`, `Automata`, `Redos`, `Transpiler`, `Linter`, `Toolkit`, `Cli`, `LanguageServer`, `PHPStan`, `Symfony`, `Laravel`), and many are renamed for what they are: `RegexParser\Regex` is `PHPRegex\Toolkit\Regex`, `RegexParser\RegexParser` is `PHPRegex\Parser\RegexParser`, `NodeTraverser` is `NodeWalker`, `CompilerNodeVisitor` is `Parser\Printer\PatternPrinter`, `ReDoSAnalyzer` is `Redos\RedosAnalyzer`, `RegexProblem` is `Linter\Diagnostic`. The entries below name classes as 1.x did; [UPGRADE-2.0.md](UPGRADE-2.0.md) maps every one.
- Enum cases are in PascalCase, and `TokenType`, `GroupType` and `QuantifierType` drop their `T_` and `T_GROUP_` prefixes: `TokenType::T_LITERAL` is `TokenType::Literal`, `GroupType::T_GROUP_CAPTURING` is `GroupType::Capturing`, `ReDoSSeverity::HIGH` is `RedosSeverity::High`. Values are unchanged.
- The configuration takes the PHPRegex name: the Symfony bundle is `PHPRegexBundle`, configured under `php_regex`, with services and parameters named `php_regex.*`; the PHPStan parameter is `phpRegex`; the Laravel provider is `PHPRegexServiceProvider`, its config `config/php-regex.php` (key `php-regex`, publish tag `php-regex-config`), its container entries `php-regex.*`. A `config/regex-parser.php` left from 1.x is no longer read, and the provider raises a deprecation that says so.
- `validate()` lets an exception that is not the library's through instead of turning it into an invalid-pattern result.
- The aliases that kept the names of moved classes resolving are gone, with the file Composer loaded on every request to register them; the moved classes answer to their new names only. See [UPGRADE-2.0.md](UPGRADE-2.0.md).
- The PHPStan extension reports by default only a pattern the target PHP refuses while the PHP running PHPStan compiles it (`regex.invalidForTarget`): PHPStan's own `regexp.pattern` already reports the others. Lint rules and ReDoS analysis are opt-in through `rules.neon`; the legacy and dead parameters are gone, and ReDoS findings share one identifier, `regex.redos`. See [UPGRADE-2.0.md](UPGRADE-2.0.md).
- `ReDoSAnalyzer`, `LanguageSolver`, `RegexTranspiler` and `RegexAnalysisService` take a `RegexParser` instead of a `Regex`, and `RegexAnalysisService::getRegex()` is `getParser()`. `CharSet` and `CharSetAnalyzer` moved from `RegexParser\ReDoS` to `RegexParser\Analysis`. See [UPGRADE-2.0.md](UPGRADE-2.0.md).
- The automata solver has one entry point, `RegexParser\Automata\LanguageSolver`: `RegexSolver`, `RegexLanguageSolver`, `RegexSolverInterface` and `RegexSolverCompilerInterface` are gone. `intersectionEmpty()` is `intersection()`, `prepare()` is `compile()`, which returns the `Dfa`; `subsetOf()` and `equivalent()` keep their names, and the result classes are unchanged. The constructor takes the parser and a DFA cache, no longer a `RegularSubsetValidator` or a `DfaBuilder`. Every class of `RegexParser\Automata` but `LanguageSolver`, `SolverOptions`, `MatchMode`, the two algorithm enums, the three result classes, `Dfa`, `DfaCacheInterface` and `InMemoryDfaCache` is `@internal`. See [UPGRADE-2.0.md](UPGRADE-2.0.md).
- Error codes are an enum, `RegexParser\ErrorCode`: `RegexException::getErrorCode()` returns one and never `null`, and `ValidationResult::$errorCode` is `?ErrorCode`. Every syntax error has its own code where 1.x said `parser.error` or `lexer.error`, and every semantic error its own where it said `regex.semantic`; those three values are gone. `RegexException`, `LexerException`, `ParserException` and `SemanticErrorException` take the code as a required second argument; `ComplexityException`, `TranspileException` and `SampleGenerationException` take an optional `ErrorCode`. `regex.callout.invalid_type`, which no pattern could raise, is gone. The CLI's JSON output and lint problems still carry the string value. The language server publishes a pattern it cannot parse under that code instead of `regex.parse.error`. See [UPGRADE-2.0.md](UPGRADE-2.0.md).
- `Regex::optimize()` and `Optimizer::optimize()` take an `OptimizerOptions` value or an array keyed in snake_case, as `Regex::create()` is; an unknown key or a value of the wrong type throws `InvalidRegexOptionException` where it was ignored or cast. `autoPossessify` is `possessive` and `allowAlternationFactorization` is `factorize`, as every configuration already named them. `RegexLintRequest::$optimizations` and `RegexAnalysisService::suggestOptimizations()` take an `OptimizerOptions`. See [UPGRADE-2.0.md](UPGRADE-2.0.md).
- `RegexParser::clearCaches()` and `Regex::clearCaches()` replace `clearValidatorCaches()`, which is gone from both: they empty every process-wide cache of the library, not only the validator's. Every such cache is bounded as well. See [UPGRADE-2.0.md](UPGRADE-2.0.md).
- What the library throws says whose mistake it is. A mistake of the caller throws an exception implementing `RegexParserExceptionInterface`: `Regex::explain()` with an unknown format throws `InvalidRegexOptionException`; an unknown formatter, a failed lint worker and a report JSON cannot hold throw the new `RegexParser\Lint\LintException`; an unknown graph or highlight format and a self-update that cannot go on throw the new `RegexParser\Cli\CliException`; the sample generator throws `SampleGenerationException` where it threw `\LogicException` or `\RuntimeException`, and `generate()` throws it for a pattern the library judges invalid, before drawing any sample. A bug of the library, a token stream read past its bounds included, throws a plain `\LogicException`. See [UPGRADE-2.0.md](UPGRADE-2.0.md).
- `Lsp\Server::run()` returns the code to exit with instead of ending the process; `bin/regex-lsp` exits with it.
- Runtime validation (`runtime_pcre_validation`) reports PCRE's message without the "Compilation failed: " PHP puts before it, and a pattern that compiles but fails on the empty subject, as `/(?R)/`, is valid.
- The ReDoS confirmation reports the JIT setting it ran under, `0`, and the limits of its options, where it reported the ini values.
- `regex analyze` and `regex debug` refuse `--redos-no-jit`: the ReDoS confirmation always runs without JIT, so the option changed nothing. `ReDoSConfirmOptions::$disableJit` and `ReDoSConfirmation::$jitDisableRequested` (`jit_disable_requested` in JSON) are gone.
- `ValidationResult::$error` is the message alone: the caret snippet is no longer appended to it, and stays in `$caretSnippet`. The CLI and the Artisan commands print both; `regex:transpile --format=json` carries the snippet under `snippet`. See [UPGRADE-2.0.md](UPGRADE-2.0.md).
- Every offset counts from the first character of the pattern body, as PCRE2's do: a modifier error points at the modifier PHP refuses, counted past the body and its closing delimiter, and a pattern with no body has no offset. The caret snippet of a fault outside the body still shows the whole pattern, delimiters included, and the language server places its diagnostics on the character at fault. See [UPGRADE-2.0.md](UPGRADE-2.0.md).
- `CacheInterface` stores trees: `write(string $key, RegexNode $ast)` and `load(string $key): ?RegexNode`; `getTimestamp()` is gone. `CachePayloadDecoder`, `FilesystemCache::defaultDirectory()` and the file extension argument of `FilesystemCache` are gone; the default cache is `ArrayCache`. See [UPGRADE-2.0.md](UPGRADE-2.0.md).
- `regex.json` is read strictly: an unknown key or lint rule id, a value of the wrong kind, or a removed 1.x key (`rules`, `redosMode`, `redosThreshold`, `redosNoJit`, `optimizations`, `minSavings`, `checks.redos.noJit`) is an error naming its replacement, every error of both files is reported at once, and the lint command exits with code 2. `checks.redos`, `checks.optimizations` and `checks.lint` are objects only; `checks.redos.mode` loses `off`; only `enabled` switches a check on; a list in `regex.json` replaces the one in `regex.dist.json` whole. `regex.schema.json` is written from the definition the command validates against. See [UPGRADE-2.0.md](UPGRADE-2.0.md).
- The lint command judges for the project's lowest PHP: `--php-version`/`--pcre-version`, else `phpVersion`/`pcreVersion` in `regex.json`, else `composer.json` (`config.platform.php`, then the floor of `require.php`, clamped to PHP 8.2), else the running PHP. The JSON report names it under `target`; the other formats print it on stderr. See [UPGRADE-2.0.md](UPGRADE-2.0.md).
- A ReDoS threshold is read the same way everywhere, by the new `ReDoSSeverity::fromConfig()`: `low`, `medium`, `high` or `critical`, in any case. `safe` and `unknown` are refused, as is any other word, with the value quoted: by `--redos-threshold` of the `lint`, `analyze` and `debug` commands and of `regex:analyze` and `regex:security`, by the Symfony and Laravel configurations, by the PHPStan rule (even with ReDoS off), and by `RegexAnalysisService`, which throws `InvalidRegexOptionException` where it fell back to `high`. See [UPGRADE-2.0.md](UPGRADE-2.0.md).
- Symfony bundle: `runtime_pcre_validation` defaults to `false` instead of `%kernel.debug%`; `exclude_paths` is `exclude`; `analysis.ignore_patterns` is merged into `redos.ignored_patterns`; `analysis.redos_threshold`, never read, is gone. A 1.x key stops the container compile with the key that replaces it. New `php_version` and `pcre_version` set the target of `regex:lint`, which otherwise reads `composer.json` in `%kernel.project_dir%`, like the standalone lint command; its JSON report names the target, and it never uses `runtime_pcre_validation`. The `regex_parser.regex` service keeps judging for the running PHP. See [the Symfony guide](docs/guides/symfony.md).
- Laravel: `config/regex-parser.php` follows the same names (`exclude`, `redos.ignored_patterns`, no `analysis.redos_threshold`), `runtime_pcre_validation` defaults to `false` instead of `APP_DEBUG`, and `php_version` / `pcre_version` set the target of `regex:lint`, which otherwise reads `composer.json` at `base_path()`. A published file lacking keys takes the package default for each one, inside sections too; `regex:lint` warns about a 1.x key it still finds. See [the Laravel guide](docs/guides/laravel.md).
- The language server judges for the workspace's target, resolved at `initialize`: `initializationOptions.phpVersion` / `pcreVersion`, else `regex.json`, else `composer.json` of the first workspace folder, else the running PHP; it logs the target with `window/logMessage`. See [the language server guide](docs/guides/lsp.md#target-php-and-pcre2).
- The lint command exits with code 2 on a configuration or usage error (an unknown `--format` included), prints errors on stderr, or as `{"error": ...}` on stdout under `--format=json`, and keeps its progress and status lines out of a JSON report. `--redos-mode=off` (use `--no-redos`) and `--redos-no-jit` are refused; the ReDoS confirmation always runs without JIT.
- Every CLI command exits with the lint command's codes: 0 when it found nothing wrong, 1 when the patterns or files it judged have a problem, 2 when the command line or the configuration cannot be used. A usage error (an unknown command or option, a missing pattern or option value, an unknown `--format` or `--target`, an invalid `--php-version`, a removed option, an unreadable `--input-file` or `regex.json`) exits with 2 where it exited with 1, and `regex` without a command with 2. `analyze` and `parse --validate` exit with 1 on an invalid pattern, `debug` on a pattern that does not parse, `analyze` and `debug` on a ReDoS risk `--redos-mode=confirmed` confirms at high severity or more, and `redos` on a pattern PHP refuses; all four exited with 0. `debug` stops on a `regex.json` it cannot read instead of ignoring it. `parse`, `validate`, `explain`, `diagram`, `highlight` and `graph` refuse an option they do not know and read options before the pattern. The Symfony and Laravel console commands exit with 2 (`Command::INVALID`) where an option or the configuration cannot be used. See [UPGRADE-2.0.md](UPGRADE-2.0.md).
- ReDoS analysis is now disabled by default for better performance. Enable explicitly via:
  - CLI: `--redos` flag or `checks.redos.enabled: true` in `regex.json`
  - PHPStan: `reportRedos: true` in rule configuration
  - Symfony: `regex_parser.redos.enabled: true` in bundle configuration
- `unicode.shorthandWithoutU` lint rule is now disabled by default to reduce noise. Most PHP codebases intentionally use ASCII-only matching. Enable via:
  - CLI: `--enable-rule=unicode.shorthandWithoutU`
  - Config: `checks.lint.rules.unicode.shorthandWithoutU: true` in `regex.json`
- Lint identifiers now use camelCase instead of snake_case for PHPStan compatibility:
  - `regex.lint.unicode.shorthand_without_u` → `regex.lint.unicode.shorthandWithoutU`
  - `regex.lint.alternation.duplicate_disjunction` → `regex.lint.alternation.duplicateDisjunction`
  - `regex.lint.charclass.duplicate_chars` → `regex.lint.charclass.duplicateChars`
  - `regex.lint.charclass.suspicious_range` → `regex.lint.charclass.suspiciousRange`
  - `regex.lint.charclass.suspicious_pipe` → `regex.lint.charclass.suspiciousPipe`

### Security

- The default cache wrote parsed trees as PHP files under the shared system temp directory and `include`d them: another local user could plant a file there and have it run, or change a verdict. Nothing is written to disk by default any more (the latest 1024 trees are kept in memory), `FilesystemCache` only runs where a directory is named, stores data read back with a class allowlist instead of code, creates its directories for their owner only (`0700`, files `0600`) without touching the process umask, and ignores a directory another user owns or others can write to.

### Added

- A Rector set, `Resources/rector/upgrade-2.0.php` in `php-regex/toolkit`, that moves code written for 1.3 to the 2.0 names: every class, enum case and renamed method. [UPGRADE-2.0.md](UPGRADE-2.0.md) carries the same map as tables.
- `--php-version=runtime`, `phpVersion: "runtime"` in `regex.json` and `Regex::create(['php_version' => 'runtime'])` name the PHP running the command and the PCRE2 it links, as PHPStan's `phpVersion` does.
- `AbstractTraversingVisitor`: a base visitor whose every `visitX()` method visits the node's children and returns `null`. Override the node types you care about and call the parent method to keep descending; a node type added in a minor release is walked through, where `AbstractNodeVisitor` returns without visiting what it holds. See [the visitors reference](docs/visitors/README.md#which-base-to-extend).
- `NodeInterface::getChildren()`, `NodeTraverser::walk()` and `NodeFinder`: a tree is walked, and nodes found in it, without a visitor for each kind of node. The walk keeps its own stack, so a pattern nested as deep as the parser allows is walked too. See [the visitors reference](docs/visitors/README.md#walking-a-tree-without-a-visitor).
- `PcreFeature` and `PcreTarget::supports()`: each behaviour PCRE2 changed, named with the release it arrived in, in one table the rules ask instead of release strings written by hand; [the PCRE page](docs/concepts/pcre.md#what-changed-in-which-release) lists them. `pcreAtLeast()` refuses a release spelled short, as `'10.4'`, which read as 10.04 and held for every target, with an `InvalidRegexOptionException`.
- `Optimizer\Optimizer`: the optimization `Regex::optimize()` runs, on any `RegexParser`; the linter uses it directly.
- `RegexParser`: reading and judging a pattern — `parse()`, `parseTolerant()`, `validate()`, `parsePattern()`, `tokenize()`, the cache and the target — in one class every other part of the library uses, with the options `Regex::create()` takes. `Regex::parser()` gives the one a facade uses. `RegexParser::CACHE_VERSION` fingerprints the extended-class reader, the validator the parser runs, the target and group numbering too.
- Substring scan assertions, PCRE2 10.45: `(*scan_substring:(1)abc)` and `(*scs:(1,<name>)abc)` are read for a target on 10.45 or later, as a `GroupNode` of the new type `GroupType::T_GROUP_SCAN_SUBSTRING` whose `scannedGroups` lists the groups; each is checked, lengths and samples treat the assertion as matching nothing of the subject, and the transpilers refuse it. Before 10.45 the name is unknown, as before. All 81 cases of the PCRE2 suite agree with pcre2test 10.44, 10.45 and 10.49.
- Perl extended classes, PCRE2 10.45: `(?[ \p{L} - [aeiou] ])` is read for a target on 10.45 or later, as an `ExtendedCharClassNode` holding a tree of `ClassSetOperationNode`s — `!` complement, `&` intersection, then `+` or `|` union, `-` difference and `^` symmetric difference left to right. It matches one character; samples and test cases are asked of the running engine when it reads the syntax, the ReDoS analysis computes the set, and the transpilers refuse it. More than 1024 operations in one class are refused as too deep, as nesting past the recursion limit is. Before 10.45 it is refused as before. All 105 cases of the PCRE2 suite agree with pcre2test 10.44, 10.45 and 10.49, offsets included.
- Calls that return capture groups, PCRE2 10.47: `(?1(2,<name>))`, `(?R(1))`, `(?&name('id'))` and `(?P>name(-1))` are read for a target on 10.47 or later, with the list in `SubroutineNode::$returnedGroups`, written back by the compiler and the highlighters, and each group checked, where PCRE refuses a missing one. Before 10.47 they are refused as before.
- `pcre_version`, next to `php_version`: patterns are judged for one PHP version and one PCRE2 release, `RegexParser\PcreTarget`, resolved once and read by the lexer, the parser, the validator and the cache key. With neither option, the running PHP and the PCRE2 it links; `php_version` alone, that PHP with the PCRE2 it bundles; `php_version: 8.4, pcre_version: 10.42` judges for the PHP 8.4 packages of Ubuntu 24.04. `Regex::target()` says which. The command line takes `--pcre-version`.
- The PHPStan extension judges patterns for PHPStan's `phpVersion`, with new `regexParser.phpVersion` (`runtime` for the PHP running PHPStan) and `regexParser.pcreVersion` parameters.
- PCRE2's official test suite (10.48, pinned) is replayed against `Regex::validate()` at compile level, under PHP's compile options. The compile verdict, the error offset and the patterns accepted although PHP refuses them are counted and published, with a fix plan, on the [PCRE2 conformance page](docs/reference/pcre2-conformance.md). Cases whose compile verdict differs on PCRE2 10.40, the engine of the oldest supported PHP, are set aside, and CI rebuilds both engines to re-check every recorded outcome. The test suite fails when a count moves without the page following.
- `regex lint` reads patterns from regex wrappers, not only from the native `preg_*` functions. `composer/pcre` (`Preg::`, `Regex::`) is recognised out of the box; `nette-utils`, `spatie-regex` and `laravel-str` are enabled with `--interop` or `extraction.interop`. A codebase that had migrated away from `preg_*` reported no patterns at all before.
- Project helpers carrying a pattern are declared with `--pattern-function` or `extraction.functions`, as `function` or `Some\Class::method`, with `#<index>` when the pattern is not the first argument and `#<index>:keys` when that argument is an array whose keys hold the patterns.
- Alphabetic assertion verbs (PCRE2 10.32+): `(*pla:...)`, `(*positive_lookahead:...)`, `(*nla:...)`, `(*plb:...)`, `(*nlb:...)`, `(*negative_lookbehind:...)`, `(*atomic:...)` parse as their classic lookaround / atomic group equivalents.
- Script run content is now parsed into the AST: `(*sr:(a+)+b)` is analyzed like any sub-pattern (its catastrophic backtracking is detected by the ReDoS engine, its length/complexity contribute to metrics).
- `ScriptRunNode::$atomic`: `true` for `(*atomic_script_run:...)` and `(*asr:...)`, whose body is atomic.
- `Regex::parseTolerant()` — explicit tolerant parsing without the `parse($regex, true)` bool-flag union return.
- `OutputFormat` enum accepted by `Regex::explain()` and `Regex::highlight()` (strings still work).
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
- Recompiling a parsed AST gives the pattern back byte for byte, spelling included: optional escapes (`\{` or `{`, `[a-z\-]` or `[a-z-]`, `\]` outside a class), the backreference syntax that was used (`(?P=name)` is no longer rewritten to `\k<name>`), the way a code point was written (`\a` stays `\a`, `«` stays `«`), and assertion conditions (`(?(?<!x)y)` is no longer re-parenthesized). Text inside `\Q...\E` is still escaped, since the quoting is not kept. Comparing patterns — what the optimizer does to decide whether a pattern changed — still runs on the normalized form, so optimization suggestions are unaffected.
- Recompiling an AST gives the pattern back byte for byte under `/x`: the whitespace the modifier makes ignorable is read back from the source, so `/  a  b  /x` and documented multi-line patterns keep their layout instead of collapsing to `/ab/x`. `RegexNode` carries the body it was parsed from for that purpose; an AST built by hand, a pretty-printed compile and normalized output are unaffected.
- `/x` can be turned on from inside the pattern: `(?x)`, `(?x:...)`, `(?-x)` and `(?^x)` now drive extended mode in the lexer, the parser and the compiler, with PCRE's scoping — a bare `(?x)` holds until the end of the enclosing group and crosses `|`, while `(?x:...)` stops at its own `)`. Comments and ignorable whitespace were previously only recognised through the pattern-level `x` modifier.

### Changed

- `Regex::generate()` throws `SampleGenerationException` when no sample the running engine matches was found, where it returned its last attempt, a string that does not match. See [UPGRADE-2.0.md](UPGRADE-2.0.md).
- `max_lookbehind_length` (default 255) now only limits variable-length lookbehinds, as PCRE2's own `max_varlookbehind` does: `(?<=a{256})` validates, `(?<=a{0,256})` does not. A fixed-length lookbehind is limited by PCRE's ceiling of 65535 characters, which no option changes. Each top-level branch of a lookbehind is measured on its own.
- `Regex::CACHE_VERSION` is a fingerprint of the code that builds an AST rather than a number raised by hand: `task cache-version` writes it, `task lint` runs that, and the test suite fails while the constant and the code disagree. Cached ASTs written by an earlier state of `main` are ignored.
- `Regex::analyze()` only reports what the pattern itself causes: a failure that is not a RegexParser exception now surfaces instead of being written into the report as a pattern error.
- A cache that throws while reading is treated as a cache miss, the way a cache that throws while writing already was.
- Symfony bundle `redos.enabled` configuration option (defaults to `false`).
- Lint configuration `checks` section with nested ReDoS and optimization settings (schema + defaults).
- PHPStan extension support for `checks` configuration overrides.
- Lint check for backreference-as-octal in character classes: `[^\1]` is treated as octal `\x01`, not a backreference — warns when a corresponding capturing group exists.
- Lint check for literal metacharacters in character classes: `[\w+]` where `+`, `*`, `?` are literals, not quantifiers. Skips negated classes and multi-element sets (3+ other elements) to avoid false positives on URI schemes, Base64, etc.
- Lint check for `(.|\n)` anti-pattern: suggests using the `s` (DOTALL) flag or `[\s\S]` instead.
- Lint check for quantified capturing groups: `(?<name>\d+)+` warns that only the last iteration's capture is retained. Named groups report as Warning; anonymous groups report as Info.
- `CharSet::contains()` now uses binary search over sorted ranges, reducing lookup from O(n) to O(log n) on the determinization hot path.
- `SecurityAccessControlAnalyzer` now uses `MatchMode::FULL` instead of `MatchMode::PARTIAL`, avoiding redundant NFA self-loops since patterns are already manually wrapped with `.*` by `normalizeSearchPattern()`.
- `RegexSolver::findExample()` product-automaton BFS now uses integer pair keys instead of string concatenation, reducing allocation overhead during DFA intersection/subset/equivalence checks.
- **Unified quantifier parsing** via a new `QuantifierBounds` value object. Previously ~20 inline re-implementations disagreed: `{,5}` meant "0 to 5" to the validator but "exactly once" to the length/lint analyzers and "never" to the sample generator; `{ 2 }` (extended mode) was only understood by the validator. All visitors (length ranges, ReDoS, linter, sample/test-case generators, optimizer, explain, railroad) now share one canonical parser.
- **Stricter (PCRE-conformant) character class ranges**: a character type, POSIX class, or Unicode property can no longer be a range endpoint. Patterns like `[\w-_]`, `[\d-z]`, `[a-\d]` — which PCRE rejects at compile time — now fail to parse instead of being silently re-interpreted with a literal hyphen. Literal hyphens at class edges (`[-a]`, `[\d-]`) are unaffected.

### Deprecated

- `Regex::new()` (identical to `Regex::create()`); `ValidationResult::isValid()` and `getErrorMessage()` methods in favor of the public `$isValid` / `$error` properties.

### Removed

- `RegexParser\Node\ClassOperationNode`, `ClassOperationType`, `TokenType::T_CLASS_INTERSECTION`, `TokenType::T_CLASS_SUBTRACTION` and `NodeVisitorInterface::visitClassOperation()` with every implementation: PHP reads `&&` and `--` inside a class as members and ranges, so the parser never built the node and the lexer never produced the tokens. See [UPGRADE-2.0.md](UPGRADE-2.0.md).
- `RegexParser\Node\UnicodeNode` and `NodeVisitorInterface::visitUnicode()`: no parser path ever produced the node — `\x{...}` and `\u{...}` escapes become a `CharLiteralNode` — so every visitor carried a method that could not be called. See [UPGRADE-2.0.md](UPGRADE-2.0.md).
- `RegexParser\ReDoS\ReDoSAnalyzerInterface`: implemented by nothing, `ReDoSAnalyzer` included.
- The PHP version id taken by `Lexer`, `Parser`, `ValidatorNodeVisitor`, `Regex::tokenize()`, `Regex::cacheSeed()`, `RegexPattern::fromDelimited()` and `PatternParser::extractPatternAndFlags()`, and `RegexOptions::$phpVersionId`/`$phpVersionExplicit`: each takes or holds a `PcreTarget` instead. `Lexer::readsWideRepeatCounts()` is gone. See [UPGRADE-2.0.md](UPGRADE-2.0.md).
- `(*LIMIT_LOOKBEHIND=n)` is no longer read as a per-pattern override of `max_lookbehind_length`: PHP refuses the verb, so a pattern using it is now reported invalid (`regex.verb.invalid`). Raise `max_lookbehind_length` instead.
- Dead `HelpfulExceptionTrait` (~430 lines, referenced nowhere).
- `Regex::new()`, a second name for `Regex::create()`; the `$tolerant` argument of `Regex::parse()`, which now returns a `RegexNode` only (`parseTolerant()` returns the errors); and `Regex::cacheSeed()`. `RegexParser::cacheSeed()` is internal. See [UPGRADE-2.0.md](UPGRADE-2.0.md).

### Fixed

- Laravel: `redos.enabled` switches the ReDoS analysis of `regex:lint` on. The service provider handed it to another setting, so the analysis never ran.
- Laravel: the `automata` settings are the defaults of `regex:compare`, which ignored them.
- Laravel: an unknown ReDoS threshold no longer breaks `php artisan list` and every other command: the lint and analysis services are resolved when a command uses them.
- A group name in quotes was taken after `(?<`, `(?P<`, `(?P=`, `(?(<` and `(?(R&`, in single or double quotes, and `(?(R-1)` was taken as a recursion condition: PCRE refuses both on every release, and so does the library now, at the quote as a missing name and at the sign as a name left open. Only `(?'name'` and `(?('name')` take a name in quotes.
- A few refused patterns got another code or offset than PCRE's: a condition `(?(+n)` that goes past group 65535 with the groups before it, a comment where the condition starts, as `(?(?#c)a)`, an unknown or misplaced alphabetic name there, as `(?(*xyz:a)`, `(?(*pla)` or `(?(*atomic:a` left open, an alphabetic name no `:` follows, as `(*pla!`, on PCRE2 10.47 and later, `(?(VERSION=10 )` in a pattern that goes wrong further on, and `(?xx)[ \E` before 10.45. `(?(?#c)(?=a)b)`, which PCRE takes, is no longer refused.
- A few conditions got another code or offset than PCRE's. Under `/u`, a condition starting with a digit of another script, as `(?(٣)a)`, is a name starting with a digit, and a version condition stopped by a character of more than one byte, as `(?(VERSIONé)a)`, is refused past the whole character on PCRE2 10.47 and later. A conditional holding a third branch is refused on the name its condition tests before 10.47, as in `(?(R)a|b|c)`, and, on every release, one character past where the group opens for each character of a group number past its first, as in `(?(+1)a|b|c)` or `(?(10)a|b|c)`.
- A condition PCRE reads as a name was judged as something else. `R` followed by more than digits is a name: `(?(Rx)a)` and `(?(R1a)a)` refer to the groups `Rx` and `R1a`, and are accepted when the pattern has them, where they were refused as a condition left open. A name something other than `)` ends, as `(?(R!)`, `(?(ab!)`, `(?(a-1)` or `(?(DEFINE!)`, is a name left open, and a name past the length limit is too long, as PCRE reports it. `VERSION` followed by a letter, as `(?(VERSIONx)a)`, is a version condition PCRE refuses even when a group bears that name, and a space before `VERSION`, as in `(?( VERSION=10)a)`, which was accepted, is refused where the name was expected. A group that is no assertion after the callout of a condition, as in `(?(?C1)(?:a))`, is refused where it starts on PCRE2 10.47 and later too.
- A recursion condition on group 0, as `(?(R0)a|b)` or `(?(R00)a|b)`, was refused as a recursion to a missing group: group 0 is the whole pattern, so PCRE reads it as `(?(R)`, and so does the library now.
- `parseTolerant()` reported the error the parser stopped on, where `validate()` reports the one PCRE meets first, as the reversed range of `/[z-a](/`: both report the same error now.
- The parentheses of an extended class `(?[...])` did not count against `max_recursion_depth`: they do now, as groups do.
- The console output of `regex lint` and of the Symfony `regex:lint` command lost the caret line under an invalid pattern once the message stopped carrying it: the character at fault is shown again, from the snippet the validation carries apart.
- Judged for a PCRE2 release before 10.45 or 10.47, a few refused patterns got the code the later releases report: `(?[` is no extended class before 10.45 and is refused as an unknown `(?` group, `(*pla` and an unknown `(*scs:` are unknown names, `[\E` left open is a backslash at the end of the pattern, a range ending in `\N` is an invalid range, and `(?(VERSION=10z)` is a condition left open before 10.47. The code now follows the judged release.
- The Laravel bridge dropped every optimization setting of `config/regex-parser.php` but `digits`, `word` and `ranges`: `canonicalize_char_classes`, `possessive`, `factorize` and `min_quantifier_count` never reached the optimizer.
- `regex redos` left `pcre.jit`, the backtrack and recursion limits and the time limit it was given set in the process after it ran, and silenced the warnings of the pattern it measured. It puts them back on every way out, and captures the warnings.
- A pattern the library was given could run under the JIT, which crashes PHP on some pattern and subject pairs in PCRE2 10.40 to 10.49: runtime validation, the ReDoS confirmation, the property and class samples of the generator, the test case generator, the PHPStan extension, the Symfony route suggestions and `regex:compare` ran it with `preg_*` directly. Every one of them goes through `RegexParser\Engine\PcreEngine`, which runs the pattern without the JIT, captures its warnings with a handler instead of silencing them, and reports its errors at the offset the pattern as written has.
- The Symfony route conflict suggestions ran a route's pattern with `preg_match()` unguarded: a pattern PHP refuses raised a warning.
- The complexity score and the sample generator kept process-wide caches that grew with every new quantifier and every new spelling of a property name: a long-running process parsing patterns by the thousand grew without end. Both keep 1000 entries now, and drop the older half when full.
- The lint results depended on whether `intl` was installed: without it, `/é/iu` was told its `i` flag was useless. With it, `/Ⓐ/iu` and `/Ⅻ/iu` were, though PCRE folds their case. Case is now read from `mbstring` as PCRE's Unicode case folding reads it, and without UTF mode as ASCII only, as PCRE's default tables do. `PatternInfo::$intlAvailable` is gone.
- A delimiter, a group name or a number was read with `ctype_*`, which answers for the process locale: under a Latin-1 `LC_CTYPE`, `\xE4` counted as a letter. They are read in ASCII, as PCRE reads them, and `ext-ctype` is no longer used. `ext-mbstring`, which the library already called, is declared in `composer.json`.
- A pattern that turns UTF mode on with `(*UTF)` or `(*UTF8)` instead of `u` was accepted with bytes that are no UTF-8, which PCRE refuses: `/(*UTF)\xff/` is now refused as `regex.encoding.invalid_utf8`. That error is reported at the first byte that starts no UTF-8 character, as PCRE does, where it was reported at offset 0.
- What a script run `(*sr:...)` holds was invisible to the linter, the metrics, the explanations and literal extraction: an issue inside it went unreported, and it was explained as its raw text. They now read inside it, as a group.
- The array, PSR-6 and PSR-16 caches never gave back a tree for a pattern holding a comma, as every `{n,m}` does, and counted a hit all the same: the stored script was cut at its first comma. Every cache now stores the tree itself, and the hits count trees given back.
- The Laravel bridge wrapped a cache store, a PSR-16 cache, in the PSR-6 adapter, and failed on the first pattern once `regex-parser.cache.store` was set.
- A cached tree altered to hold an object of another class is a cache miss, not a `TypeError`.
- `generate()` never gave a sample for a pattern delimited by `_`, `*` or `)`: the `(*NO_JIT)` it puts after the opening delimiter held that delimiter and ended the pattern. The pattern moves to another delimiter for the check.
- `explain()` explained a quantified group twice to lay it out, doubling its work at every level of nesting: a pattern of a hundred bytes nested twenty deep took a minute, in `analyze()` and in editor hovers too. It now takes time in proportion to the pattern, with the same output.
- `validate()` reported any failure of the library itself, a `TypeError` or an exhausted stack, as a syntax error in the pattern. Only the library's own exceptions are a verdict now; anything else surfaces.
- `PcreTarget` reads a PCRE2 release without the engine: with a very low `pcre.backtrack_limit`, `Regex::create()` refused every release, the running one included, and blamed the `pcre_version` option for it. The library still needs the limit near its default to read patterns (see [PCRE](docs/concepts/pcre.md#which-php-and-which-pcre2-judge-a-pattern)).
- Error offsets for PCRE2 up to 10.46 in two places: a version condition whose minor has three digits or more, then text, as `(?(VERSION>=10.999x)`, stops at the third digit (a two-digit minor there); and a range from a type, a POSIX class or a property, as `[\d-\X]`, is refused on its hyphen up to 10.44, before its end is read. On every release, a range ending in an escape a class does not take, as `[\d-\j]`, is reported at the escape.
- `generate()` checked its samples with the JIT, which in PCRE2 10.40 to 10.49 crashes PHP on some pattern and subject pairs, as `(?|(\*)(*napla:(.+))|()(?=\S_(\2?)))+_` with `*a_` (see [PCRE](docs/concepts/pcre.md#a-known-jit-crash)). Samples are now checked by the interpreter, one the engine gives up on past a limit, or on an error it meets matching, is not given as checked, thirty-two samples are tried instead of eight, and a sample past 1 MiB, which references repeated in a count can reach, is not built.
- An alphabetic lookbehind PCRE cannot take, as `(*plb:a+)` or `(*naplb:\X)`, is reported at the last letter of its name, where PCRE reports it, not at its `(`. Before PCRE2 10.43, `\N{ U+41}` is refused past the `\N`, as a name, and `\C` under `u` in a lookbehind at that lookbehind for a PHP that compiles `\C`.
- `generate()` gave a byte that is no UTF-8 character for a range between two multibyte characters, as `[Ā-Ą]` under `u`; it gives a code point of the range. POSIX classes, negated ones included (`[[:^ascii:]]`), are sampled from what the engine matches; a condition on a lookaround tries both branches; and a lookaround the surrounding text already satisfies, as in `[[:<:]]red[[:>:]]`, leaves that text as it is. Groups are numbered as PCRE numbers them, through branch resets and script runs, and a name several groups share refers to the first of them that captured. A capture inside a lookahead holds for what follows it, names past ASCII are resolved, and an extended class under `i` is sampled as the engine reads it caseless. A group a substring scan reads captures text that starts with what the scan's body matches. Lookaheads in a row, as in password rules, are drawn again until one text satisfies them all, within a bound. A version condition takes the branch the running PCRE2 takes (a one-digit minor counts tens before 10.47, `10.5` being `10.50` there), and a call to a name several groups share runs the first of them. Nothing after a `(*ACCEPT)` is generated, up to the call or the assertion it ends. An alternative that ends the subject, as the `$` of `(\d+(?:\s|$))` in groups in a row, is not drawn where the pattern must still add text. A sample the engine gave up on is not tried again with text around it, which took seconds on a pattern past the backtrack limit, eight such samples end the search, and the exception says how many samples the engine gave up on and why. Something that takes no text, repeated, as `[[:<:]]+`, is generated once and adds no character of its own, and optional, adds nothing; a lookahead that calls a group defined after it is fitted to the text that follows. The `s` and `m` flags, set on the pattern or inside it, as `(?s)` or `(?m:...)`, hold up to the end of the group that sets them: a dot takes a newline under `s`, and under `m` a `$` is no end of the subject. A lookbehind in a group reads the text before the group, which may already satisfy it, and `(?(R)`, `(?(R1)` and `(?(R&name)` take the branch of the call being generated.
- Before PCRE2 10.45, `\8` or `\9` followed by eight digits or more, as in `a\800000000b`, is no reference: PCRE2 stops reading the number and takes the digit and the rest as text. It was refused as a reference to a missing group for those targets.
- The nodes in the body of `(*pla:...)`, `(*sr:...)` and the other alphabetic assertions counted their offsets from the start of the body, not of the pattern, so anything that reports a position there — lint, highlighting, the written-back text of a node — pointed at the wrong place. They now count from the pattern.
- The HTML highlighter wrote `(?<`, `(?<=`, `(?<!`, `(?>`, `(?&` and `(?P>` without escaping `<`, `>` and `&`, so a browser read `(?<name>` as a tag.
- `\NN` of 10 or more, starting with 1 to 7, was read as a back reference in the tree and judged against the pattern's total group count. PCRE reads it as an octal escape unless that many groups opened *before* it: `\11` followed by eleven groups is a tab, `\1000*` is `@` then `0*`. The parser now builds a `CharLiteralNode` (`OCTAL_LEGACY`) for it, so lengths, literal prefixes, samples and ReDoS analysis see a character; the compiled form spells it `\o{101}` where groups written before it would turn `\101` into a reference. The suspicious-escape lint no longer reports an octal escape past `\377` in UTF mode, where it is a code point.
- `php_version` naming the running PHP mixed two engines: the parser judged with the PCRE2 that PHP bundles, the validator with the one it links, so `/+/` was reported at 0 and `/[[:foo:]]/` at 8. One target now judges both.
- The cache key did not name the PCRE2 release, though the tree depends on it (`{,2}` repeats from 10.43): a cache shared across engines, or kept across a PCRE2 upgrade, could serve a tree read for another one. It now names the PHP minor version and the PCRE2 release, so PHP 8.4.1 and 8.4.26 share their trees.
- The `r` modifier was accepted for PHP 8.4 whatever PCRE2 it links; PHP reads it only when built against PCRE2 10.43 or later.
- The `n` modifier was accepted for PHP 8.1 and older, which refuse it ("Unknown modifier 'n'").
- The automata solver's DFA cache did not name the target: two solvers sharing it for different PCRE2 releases could read `{,2}` as the other one does.
- A count past 65535, as in `a{655360}`, is refused past the whole number from PCRE2 10.45, not 10.47; the version condition reads `10.100` whole from 10.47; and the Beria Erfe, Sidetic, Tai Yo and Tolong Siki scripts arrived in 10.48, not 10.45.
- Without a `php_version`, a pattern was judged as if PHP 8.4 always ran PCRE2 10.43 or newer. The PHP 8.4 packages of Ubuntu 24.04, among others, link its PCRE2 10.42: there `(?aD)`, `(?r)`, `\x{ 41 }`, `\N{U+ }` and variable-length lookbehinds were accepted though PHP refuses them, and `{,2}` refused though PHP reads it as text. The linked PCRE2 now decides, whatever the PHP version.
- PHP 8.5 compiles without `PCRE2_EXTRA_ALLOW_LOOKAROUND_BSK`, so `\K` inside a lookaround is refused there; it was accepted for every version. It is now reported as `regex.keep.in_lookaround` for PHP 8.5 and newer, at the end of the pattern as PHP reports it.
- `\N{name}` with a character name, as in `\N{LATIN SMALL LETTER A}`, was accepted when the name was known; PCRE2 supports no character names. It is now refused with `regex.escape.unsupported`, as unknown names already were.
- `\N{U+...}` outside UTF mode is refused for the missing UTF mode before its padding is judged, as every PCRE2 release does.
- PHP 8.5 deprecation notices from `ord()` in the explanation and sample generation of patterns are gone.
- `(?i-i)` and other settings that turn a modifier on and off at once were refused as conflicting; PHP compiles them, the turn-off winning, and so does the parser now.
- Under `x`, U+0085, U+200E, U+200F, U+2028 and U+2029 (byte 0x85 without `u`) were read as literal characters; PCRE skips them as whitespace, and so does the parser now.
- A string callout or a verb name holding a parenthesis inside `(*pla:...)`, as in `(*pla:(?C"a)b"))` or `(*pla:(*MARK:a(b))`, broke the body of the assertion.
- A conditional with more than two branches, a (DEFINE) with more than one, and a forward relative reference to a missing group were reported before errors PCRE meets first; they now wait, as missing groups do.
- Optimizing could drop a group a quantifier needs: `a(?:)*` became `a*`, `(?:^)*a` the invalid `^*a`, `(?:)?-+` the invalid `?-+`. An empty group, an anchor or an assertion under a quantifier keeps its group.
- Compiling a pattern that opens with `(*LIMIT_MATCH=n)(*UTF)` wrote its non-ASCII characters as separate bytes.
- Under `x`, a `#` comment holding `(` or `[` inside the body of `(*pla:...)` broke that body; it is now read as a comment. A `(` that `\c` takes as its character no longer opens a group there either, and a body that never closes is reported as a missing `)` instead of exhausting PHP's backtracking limit. The pretty-printed and compiled forms keep an empty scoped group `(?i:)` apart from the setting `(?i)`.
- A pattern PCRE refuses as too large to compile was reported valid: a group repeated with a count is compiled once per repetition, so `(?:a){8192}` or `((?:a){100}){100}` passes the 64 KiB PCRE takes. The validator now reports `regex.pattern.too_large` when the smallest size PCRE could compile the pattern to already passes that limit; below it, the pattern is left to PCRE.
- `\N{U+...}` above U+10FFFF, as in `\N{U+110000}`, was accepted; PHP refuses it as it refuses `\x{110000}`, with `regex.unicode.out_of_range` at the closing brace.
- `\g-` or `\g+` with no digit after the sign was reported as a relative reference to zero, one character too far; it is now reported as PCRE reports it, a `\g` followed by no reference, on the sign.
- An escaped letter inside a group name, as in `(?<a\y>...)`, was read as the letter and the name accepted as `ay`; PHP refuses any escape in a name, and so does the parser now.
- A pattern with an escape PHP refuses and a structural error further on (`\y(`, `\L\c`, `[a\y`, `[\R(`, `\Pf(`) was reported at the structural error, a missing `)` or `]`; PCRE reads the pattern in one pass and stops on the escape first. Likewise a class left open or a lone `\c` at the end hid a syntax error before it (`+[^`, `)[`). `validate()` now reports the error PCRE meets first; `parse()` is unchanged.
- The pretty-printed form of a pattern showed a modifier setting such as `(?i)` as an empty scoped group, `(?i:)`, which applies to nothing; it now shows `(?i)`.
- `\k` inside a class (`[\k]`, `[a\k<n>]`) was accepted for every target; PCRE2 reads it as the letter only from 10.45, which no PHP release bundles yet, and refuses it before. It is now refused unless the running PHP links PCRE2 10.45 or newer.
- The body of `(*pla:...)`, `(?*...)` and the other alphabetic assertions was never checked for escapes PHP refuses (`(*pla:\y)`, `(*pla:\U)`, `(*pla:[\B])`), and the body of `(*sr:...)` was not checked at all (`(*sr:a{2,1})`); both are now checked like the rest of the pattern. An error found inside such a body is reported at its offset in the whole pattern, not in the body.
- `\b{g}` and `\B{g}` were read as grapheme-boundary assertions, which PCRE2 does not have: it reads a word boundary followed by the text `{g}`. They now parse that way, so `\B{g}+` (which repeats the `}`) is no longer refused.
- A quantifier after `(*LIMIT_MATCH=n)`, as in `(*LIMIT_MATCH=10)+`, was accepted; PHP refuses to repeat it, as it refuses to repeat the other start-of-pattern settings.
- The body of `(*pla:...)`, `(*atomic:...)`, `(*script_run:...)` and the other alphabetic assertions ended at the first `)` outside a nested group, even one that was escaped, in a class, quoted by `\Q...\E` or in a `(?#...)` comment: `(*pla:\))` and `(*pla:[)])` were refused, `(*pla:\Qa)` accepted. It is now read as any group body is.
- Error offsets now follow the PCRE2 release that judges the pattern. PCRE2 10.47 reports most syntax errors past the character at fault rather than on it, and 10.45 moved a few; the library reported the newer offset for some errors and the older one for others. For a `php_version` target, whose bundled PCRE2 is 10.40 to 10.44, it now reports the older offsets, and without a target the offsets of the PCRE2 the running PHP links. On random malformed patterns, offsets for a PHP 8.4 target went from 58% to 94% agreement with PCRE2 10.44. A count with nothing to repeat, as in `{2,1}` or `{65536}`, is now refused on its numbers, as PCRE reads them first, and the unmatched `)` offset moved in PCRE2 10.47, not 10.46.
- `validate()` reports the error PCRE meets first in more cases: an unknown POSIX class or a reversed range in a class a syntax error follows (`[[:foo:]](?(?(`, `[z-a](?#`), an item refused before its count (`[z-a]{2,1}`, `\pX{65536}`), numbers out of order or past 65535 after an anchor, a callout or another quantifier (`^{2,1}`, `a++{65536}`), and an escape where `(?` expects an option letter (`(?i\y`, refused on the backslash). From PCRE2 10.45, a property name holding a character no name holds, as in `\p{L!}`, is malformed (`regex.unicode.property_malformed`) and refused past that character rather than unknown at the brace; every release reads 49 characters of a name at most. On random malformed patterns, offsets now agree with PCRE2 in 98.5% of cases, for a PHP 8.4 target and for the running PHP alike.
- More error offsets follow the PCRE2 release that judges the pattern: an alphabetic name PCRE2 10.44 does not know, as `(*scs:`, is refused where the name ends even when a quantifier follows; before 10.45 a character type or a property is refused as a range start on the hyphen and as a range end past its letter (`[\d-a]`, `[z-\p{Lu}]`), a group number past 65535 past the digit that takes it over (`\g66666666`, `(?66666666)`, `(?(8000000000`), and a `(*LIMIT_...)` value past the digit it refuses; before 10.47 `\N{name}` is refused on its brace, a malformed version condition on the character at fault, a verb as a condition on its `*`, and a POSIX item outside a class, as `[:x:]`, on its `[`. `\g{66666666}` and `\g<66666666>` are refused on their brace, as every release does.
- A conditional whose condition is neither an assertion, a number, a version nor a name was accepted: `(?(\1)a)`, `(?(\g{1})a)`, `(?((?=a))b)`, `(?((?1))b)`, `(?({name})a)` and `(?(>name<)a)`, as was a bare name holding other characters, as in `(?(a-b)x)`. PHP refuses each, "subpattern name expected" where the name should start or "missing terminator" where it stops; so does the parser now. The lookaround is written `(?(?=a)b)`, the reference `(?(1)a)` or `(?(<name>)a)`.
- A verb or alphabetic assertion name is read with its digits, as PCRE reads it: `(*MARK9.` and `(*9` are refused where the name ends, not at the digit. From PCRE2 10.47, an alphabetic name followed by no colon, as in `(*pla)` or `(*atomic)`, is refused past the character after it.
- A lookbehind holding an octal escape written `\101`, with fewer groups than its number, was refused as unbounded: `(?<=\101)a` compiles, `\101` being the character `A`. It now counts as one character, and the digits past the three octal ones as literal characters.
- A start-of-pattern setting in the body of an alphabetic assertion, as in `(*pla:(*UTF))` or `(*atomic:(*CR))`, was accepted as if it opened the pattern; PHP refuses it as an unknown verb, and so does the validator now.
- A callout in a condition followed by a comment, an empty `\Q\E` or, under `x`, whitespace before its assertion, as in `(?(?C1)(?#c)(?=a)b)`, was refused; PCRE skips them there as anywhere, and so does the parser now. What comes instead of the assertion is refused where PCRE stops.
- `generate()` lays the text a positive lookahead generates over the text that follows it, and a positive lookbehind's over the text before it, instead of adding them at the ends of the sample: `^(?=.*\d)(?=.*[a-z])\w{8}$` and `a(?=bc)\w\wd` now get a sample that matches. When no attempt matches, text around the sample is tried, which satisfies `\b`, `\Ba\B` or `(?!^)abc`. A relative call, `(?-1)` or `(?+1)`, now counts the groups opened before it; `(?+1)` threw a `LogicException`. On the patterns of the PCRE2 test suite, samples now match for 97% of them, up from 96%.
- `\x` with one hexadecimal digit, as in `\x0` or `\xA`, was parsed with the code point -1, and `\x` with none as the letter `x`, which PCRE2 10.40 to 10.44 read as NUL; both now carry the character they stand for. From PCRE2 10.45, `\x` with no digit stays refused.
- `generate()` left `\g1`, `\g{1}` and the relative `\g-1`, `\g{-1}` references empty, wrote a `\NN` that names no group as nothing instead of the octal character PCRE reads, and wrote a character given by its code as UTF-8 even without UTF mode.
- `generate()` produced an ASCII letter, digit or punctuation mark for any `\p{...}`, which most properties do not hold: `\p{Greek}`, `\p{Zs}` or `\P{L}` gave samples their pattern does not match. It now asks PCRE for characters that have the property. On the PCRE2 test suite, the share of samples that match their pattern went from 69% to 95%.
- The `regex.lint.backref.undefined` rule reported `\11`, `\128` or, in UTF mode, `\666` as references to missing groups; PCRE reads a `\NN` of two digits or more that names no group as an octal escape when it starts with an octal digit, and the rule now leaves those alone.
- An escape such as `\666` or `\6666666666` that names no group and reads as an octal value past `\377` without UTF mode was reported as a reference to a missing group, at the end of its digits; PHP refuses the octal value where its three digits end, and so does the validator now (`regex.octal.out_of_range`).
- A malformed or unclosed `(*LIMIT_MATCH=...)` value, as in `(*LIMIT_MATCH=12bc)`, was reported at the closing parenthesis or at the start of the value; it is now reported where PCRE2 stops reading, which moved by one character in PCRE2 10.45.
- `(*CASELESS_RESTRICT)` and `(*TURKISH_CASING)`, the start-of-pattern settings PCRE2 10.45 added, are accepted when the running PHP links PCRE2 10.45 or later, with the checks PCRE2 makes: Turkish casing needs UTF mode (`regex.verb.turkish_casing_without_utf`) and does not go with the caseless restriction (`regex.verb.conflicting_casings`). For a targeted PHP version they stay unknown verbs, as no PHP bundles 10.45.
- The smallest compiled size the validator proves now counts what classes, callouts, named verbs, properties and the types UCP reads take, as PCRE2 compiles them: `(?:[ab]c){1599}`, `(?:(?C1)a){4681}` or `(?:\p{L}a){5958}/u` were accepted though PHP refuses them as too large. It stays a lower bound: a class PCRE may compile smaller (one member, a case pair, one matching every character) counts as little as it may.
- `[[:<:]]` and `[[:>:]]`, which PCRE2 reads as the start and the end of a word (`\b(?=\w)` and `\b(?<=\w)`), were parsed as a class followed by a literal `]`: the length range, `literals()`, `optimize()` and the explanation all described a different pattern. They now parse into that boundary and lookaround, and compile back as written.
- A version condition with a number over 1000, as in `(?(VERSION=1001.1)...)`, was accepted; PHP refuses it. For PHP 8.2 to 8.5, whose PCRE2 reads a minor of two digits at most, `(?(VERSION>=10.100)...)` was accepted too. Both are now refused where PCRE2 stops reading.
- For PHP 8.2 and 8.3, a lookbehind holding a group of variable length repeated zero times, as in `(?<=a(b?c){0}d)`, was accepted; the PCRE2 those versions bundle does not count it as fixed length, and it is now refused there.
- Group names of 33 to 128 code units were accepted for PHP 8.2 and 8.3, whose PCRE2 stops at 32 (PCRE2 10.44 raised the limit to 128); they are now refused there, where the name ends. A reference or a call to a name past the limit, as in `\k<...>`, `\g{...}`, `(?&...)` or `(?(name)...)`, was reported as a missing group; PHP reports the length, and so does the parser now.
- With a `php_version` target, Unicode property names were judged by the PCRE2 the running PHP links, not the one the targeted PHP bundles: `\p{Kawi}` was accepted for PHP 8.2 and 8.3, `\p{Garay}` and the other Unicode 16 and 17 scripts for every PHP version, as was a `^` after spaces (`\p{ ^Lu}`). They are now refused where the bundled PCRE2 does not know them.
- A pattern ending in `\c\` (the control character 0x1C) followed by an empty `\E` or a space under `x` compiled back with the `\c\` right before the closing delimiter, which PHP then read as escaped: the compiled and optimized forms had no end. The compiler now closes such a run with an empty `\E`.
- A string callout that doubles its delimiter to hold it, as in `(?C"a""b")`, compiled back with the delimiter single, which ended the callout early: PHP refused the result. The text is now written back with its delimiter doubled, and a callout keeps the delimiter it was written with instead of turning into `(?C"...")`.
- `optimize()` dropped a group repeated `{0}` even when it holds a capturing group, which renumbered the groups after it and removed what `(?1)` calls: `(?1)(?:(b)){0}` became `(?1)`, which PHP refuses. It also dropped a possessive `{1}+` as if it were `{1}`, and merged possessive counts into greedy ones, losing their atomicity: `(?:a|ab){1}+c` matches `ac` but not `abc`, its optimized form matched both.
- `literals()` claimed prefixes and suffixes that matches do not have. A lookaround was read as consumed text (`foo(?!bar)` gave the prefix `foobar`); a branch about which nothing was known was left out of a union (`(?:b|:+)` gave the suffix `b`); a set past 100 entries was cut and still reported as the only possibilities; `(*ACCEPT)` was read as an empty string; `(?i)` stopped at its own group, and caseless matching of non-ASCII letters was not folded, nor, in UTF mode, `k` with the Kelvin sign and `s` with the long s. Checked against what PHP matches on the PCRE2 test suite subjects, every match now starts with a reported prefix and ends with a reported suffix. A set too large to keep is now dropped rather than cut.
- The length range counted every literal as one character: an empty group, an empty branch or an option setting such as `(?s)` counted one, a `\Q...\E` run one whatever its length. It now counts what the literal holds (UTF-8 characters in UTF mode, bytes otherwise), `\R` as one or two characters, `\X` as unbounded, and ends at a `(*ACCEPT)`. Lint rules and lookbehind checks that rely on it follow: `(?<=\R)` is now refused for PHP 8.2 and 8.3, whose PCRE2 does not take a lookbehind of variable length.
- `\g{ 1 }`, `\g{ -1 }`, `\k{ name }` and the other braced references padded with spaces or tabs were refused; PCRE2 10.43 accepts them, so PHP 8.4 and 8.5 compile them. They are now read as the reference they pad, and refused for PHP 8.2 and 8.3 where those PHP versions stop reading them.
- `(*atomic_script_run:...)` and `(*asr:...)` were read as opaque verbs: their body was never checked (`(*asr:\y)` and `(*asr:(a)\2)` were accepted), its groups were not numbered (`(*asr:(a))\1` was refused), and the construct could not be repeated. They now parse into a `ScriptRunNode` whose new `atomic` property is `true`, and compile back to the spelling they were written in.
- `(*PLA:...)`, `(*SR:...)`, `(*Atomic:...)` and other spellings of the alphabetic assertions and script runs not in lowercase were accepted; PCRE2 knows them in lowercase only and refuses any other spelling as an unknown verb, and so does the parser now.
- Three limits PCRE sets on what it compiles were not checked, so patterns PHP refuses were reported valid: a `(*MARK)`, `(*PRUNE)`, `(*SKIP)` or `(*THEN)` name longer than 255 code units (`regex.verb.name_too_long`), a `(*LIMIT_MATCH=n)`, `(*LIMIT_HEAP=n)` or `(*LIMIT_DEPTH=n)` value above 4294967289 (`regex.verb.limit_too_large`), and parentheses nested more than 250 deep (`regex.group.nested_too_deep`). Each is reported where PHP reports it.
- For PHP 8.2 and 8.3, `{,2}` and a count padded with spaces (`{ 2 }`, `{2, 3}`) were read as repeats; the PCRE2 those versions bundle reads them as literal text, so `/{,2}/` was refused and `/a{,2}/` misread. They are now text there, and repeats from PHP 8.4. `\N{,2}` is refused before PHP 8.4, and `\N` followed by such a count is reported where the `\N` ends, as PCRE2 10.40 and 10.42 report it.
- The ASCII options PCRE2 10.43 added to `(?...)` — `a` alone, or with one of `D`, `S`, `W`, `P`, `T`, as in `(?aD)` or `(?i-aW:...)` — were refused; PHP 8.4 takes them. They are now read for PHP 8.4 and for a running PHP that links PCRE2 10.43 or newer, and still refused for PHP 8.2 and 8.3.
- Spaces or tabs right after the `U+` of `\N{U+...}` were left unjudged. From PCRE2 10.43 they may only run up to the closing brace (`\N{U+ }`), so `\N{U+ 41}` and an unclosed `\N{U+ ` are now refused; for PHP 8.2 and 8.3, whose PCRE2 refuses any padding there, `\N{U+ }` is refused too.
- An unmatched `)` is reported past the `)` when the running PCRE2 is newer than 10.45, where PHP reports it, and on it for older releases and for an explicit `php_version` target (PHP bundles 10.44); the message now says "Unmatched closing parenthesis".
- A pattern with two errors, one of them a reference to a missing group, was reported at the reference. PCRE resolves references to groups by number or name only once it has read the whole pattern, and measures lookbehinds before that, so `\2\y` fails on the `\y` and `\2x(?<=a+)` on the lookbehind; the validator now reports the same error. Relative references are still judged where they stand, as PCRE does.
- `\c` followed by a tab, a newline, another control character or DEL was reported valid; PCRE takes only printable ASCII after `\c`, and refuses these.
- A malformed `\k` name was reported valid when it had an opener: `\k<`, `\k{ab`, `\k'a`, `\k<a-b>`, `\k{1,}`. PHP refuses them all; they now fail where PCRE stops reading, as `(?<`, `(?&` and `\g` already did.
- Compiling or optimizing a pattern could fuse two items into one longer escape: `(a)\1\E0` compiled to `(a)\10` (the octal `\010`), `\xa\Eb` to `\xab`, `a{\E2}` to the repeat `a{2}`, and optimizing `(a)\1(?:)0` gave `(a)\10`. A `\E` or `\Q\E` is still dropped where it separates nothing, and kept as written where dropping it would join two items; where no source says how two items were separated, an empty group (`\E` in a class) keeps a digit escape apart from the next digit.
- `\p` and `\P` with no property after them (`\p`, `\p1`, `\p\E{L}`, an unclosed `\p{L`, the empty `\p{}`) were read as the letters `p` and `P` and reported valid; PHP refuses them. They now fail with `regex.unicode.property_malformed` (or `regex.unicode.property_invalid` for `\p{}`) at PCRE's offset.
- Compiling a pattern that opens with `(*UTF)` but has no `u` flag wrote its non-ASCII characters back as separate bytes (`é` as `\xC3\xA9`), which UTF mode reads as two characters; they are now kept whole, as under `u`.
- A `\Q` run left open at the end of the pattern kept its final newline out of the tree, so recompiling `/a\Q\n/` dropped the newline and matched `a` alone.
- Errors are reported at the offset PHP reports: `ValidationResult::$offset`, and the position an error message quotes, now point at the byte of the pattern body where `preg_match()` places its "Compilation failed: ... at offset N" warning, which is usually where PCRE stops reading the construct at fault. `\1` with no group is reported at 2, the end of the reference, not at 0; `[a-\d]` at 5, after the class escape; `(?<=x(a+))` at 0, the start of the lookbehind, rather than at the `+`; `(?z)` at 3, past the unknown letter; `(?<0a>x)` at 4, past the leading digit; a duplicate group name past the `>` that closes it. Where PHP releases disagree on an offset, the library reports the one of PCRE2 10.48 or, for a construct only newer releases read such as `(?[...])`, the one of the PCRE2 10.40 that PHP 8.2 bundles. Whether a pattern is valid is unchanged, and body offsets stay relative to the pattern body. A pattern holding two errors may now report the other one, the one PHP reports: `[\d-\N]` the `\N` a class refuses rather than the range, `x{70000,1}` the number too big rather than the reversed bounds.
- A `(?#...)` comment between an item and its quantifier no longer takes the quantifier: PCRE skips the comment, so `a(?#c)*` repeats the `a`, and `a*(?#c)+` is the possessive `a*+`. The tree used to repeat the comment and match a single `a`, so generated samples, ReDoS and lint results followed the wrong pattern. With nothing repeatable before the comment, as in `(?#c)*`, `(?i)(?#c)*` or `^(?#c)*`, validation now refuses the quantifier at the offset PHP reports, as `preg_match()` does; it used to accept it. Recompiling puts the comment after the quantifier: `a(?#c)*` comes back as `a*(?#c)`.
- A quantifier after a `\Q...\E` run repeats the run's last character only, as in PCRE: `\Qab\E*` is `ab*` and `\Qab\E{2}` is `abb`. The tree repeated the whole run, so a generated sample such as `abab` was one the pattern does not match. The last character is a code point in UTF-8 mode and a byte otherwise. A lone `\E` or an empty `\Q\E` is skipped like a comment: `a*\E+` is the possessive `a*+`, `/^a\E {,2}$/x`, which was refused, validates, and `a{1,3}\E{2}` and `a*\Q\E*` are refused at the offset PHP reports; they validated.
- Inside a class, a `\Q...\E` run stands for its characters one by one, as in PCRE: `[\Qabc\E-z]` is `a`, `b` and the range `c-z`, and `[\Qaz\E-a]` is refused as the reversed range `z-a`.
- An `\E`, or an empty `\Q\E`, between a range start and its hyphen no longer breaks the range: PCRE builds it through them, so `[a\E-c]` matches `b`, and `[z\E-a]` is refused as a reversed range, as `preg_match()` refuses it. After a class escape, `[\w\E-a]`, the hyphen stays a member up to PCRE2 10.44, the newest any PHP release bundles; only a newer linked PCRE2, without an explicit `php_version`, refuses it.
- `[a&&b]` and `[a--b]` read as PCRE reads them. PHP compiles without PCRE2's extended class syntax, so `&&` and `--` inside a class are not an intersection and a subtraction: `[a&&b]` holds `a`, `&` and `b`, and `[a--b]` is a range from `a` down to `-`, which PHP refuses. Validation accepted `[a--b]`, `[a--]`, `[^^--]` and `[\w--\d]`, and refused `[--[]` and `[]-\E]`; a `[` after `&&` opened a nested class, so `[a-z&&[^aeiou]]` was read as one class where PHP reads a class followed by a literal `]`.
- Spaces inside `\x{ 41 }`, `\o{ 101 }`, `\N{ U+41 }` and a `\N{4 }` repeat arrived in PCRE2 10.43, which PHP bundles from 8.4; they validated for any target. Targeting PHP 8.2 or 8.3, they are now refused, as those releases' PCRE2 refuses them, unless the running PHP links a newer PCRE2. The unpadded forms are valid everywhere, and `a{ 4 }` is unchanged.
- Under the `n` modifier or `(?n)`, a plain `(...)` group was counted as a capture, so `/(?n)(a)\1/` and `/(a)\1/n` validated though PHP refuses them. Such a group now captures nothing and takes no number — named groups still do — through `(?n:...)`, `(?-n)` and `(?^)` as PCRE scopes them. It is read as a non-capturing group and written back with the `(` it was spelled with.
- `\g'1'` and `\g'-1'` call the group, as `\g<1>` does; they were read as back references.
- A pattern cut short by an unescaped delimiter is reported as such, at that delimiter: `/a/b/` gives "Unescaped delimiter \"/\" at position 2 ends the pattern early" rather than a list of unknown modifiers, or a misleading note about the removed `e` modifier when the cut-off text holds an `e`.
- `\g{name}` was read as a call to the group, and written back as `\g<name>`, which changes what the pattern matches: `/(?<n>a|b)\g{n}/` refuses "ab", but its rewrite matched it. It is now a back reference, like `\k{name}`, and is written back as `\g{name}`. `\g<name>` and `\g'name'` stay calls.
- A delimiter inside a character class, a comment or a `\Q...\E` run no longer hides from the end of the pattern: PHP ends a pattern at the first delimiter a backslash does not escape, so `/[/]/` has the unknown modifiers `]/` and is refused, as `preg_match()` refuses it. Such patterns, like `"/([[:alnum:]]+)://.../i"`, fail at runtime with a warning and a `null` result; validation used to report them as valid.
- Validation accepted variable-length lookbehinds such as `(?<=a?)b` when targeting PHP 8.2 or 8.3, whose bundled PCRE2 (10.40, 10.42) refuses them: they need PCRE2 10.43, bundled from PHP 8.4 (`regex.lookbehind.variable_length_not_supported`). Branches of different fixed lengths, `(?<=a|bc)b`, stay valid everywhere. Without an explicit `php_version`, the PCRE2 the running PHP links decides, and one from 10.45 on also refuses a `\x` with no digit.
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
