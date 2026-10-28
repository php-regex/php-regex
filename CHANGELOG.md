# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.0.0] - Unreleased

2.0 is a new major line; 1.x lives on the `1.x` branch. [UPGRADE-2.0.md](UPGRADE-2.0.md) walks through every change below that needs one on your side.

Each package keeps its own changelog, with the details this page leaves out: [regex-automata](src/Automata/CHANGELOG.md), [regex-cli](src/Cli/CHANGELOG.md), [regex-explain](src/Explain/CHANGELOG.md), [regex-generator](src/Generator/CHANGELOG.md), [regex-language-server](src/LanguageServer/CHANGELOG.md), [regex-laravel](src/Laravel/CHANGELOG.md), [regex-linter](src/Linter/CHANGELOG.md), [regex-optimizer](src/Optimizer/CHANGELOG.md), [regex-parser](src/Parser/CHANGELOG.md), [regex-phpstan](src/PHPStan/CHANGELOG.md), [regex-phpunit](src/PHPUnit/CHANGELOG.md), [regex-psalm](src/Psalm/CHANGELOG.md), [regex-rector](src/Rector/CHANGELOG.md), [regex-redos](src/Redos/CHANGELOG.md), [regex-symfony](src/Symfony/CHANGELOG.md), [regex-toolkit](src/Toolkit/CHANGELOG.md), [regex-transpiler](src/Transpiler/CHANGELOG.md).

### Highlights

- Seventeen `php-regex/regex-*` packages under the `PHPRegex\` namespace, on PHP 8.1 and later: install the `Regex` facade (`regex-toolkit`), the parser alone, or one integration. The Rector set `RegexSetList::UPGRADE_2_0` moves 1.3 code to the 2.0 names.
- Patterns are judged for a target: a PHP version, a PCRE2 release and an `LC_CTYPE` locale (`php_version`, `pcre_version`, `locale`). Each PHP from 7.3 is judged with the PCRE2 it bundles: the rules of PCRE2 10.39 to 10.49 in full, the changes of 10.32 to 10.38 the library names.
- Validation follows PCRE2: its official test suite (10.49) is replayed against `Regex::validate()` and the results are published on the [PCRE2 conformance page](docs/reference/pcre2-conformance.md); errors carry an `ErrorCode` and the offset PCRE2 reports.
- `regex lint` judges the whole PHP range `composer.json` allows, reads `PHP_VERSION_ID` guards and `@regex-target` markers, and reports a pattern a later PHP refuses or reads with another meaning (`regex.lint.compat.meaningChanges`).
- The lint reads more patterns: eight library presets (composer/pcre, nette/utils, spatie/regex, Laravel's `Str`, PHPUnit, webmozart/assert, beberlei/assert, Symfony's `Regex` constraint), `RegexIterator`, `filter_var()`, patterns held in constants, variables and properties, and parameters marked `#[RegexPattern]` or `#[Language('RegExp')]`.
- ReDoS verdicts are proven: linear, polynomial with its degree, or exponential with an attack string; the confirmed mode replays the attack on the running PCRE2, the quadratic cost of an unanchored search is reported, and `RedosRepairer` offers repairs proven linear.
- The automata solver reads lookarounds, anchors, word boundaries and the `m` flag, and adds `matchEquivalent()` (same `$matches` on every subject), `language()` and `acceptsPrefix()`. The optimizer offers a rewrite only once the solver proves it.
- Capture shapes: `Regex::captureShape()` reads from the pattern alone what `preg_match()` and `preg_match_all()` write into `$matches`, written as a PHPStan type and used by the Psalm plugin.
- New integrations: a Psalm plugin (`regex-psalm`), Rector rules that turn `preg_*` calls into string functions or ready a pattern for an older PHP (`regex-rector`), and PHPUnit assertions (`regex-phpunit`).
- The PHPStan extension judges for PHPStan's `phpVersion` range and reports what PHPStan does not: a pattern the target refuses, a replacement naming a missing group, a pattern passed to a `#[RegexPattern]` parameter.
- A language server, `bin/regex-lsp` or `regex lsp`, publishes the lint's diagnostics in the editor, with hover, completion and quick fixes.
- Symfony's `ValidRegex` constraint and Laravel's `ValidRegex` rule validate a pattern a user submits; the bridges' `regex:lint` runs the same lint as the CLI. Symfony 6.4 to 8 and Laravel 10.37 to 13 are supported.
- `regex format` and `Regex::format()` lay a long pattern out under `x`, and `Regex::compact()` puts it back on one line; a parsed pattern prints back byte for byte.
- `Regex::info()` and `Regex::compatibility()` give the facts PCRE2 computes on a compiled pattern and the PHP and PCRE2 releases a pattern is valid on; `JsonDumper` exports a tree described by `ast.schema.json`.
- The default cache no longer writes PHP files to the shared temp directory, and `regex.phar` is built in CI with a build provenance attestation (see **Security**).

### Backward-incompatible changes

