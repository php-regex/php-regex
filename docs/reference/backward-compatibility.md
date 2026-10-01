# Backward Compatibility Promise

PHPRegex follows [semantic versioning](https://semver.org/). Every package of
the family (`php-regex/regex-*`) is released together, with one version number,
and each requires its siblings at the same minor version: you never compute a
compatibility matrix between them.

This page says what a minor release (2.1, 2.2, …) and a patch release
(2.0.1, …) may change, and what only the next major release (3.0) may change.

## What the promise covers

Every class, interface, trait and enum **not marked `@internal`**, with its
public methods, public properties and public constants. A class marked
`@internal` is not part of the API: it may change in any release. Some of them
are used across packages; those keep their signatures within 2.x because the
packages are released together, but code outside this project must not rely on
them.

In short, the public surface is:

| package | public |
|---|---|
| `regex-parser` | `RegexParser`, `ParserOptions`, `PcreTarget`, `PcreFeature`, `ErrorCode`, `DelimitedPattern`, `TolerantParseResult`, every class of `Node\`, the visitor base classes (`NodeVisitorInterface`, `AbstractNodeVisitor`, `AbstractTraversingVisitor`), `NodeWalker`, `NodeFinder`, `TraversalAction`, `Token\`, `Validation\ValidationResult` and `ValidationErrorCategory`, `Printer\`, the analyses of `Analysis\` (but `ByteCharSet` and `CharSetAnalyzer`), `Cache\` (but `AstSerializer`), `Engine\`, and the exceptions of `Exception\` |
| `regex-explain` | the explainers, the renderers, `Highlighter\ConsoleHighlighter` and `HtmlHighlighter` |
| `regex-optimizer` | `Optimizer`, `OptimizerOptions`, `OptimizationResult`, `Modernizer` |
| `regex-generator` | `SampleGenerator`, `TestCaseGenerator`, `SampleGenerationException` |
| `regex-automata` | `LanguageSolver`, `SolverOptions`, `MatchMode`, the two algorithm enums, the three result classes, `Dfa`, `DfaState`, `DfaCacheInterface`, `InMemoryDfaCache`, `Exception\ComplexityException` |
| `regex-redos` | `RedosAnalyzer`, `RedosAnalysis`, `RedosSeverity`, `RedosMode`, `RedosConfidence`, `Finding`, `Hotspot`, `Heatmap`, `Confirmation`, `ConfirmationSample`, `ConfirmationOptions`, `ConfirmationRunner`, `ConfirmationRunnerInterface` |
| `regex-transpiler` | `Transpiler`, `TranspileOptions`, `TranspileResult`, `TranspileException` |
| `regex-linter` | `PatternLinter`, `Diagnostic`, `DiagnosticType`, `LintSeverity`, `LintException`, `Rule\RuleViolation` |
| `regex-toolkit` | `Regex`, `AnalysisReport`, `OutputFormat` |
| `regex-phpstan` | `RegexPatternRule`, and the `phpRegex` parameters of `extension.neon` |
| `regex-symfony` | `PHPRegexBundle`, the `php_regex` configuration, the commands' names and options |
| `regex-laravel` | `PHPRegexServiceProvider`, the `Regex` facade, the `php-regex` configuration, the commands' names and options |
| `regex-cli` | the `regex` command: its commands, options, exit codes and JSON output (no PHP class is public) |
| `regex-language-server` | the protocol it speaks (no PHP class is public) |

## Using the API: call, extend, implement

Calling a public method, reading a public property, catching a public exception
and type-hinting against a public class or interface are covered.

Extending and implementing are covered only where the API is meant for it:

- **Extend** `AbstractNodeVisitor` or `AbstractTraversingVisitor` to write a
  visitor. Implementing `NodeVisitorInterface` directly is not covered: a minor
  release may add a method to it for a new node, and the abstract classes
  provide that method for you.
- **Implement** `CacheInterface` or `RemovableCacheInterface` to bring your own
  AST cache, and `DfaCacheInterface` for your own DFA cache.

Every other public interface (`NodeInterface`, `ConfirmationRunnerInterface`, …)
is there to type against, not to implement. Lint rules, transpiler targets and
pattern sources are not extension points in 2.0: there is no public registry to
add one to yet.

## What a minor release may change

- **New node types**, with the matching `visitX()` method on
  `NodeVisitorInterface` and its default in the abstract visitors.
- **New enum cases**, in `ErrorCode`, `TokenType`, `PcreFeature`, `GroupType`
  and the others. A `match` over one of these enums needs a `default` arm.
- **New optional parameters**: a node constructor or a method may gain a
  trailing parameter with a default value. Pass arguments by position for the
  ones you set, or by name.
- **New classes, methods and options**, and new keys in a JSON report or a
  configuration file.
- **Message texts**: an error or lint message may be reworded; the CHANGELOG
  says so. Match on the error code, not on the message.
- **Support for a new PCRE2 release**, as `PcreFeature` cases and the targets
  that use them.
- **Deprecations**: anything removed in 3.0 is deprecated in a 2.x minor first,
  with the replacement named.

## What stays for all of 2.x

- The **values of `ErrorCode`** (`regex.group.unclosed`, …) and the
  **identifiers the PHPStan extension reports** (`regex.invalidForTarget`,
  `regex.redos`, …): baselines and ignore lists keep working.
- The **configuration keys** of `regex.json`, of the Symfony bundle, of the
  Laravel config file and of the PHPStan extension, and the **exit codes** of
  every command (0 done, 1 a pattern or file problem, 2 a usage or
  configuration error).
- The **PHP floor**, PHP 8.2.

## What a patch release may change

A patch release fixes bugs. A pattern the library judged differently from
PHP's PCRE2 for the same release is a bug: its fix ships in a patch even though
it changes a verdict, an error code or an offset, and the CHANGELOG lists it.

## Caches

`RegexParser::CACHE_VERSION` changes whenever the code that builds a tree
changes, in any release, and every cached tree is rebuilt then. A cache is
never a format to depend on.
