# Backward Compatibility Promise

PHPRegex follows [semantic versioning](https://semver.org/). Every package of
the family (`php-regex/regex-*`) is released together, with one version number,
and each requires its siblings at exactly its own version (Composer's
`self.version`): you never compute a compatibility matrix between them. Install
and update them together, with `composer update 'php-regex/*'`; Composer refuses
a partial update.

This page says what a minor release (2.1, 2.2, …) and a patch release
(2.0.1, …) may change, and what only the next major release (3.0) may change.

## What the promise covers

Every class, interface, trait and enum **not marked `@internal`**, with its
public methods, public properties and public constants. A class marked
`@internal` is not part of the API: it may change in any release.

Some `@internal` classes and constructors are shared between packages. They
may change in any release, since siblings always run at the same version. Code
outside this project must not use them.

The `PHPRegex\Parser\Hir` classes of `regex-parser` are `@internal` like the
rest: `regex-automata` and `regex-redos`, which build on them, move with them.

Result objects are built by the library. Their class, methods and properties
are public, but their constructors are `@internal`: read them, never build
them. A minor release may add a constructor parameter, required or not. If a
test of your own code needs one, get it from the producer on a known pattern,
for example `LanguageSolver::equivalent()` for an `EquivalenceResult` or
`RedosAnalyzer::analyze()` for a `RedosAnalysis`. The result objects are:

- `regex-parser`: `TolerantParseResult`, `Validation\ValidationResult`,
  `Analysis\GroupNumbering`, `Analysis\LiteralExtractionResult`,
  `Analysis\CaptureShape`, `Analysis\CaptureGroupShape`, `Engine\PcreMatch`,
  `Engine\PcreError`;
- `regex-optimizer`: `OptimizationResult`, `RedosRepair`;
- `regex-automata`: `Solver\EquivalenceResult`, `Solver\IntersectionResult`,
  `Solver\SubsetResult`, `Solver\MatchEquivalenceResult`, `Language`,
  `TrivialMatch`;
- `regex-redos`: `RedosAnalysis`, `Finding`, `Hotspot`, `RedosWitness`,
  `Confirmation`, `ConfirmationSample`;
- `regex-transpiler`: `TranspileResult`;
- `regex-linter`: `Rule\RuleViolation`;
- `regex-toolkit`: `AnalysisReport`.

`@internal` still means removable. The 2.0.0 release removed `Automata\Alphabet\CharSet`
(use `Parser\Hir\CharSet`) and the AST-walking transformer behind the solver
(now `Transform\HirToNfaTransformer`); [UPGRADE-2.0.md](../../UPGRADE-2.0.md)
maps every removed name.

In short, the public surface is:

| package | public |
|---|---|
| `regex-parser` | `RegexParser`, `ParserOptions`, `PcreTarget`, `PcreFeature`, `ErrorCode`, `DelimitedPattern`, `TolerantParseResult`, `NodeVisitorInterface`, `AbstractNodeVisitor`, `AbstractTraversingVisitor`, `NodeWalker`, `NodeFinder`, `TraversalAction`, `Token\Token`, `Token\TokenStream`, `Token\TokenType`, `Validation\ValidationResult`, `Validation\ValidationErrorCategory`, `Printer\PatternPrinter`, `Printer\NodeDumper`, `Analysis\ComplexityScorer`, `Analysis\GroupNumbering`, `Analysis\GroupNumberingCollector`, `Analysis\LengthRangeCalculator`, `Analysis\LiteralExtractor`, `Analysis\LiteralExtractionResult`, `Analysis\LiteralSet`, `Analysis\MetricsCollector`, `Analysis\CaptureShapeAnalyzer`, `Analysis\CaptureShape`, `Analysis\CaptureGroupShape`, `Analysis\Participation`, `Analysis\RequiredLiteralAnalyzer`, `Cache\CacheInterface`, `Cache\RemovableCacheInterface`, `Cache\ArrayCache`, `Cache\NullCache`, `Cache\FilesystemCache`, `Cache\PsrCacheAdapter`, `Cache\PsrSimpleCacheAdapter`, `Engine\PcreEngine`, `Engine\PcreError`, `Engine\PcreLimits`, `Engine\PcreMatch`, `Exception\ExceptionInterface`, `Exception\RegexException`, `Exception\LexerException`, `Exception\ParserException`, `Exception\SyntaxErrorException`, `Exception\SemanticErrorException`, `Exception\RecursionLimitException`, `Exception\ResourceLimitException`, `Exception\InvalidRegexOptionException`, `Exception\CacheException`, `Node\*` |
| `regex-explain` | `TextExplainer`, `HtmlExplainer`, `AsciiTreeRenderer`, `MermaidRenderer`, `RailroadSvgRenderer`, `Highlighter\ConsoleHighlighter`, `Highlighter\HtmlHighlighter` |
| `regex-optimizer` | `Optimizer`, `OptimizerOptions`, `OptimizationResult`, `Modernizer`, `RedosRepairer`, `RedosRepair` |
| `regex-generator` | `SampleGenerator`, `TestCaseGenerator`, `SampleGenerationException` |
| `regex-automata` | `LanguageSolver`, `Options\SolverOptions`, `Options\MatchMode`, `Determinization\DeterminizationAlgorithm`, `Minimization\MinimizationAlgorithm`, `Solver\EquivalenceResult`, `Solver\IntersectionResult`, `Solver\SubsetResult`, `Solver\MatchEquivalenceResult`, `Model\Dfa`, `Model\DfaState`, `Solver\DfaCacheInterface`, `Solver\InMemoryDfaCache`, `Exception\ComplexityException`, `TrivialMatchClassifier`, `TrivialMatch`, `TrivialMatchKind`, `Language` |
| `regex-redos` | `RedosAnalyzer`, `RedosAnalysis`, `RedosOptions`, `RedosSeverity`, `RedosComplexity`, `RedosProof`, `RedosWitness`, `RedosMode`, `RedosConfidence`, `Finding`, `Hotspot`, `Heatmap`, `Confirmation`, `ConfirmationSample`, `ConfirmationOptions`, `ConfirmationRunner`, `ConfirmationRunnerInterface` |
| `regex-transpiler` | `Transpiler`, `TranspileOptions`, `TranspileResult`, `TranspileException` |
| `regex-linter` | `PatternLinter`, `LintSeverity`, `LintException`, `Rule\RuleViolation` |
| `regex-toolkit` | `Regex`, `AnalysisReport`, `OutputFormat` |
| `regex-phpstan` | `RegexPatternRule` |
| `regex-symfony` | `PHPRegexBundle` |
| `regex-laravel` | `PHPRegexServiceProvider`, `Facades\Regex` |
| `regex-cli` | no PHP class is public |
| `regex-language-server` | no PHP class is public |

Each path is relative to the package namespace: `Analysis\CaptureShape` in
`regex-parser` is `PHPRegex\Parser\Analysis\CaptureShape`. `Node\*` is every
class of `Node\`.

In `regex-parser`, `NodeVisitorInterface`, `AbstractNodeVisitor` and
`AbstractTraversingVisitor` are the visitor base classes. `Analysis\ByteCharSet`,
`Analysis\CharSetAnalyzer` and `Cache\AstSerializer` are not public, nor is any
other class of those namespaces left out of the row.

In `regex-automata`, `Determinization\DeterminizationAlgorithm` and
`Minimization\MinimizationAlgorithm` are the two algorithm enums.

In `regex-redos`, the promise covers `RedosAnalyzer::ANALYSIS_VERSION`,
`RedosAnalysis::isProvenSafe()` and `headline()`, `RedosSeverity::rank()`, and
`Confirmation::wasSkipped()` and `Confirmation::LIMITS_UNAVAILABLE`.

The bridges and tools carry more than their classes:

- `regex-phpstan`: the `phpRegex` parameters of `extension.neon`.
- `regex-symfony`: the `php_regex` configuration, and the commands' names and
  options.
- `regex-laravel`: the `Regex` facade (`Facades\Regex`), the `php-regex`
  configuration, and the commands' names and options.
- `regex-cli`: the `regex` command, with its commands, options, exit codes and
  JSON output.
- `regex-language-server`: the protocol it speaks.

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
  ones you set, or by name. 2.0.0 adds one: `Optimizer::__construct()` takes an
  optional trailing `?DfaCacheInterface $dfaCache = null` — with it, one
  instance reuses the DFAs its equivalence checks compiled, and without it a
  fresh in-memory cache is used, as before.
- **New classes, methods and options**, and new keys in a JSON report or a
  configuration file.
- **Message texts**: an error or lint message may be reworded; the CHANGELOG
  says so. Match on the error code, not on the message.
- **Support for a new PCRE2 release**, as `PcreFeature` cases and the targets
  that use them.
- **A wider ReDoS model**: a construct the structural heuristics judge today
  (a backreference, a conditional, a non-atomic lookaround, …) may become
  proven, and an ambiguity listed as without witness may get one, with
  `RedosAnalyzer::ANALYSIS_VERSION` raised. Their patterns move from
  `proof: heuristic` to `proof: proven`, and their severity may move with them.
  The heuristic lint issues (nested quantifiers, dot-star in a quantifier,
  overlapping character sets) are dropped for a pattern the analysis proves
  linear, so they may disappear for a pattern the wider model now proves.
- **New ReDoS options**: a configuration key for the analysis budget may be
  added; none is removed.
- **What a lint rule reports**: a rule may report more or fewer patterns when
  its analysis reads a case it missed, and the CHANGELOG names the rule. A
  patch only removes a false positive.
- **Deprecations**: anything removed in 3.0 is deprecated in a 2.x minor first,
  with the replacement named.

## What stays for all of 2.x

- The **values of `ErrorCode`** (`regex.group.unclosed`, …) and the
  **identifiers the PHPStan extension reports** (`regex.invalidForTarget`,
  `regex.redos`, …): baselines and ignore lists keep working.
- The **text of the PHPStan ReDoS messages**: `Exponential backtracking
  (ReDoS): %s`, `Polynomial backtracking (ReDoS): %s` and `Potential
  backtracking (ReDoS): %s`, followed by the pattern. The severity, the proof
  and the attack are in the tip, which may change. Which patterns get an error
  is not frozen: when the analysis improves, an error may appear, disappear or
  move to another class (Exponential, Polynomial, Potential). Regenerate the
  baseline after an upgrade that changes the analysis; the CHANGELOG says
  when one does.
- The **configuration keys** of `regex.json`, of the Symfony bundle, of the
  Laravel config file and of the PHPStan extension, and the **exit codes** of
  every command (0 done, 1 a pattern or file problem, 2 a usage or
  configuration error).
- The **severity of the lint rules**: a minor never raises an existing rule to
  error severity, the one that fails `regex lint`, and a new rule lands at
  warning severity or lower.
- The **pattern in the machine reports**: the JSON, Checkstyle and JUnit
  reports of `regex lint` carry each pattern as it is written, with only what
  their format cannot hold spelled as an escape, so a tool can match a result
  to its source. The console and GitHub reports show a display form that reads
  back as the same pattern; that console form may be refined. The PHPStan
  messages show it cut after 50 characters, and that rendering stays: a
  baseline written for 2.0 keeps matching.
- The **`regex lint` baseline file**: a baseline generated by 2.0 is read by
  every 2.x, and an issue it records stays matched when its line or its
  message changes.
- The **PHP floor**, PHP 8.2: no 2.x release raises it.

## What a patch release may change

A patch release fixes bugs. A pattern the library judged differently from
PHP's PCRE2 for the same release is a bug: its fix ships in a patch even though
it changes a verdict, an error code or an offset, and the CHANGELOG lists it.

The same holds for the ReDoS model. `safe (proven)` promises that the model
holds no ambiguity; a pattern given that verdict on which the running engine
exhausts its backtrack limit in one match attempt is a soundness bug. Its fix
ships in a patch, with `RedosAnalyzer::ANALYSIS_VERSION` raised, even though
it changes a severity, and may move the pattern to `proof: heuristic`.

## Caches

`RegexParser::CACHE_VERSION` changes whenever the code that builds a tree
changes, in any release, and every cached tree is rebuilt then. It covers the
code that turns a tree into automata too: a `DfaCacheInterface` you keep across
releases is keyed on it, so its DFAs are rebuilt after such an upgrade. A cache
is never a format to depend on.