- Everything under **Removed** below, and every change [UPGRADE-2.0.md](UPGRADE-2.0.md) lists.
- The library ships as seventeen `php-regex/regex-*` packages from the `php-regex/php-regex` repository; `yoeunes/regex-parser` stays on 1.x. See [Packages](UPGRADE-2.0.md#packages).
- Every class moved from `RegexParser\` to `PHPRegex\<Package>\`, many under a new name (`RegexParser\Regex` is `PHPRegex\Toolkit\Regex`, `CompilerNodeVisitor` is `Parser\Printer\PatternPrinter`); the entries below use the 2.0 names. See [Names](UPGRADE-2.0.md#names).
- The aliases that kept the 1.x class names resolving are gone, with the file Composer loaded on every request for them. See [Names](UPGRADE-2.0.md#names).
- The public API is declared: everything else is `@internal` (the CLI and language server classes, the bridges' commands, the lint rules and diagnostics, the transpiler targets, the lexer, the grammar, the validator), as [the backward compatibility promise](docs/reference/backward-compatibility.md) states. See [Names](UPGRADE-2.0.md#names).
- The constructors of result objects (`RedosAnalysis`, `CaptureShape`, `EquivalenceResult`, …) are `@internal`, and each package requires its siblings at its own version (`self.version`). See [Packages](UPGRADE-2.0.md#packages).
- Enum cases are in PascalCase, and `TokenType`, `GroupType` and `QuantifierType` drop their `T_` and `T_GROUP_` prefixes (`TokenType::T_LITERAL` is `TokenType::Literal`); values are unchanged. See [Names](UPGRADE-2.0.md#names).
- The configuration takes the PHPRegex name: `PHPRegexSymfonyBundle` under `php_regex`, the PHPStan parameter `phpRegex`, Laravel's `PHPRegexServiceProvider` with `config/php-regex.php`; `config/regex-parser.php` is no longer read. See [Laravel](UPGRADE-2.0.md#laravel-configphp-regexphp-replaces-configregex-parserphp).
- One target, `PcreTarget`, replaces the PHP version id the lexer, parser, validator and `DelimitedPattern` took. See [One target](UPGRADE-2.0.md#one-target-pcretarget-replaces-the-php-version-id).
- `php_version` refuses a version below PHP 7.0 or past 9.99, and `pcre_version` a release below PCRE2 10.00; the Rector rules judge a target of PHP 10 as 9.99. See [php_version](UPGRADE-2.0.md#php_version-names-a-release-and-the-limits-are-checked-where-the-options-are-built).
- `php_version` is read whole, so `"8.2foo"`, `"8.2.100"` and `82` throw where a prefix was read, and `new ParserOptions()` checks its limits as `fromArray()` did. See [php_version](UPGRADE-2.0.md#php_version-names-a-release-and-the-limits-are-checked-where-the-options-are-built).
- The options of `Regex::create()` and `RegexParser::create()` are the closed shape `OptionsArray`; options read from a file go through `ParserOptions::fromArray()` or the new `Regex::fromOptions()`. See [php_version](UPGRADE-2.0.md#php_version-names-a-release-and-the-limits-are-checked-where-the-options-are-built).
- `PcreLimits`, `RedosOptions` and `SolverOptions` refuse a limit that means nothing (a cap below 1, a budget below 0) with `InvalidRegexOptionException`. See [php_version](UPGRADE-2.0.md#php_version-names-a-release-and-the-limits-are-checked-where-the-options-are-built).
- `Regex::tokenize()` is an instance method; it and `RegexParser::tokenize()` return a `list<Token>` read for the instance's target. See [tokenize()](UPGRADE-2.0.md#tokenize-returns-the-tokens-for-the-instances-target).
- One parameter name per concept, which named arguments make part of the API: `$regex` for a delimited pattern, `$ast`, `$subject`, `$parser`. See [One name per argument](UPGRADE-2.0.md#one-name-per-argument).
- `AnalysisReport` names its parts `$optimization` and `$explanation`; `DelimitedPattern` drops `fromRaw()` and `toString()` for its constructor and `(string)`. See [One way to do each thing](UPGRADE-2.0.md#one-way-to-do-each-thing-on-the-facade).
- Error codes are the enum `PHPRegex\Parser\ErrorCode`, one per syntax or semantic error, where 1.x said `parser.error`, `lexer.error` or `regex.semantic`; exceptions take the code as second argument. See [Error codes](UPGRADE-2.0.md#error-codes-are-an-errorcode-enum).
- The exceptions are read through their readonly properties: `getErrorCode()`, `getPosition()`, `getSnippet()` and `getHint()` are gone. See [ValidationResult](UPGRADE-2.0.md#validationresulterror-is-the-message-alone).
- An exception holds its snippet once, in `RegexException::$snippet`: `VisualContextInterface::getVisualSnippet()` is gone. See [The snippet](UPGRADE-2.0.md#the-snippet-of-an-exception-is-snippet).
- What the library throws says whose mistake it is: a caller's mistake implements `PHPRegex\Parser\Exception\ExceptionInterface` (new `LintException`, `CliException`), a bug of the library is a plain `\LogicException`. See [Exceptions](UPGRADE-2.0.md#exceptions-say-whose-mistake-it-is).
- `validate()` lets an exception that is not the library's through, where it turned it into an invalid-pattern result. See [Exceptions](UPGRADE-2.0.md#exceptions-say-whose-mistake-it-is).
- `RedosAnalyzer` reports a pattern as not analysed only when the library refuses it; any other error surfaces instead of an `Unknown` verdict. See [Exceptions](UPGRADE-2.0.md#exceptions-say-whose-mistake-it-is).
- A limit of the library is no longer an invalid pattern: the new `LimitException` sits beside `ParserException`, `validate()` gives the category `limit`, and the lint, the CLI and the editor say `not analyzed`. See [A limit of the library](UPGRADE-2.0.md#a-limit-of-the-library-is-no-invalid-pattern).
- `ValidationResult::$error` is the message alone, the caret snippet staying in `$caretSnippet`. See [ValidationResult](UPGRADE-2.0.md#validationresulterror-is-the-message-alone).
- `json_encode()` of a `ValidationResult` gives snake_case keys (`is_valid`, `complexity_score`, `caret_snippet`, `error_code`). See [JSON output](UPGRADE-2.0.md#json-output).
- Every offset counts from the first character of the pattern body, as PCRE2's do. See [Every offset](UPGRADE-2.0.md#every-offset-counts-from-the-pattern-body).
- Runtime validation (`runtime_pcre_validation`) reports PCRE's message without PHP's "Compilation failed: ", and `/(?R)/` is valid. See [runtime_pcre_validation](UPGRADE-2.0.md#runtime_pcre_validation-needs-the-running-engine-as-target).
- `CacheInterface` stores trees (`write(string $key, RegexNode $ast)`, `load()`), `getTimestamp()` and `CachePayloadDecoder` are gone, and the default cache is `ArrayCache`. See [The cache](UPGRADE-2.0.md#the-cache-stores-trees-in-memory-by-default).
- `RemovableCacheInterface::clear()` takes no argument and empties the cache; `FilesystemCache::clear()` removes only the files it wrote. See [A cache is emptied whole](UPGRADE-2.0.md#a-cache-is-emptied-whole).
- `clearCaches()` replaces `clearValidatorCaches()` on `Regex` and `RegexParser`, and empties every process-wide cache of the library. See [clearCaches()](UPGRADE-2.0.md#clearcaches-replaces-clearvalidatorcaches).
- `SequenceNode::$children` and `AlternationNode::$alternatives` are lists: the constructors renumber the array they are given. See [Sequences and alternations](UPGRADE-2.0.md#sequences-and-alternations-hold-lists).
- `CalloutNode::accept()` and `DefineNode::accept()` drop their native `mixed` return type; the `$phpVersionRange` of the PHPStan rules is typed `int|array|null`. See [accept()](UPGRADE-2.0.md#accept-has-no-native-return-type-on-any-node).
- `Regex::highlight()` refuses a format other than `console` and `html`, `text` included. See [Regex::highlight()](UPGRADE-2.0.md#regexhighlight-refuses-an-unknown-format).
- `RailroadSvgRenderer` is a `string` visitor; its layout and `Svg*` type aliases are internal. See [Names](UPGRADE-2.0.md#names).
- `Regex::optimize()` and `Optimizer::optimize()` take an `OptimizerOptions` or an array keyed in snake_case; an unknown key throws, `autoPossessify` is `possessive`, `allowAlternationFactorization` is `factorize`. See [Optimizer options](UPGRADE-2.0.md#optimizer-options-are-typed-and-keyed-in-snake_case).
- The array options of `optimize()` are the closed shape `OptimizerOptionsArray`, so PHPStan reports a misspelt key or a wrong value where the array is written. See [Optimizer options](UPGRADE-2.0.md#optimizer-options-are-typed-and-keyed-in-snake_case).
- `verify_with_automata` is on by default: a rewrite is offered only once the automata prove it writes the same `$matches`, which makes `optimize()` slower. See [Optimizer options](UPGRADE-2.0.md#optimizer-options-are-typed-and-keyed-in-snake_case).
- `RedosRepair::$complexity` is gone: every repair is proven linear. See [RedosRepair](UPGRADE-2.0.md#redosrepair-has-no-complexity).
- `LiteralExtractionResult::$confidence` is the `LiteralConfidence` enum. See [LiteralExtractionResult](UPGRADE-2.0.md#literalextractionresultconfidence-is-an-enum).
- `RedosAnalysis` reads through its properties: `$confidence` (never null) replaces `confidenceLevel()`, `$culpritNode` replaces `getCulpritNode()`, `getPrimaryHotspot()` is `primaryHotspot()`. See [ValidationResult](UPGRADE-2.0.md#validationresulterror-is-the-message-alone).
- `PatternLinter` gives its findings through `getIssues()` only, takes the rules to enable only, and throws `UnknownLintRuleException` for a rule it does not run; `Transpiler` takes the parser only. See [ValidationResult](UPGRADE-2.0.md#validationresulterror-is-the-message-alone).
- `RedosAnalyzer`, `LanguageSolver`, `Transpiler` and `AnalysisService` take a `RegexParser` instead of a `Regex`; `CharSet` is `PHPRegex\Parser\Analysis\ByteCharSet`. See [What reads patterns](UPGRADE-2.0.md#what-reads-patterns-takes-a-regexparser).
- The automata solver has one entry point, `LanguageSolver`: `intersectionEmpty()` is `intersection()`, `prepare()` is `compile()`, and the other solver classes are gone or internal. See [LanguageSolver](UPGRADE-2.0.md#one-automata-entry-point-languagesolver).
- `Model\DfaState` reads its moves through `transitionFor()`, `rangeCount()` and `ranges()`; its public arrays are gone, and a large DFA holds less than half the memory. See [LanguageSolver](UPGRADE-2.0.md#one-automata-entry-point-languagesolver).
- `ConfirmationOptions` refuses an out-of-range value with `InvalidRegexOptionException`, where it clamped it. See [The ReDoS confirmation options](UPGRADE-2.0.md#the-redos-confirmation-options-refuse-a-value-they-clamped).
- The ReDoS confirmation always runs without JIT and reports so (`jit_setting` is `false`), with the limits of its options. See [No JIT switch](UPGRADE-2.0.md#the-redos-confirmation-has-no-jit-switch).
- `regex analyze` and `regex debug` refuse `--redos-no-jit`; `ReDoSConfirmOptions::$disableJit` and `jit_disable_requested` are gone. See [No JIT switch](UPGRADE-2.0.md#the-redos-confirmation-has-no-jit-switch).
- `LanguageServer\Server::run()` returns the exit code instead of ending the process; `bin/regex-lsp` exits with it.
- ReDoS analysis is off by default: `--redos` or `checks.redos.enabled` for the CLI, `rules.neon` for PHPStan, `redos.enabled` in the Symfony and Laravel configurations. See [The PHPStan extension](UPGRADE-2.0.md#the-phpstan-extension-reports-what-phpstan-does-not).
- A ReDoS threshold is `low`, `medium`, `high` or `critical` everywhere, read by `RedosSeverity::fromConfig()`; `safe`, `unknown` and any other word are refused. See [A ReDoS threshold](UPGRADE-2.0.md#a-redos-threshold-is-low-medium-high-or-critical-everywhere).
- `regex.json` is read strictly: an unknown key or rule id, a value of the wrong kind or a 1.x key is an error (exit code 2), only `enabled` switches a check on, and a list in `regex.json` replaces the one in `regex.dist.json`. See [regex.json](UPGRADE-2.0.md#regexjson-one-spelling-per-setting-and-nothing-unknown).
- The lint judges for the project's lowest PHP: `--php-version`, `phpVersion` in `regex.json`, `composer.json`, else the running PHP; PHP 7.2 and earlier (PCRE1) are refused, and the later PHP versions the constraint allows are checked too. See [The lowest PHP](UPGRADE-2.0.md#the-lint-command-judges-for-the-projects-lowest-php).
- `regex lint`, the language server and the bridges' `regex:lint` read more patterns, so a project clean on 1.x may report findings on unchanged code; `extraction.interop` narrows what is read. See [The lint reads more patterns](UPGRADE-2.0.md#the-lint-reads-more-patterns).
- A name in `extraction.interop` that is no preset is a configuration error naming the preset it most likely means, in the CLI, the bridges, PHPStan and the language server. See [The lint reads more patterns](UPGRADE-2.0.md#the-lint-reads-more-patterns).
- `@regex-ignore` and `@regex-ignore-next-line` markers, and baseline entries, that silence nothing are reported as `regex.ignore.unmatched`; `--no-report-unmatched-ignores` or `reportUnmatchedIgnores` turns it off. See [Markers that silence nothing](UPGRADE-2.0.md#markers-and-baseline-entries-that-silence-nothing).
- `@regex-ignore` and `@regex-ignore-next-line` are read only where they start a comment or a line of a doc comment: `// legacy, @regex-ignore` silences nothing. See [Markers that silence nothing](UPGRADE-2.0.md#markers-and-baseline-entries-that-silence-nothing).
- A short id after a marker, `// @regex-ignore quantifier.useless`, silences that issue only, where it silenced the whole line. See [Markers that silence nothing](UPGRADE-2.0.md#markers-and-baseline-entries-that-silence-nothing).
- `regex lint --baseline` reads a new format, `{"version": 1, "issues": [...]}`, matched on identifier, file and pattern hash; a 1.x baseline or a missing file is a usage error, and `--baseline <file>` takes its file after a space. See [regex lint --baseline](UPGRADE-2.0.md#regex-lint---baseline).
- The lint honours each rule's severity in every report: `critical` and `error` fail the run (`unicode.multibyteInClassWithoutU`, `unicode.quantifiedMultibyteWithoutU`, `unicode.propertyWithoutU`), `style`, `perf` and `info` are infos. See [Exit codes and streams](UPGRADE-2.0.md#the-lint-commands-exit-codes-and-streams).
- `regex.lint.unicode.shorthandWithoutU` is off by default: `--enable-rule=unicode.shorthandWithoutU` or `checks.lint.rules` turns it on.
- Lint identifiers are camelCase, as PHPStan wants: `unicode.shorthand_without_u` is `unicode.shorthandWithoutU`, and likewise `alternation.duplicateDisjunction`, `charclass.duplicateChars`, `charclass.suspiciousRange`, `charclass.suspiciousPipe`.
- Some lint messages are reworded (useless `m` and `s` flags, a lazy quantifier under `/U`, the redundant-class hint, the invalid delimiter): a PHPStan baseline holding lint issues is regenerated once.
- The lint command exits with 2 on a configuration or usage error and writes errors to stderr, or as the envelope `{"error": ..., "stage": ...}` under `--format=json`; `--redos-mode=off` is refused. See [Exit codes and streams](UPGRADE-2.0.md#the-lint-commands-exit-codes-and-streams).
- Every command writes a usage error (exit code 2) to stderr with its usage line, where only `lint` did; the JSON error envelope stays on stdout. See [Every command exits with 0, 1 or 2](UPGRADE-2.0.md#every-command-exits-with-0-1-or-2).
- Every CLI command exits with 0, 1 or 2 as the lint does, and refuses an option it does not know. See [Every command exits with 0, 1 or 2](UPGRADE-2.0.md#every-command-exits-with-0-1-or-2).
- `regex lint` refuses a path that does not exist (exit code 2). See [JSON output](UPGRADE-2.0.md#json-output).
- Laravel's `regex:lint` fails on a file whose validation rules it cannot read, reported as `regex.lint.source.unreadable`. See [Exit codes and streams](UPGRADE-2.0.md#the-lint-commands-exit-codes-and-streams).
- `--format` says `console` for the terminal on every command; `text`, `ascii` and `cli` are refused. See [--format](UPGRADE-2.0.md#--format-says-console-for-the-terminal).
- The JSON, Checkstyle and JUnit reports carry each pattern as written, escaping only invalid UTF-8 and what XML cannot hold; the console and GitHub reports escape the characters that hide or move text. See [JSON output](UPGRADE-2.0.md#json-output).
- `lint --format=github` writes the pattern's column in the file as `col=`, and escapes `%`, `:` and `,` as GitHub requires; the Checkstyle `column` is that column. See [GitHub and Checkstyle](UPGRADE-2.0.md#github-and-checkstyle-reports-point-at-the-pattern).
- The Checkstyle `source` is the issue identifier alone, without the `regex-parser.` prefix. See [GitHub and Checkstyle](UPGRADE-2.0.md#github-and-checkstyle-reports-point-at-the-pattern).
- The PHPStan extension reports by default only `regex.invalidForTarget`; lint rules and ReDoS are opt-in through `rules.neon`, and ReDoS findings share the identifier `regex.redos`. See [The PHPStan extension](UPGRADE-2.0.md#the-phpstan-extension-reports-what-phpstan-does-not).
- The PHPStan ReDoS threshold is `high` by default, where it was `critical`. See [The PHPStan ReDoS threshold](UPGRADE-2.0.md#the-phpstan-redos-threshold-is-high-by-default).
- The PHPStan ReDoS message is `Exponential backtracking (ReDoS): <pattern>` (or `Polynomial`, `Potential`), stable for 2.x; baselines holding one are regenerated once. See [ReDoS verdicts](UPGRADE-2.0.md#redos-verdicts-are-proven-and-the-phpstan-redos-message-changed).
- The PHPStan messages show the pattern as the console does, cut after 50 characters on a character boundary; a baseline holding such a pattern is regenerated once. See [ReDoS verdicts](UPGRADE-2.0.md#redos-verdicts-are-proven-and-the-phpstan-redos-message-changed).
- Symfony bundle: `runtime_pcre_validation` defaults to `false`, `exclude_paths` is `exclude`, `analysis.ignore_patterns` is merged into `redos.ignored_patterns`, `analysis.redos_threshold` is gone, and `php_version` / `pcre_version` set the target of `regex:lint`. See [Symfony](UPGRADE-2.0.md#symfony-the-bundle-configuration-in-20).
- Laravel: `config/php-regex.php` takes the same names, `runtime_pcre_validation` defaults to `false`, and `php_version` / `pcre_version` set the target of `regex:lint`. See [Laravel](UPGRADE-2.0.md#laravel-configphp-regexphp-replaces-configregex-parserphp).
- Symfony's `regex:compare` no longer answers to `debug:compare`. See [Symfony](UPGRADE-2.0.md#symfony-debugcompare-is-gone).
- Symfony's `regex:routes` no longer takes `--check-conflicts`, which changed nothing. See [Symfony](UPGRADE-2.0.md#symfony-regexroutes---check-conflicts-is-gone).
- The language server judges for the workspace's target (`initializationOptions`, `regex.json`, `composer.json`, the running PHP). See [The language server](UPGRADE-2.0.md#the-language-server-judges-for-the-workspaces-target).
- `Regex::optimize()` and `Optimizer::optimize()` take `?OptimizerOptions $options = null`; the array form is gone: read a configuration array once with `OptimizerOptions::fromArray()`. See [Optimizer options](UPGRADE-2.0.md#optimizer-options-are-typed-and-keyed-in-snake_case).
- `explain()` takes `string|ExplainFormat` (`Text`, `Html`) and `highlight()` `string|HighlightFormat` (`Console`, `Html`), so a format the method does not write cannot be named; `'text'`, `'html'` and `'console'` stay accepted.

### Security

- The default cache wrote parsed trees as PHP files under the shared temp directory and `include`d them, so another local user could run code or change a verdict. Nothing is written to disk by default (the latest 1024 trees stay in memory); `FilesystemCache` runs only on a directory you name, stores data read back with a class allowlist, writes `0700`/`0600`, and ignores a directory another user owns or can write to.
- `regex.phar` is built by a GitHub Actions workflow from the release tag and carries a build provenance attestation: `gh attestation verify regex.phar --repo php-regex/php-regex` checks it.

### Added

#### Parser and toolkit
- `QuantifierNode::$bounds` holds the least and the most repetitions of a quantifier, read once when the node is built (null only for a node built by hand around a token that is no quantifier).
- `new SampleGenerator(seed: 42)` takes the seed with the other arguments; `setSeed()` and `resetSeed()` stay, and each sample drawn on a seeded instance starts again from the seed.
- `new Optimizer()` and `new Transpiler()` without a parser read with `RegexParser::create()`.
- `new CompatibilityChecker($parser)` and `RangeDiff::between($from, $to, $parser)` judge every target with that parser's locale, recursion depth and limits.

- `RegexParser`, the class that reads and judges a pattern (`parse()`, `parseTolerant()`, `validate()`, `tokenize()`, the cache, the target); `Regex::parser()` gives the facade's one, `Regex::parseTolerant()` replaces the `$tolerant` flag.
- `pcre_version`, `locale` and `php_version: "runtime"` (also `--pcre-version`, `--locale`, `--php-version=runtime`); `PcreTarget`, `Regex::target()`, `withTarget()`, `PcreTarget::bundledWith()`, `judgedWith()` and `phpVersionBoundaries()`.
- `PcreFeature` and `PcreTarget::supports()`: each behaviour PCRE2 changed, with the release it arrived in, as [the PCRE page](docs/concepts/pcre.md#what-changed-in-which-release) lists them.
- PCRE2 10.45 and 10.47 syntax for a target that has it: substring scan assertions `(*scan_substring:(1)abc)`, Perl extended classes `(?[ \p{L} - [aeiou] ])`, and calls that return groups `(?1(2,<name>))`.
- Alphabetic assertions (`(*pla:...)`, `(*atomic:...)`, …) and script runs (`(*sr:...)`, `ScriptRunNode::$atomic`) parse into the tree; `(?x)`, `(?x:...)` and `(?-x)` drive extended mode.
- `RangeDiff::between()` answers whether a pattern changes between two PHP versions; `CompatibilityChecker` and `Regex::compatibility()` give its verdict per PHP version and PCRE2 release.
- `PatternInfoAnalyzer` and `Regex::info()`: capture count, group names, newline conventions, match limits and length bounds, as PCRE2 computes them.
- `CaptureShapeAnalyzer` and `Regex::captureShape()`: per group, whether it is set, its length bounds and values, and facts such as `digitsOnly`, `decimalInt`, `lowercase`; `matchShape()` and `matchAllShape()` write PHPStan types. See [Capture Shapes](docs/reference/capture-shapes.md).
- `JsonDumper` exports a tree for `json_encode()`, described by `ast.schema.json`.
- `NodeInterface::getChildren()`, `NodeFinder` and `AbstractTraversingVisitor`; the visitor bases default their `TReturn` to `null`.
- `RegexNode::isUnicode()`, `ValidationResult::render()` and `jsonSerialize()`, `VisualContextInterface`, `LintSeverity::level()` and `failsRun()`, and named PHPStan type aliases for the arrays the library returns.
- `PatternPrinter::asWritten()`, `normalized()` and `pretty()`; a parsed pattern prints back byte for byte, spelling and `/x` layout included.
- `regex format`, `Regex::format()`, `regex format --compact` and `Regex::compact()`.
- `validate()` fills `hint` for the common mistakes, and a "Did you mean" for a near group or property name.
- The attribute `PHPRegex\Parser\Attribute\RegexPattern` marks a parameter that receives a pattern.
- Deprecations are raised as Symfony's `trigger_deprecation()` does; nothing is deprecated in 2.0.0.

#### Lint and CLI
- `Regex::lint()` runs the lint rules for the facade's target, and nothing else: no validation, ReDoS, optimization, ignores or baseline; an invalid pattern throws what `parse()` throws. `new PatternLinter($rules, $target)` takes the target second.

- Lint rules for the regex defects SonarPHP reports, mapped in [SonarPHP Regex Rules](docs/reference/sonar.md): on by default at `warning`, `regex.lint.quantifier.emptyRepeat`, `regex.lint.anchor.alternationPrecedence`, `regex.lint.quantifier.possessiveImpossible`, `regex.lint.anchor.impossible.boundary`, `regex.lint.lookaround.impossible` and `regex.lint.group.empty`; off by default, `regex.lint.charclass.single` (style), `regex.lint.literal.multipleSpaces` (style) and `regex.lint.quantifier.lazyToClass` (perf).
- More lint rules, on by default: `regex.lint.compat.meaningChanges`, `regex.lint.group.alwaysEmptyCapture`, `regex.lint.literal.invisible`, `regex.lint.flag.useless.D`, `regex.lint.flag.useless.x`, `regex.lint.quantifier.lazyEnd`, `regex.lint.quantifier.assertion`, `regex.lint.unicode.multibyteInClassWithoutU` and `regex.lint.unicode.quantifiedMultibyteWithoutU` (errors), `regex.lint.unicode.propertyWithoutU` and `regex.lint.unicode.bracedHexWithoutU` (errors), and checks for a backreference read as octal in a class, quantifier characters in a class, `(.|\n)` and a quantified capturing group.
- More lint rules, off by default: `regex.lint.anchor.dollarNewline`, `regex.lint.quantifier.uselessLazy`, `regex.lint.lookaround.edgeQuantifier`, `regex.lint.lookaround.redundant`.
- `regex.lint.complexity` on demand (`checks.complexity`), and the ReDoS verdict as `regex.lint.redos` and `regex.lint.redos.search`.
- `checks` in `regex.json` (`checks.lint.rules`, `checks.redos`, `checks.optimizations`), `--lint`, `--no-lint`, `--enable-rule`, `--disable-rule`, `--redos`; a rule id is taken short or in full, and a misspelt one or a mistyped command is told the nearest name.
- Every lint finding links to its section of [the rule reference](docs/reference/rules.md): `docs_url` in JSON, `Read more:` in GitHub and Checkstyle, `codeDescription` in the editor, the PHPStan tip.
- `@regex-target php>=8.4` and `@regex-target-next-line` narrow the versions a pattern is judged for; `PHP_VERSION_ID` and `version_compare()` guards do the same.
- `@regex-ignore` takes the identifiers it silences, comma-separated.
- `--pattern-function` and `extraction.functions` declare project helpers that carry a pattern; `--interop` and `--no-interop` choose the presets.
- `regex --cache-dir <dir>` keeps the running PCRE2's answers about Unicode classes between runs.
- The JSON outputs name the target's locale and the PHP that ran the analysis, and the JSON of every command is written down in [the JSON output reference](docs/reference/json-output.md), frozen for 2.x.
- `regex lint` names the file it is held on after 10 seconds, and writes a progress line every 30 seconds without a terminal.
- `PHPRegex\Linter\Extraction\ExtractorInterface` is public: an application reads its own calls with it (the Symfony `extractor_service` setting), returning a list of `PatternOccurrence::found()`; the `@regex-ignore` markers of the files it reads apply to its patterns.

#### Analysis

- `RedosAnalyzer` proves the complexity class of one match attempt, with an attack string and `$upperBoundDegree`; `RedosAnalysis::headline()` is the one verdict every surface prints. See the [ReDoS guide](docs/guides/redos.md).
- The confirmed ReDoS mode replays an exponential attack on the running PCRE2 without the JIT; `Confirmation::wasSkipped()` tells a run that could not set the engine limits.
- `RedosAnalysis::$searchCost` prices the retries of an unanchored search, as for `/\s+$/` on a long run of spaces.
- `RedosRepairer` rewrites a vulnerable pattern into one proven linear, saying whether the automata prove it matches the same.
- `Regex::analyze()` takes the `$threshold`, `$mode` and `$confirmOptions` of `Regex::redos()`.
- `LanguageSolver` reads lookarounds, word boundaries, anchors anywhere, the `m` flag and the `x`, `U`, `n`, `J`, `S`, `X` flags; its character sets come from the running PCRE2.
- `LanguageSolver::matchEquivalent()`, `language()` and `acceptsPrefix()`. See [the solver reference](docs/reference/logic-solver.md).
- `TrivialMatchClassifier` (with `matchedLiteral()`) and `RequiredLiteralAnalyzer` find the `preg_*` calls a string function can do or prefilter. See [Prefilters](docs/reference/prefilters.md).
- `Optimizer\Optimizer`, the optimization `Regex::optimize()` runs, on any `RegexParser`; `OptimizationResult::$changes` names each kind of rewrite made.
- `SampleGenerator::generate()` returns a sample the running engine matches.
- The transpiler's `html-pattern` target (`regex transpile --target=html`), the value of an HTML `pattern` attribute.

#### Integrations

- `php-regex/regex-psalm`: types `$matches` of `preg_match()`, `preg_match_all()`, `preg_replace_callback()` and `preg_split()` from the pattern, narrows the subject, and reports invalid patterns.
- `php-regex/regex-rector`: `RegexSetList::STRING_FUNCTIONS` turns `preg_*` calls into `str_contains()`, `str_replace()` or `explode()` once the automata prove it; `RegexSetList::PCRE_UPGRADE` readies patterns for an older PHP; `RegexSetList::UPGRADE_2_0` moves 1.3 code to the 2.0 names.
- `php-regex/regex-phpunit`: `assertPatternMatches()`, `assertPatternDoesNotMatch()`, `assertPatternCompiles()` and `assertPatternIsLinear()`.
- PHPStan: `phpRegex.phpVersion`, `phpRegex.pcreVersion`, `phpRegex.locale`, `phpRegex.interop`; `RegexPatternArgumentRule` for `#[RegexPattern]` and `#[Language('RegExp')]` parameters; `regex.replacement.undefinedGroup`, always reported; `regex.trivialMatch`; the presets' static methods judged like `preg_*()`.
- Symfony: the `ValidRegex` constraint and its English catalogue; registration by Symfony Flex on `composer require`, the bundle being `PHPRegexSymfonyBundle`; `regex:explain`; `php_regex.extraction`, `locale`, `redos.enabled`, `analysis.complexity` and `report_unmatched_ignores`.
- Laravel: the bridge, with its `Regex` facade and the `regex:lint`, `regex:routes`, `regex:explain`, `regex:compare` and `regex:transpile` commands; the `ValidRegex` rule; `php-regex.extraction`, `vendor_paths`, `locale`, `analysis.complexity` and `report_unmatched_ignores`; Laravel 13.
- A language server, `bin/regex-lsp` (also `regex lsp`), with diagnostics, hover, completion and quick fixes, and the ReDoS verdict when `initializationOptions.redos` or `checks.redos.enabled` asks for it.

### Changed

- PHP 8.1 is supported by every package; the Symfony bundle runs on Symfony 6.4, 7.4 and 8, the Laravel bridge on Laravel 10.37 to 13.
- ReDoS severities follow the proven class: exponential is `critical`, polynomial of degree 3 or more `high`, of degree 2 `medium`, linear `safe`; a pattern proven linear carries none of the heuristics' findings. `RedosAnalyzer::ANALYSIS_VERSION` is `'1'`.
- The optimizer keeps a rewrite only when it writes the same `$matches`, checked by `LanguageSolver::matchEquivalent()`; a rewrite that adds a possessive quantifier or an atomic group is always proven. A rewrite of a pattern under `x` keeps its layout and comments.
- `max_lookbehind_length` limits only variable-length lookbehinds, as PCRE2's `max_varlookbehind` does.
- `Regex::generate()` throws `SampleGenerationException` when no sample matches. See [generate()](UPGRADE-2.0.md#generate-throws-when-it-finds-no-matching-sample).
- A character type, POSIX class or property can no longer end a class range (`[\w-_]` is refused, as PCRE refuses it).
- Reading and judging a pattern is faster: `validate()` of a new pattern takes about 60 µs where it took about 110 µs, `parse()` about 43 µs where it took 84 (about 24,000 patterns from open-source projects, PHP 8.4, PCRE2 10.49).
- The lint reads each pattern once, shares its automata between rules, skips a rule whose every identifier is off, and caps its workers by the CPU quota and memory limit of a container.
- The lint shares its work between workers as each finishes, shows the progress on stderr in three steps, and defaults to the logical CPUs the process may use.
- The language server analyses only the patterns a change touched.
- `ArrayCache` drops the tree used longest ago; a parser with a filesystem or PSR cache keeps the trees it read in memory in front of it.
- `RegexParser::CACHE_VERSION` fingerprints the code that builds a tree, and DFA caches are keyed on it: caches written by an earlier release are rebuilt. See [Cached ASTs](UPGRADE-2.0.md#cached-asts-are-rebuilt).
- A cache that throws while reading is a miss; an `\Error` it throws surfaces.
- The JSON the `regex` command prints is snake_case throughout, and the JSON of Symfony's `regex:analyze` and Laravel's `regex:routes` and `regex:compare` follows the same rules.
- The PHPStan rule no longer reports ReDoS on a call whose subject is constant.
- The Symfony bundle is an `AbstractBundle` whose services are private and named `php_regex.*`: autowire `PHPRegex\Toolkit\Regex`. See [Symfony](UPGRADE-2.0.md#symfony-the-bundles-services-are-private).
- The console commands name the author and ask for a GitHub star under `-v` only; `regex --help` lists the commands without every option.
- Patterns are displayed so that they read back as the same pattern: control bytes and invalid UTF-8 as `\xHH`, non-ASCII characters kept.
- Some messages are reworded: parse errors end with a period, `--php-version` errors name the option.
- Every exception lives in the `Exception` namespace of its package (`PHPRegex\Transpiler\Exception\TranspileException`, `PHPRegex\Generator\Exception\SampleGenerationException`, `PHPRegex\Linter\Exception\LintException`, `UnknownLintRuleException`, `UnsupportedTargetException`); `UnsupportedTargetException` and `PHPRegex\Parser\Exception\JsonEncodingException` are public. See [Breaking Changes](UPGRADE-2.0.md#every-exception-sits-in-the-exception-namespace-of-its-package).

### Removed

- The `regex clear-cache` command and its `cache:clear` alias, which cleared nothing. See [regex clear-cache](UPGRADE-2.0.md#regex-clear-cache-is-gone).
- `UnicodeNode`, `ClassOperationNode` and `ReDoSAnalyzerInterface`, which nothing produced or implemented, with `visitUnicode()`, `visitClassOperation()` and the class operation tokens. See [UnicodeNode](UPGRADE-2.0.md#unicodenode-and-visitunicode-are-gone) and [ClassOperationNode](UPGRADE-2.0.md#classoperationnode-and-the-class-operation-tokens-are-gone).
- `Automata\Alphabet\CharSet` and `AstToNfaTransformer`.
- `Regex::new()`, the `$tolerant` argument of `Regex::parse()`, and `Regex::cacheSeed()`. See [One way to do each thing](UPGRADE-2.0.md#one-way-to-do-each-thing-on-the-facade).
- The accessors of `ValidationResult` (`isValid()`, `getErrorMessage()`, …) and the methods of `AnalysisReport` that repeated its properties: read the properties. See [ValidationResult](UPGRADE-2.0.md#validationresulterror-is-the-message-alone).
- The three-argument form of `new UnicodePropNode()`.
- The PHPStan parameter `phpRegex.noNewMixed`, with `NoNewMixedRule` and `MixedTypeCollector`. See [The PHPStan extension](UPGRADE-2.0.md#the-phpstan-extension-reports-what-phpstan-does-not).
- `(*LIMIT_LOOKBEHIND=n)` as an override of `max_lookbehind_length`: PHP refuses the verb.
- Reading a 1.x lint baseline: regenerate it once. See [regex lint --baseline](UPGRADE-2.0.md#regex-lint---baseline).
- The 1.x comments `// @regex-lint-ignore` and `@regex-suppress`: write `@regex-ignore-next-line` and `@regex-ignore`. See [// @regex-lint-ignore](UPGRADE-2.0.md#-regex-lint-ignore) and [@regex-suppress](UPGRADE-2.0.md#regex-suppress).
- The `REGEX_PARSER_IDE` variable, replaced by `PHP_REGEX_IDE`, and every message naming the replacement of a 1.x configuration key: an old key is refused as unknown, or ignored in Laravel's configuration. See [Laravel](UPGRADE-2.0.md#laravel-configphp-regexphp-replaces-configregex-parserphp).

### Fixed

Most fixes of 2.0 bring a verdict in line with what PCRE2 and PHP do. The package changelogs list the fixes of the CLI, the lint, the bridges, the language server, the ReDoS analysis and the transpiler.

- `Regex::compatibility()` judged every point under the runtime's locale and default limits, whatever the facade was created with, and `Regex::analyze()` linted for the running PHP and PCRE2 instead of the facade's target.
- Validation follows the targeted PCRE2 release: variable-length lookbehinds, `{,n}` and padded counts (10.43), `\K` in a lookaround (refused from PHP 8.5), spaces in `\x{ 41 }` and braced references, the `r` and `n` modifiers, ASCII options `(?aD)`, `(*CASELESS_RESTRICT)`, `\k` in a class, script and property names per Unicode release, group names of 33 to 128 characters.
- Patterns PHP refuses are refused: unknown escapes (`\i`), `\p` with no property, `\N{name}`, surrogates and code points past U+10FFFF, `\c` before a control character, `(?u)`, a quantifier on a start-of-pattern setting, quoted or escaped group names, a malformed `\k`, `(**...)`, mixed-case `(*PLA:...)`, a `(*MARK)` name past 255, NUL bytes before PHP 8.2, invalid UTF-8 under `(*UTF)`, conditions that are no assertion, number, version or name.
- Patterns PHP compiles are accepted: `\K` in a lookbehind, `[[:^word:]]`, `(?i-i)`, version conditions, `(?(R)...)` naming a group `R`, `(?(R0)...)`, `[a&&b]` and `[a--b]` as members, `\N{U+0041}` in a class, relative calls `\g<-1>`, bounded calls in a lookbehind, a verb wrapping nested groups (`(*atomic:((a)))`).
- A pattern too large to compile, a lookbehind too long or too complicated, and nesting past 250 parentheses are judged as the targeted PCRE2 counts them, the locale's character tables included.
- `\NN` is a back reference or an octal escape as PCRE reads it (`(a)\11`, `\128`, `\666`), and `\g{name}`, `\g'1'` and relative references across a branch reset mean what PCRE says.
- Byte-mode patterns are read byte by byte: a quantifier after `é` repeats its last byte, and a class range ends on a byte.
- `\Q...\E`, `(?#...)` comments and `#` comments under `x` are read as PCRE reads them: between an item and its quantifier, in a class, inside alphabetic assertions, ending at the newline the leading verb sets.
- A delimiter is read as PHP reads it: a delimiter inside a class or a comment still ends the pattern, a form feed before it is skipped, a tab after it is refused.
- Verdicts no longer depend on the process locale, `intl`, mbstring's internal encoding or the caller's `pcre.backtrack_limit`, and every pattern the library runs (runtime validation, the ReDoS replay, the generators, the PHPStan extension) runs without the JIT, which crashes PHP on some patterns in PCRE2 10.40 to 10.49.
- The first error reported is the one PCRE2 meets first, at the offset and with the code the targeted release reports (10.45 and 10.47 moved most offsets): ranges in open classes, unclosed names, references, callouts, verbs, counts and conditions.
- `parseTolerant()` reports the same error as `validate()`; an unescaped delimiter that ends the pattern early is named as such.
- `{,5}` and padded counts mean the same to every visitor, and `\b{g}`, `[[:<:]]`, `\x0` and `\N{U+ }` parse as PCRE reads them.
- Printing keeps what PCRE reads: no item fused into a longer escape (`(a)\1\E0`), no double-wrapped POSIX class, version and recursion conditions, string callouts, `(*:label)`, `\g{name}`, bracket delimiters and `\c\` before a delimiter.
- Explanations, text and HTML, say what each construct matches under the flags in force (`.`, anchors, `\X`, `\d` under `/u`, conditions, Unicode properties) and escape their HTML once; the HTML highlighter escapes `<`, `>` and `&`.
- Highlighters, explanations and the Mermaid diagram spell invalid UTF-8 as `\xHH` or U+FFFD and cut labels on a character boundary.
- The optimizer no longer changes what a pattern matches: no `\w` for `[a-zA-Z0-9_]`, no dropped group a quantifier or `(?1)` needs, no `/s` or `/m` dropped for a dot or anchor in a DEFINE group, no branch reordering by `factorize`, no possessive under `U`, bytes counted as bytes without `/u`.
- The `Modernizer` writes `\s` and `\d` only where they match the same characters, and keeps condition references.
- The automata solver reads possessive quantifiers and atomic groups, `$` before a final newline, newline conventions such as `(*CRLF)`, `(*UTF8)`, the `r` modifier, ASCII options and Unicode case folds (the Kelvin sign under `/iu`); counter-examples without `/u` are raw bytes, and `SolverOptions::$maxTransitionsProcessed` defaults to 1,000,000.
- The ReDoS proof and heuristics read inline options, `xx`, lookarounds, conditions and atomic alternations as PCRE does, and give one verdict to two spellings of one pattern; a proven verdict no longer turns `Unknown` where the replay cannot set its limits.
- `generate()` gives samples the pattern matches: `\p{...}` properties, negated classes, lookarounds, references, multibyte ranges, `_`, `*` or `)` as delimiter.
- `literals()` claims no prefix a lookaround or an unknown branch does not guarantee, and `Regex::literals('/a{99999999}/')` returns at once; length ranges count `\Q...\E`, empty groups and settings right.
- Lint rules read constructs as PCRE does, without the false positives they gave: branch-reset numbering, newline conventions (`anchor.impossible.end`), `(?^)` (`flag.redundant`), script runs (`backref.useless`), caseless branches (`alternation.overlap`), `\N{U+41}` (`escape.suspicious`), octal `\NN` (`backref.undefined`), `(*UTF)` (`unicode.propertyWithoutU`), and the target's PCRE2 for the rules that ask the automata.
- `MetricsCollector` counts each tree afresh, and every visitor covers every node type.
- `ArrayCache` returns the trees it stores, which it never did, the PSR-6 and PSR-16 caches too for a pattern holding a comma, PSR adapters count their hits, and a cache key names the PHP version, the PCRE2 release and the target.
- Hostile patterns are read in linear time: long `\Q...\E` runs, unclosed callouts, verbs and version conditions, nested subroutine calls in a lookbehind, `explain()` of nested quantified groups; checking a suggested rewrite stays within a budget (Symfony's 4 KB URL pattern took about 1 GB), and process-wide caches are bounded.
- `regex redos` restores the ini settings it changed, and the CLI runs where `ini_get()` or `ini_set()` is disabled.
- PHPStan reads a `preg_*` call with named arguments and each pattern of an array, and refuses a `phpRegex.phpVersion` it cannot read; the Rector rules require Rector 2.4.
- Laravel: `redos.enabled`, the `automata` settings, every optimization setting and `php-regex.cache.store` are honoured; an unknown threshold no longer breaks `php artisan list`, `regex:lint` without a path lints `app/` and refuses a bad `--jobs`, and the rule extractor no longer backtracks on a run of backslashes.
- Symfony: `regex:security` reads quoted firewall patterns, console output escapes `<` in patterns and values, `regex:transpile` refuses an empty `--target`, and `regex:lint` no longer reports on a route requirement what the router adds to it.
- `regex lint --redos`, and the bridges' `redos.enabled`, report ReDoS findings, which they never did; both PHP extractors read the same calls, arrays of patterns included.
- The lint report: `--baseline` keeps columns, `--generate-baseline` with `--baseline` covers the full report, `--format=json` encodes byte-mode patterns, a file of invalid UTF-8 or of several megabytes no longer breaks the run.
- The CLI: `regex version`, `self-update` and `help` refuse arguments they do not take, the GitHub, Checkstyle and JUnit reports show a low ReDoS verdict as a warning, every finding of one rule in one pattern is reported, and forked workers neither run the shutdown functions they inherit nor repeat the caller's output buffers.
- The language server places diagnostics on the text at fault, publishes the hint, reads any delimiter PHP accepts and `checks.lint.rules`, offers only proven quick fixes with their quotes escaped, answers a malformed message or a request after `shutdown` with an error, and reports the library's version.
- `php-regex/php-regex` refuses the Laravel and Symfony releases its bridges refuse, and the linter conflicts with `nikic/php-parser` older than 4.18.

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
