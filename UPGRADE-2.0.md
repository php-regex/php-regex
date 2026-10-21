# Upgrading from 1.x to 2.0

2.0 is a new major line. The 1.x line stays on the
[`1.x` branch](https://github.com/php-regex/php-regex/tree/1.x), with its own
upgrade notes; this guide covers moving from 1.3 to 2.0. For every change, see
[CHANGELOG.md](CHANGELOG.md).

### Packages

`yoeunes/regex-parser` stays on 1.x. 2.0 ships as `php-regex/regex-*`
packages; replace it with the one you use:

| what you use | require |
|---|---|
| the `Regex` facade, or a bit of everything | `php-regex/regex-toolkit` |
| the parser, the validator and the AST only | `php-regex/regex-parser` |
| the PHPStan extension | `php-regex/regex-phpstan` (dev) |
| regex assertions in your test suite | `php-regex/regex-phpunit` (dev) |
| the Symfony bundle | `php-regex/regex-symfony` |
| the Laravel provider | `php-regex/regex-laravel` |
| the `regex` command | `php-regex/regex-cli` (dev) |
| the language server | `php-regex/regex-language-server` (dev) |

The other packages (`regex-explain`, `regex-optimizer`, `regex-generator`,
`regex-automata`, `regex-redos`, `regex-transpiler`, `regex-linter`) come with
the toolkit, or alone when only that part is needed.

`php-regex/php-regex`, the package that installs all seventeen at once,
carries the 1.x line as well: Packagist lists under that name the same
v0.1.0 to v1.3.0 releases as `yoeunes/regex-parser`. Until 2.0.0 is tagged,
`composer require php-regex/php-regex` with no version installs 1.3, and a
`php-regex/regex-*` package with no version installs nothing, having no
stable release yet. Before the tag, pin the development branch, as the
installation commands of the documentation do:

```bash
composer config minimum-stability dev
composer config prefer-stable true
composer require php-regex/regex-toolkit:2.x-dev
```

Every package runs on PHP 8.1 or later, `php-regex/php-regex` included:
`regex-symfony` on Symfony 6.4, 7.4 and 8, `regex-laravel` on Laravel 10.37
to 13. `php-regex/php-regex` refuses an older Symfony or Laravel as those two
packages do, so Composer says so at install time.

### Names

Every class moved from `RegexParser\` to `PHPRegex\`, under the package it
ships in, and many took a name that says what they are. Enum cases are in
PascalCase, and a few methods are renamed. The sections after this one name
classes as 1.3 did; the tables at the end of this one give each 2.0 name.

A few 1.3 classes map to a 2.0 class marked `@internal`, which the
[backward compatibility promise](docs/reference/backward-compatibility.md)
does not cover: `RegexProblem` and `ProblemType` became `Linter\Diagnostic`
and `Linter\DiagnosticType`, built only by the lint commands. Read lint
results through `PatternLinter::getIssues()` (`Rule\RuleViolation`) or the
CLI's JSON output.

A Rector set makes these changes in your code. It ships with the Rector
rules, in `php-regex/regex-rector` (`php-regex/php-regex` carries it too):

```bash
composer require --dev php-regex/regex-rector
```

Add the set to your `rector.php` and run Rector twice:

```php
use PHPRegex\Rector\Set\RegexSetList;
use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withPaths([__DIR__.'/src'])
    ->withSets([RegexSetList::UPGRADE_2_0])
    ->withImportNames(removeUnusedImports: true);
```

Without `withImportNames()`, Rector writes each new name in full,
`\PHPRegex\Toolkit\Regex::create()`, and leaves the `use` lines of the 1.x names.

It renames classes, enum cases and the renamed methods; the second run catches
the methods called on an object whose class the first run renamed. It does not change
what the tables mark as removed, the configuration keys below, the error codes
(see "Error codes are an `ErrorCode` enum") or the offsets (see "Every offset
counts from the pattern body").

The configuration takes the PHPRegex name:

| 1.3 | 2.0 |
|---|---|
| Symfony: `RegexParserBundle`, configured under `regex_parser:` | `PHPRegex\Symfony\PHPRegexBundle`, configured under `php_regex:` |
| Symfony: services and parameters `regex_parser.*` | `php_regex.*` |
| PHPStan: parameter `regexParser` | `phpRegex` |
| PHPStan: rule `RegexParserRule` | `PHPRegex\PHPStan\RegexPatternRule` |
| Laravel: `RegexParserServiceProvider` | `PHPRegex\Laravel\PHPRegexServiceProvider` |
| Laravel: `config/regex-parser.php`, key `regex-parser` | `config/php-regex.php`, key `php-regex` |
| Laravel: publish tag `regex-parser-config` | `php-regex-config` |
| Laravel: container entries `regex-parser.*` | `php-regex.*` |

Laravel loads a `config/regex-parser.php` left from 1.3 but nothing reads it
and nothing warns about it: every setting in it silently takes the package
default. Publish the new file, move your settings to it, and delete the old
one.

<!-- upgrade-map:start (php tests/Tools/write_upgrade_map.php) -->

| 1.3 | 2.0 |
|---|---|
| `RegexParser\AnalysisReport` | `PHPRegex\Toolkit\AnalysisReport` |
| `RegexParser\Automata\Api\RegexLanguageSolver` | `PHPRegex\Automata\LanguageSolver` |
| `RegexParser\Automata\AstToNfaTransformer` | `PHPRegex\Automata\Transform\HirToNfaTransformer` |
| `RegexParser\Automata\Builder\DfaBuilder` | `PHPRegex\Automata\Builder\DfaBuilder` |
| `RegexParser\Automata\Builder\NfaBuilder` | `PHPRegex\Automata\Builder\NfaBuilder` |
| `RegexParser\Automata\Determinization\DeterminizationAlgorithm` | `PHPRegex\Automata\Determinization\DeterminizationAlgorithm` |
| `RegexParser\Automata\Determinization\DeterminizationAlgorithmFactory` | `PHPRegex\Automata\Determinization\DeterminizationAlgorithmFactory` |
| `RegexParser\Automata\Determinization\DeterminizationAlgorithmInterface` | `PHPRegex\Automata\Determinization\DeterminizationAlgorithmInterface` |
| `RegexParser\Automata\Determinization\SubsetConstruction` | `PHPRegex\Automata\Determinization\SubsetConstruction` |
| `RegexParser\Automata\Determinization\SubsetConstructionIndexed` | `PHPRegex\Automata\Determinization\SubsetConstructionIndexed` |
| `RegexParser\Automata\Determinization\WorkBudgetAwareDeterminizationAlgorithmInterface` | `PHPRegex\Automata\Determinization\WorkBudgetAwareDeterminizationAlgorithmInterface` |
| `RegexParser\Automata\Dfa` | `PHPRegex\Automata\Model\Dfa` |
| `RegexParser\Automata\DfaBuilder` | `PHPRegex\Automata\Builder\DfaBuilder` |
| `RegexParser\Automata\DfaMinimizer` | `PHPRegex\Automata\Minimization\DfaMinimizer` |
| `RegexParser\Automata\DfaState` | `PHPRegex\Automata\Model\DfaState` |
| `RegexParser\Automata\EquivalenceResult` | `PHPRegex\Automata\Solver\EquivalenceResult` |
| `RegexParser\Automata\HopcroftWorklist` | `PHPRegex\Automata\Minimization\HopcroftWorklist` |
| `RegexParser\Automata\IntersectionResult` | `PHPRegex\Automata\Solver\IntersectionResult` |
| `RegexParser\Automata\MatchMode` | `PHPRegex\Automata\Options\MatchMode` |
| `RegexParser\Automata\MinimizationAlgorithm` | `PHPRegex\Automata\Minimization\MinimizationAlgorithm` |
| `RegexParser\Automata\MinimizationAlgorithmFactory` | `PHPRegex\Automata\Minimization\MinimizationAlgorithmFactory` |
| `RegexParser\Automata\MinimizationAlgorithmInterface` | `PHPRegex\Automata\Minimization\MinimizationAlgorithmInterface` |
| `RegexParser\Automata\Minimization\DfaMinimizer` | `PHPRegex\Automata\Minimization\DfaMinimizer` |
| `RegexParser\Automata\Minimization\HopcroftWorklist` | `PHPRegex\Automata\Minimization\HopcroftWorklist` |
| `RegexParser\Automata\Minimization\MinimizationAlgorithm` | `PHPRegex\Automata\Minimization\MinimizationAlgorithm` |
| `RegexParser\Automata\Minimization\MinimizationAlgorithmFactory` | `PHPRegex\Automata\Minimization\MinimizationAlgorithmFactory` |
| `RegexParser\Automata\Minimization\MinimizationAlgorithmInterface` | `PHPRegex\Automata\Minimization\MinimizationAlgorithmInterface` |
| `RegexParser\Automata\Minimization\MoorePartitionRefinement` | `PHPRegex\Automata\Minimization\MoorePartitionRefinement` |
| `RegexParser\Automata\Minimization\WorkBudgetAwareMinimizationAlgorithmInterface` | `PHPRegex\Automata\Minimization\WorkBudgetAwareMinimizationAlgorithmInterface` |
| `RegexParser\Automata\Model\Dfa` | `PHPRegex\Automata\Model\Dfa` |
| `RegexParser\Automata\Model\DfaState` | `PHPRegex\Automata\Model\DfaState` |
| `RegexParser\Automata\Model\Nfa` | `PHPRegex\Automata\Model\Nfa` |
| `RegexParser\Automata\Model\NfaFragment` | `PHPRegex\Automata\Model\NfaFragment` |
| `RegexParser\Automata\Model\NfaState` | `PHPRegex\Automata\Model\NfaState` |
| `RegexParser\Automata\Model\NfaTransition` | `PHPRegex\Automata\Model\NfaTransition` |
| `RegexParser\Automata\MoorePartitionRefinement` | `PHPRegex\Automata\Minimization\MoorePartitionRefinement` |
| `RegexParser\Automata\Nfa` | `PHPRegex\Automata\Model\Nfa` |
| `RegexParser\Automata\NfaBuilder` | `PHPRegex\Automata\Builder\NfaBuilder` |
| `RegexParser\Automata\NfaFragment` | `PHPRegex\Automata\Model\NfaFragment` |
| `RegexParser\Automata\NfaState` | `PHPRegex\Automata\Model\NfaState` |
| `RegexParser\Automata\NfaTransition` | `PHPRegex\Automata\Model\NfaTransition` |
| `RegexParser\Automata\Options\MatchMode` | `PHPRegex\Automata\Options\MatchMode` |
| `RegexParser\Automata\Options\SolverOptions` | `PHPRegex\Automata\Options\SolverOptions` |
| `RegexParser\Automata\RegexSolver` | `PHPRegex\Automata\LanguageSolver` |
| `RegexParser\Automata\RegularSubsetValidator` | `PHPRegex\Automata\Transform\RegularSubsetValidator` |
| `RegexParser\Automata\SolverOptions` | `PHPRegex\Automata\Options\SolverOptions` |
| `RegexParser\Automata\Solver\DfaCacheInterface` | `PHPRegex\Automata\Solver\DfaCacheInterface` |
| `RegexParser\Automata\Solver\EquivalenceResult` | `PHPRegex\Automata\Solver\EquivalenceResult` |
| `RegexParser\Automata\Solver\InMemoryDfaCache` | `PHPRegex\Automata\Solver\InMemoryDfaCache` |
| `RegexParser\Automata\Solver\IntersectionResult` | `PHPRegex\Automata\Solver\IntersectionResult` |
| `RegexParser\Automata\Solver\RegexSolver` | `PHPRegex\Automata\LanguageSolver` |
| `RegexParser\Automata\Solver\SubsetResult` | `PHPRegex\Automata\Solver\SubsetResult` |
| `RegexParser\Automata\SubsetResult` | `PHPRegex\Automata\Solver\SubsetResult` |
| `RegexParser\Automata\Support\WorkBudget` | `PHPRegex\Automata\Support\WorkBudget` |
| `RegexParser\Automata\Transform\AstToNfaTransformer` | `PHPRegex\Automata\Transform\HirToNfaTransformer` |
| `RegexParser\Automata\Transform\RegularSubsetValidator` | `PHPRegex\Automata\Transform\RegularSubsetValidator` |
| `RegexParser\Automata\Unicode\CodePointHelper` | `PHPRegex\Automata\Unicode\CodePointHelper` |
| `RegexParser\Bridge\PHPStan\RegexParserRule` | `PHPRegex\PHPStan\RegexPatternRule` |
| `RegexParser\Bridge\Symfony\Analyzer\AnalysisContext` | `PHPRegex\Symfony\Analyzer\AnalysisContext` |
| `RegexParser\Bridge\Symfony\Analyzer\AnalysisIssue` | `PHPRegex\Symfony\Analyzer\AnalysisIssue` |
| `RegexParser\Bridge\Symfony\Analyzer\AnalysisNotice` | `PHPRegex\Symfony\Analyzer\AnalysisNotice` |
| `RegexParser\Bridge\Symfony\Analyzer\AnalysisReport` | `PHPRegex\Symfony\Analyzer\SecurityReport` |
| `RegexParser\Bridge\Symfony\Analyzer\AnalyzerInterface` | `PHPRegex\Symfony\Analyzer\AnalyzerInterface` |
| `RegexParser\Bridge\Symfony\Analyzer\Formatter\ConsoleReportFormatter` | `PHPRegex\Symfony\Analyzer\Formatter\ConsoleReportFormatter` |
| `RegexParser\Bridge\Symfony\Analyzer\Formatter\JsonReportFormatter` | `PHPRegex\Symfony\Analyzer\Formatter\JsonReportFormatter` |
| `RegexParser\Bridge\Symfony\Analyzer\IssueDetail` | `PHPRegex\Symfony\Analyzer\IssueDetail` |
| `RegexParser\Bridge\Symfony\Analyzer\ReportSection` | `PHPRegex\Symfony\Analyzer\ReportSection` |
| `RegexParser\Bridge\Symfony\Analyzer\RoutesAnalyzer` | `PHPRegex\Symfony\Analyzer\RoutesAnalyzer` |
| `RegexParser\Bridge\Symfony\Analyzer\SecurityAnalyzer` | `PHPRegex\Symfony\Analyzer\SecurityAnalyzer` |
| `RegexParser\Bridge\Symfony\Analyzer\Severity` | `PHPRegex\Symfony\Analyzer\CheckOutcome` |
| `RegexParser\Bridge\Symfony\Command\CompareCommand` | `PHPRegex\Symfony\Command\CompareCommand` |
| `RegexParser\Bridge\Symfony\Command\RegexAnalyzeCommand` | `PHPRegex\Symfony\Command\AnalyzeCommand` |
| `RegexParser\Bridge\Symfony\Command\RegexLintCommand` | `PHPRegex\Symfony\Command\LintCommand` |
| `RegexParser\Bridge\Symfony\Command\RegexRoutesCommand` | `PHPRegex\Symfony\Command\RoutesCommand` |
| `RegexParser\Bridge\Symfony\Command\RegexSecurityCommand` | `PHPRegex\Symfony\Command\SecurityCommand` |
| `RegexParser\Bridge\Symfony\Command\RegexTranspileCommand` | `PHPRegex\Symfony\Command\TranspileCommand` |
| `RegexParser\Bridge\Symfony\Extractor\RouteRegexPatternSource` | `PHPRegex\Symfony\Extractor\RoutePatternSource` |
| `RegexParser\Bridge\Symfony\Extractor\ValidatorRegexPatternSource` | `PHPRegex\Symfony\Extractor\ValidatorPatternSource` |
| `RegexParser\Bridge\Symfony\Output\SymfonyConsoleFormatter` | `PHPRegex\Linter\Formatter\ConsoleTagFormatter` |
| `RegexParser\Bridge\Symfony\RegexParserBundle` | `PHPRegex\Symfony\PHPRegexBundle` |
| `RegexParser\Bridge\Symfony\Routing\RouteConflictAnalyzer` | `PHPRegex\Symfony\Routing\RouteConflictAnalyzer` |
| `RegexParser\Bridge\Symfony\Routing\RouteConflictReport` | `PHPRegex\Symfony\Routing\RouteConflictReport` |
| `RegexParser\Bridge\Symfony\Routing\RouteConflictSuggestionBuilder` | `PHPRegex\Symfony\Routing\RouteConflictSuggestionBuilder` |
| `RegexParser\Bridge\Symfony\Routing\RouteRequirementNormalizer` | `PHPRegex\Symfony\Routing\RouteRequirementNormalizer` |
| `RegexParser\Bridge\Symfony\Security\SecurityAccessControlAnalyzer` | `PHPRegex\Symfony\Security\SecurityAccessControlAnalyzer` |
| `RegexParser\Bridge\Symfony\Security\SecurityAccessControlReport` | `PHPRegex\Symfony\Security\SecurityAccessControlReport` |
| `RegexParser\Bridge\Symfony\Security\SecurityAccessSuggestionBuilder` | `PHPRegex\Symfony\Security\SecurityAccessSuggestionBuilder` |
| `RegexParser\Bridge\Symfony\Security\SecurityConfigExtractor` | `PHPRegex\Symfony\Security\SecurityConfigExtractor` |
| `RegexParser\Bridge\Symfony\Security\SecurityConfigLocator` | `PHPRegex\Symfony\Security\SecurityConfigLocator` |
| `RegexParser\Bridge\Symfony\Security\SecurityFirewallAnalyzer` | `PHPRegex\Symfony\Security\SecurityFirewallAnalyzer` |
| `RegexParser\Bridge\Symfony\Security\SecurityFirewallReport` | `PHPRegex\Symfony\Security\SecurityFirewallReport` |
| `RegexParser\Bridge\Symfony\Security\SecurityPatternNormalizer` | `PHPRegex\Symfony\Security\SecurityPatternNormalizer` |
| `RegexParser\Cache\ArrayCache` | `PHPRegex\Parser\Cache\ArrayCache` |
| `RegexParser\Cache\CacheInterface` | `PHPRegex\Parser\Cache\CacheInterface` |
| `RegexParser\Cache\FilesystemCache` | `PHPRegex\Parser\Cache\FilesystemCache` |
| `RegexParser\Cache\NullCache` | `PHPRegex\Parser\Cache\NullCache` |
| `RegexParser\Cache\PsrCacheAdapter` | `PHPRegex\Parser\Cache\PsrCacheAdapter` |
| `RegexParser\Cache\PsrSimpleCacheAdapter` | `PHPRegex\Parser\Cache\PsrSimpleCacheAdapter` |
| `RegexParser\Cache\RemovableCacheInterface` | `PHPRegex\Parser\Cache\RemovableCacheInterface` |
| `RegexParser\Cli\Application` | `PHPRegex\Cli\Application` |
| `RegexParser\Cli\Command\AbstractCommand` | `PHPRegex\Cli\Command\AbstractCommand` |
| `RegexParser\Cli\Command\AnalyzeCommand` | `PHPRegex\Cli\Command\AnalyzeCommand` |
| `RegexParser\Cli\Command\CommandInterface` | `PHPRegex\Cli\Command\CommandInterface` |
| `RegexParser\Cli\Command\CompareCommand` | `PHPRegex\Cli\Command\CompareCommand` |
| `RegexParser\Cli\Command\DebugCommand` | `PHPRegex\Cli\Command\DebugCommand` |
| `RegexParser\Cli\Command\DiagramCommand` | `PHPRegex\Cli\Command\DiagramCommand` |
| `RegexParser\Cli\Command\ExplainCommand` | `PHPRegex\Cli\Command\ExplainCommand` |
| `RegexParser\Cli\Command\GraphCommand` | `PHPRegex\Cli\Command\GraphCommand` |
| `RegexParser\Cli\Command\HelpCommand` | `PHPRegex\Cli\Command\HelpCommand` |
| `RegexParser\Cli\Command\HighlightCommand` | `PHPRegex\Cli\Command\HighlightCommand` |
| `RegexParser\Cli\Command\ParseCommand` | `PHPRegex\Cli\Command\ParseCommand` |
| `RegexParser\Cli\Command\RedosCommand` | `PHPRegex\Cli\Command\RedosCommand` |
| `RegexParser\Cli\Command\SelfUpdateCommand` | `PHPRegex\Cli\Command\SelfUpdateCommand` |
| `RegexParser\Cli\Command\TranspileCommand` | `PHPRegex\Cli\Command\TranspileCommand` |
| `RegexParser\Cli\Command\ValidateCommand` | `PHPRegex\Cli\Command\ValidateCommand` |
| `RegexParser\Cli\Command\VersionCommand` | `PHPRegex\Cli\Command\VersionCommand` |
| `RegexParser\Cli\ConsoleStyle` | `PHPRegex\Cli\ConsoleStyle` |
| `RegexParser\Cli\GlobalOptions` | `PHPRegex\Cli\GlobalOptions` |
| `RegexParser\Cli\GlobalOptionsParser` | `PHPRegex\Cli\GlobalOptionsParser` |
| `RegexParser\Cli\Graph\GraphGenerator` | `PHPRegex\Cli\Graph\GraphGenerator` |
| `RegexParser\Cli\Graph\GraphvizDumper` | `PHPRegex\Cli\Graph\GraphvizDumper` |
| `RegexParser\Cli\Graph\MermaidDumper` | `PHPRegex\Cli\Graph\MermaidDumper` |
| `RegexParser\Cli\Input` | `PHPRegex\Cli\Input` |
| `RegexParser\Cli\Output` | `PHPRegex\Cli\Output` |
| `RegexParser\Cli\ParsedGlobalOptions` | `PHPRegex\Cli\ParsedGlobalOptions` |
| `RegexParser\Cli\SelfUpdate\SelfUpdater` | `PHPRegex\Cli\SelfUpdate\SelfUpdater` |
| `RegexParser\Exception\ComplexityException` | `PHPRegex\Automata\Exception\ComplexityException` |
| `RegexParser\Exception\InvalidRegexOptionException` | `PHPRegex\Parser\Exception\InvalidRegexOptionException` |
| `RegexParser\Exception\LexerException` | `PHPRegex\Parser\Exception\LexerException` |
| `RegexParser\Exception\ParserException` | `PHPRegex\Parser\Exception\ParserException` |
| `RegexParser\Exception\RecursionLimitException` | `PHPRegex\Parser\Exception\RecursionLimitException` |
| `RegexParser\Exception\RegexException` | `PHPRegex\Parser\Exception\RegexException` |
| `RegexParser\Exception\RegexParserExceptionInterface` | `PHPRegex\Parser\Exception\ExceptionInterface` |
| `RegexParser\Exception\ResourceLimitException` | `PHPRegex\Parser\Exception\ResourceLimitException` |
| `RegexParser\Exception\SemanticErrorException` | `PHPRegex\Parser\Exception\SemanticErrorException` |
| `RegexParser\Exception\SyntaxErrorException` | `PHPRegex\Parser\Exception\SyntaxErrorException` |
| `RegexParser\Exception\TranspileException` | `PHPRegex\Transpiler\TranspileException` |
| `RegexParser\Exception\VisualContextTrait` | `PHPRegex\Parser\Exception\VisualContextTrait` |
| `RegexParser\GroupNumbering` | `PHPRegex\Parser\Analysis\GroupNumbering` |
| `RegexParser\GroupNumberingCollector` | `PHPRegex\Parser\Analysis\GroupNumberingCollector` |
| `RegexParser\Internal\PatternParser` | `PHPRegex\Parser\Syntax\PatternParser` |
| `RegexParser\Lexer` | `PHPRegex\Parser\Lexer` |
| `RegexParser\LintIssue` | `PHPRegex\Linter\Rule\RuleViolation` |
| `RegexParser\Lint\Command\LintArgumentParser` | `PHPRegex\Cli\Command\LintArgumentParser` |
| `RegexParser\Lint\Command\LintArguments` | `PHPRegex\Linter\Config\LintArguments` |
| `RegexParser\Lint\Command\LintCommand` | `PHPRegex\Cli\Command\LintCommand` |
| `RegexParser\Lint\Command\LintConfigLoader` | `PHPRegex\Linter\Config\LintConfigLoader` |
| `RegexParser\Lint\Command\LintConfigResult` | `PHPRegex\Linter\Config\LintConfigResult` |
| `RegexParser\Lint\Command\LintDefaultsBuilder` | `PHPRegex\Linter\Config\LintDefaultsBuilder` |
| `RegexParser\Lint\Command\LintExtractorFactory` | `PHPRegex\Linter\Config\LintExtractorFactory` |
| `RegexParser\Lint\Command\LintOutputRenderer` | `PHPRegex\Cli\Command\LintOutputRenderer` |
| `RegexParser\Lint\Command\LintParseResult` | `PHPRegex\Cli\Command\LintParseResult` |
| `RegexParser\Lint\ExtractorInterface` | `PHPRegex\Linter\Extraction\ExtractorInterface` |
| `RegexParser\Lint\Formatter\AbstractOutputFormatter` | `PHPRegex\Linter\Formatter\AbstractOutputFormatter` |
| `RegexParser\Lint\Formatter\CheckstyleFormatter` | `PHPRegex\Linter\Formatter\CheckstyleFormatter` |
| `RegexParser\Lint\Formatter\ConsoleFormatter` | `PHPRegex\Linter\Formatter\ConsoleFormatter` |
| `RegexParser\Lint\Formatter\FormatterRegistry` | `PHPRegex\Linter\Formatter\FormatterRegistry` |
| `RegexParser\Lint\Formatter\GithubFormatter` | `PHPRegex\Linter\Formatter\GithubFormatter` |
| `RegexParser\Lint\Formatter\JsonFormatter` | `PHPRegex\Linter\Formatter\JsonFormatter` |
| `RegexParser\Lint\Formatter\JunitFormatter` | `PHPRegex\Linter\Formatter\JunitFormatter` |
| `RegexParser\Lint\Formatter\LinkFormatter` | `PHPRegex\Linter\Formatter\LinkFormatter` |
| `RegexParser\Lint\Formatter\OutputConfiguration` | `PHPRegex\Linter\Formatter\OutputConfiguration` |
| `RegexParser\Lint\Formatter\OutputFormatterInterface` | `PHPRegex\Linter\Formatter\OutputFormatterInterface` |
| `RegexParser\Lint\Formatter\RelativePathHelper` | `PHPRegex\Linter\Formatter\RelativePathHelper` |
| `RegexParser\Lint\PhpRegexPatternSource` | `PHPRegex\Linter\Source\PhpFilePatternSource` |
| `RegexParser\Lint\PhpStanExtractionStrategy` | `PHPRegex\Linter\Extraction\PhpParserExtractionStrategy` |
| `RegexParser\Lint\RegexAnalysisService` | `PHPRegex\Linter\AnalysisService` |
| `RegexParser\Lint\RegexLintReport` | `PHPRegex\Linter\LintReport` |
| `RegexParser\Lint\RegexLintRequest` | `PHPRegex\Linter\LintRequest` |
| `RegexParser\Lint\RegexLintService` | `PHPRegex\Linter\LintService` |
| `RegexParser\Lint\RegexPatternExtractor` | `PHPRegex\Linter\PatternExtractor` |
| `RegexParser\Lint\RegexPatternOccurrence` | `PHPRegex\Linter\PatternOccurrence` |
| `RegexParser\Lint\RegexPatternSourceCollection` | `PHPRegex\Linter\Source\PatternSourceCollection` |
| `RegexParser\Lint\RegexPatternSourceContext` | `PHPRegex\Linter\Source\PatternSourceContext` |
| `RegexParser\Lint\RegexPatternSourceInterface` | `PHPRegex\Linter\Source\PatternSourceInterface` |
| `RegexParser\Lint\TokenBasedExtractionStrategy` | `PHPRegex\Linter\Extraction\TokenBasedExtractionStrategy` |
| `RegexParser\LiteralExtractionResult` | `PHPRegex\Parser\Analysis\LiteralExtractionResult` |
| `RegexParser\LiteralSet` | `PHPRegex\Parser\Analysis\LiteralSet` |
| `RegexParser\NodeVisitor\AbstractNodeVisitor` | `PHPRegex\Parser\AbstractNodeVisitor` |
| `RegexParser\NodeVisitor\AsciiTreeVisitor` | `PHPRegex\Explain\AsciiTreeRenderer` |
| `RegexParser\NodeVisitor\CompilerNodeVisitor` | `PHPRegex\Parser\Printer\PatternPrinter` |
| `RegexParser\NodeVisitor\ComplexityScoreNodeVisitor` | `PHPRegex\Parser\Analysis\ComplexityScorer` |
| `RegexParser\NodeVisitor\ConsoleHighlighterVisitor` | `PHPRegex\Explain\Highlighter\ConsoleHighlighter` |
| `RegexParser\NodeVisitor\DumperNodeVisitor` | `PHPRegex\Parser\Printer\NodeDumper` |
| `RegexParser\NodeVisitor\ExplainNodeVisitor` | `PHPRegex\Explain\TextExplainer` |
| `RegexParser\NodeVisitor\HighlighterVisitor` | `PHPRegex\Explain\Highlighter\AbstractHighlighter` |
| `RegexParser\NodeVisitor\HtmlExplainNodeVisitor` | `PHPRegex\Explain\HtmlExplainer` |
| `RegexParser\NodeVisitor\HtmlHighlighterVisitor` | `PHPRegex\Explain\Highlighter\HtmlHighlighter` |
| `RegexParser\NodeVisitor\LengthRangeNodeVisitor` | `PHPRegex\Parser\Analysis\LengthRangeCalculator` |
| `RegexParser\NodeVisitor\LinterNodeVisitor` | `PHPRegex\Linter\PatternLinter` |
| `RegexParser\NodeVisitor\LiteralExtractorNodeVisitor` | `PHPRegex\Parser\Analysis\LiteralExtractor` |
| `RegexParser\NodeVisitor\MermaidNodeVisitor` | `PHPRegex\Explain\MermaidRenderer` |
| `RegexParser\NodeVisitor\MetricsNodeVisitor` | `PHPRegex\Parser\Analysis\MetricsCollector` |
| `RegexParser\NodeVisitor\ModernizerNodeVisitor` | `PHPRegex\Optimizer\Modernizer` |
| `RegexParser\NodeVisitor\NodeVisitorInterface` | `PHPRegex\Parser\NodeVisitorInterface` |
| `RegexParser\NodeVisitor\OptimizerNodeVisitor` | `PHPRegex\Optimizer\Rewriter` |
| `RegexParser\NodeVisitor\RailroadSvgVisitor` | `PHPRegex\Explain\RailroadSvgRenderer` |
| `RegexParser\NodeVisitor\ReDoSProfileNodeVisitor` | `PHPRegex\Redos\RedosProfiler` |
| `RegexParser\NodeVisitor\SampleGeneratorNodeVisitor` | `PHPRegex\Generator\SampleGenerator` |
| `RegexParser\NodeVisitor\TestCaseGeneratorNodeVisitor` | `PHPRegex\Generator\TestCaseGenerator` |
| `RegexParser\NodeVisitor\ValidatorNodeVisitor` | `PHPRegex\Parser\Validation\Validator` |
| `RegexParser\Node\AbstractNode` | `PHPRegex\Parser\Node\AbstractNode` |
| `RegexParser\Node\AlternationNode` | `PHPRegex\Parser\Node\AlternationNode` |
| `RegexParser\Node\AnchorNode` | `PHPRegex\Parser\Node\AnchorNode` |
| `RegexParser\Node\AssertionNode` | `PHPRegex\Parser\Node\AssertionNode` |
| `RegexParser\Node\BackrefNode` | `PHPRegex\Parser\Node\BackrefNode` |
| `RegexParser\Node\CalloutNode` | `PHPRegex\Parser\Node\CalloutNode` |
| `RegexParser\Node\CharClassNode` | `PHPRegex\Parser\Node\CharClassNode` |
| `RegexParser\Node\CharLiteralNode` | `PHPRegex\Parser\Node\CharLiteralNode` |
| `RegexParser\Node\CharLiteralType` | `PHPRegex\Parser\Node\CharLiteralType` |
| `RegexParser\Node\CharTypeNode` | `PHPRegex\Parser\Node\CharTypeNode` |
| `RegexParser\Node\CommentNode` | `PHPRegex\Parser\Node\CommentNode` |
| `RegexParser\Node\ConditionalNode` | `PHPRegex\Parser\Node\ConditionalNode` |
| `RegexParser\Node\ControlCharNode` | `PHPRegex\Parser\Node\ControlCharNode` |
| `RegexParser\Node\DefineNode` | `PHPRegex\Parser\Node\DefineNode` |
| `RegexParser\Node\DotNode` | `PHPRegex\Parser\Node\DotNode` |
| `RegexParser\Node\GroupNode` | `PHPRegex\Parser\Node\GroupNode` |
| `RegexParser\Node\GroupType` | `PHPRegex\Parser\Node\GroupType` |
| `RegexParser\Node\KeepNode` | `PHPRegex\Parser\Node\KeepNode` |
| `RegexParser\Node\LimitMatchNode` | `PHPRegex\Parser\Node\LimitMatchNode` |
| `RegexParser\Node\LiteralNode` | `PHPRegex\Parser\Node\LiteralNode` |
| `RegexParser\Node\NodeInterface` | `PHPRegex\Parser\Node\NodeInterface` |
| `RegexParser\Node\PcreVerbNode` | `PHPRegex\Parser\Node\PcreVerbNode` |
| `RegexParser\Node\PosixClassNode` | `PHPRegex\Parser\Node\PosixClassNode` |
| `RegexParser\Node\QuantifierNode` | `PHPRegex\Parser\Node\QuantifierNode` |
| `RegexParser\Node\QuantifierType` | `PHPRegex\Parser\Node\QuantifierType` |
| `RegexParser\Node\RangeNode` | `PHPRegex\Parser\Node\RangeNode` |
| `RegexParser\Node\RegexNode` | `PHPRegex\Parser\Node\RegexNode` |
| `RegexParser\Node\ScriptRunNode` | `PHPRegex\Parser\Node\ScriptRunNode` |
| `RegexParser\Node\SequenceNode` | `PHPRegex\Parser\Node\SequenceNode` |
| `RegexParser\Node\SubroutineNode` | `PHPRegex\Parser\Node\SubroutineNode` |
| `RegexParser\Node\UnicodePropNode` | `PHPRegex\Parser\Node\UnicodePropNode` |
| `RegexParser\Node\VersionConditionNode` | `PHPRegex\Parser\Node\VersionConditionNode` |
| `RegexParser\OptimizationResult` | `PHPRegex\Optimizer\OptimizationResult` |
| `RegexParser\Parser` | `PHPRegex\Parser\Syntax\TokenParser` |
| `RegexParser\ProblemType` | `PHPRegex\Linter\DiagnosticType` |
| `RegexParser\ReDoS\CharSet` | `PHPRegex\Parser\Analysis\ByteCharSet` |
| `RegexParser\ReDoS\CharSetAnalyzer` | `PHPRegex\Parser\Analysis\CharSetAnalyzer` |
| `RegexParser\ReDoS\ReDoSAnalysis` | `PHPRegex\Redos\RedosAnalysis` |
| `RegexParser\ReDoS\ReDoSAnalyzer` | `PHPRegex\Redos\RedosAnalyzer` |
| `RegexParser\ReDoS\ReDoSConfidence` | `PHPRegex\Redos\RedosConfidence` |
| `RegexParser\ReDoS\ReDoSConfirmOptions` | `PHPRegex\Redos\ConfirmationOptions` |
| `RegexParser\ReDoS\ReDoSConfirmation` | `PHPRegex\Redos\Confirmation` |
| `RegexParser\ReDoS\ReDoSConfirmationRunner` | `PHPRegex\Redos\ConfirmationRunner` |
| `RegexParser\ReDoS\ReDoSConfirmationRunnerInterface` | `PHPRegex\Redos\ConfirmationRunnerInterface` |
| `RegexParser\ReDoS\ReDoSConfirmationSample` | `PHPRegex\Redos\ConfirmationSample` |
| `RegexParser\ReDoS\ReDoSFinding` | `PHPRegex\Redos\Finding` |
| `RegexParser\ReDoS\ReDoSHeatmap` | `PHPRegex\Redos\Heatmap` |
| `RegexParser\ReDoS\ReDoSHotspot` | `PHPRegex\Redos\Hotspot` |
| `RegexParser\ReDoS\ReDoSInputGenerator` | `PHPRegex\Redos\InputGenerator` |
| `RegexParser\ReDoS\ReDoSMode` | `PHPRegex\Redos\RedosMode` |
| `RegexParser\ReDoS\ReDoSSeverity` | `PHPRegex\Redos\RedosSeverity` |
| `RegexParser\Regex` | `PHPRegex\Toolkit\Regex` |
| `RegexParser\RegexOptions` | `PHPRegex\Parser\ParserOptions` |
| `RegexParser\RegexPattern` | `PHPRegex\Parser\DelimitedPattern` |
| `RegexParser\RegexProblem` | `PHPRegex\Linter\Diagnostic` |
| `RegexParser\Runtime\PcreRuntimeInfo` | `PHPRegex\Cli\PcreRuntimeInfo` |
| `RegexParser\Severity` | `PHPRegex\Linter\LintSeverity` |
| `RegexParser\Token` | `PHPRegex\Parser\Token\Token` |
| `RegexParser\TokenStream` | `PHPRegex\Parser\Token\TokenStream` |
| `RegexParser\TokenType` | `PHPRegex\Parser\Token\TokenType` |
| `RegexParser\TolerantParseResult` | `PHPRegex\Parser\TolerantParseResult` |
| `RegexParser\Transpiler\RegexTranspiler` | `PHPRegex\Transpiler\Transpiler` |
| `RegexParser\Transpiler\Target\JavaScript\JavaScriptCompilerVisitor` | `PHPRegex\Transpiler\Target\JavaScript\JavaScriptPrinter` |
| `RegexParser\Transpiler\Target\JavaScript\JavaScriptTarget` | `PHPRegex\Transpiler\Target\JavaScript\JavaScriptTarget` |
| `RegexParser\Transpiler\Target\Python\PythonCompilerVisitor` | `PHPRegex\Transpiler\Target\Python\PythonPrinter` |
| `RegexParser\Transpiler\Target\Python\PythonTarget` | `PHPRegex\Transpiler\Target\Python\PythonTarget` |
| `RegexParser\Transpiler\Target\TargetRegistry` | `PHPRegex\Transpiler\Target\TargetRegistry` |
| `RegexParser\Transpiler\Target\TranspileTargetInterface` | `PHPRegex\Transpiler\Target\TargetInterface` |
| `RegexParser\Transpiler\TranspileContext` | `PHPRegex\Transpiler\TranspileContext` |
| `RegexParser\Transpiler\TranspileOptions` | `PHPRegex\Transpiler\TranspileOptions` |
| `RegexParser\Transpiler\TranspileResult` | `PHPRegex\Transpiler\TranspileResult` |
| `RegexParser\ValidationErrorCategory` | `PHPRegex\Parser\Validation\ValidationErrorCategory` |
| `RegexParser\ValidationResult` | `PHPRegex\Parser\Validation\ValidationResult` |
| `RegexParser\Automata\Alphabet\CharSet` | removed: use `PHPRegex\Parser\Hir\CharSet` |
| `RegexParser\Automata\AstToNfaTransformerInterface` | removed: type against `PHPRegex\Automata\Transform\HirToNfaTransformer`, one concrete class |
| `RegexParser\Automata\CharSet` | removed: use `PHPRegex\Parser\Hir\CharSet` |
| `RegexParser\Automata\RegexSolverInterface` | removed: type against `PHPRegex\Automata\LanguageSolver` |
| `RegexParser\Automata\Transform\AstToNfaTransformerInterface` | removed: type against `PHPRegex\Automata\Transform\HirToNfaTransformer`, one concrete class |
| `RegexParser\Automata\Solver\RegexSolverCompilerInterface` | removed: type against `PHPRegex\Automata\LanguageSolver` |
| `RegexParser\Automata\Solver\RegexSolverInterface` | removed: type against `PHPRegex\Automata\LanguageSolver` |
| `RegexParser\Bridge\Symfony\Analyzer\AnalyzerRegistry` | removed: no replacement: AnalyzeCommand takes its two analyzers directly |
| `RegexParser\Bridge\Symfony\DependencyInjection\Configuration` | removed: no replacement: `PHPRegex\Symfony\PHPRegexBundle` defines its configuration itself |
| `RegexParser\Bridge\Symfony\DependencyInjection\RegexParserExtension` | removed: no replacement: `PHPRegex\Symfony\PHPRegexBundle` loads its services itself; its container extension keeps the php_regex alias |
| `RegexParser\Bridge\Symfony\Routing\RouteControllerFileResolver` | removed: no replacement: nothing called it |
| `RegexParser\Cli\Command\ClearCacheCommand` | removed: no replacement: the binary keeps no cache between runs, so the regex clear-cache command had nothing to clear |
| `RegexParser\Node\ClassOperationNode` | removed: no replacement: PHP reads "&&" and "--" in a class as members and ranges, so no pattern ever produced it |
| `RegexParser\Node\ClassOperationType` | removed: no replacement: it only typed ClassOperationNode |
| `RegexParser\Node\UnicodeNode` | removed: use `PHPRegex\Parser\Node\CharLiteralNode`, which every \x{...} and \u{...} escape already became |
| `RegexParser\ReDoS\ReDoSAnalyzerInterface` | removed: type against `PHPRegex\Redos\RedosAnalyzer` |

| 1.3 enum case | 2.0 |
|---|---|
| `TokenType::T_LITERAL` | `TokenType::Literal` |
| `TokenType::T_CHAR_TYPE` | `TokenType::CharType` |
| `TokenType::T_GROUP_OPEN` | `TokenType::GroupOpen` |
| `TokenType::T_GROUP_CLOSE` | `TokenType::GroupClose` |
| `TokenType::T_GROUP_MODIFIER_OPEN` | `TokenType::GroupModifierOpen` |
| `TokenType::T_CHAR_CLASS_OPEN` | `TokenType::CharClassOpen` |
| `TokenType::T_CHAR_CLASS_CLOSE` | `TokenType::CharClassClose` |
| `TokenType::T_QUANTIFIER` | `TokenType::Quantifier` |
| `TokenType::T_ALTERNATION` | `TokenType::Alternation` |
| `TokenType::T_DOT` | `TokenType::Dot` |
| `TokenType::T_ANCHOR` | `TokenType::Anchor` |
| `TokenType::T_EOF` | `TokenType::Eof` |
| `TokenType::T_RANGE` | `TokenType::Range` |
| `TokenType::T_NEGATION` | `TokenType::Negation` |
| `TokenType::T_BACKREF` | `TokenType::Backref` |
| `TokenType::T_UNICODE` | `TokenType::Unicode` |
| `TokenType::T_POSIX_CLASS` | `TokenType::PosixClass` |
| `TokenType::T_ASSERTION` | `TokenType::Assertion` |
| `TokenType::T_UNICODE_PROP` | `TokenType::UnicodeProp` |
| `TokenType::T_OCTAL` | `TokenType::Octal` |
| `TokenType::T_OCTAL_LEGACY` | `TokenType::OctalLegacy` |
| `TokenType::T_COMMENT_OPEN` | `TokenType::CommentOpen` |
| `TokenType::T_PCRE_VERB` | `TokenType::PcreVerb` |
| `TokenType::T_G_REFERENCE` | `TokenType::GReference` |
| `TokenType::T_KEEP` | `TokenType::Keep` |
| `TokenType::T_LITERAL_ESCAPED` | `TokenType::LiteralEscaped` |
| `TokenType::T_QUOTE_MODE_START` | `TokenType::QuoteModeStart` |
| `TokenType::T_QUOTE_MODE_END` | `TokenType::QuoteModeEnd` |
| `TokenType::T_CALLOUT` | `TokenType::Callout` |
| `TokenType::T_UNICODE_NAMED` | `TokenType::UnicodeNamed` |
| `TokenType::T_CONTROL_CHAR` | `TokenType::ControlChar` |
| `GroupType::T_GROUP_CAPTURING` | `GroupType::Capturing` |
| `GroupType::T_GROUP_NON_CAPTURING` | `GroupType::NonCapturing` |
| `GroupType::T_GROUP_NAMED` | `GroupType::Named` |
| `GroupType::T_GROUP_LOOKAHEAD_POSITIVE` | `GroupType::LookaheadPositive` |
| `GroupType::T_GROUP_LOOKAHEAD_NEGATIVE` | `GroupType::LookaheadNegative` |
| `GroupType::T_GROUP_LOOKBEHIND_POSITIVE` | `GroupType::LookbehindPositive` |
| `GroupType::T_GROUP_LOOKBEHIND_NEGATIVE` | `GroupType::LookbehindNegative` |
| `GroupType::T_GROUP_INLINE_FLAGS` | `GroupType::InlineFlags` |
| `GroupType::T_GROUP_ATOMIC` | `GroupType::Atomic` |
| `GroupType::T_GROUP_BRANCH_RESET` | `GroupType::BranchReset` |
| `QuantifierType::T_GREEDY` | `QuantifierType::Greedy` |
| `QuantifierType::T_LAZY` | `QuantifierType::Lazy` |
| `QuantifierType::T_POSSESSIVE` | `QuantifierType::Possessive` |
| `CharLiteralType::UNICODE` | `CharLiteralType::Unicode` |
| `CharLiteralType::UNICODE_NAMED` | `CharLiteralType::UnicodeNamed` |
| `CharLiteralType::OCTAL` | `CharLiteralType::Octal` |
| `CharLiteralType::OCTAL_LEGACY` | `CharLiteralType::OctalLegacy` |
| `ValidationErrorCategory::SYNTAX` | `ValidationErrorCategory::Syntax` |
| `ValidationErrorCategory::SEMANTIC` | `ValidationErrorCategory::Semantic` |
| `ValidationErrorCategory::PCRE_RUNTIME` | `ValidationErrorCategory::PcreRuntime` |
| `ReDoSSeverity::SAFE` | `RedosSeverity::Safe` |
| `ReDoSSeverity::LOW` | `RedosSeverity::Low` |
| `ReDoSSeverity::MEDIUM` | `RedosSeverity::Medium` |
| `ReDoSSeverity::UNKNOWN` | `RedosSeverity::Unknown` |
| `ReDoSSeverity::HIGH` | `RedosSeverity::High` |
| `ReDoSSeverity::CRITICAL` | `RedosSeverity::Critical` |
| `ReDoSMode::OFF` | `RedosMode::Off` |
| `ReDoSMode::THEORETICAL` | `RedosMode::Theoretical` |
| `ReDoSMode::CONFIRMED` | `RedosMode::Confirmed` |
| `ReDoSConfidence::LOW` | `RedosConfidence::Low` |
| `ReDoSConfidence::MEDIUM` | `RedosConfidence::Medium` |
| `ReDoSConfidence::HIGH` | `RedosConfidence::High` |
| `MatchMode::FULL` | `MatchMode::Full` |
| `MatchMode::PARTIAL` | `MatchMode::Partial` |
| `MinimizationAlgorithm::HOPCROFT` | `MinimizationAlgorithm::Hopcroft` |
| `MinimizationAlgorithm::MOORE` | `MinimizationAlgorithm::Moore` |
| `DeterminizationAlgorithm::SUBSET` | `DeterminizationAlgorithm::Subset` |
| `DeterminizationAlgorithm::SUBSET_INDEXED` | `DeterminizationAlgorithm::SubsetIndexed` |
| `Severity::PASS` | `CheckOutcome::Pass` |
| `Severity::WARN` | `CheckOutcome::Warn` |
| `Severity::FAIL` | `CheckOutcome::Fail` |
| `Severity::CRITICAL` | `CheckOutcome::Critical` |

| 1.3 method | 2.0 |
|---|---|
| `RegexAnalysisService::getRegex()` | `AnalysisService::getParser()` |
| `RegexLanguageSolver::intersectionEmpty()` | `LanguageSolver::intersection()` |
| `RegexLanguageSolver::prepare()` | `LanguageSolver::compile()` |
| `Regex::clearValidatorCaches()` | `Regex::clearCaches()` |
| `Regex::new()` | `Regex::create()` |

<!-- upgrade-map:end -->

### `regex lint --baseline`

The baseline file has a new format, `{"version": 1, "issues": [...]}`, and an
issue is matched on its identifier, its file and its pattern rather than on
its line and message, so a moved pattern or a reworded message no longer
brings it back. **A 1.x baseline, a plain list, no longer loads at all**: it
is refused as a usage error (exit code 2) before anything is scanned or
written — including a run that also passes `--generate-baseline`. Generate it
once more to move to the new matching:

```bash
vendor/bin/regex lint src/ --generate-baseline regex-baseline.json
```

A baseline file that is missing or is not a baseline is now a usage error
(exit code 2), where 1.x went on with no baseline. See
[the CLI guide](docs/guides/cli.md#baseline).

### `// @regex-lint-ignore`

The 1.x comment `// @regex-lint-ignore` on the line above a pattern no longer
hides its issues. Write `// @regex-ignore-next-line` there instead (or
`// @regex-ignore` at the end of the pattern's own line), the markers the
documentation names:

```php
// @regex-ignore-next-line
preg_match('/(a+)+$/', $input);
```

### `@regex-suppress`

The 1.x comment `@regex-suppress`, an undocumented second spelling of
`@regex-ignore`, no longer hides the issues of a pattern on its line. Write
`@regex-ignore` instead:

```php
preg_match('/(a+)+$/', $input); // @regex-ignore
```

### Markers and baseline entries that silence nothing

A marker is read where it starts a comment (after `//`, `#`, `/*` or `/**`)
or a line of a doc comment. One written in the middle of a sentence,
`// legacy, @regex-ignore`, no longer silences its line: move it to the start
of the comment, `// @regex-ignore legacy`.

A marker reads the short id of a lint rule or of an analysis issue, as
`regex.json` names it, as that issue: `// @regex-ignore quantifier.useless`
silences `regex.lint.quantifier.useless` only, and is reported when that
issue is not on its line. Such a word was a note before, which silenced every
issue of the line. A marker meant to silence the whole line names nothing,
or starts its note with a word that is no rule id.

`regex lint`, Symfony's and Laravel's `regex:lint` and the language server
report each `@regex-ignore` or `@regex-ignore-next-line` marker that
silences nothing, and `regex lint --baseline` each entry no issue takes any
more in a file the run reads or in a file gone from a path it lints, as an error, `regex.ignore.unmatched` (a
warning in the editor). A project carrying such markers or entries fails its
next run until they are removed. To keep them for now, turn the report off:
`--no-report-unmatched-ignores` or `"reportUnmatchedIgnores": false` in
`regex.json`, `report_unmatched_ignores: false` under `php_regex` in Symfony,
`'report_unmatched_ignores' => false` in Laravel's `config/php-regex.php`. See
[the CLI guide](docs/guides/cli.md#markers-that-silence-nothing).

### Breaking Changes

#### One way to do each thing on the facade

- `Regex::new()` is gone: it was `Regex::create()` under another name. The
  Rector set renames the calls.
- `Regex::parse()` takes the pattern only and returns a `RegexNode`. Replace
  `parse($pattern, true)` with `parseTolerant($pattern)`; Rector cannot, and
  PHP accepts the extra argument without a word, so search for the calls (or
  let PHPStan report them).
- `Regex::cacheSeed()` is gone, and `RegexParser::cacheSeed()` is internal: the
  seed's shape follows the cache version and may change in any release.

#### One name per argument

PHP lets a caller name an argument, so a parameter name is part of the API.
`$regex` is a pattern with its delimiters and flags everywhere, `$pattern`
its body, `$ast` a parsed tree, `$subject` the text matched against and
`$parser` the parser a service takes. These parameters took another name:

| method | before | after |
|---|---|---|
| `Transpiler::__construct()` | `$regex` | `$parser` |
| `Transpiler::transpile()` | `$pattern` | `$regex` |
| `LanguageSolver::acceptsPrefix()` | `$pattern`, `$input` | `$regex`, `$subject` |
| `LanguageSolver::language()`, `compile()` | `$pattern` | `$regex` |
| `TrivialMatchClassifier::classify()`, `matchedLiteral()` | `$pattern` | `$regex` |
| `RedosRepairer::repair()` | `$pattern` | `$regex` |
| `PcreEngine::compile()`, `match()`, `test()`, `matchAll()` | `$pattern` | `$regex` |
| `CaptureShapeAnalyzer::analyze()`, `PatternInfoAnalyzer::analyze()`, `RequiredLiteralAnalyzer::analyze()` | `$regex` | `$ast` |
| `GroupNumberingCollector::collect()` | `$node` | `$ast` |

Only a call that names the argument changes.

#### `tokenize()` returns the tokens, for the instance's target

`Regex::tokenize()` was static and returned a `TokenStream`, the cursor the
parser walks the tokens with. It is now an instance method, like
`RegexParser::tokenize()`, and both return a `list<Token>` read for the
instance's target; `TokenStream` is internal.

| before | after |
|---|---|
| `Regex::tokenize($regex)` | `Regex::create()->tokenize($regex)` |
| `Regex::tokenize($regex, $phpVersionId)` | `Regex::create(['php_version' => $phpVersionId])->tokenize($regex)` |
| `$stream->getTokens()` | the list itself |
| `$stream->getPattern()` | the pattern body you passed, without its delimiters and flags |

#### `regex clear-cache` is gone

The `clear-cache` command and its `cache:clear` alias are removed. The binary
never configured a persistent cache: every run parses with an in-memory cache
that ends with the process, so the command had nothing to clear. Remove it
from your scripts; `regex clear-cache` is now an unknown command and exits
with 2. An application that configures a removable cache, such as
`FilesystemCache`, clears it from its own code: `$regex->getCache()->clear()`
when the cache implements `RemovableCacheInterface`.

#### A cache is emptied whole

`RemovableCacheInterface::clear()` takes no argument. The parser stores a tree
under a seed built from the regex, the PCRE target and the recursion limit,
never under the regex itself, so `clear('/foo/')` removed nothing; call
`clear()`. An implementation of the interface drops the parameter.

`CacheInterface::generateKey()` names its parameter `$seed`, and the
`keyFactory` closure of `PsrCacheAdapter` and `PsrSimpleCacheAdapter`
receives that seed. Its format is internal: a key built from it belongs in
the cache, nowhere else.

`FilesystemCache::clear()` removes the files the cache wrote and the
directories they leave empty; anything else in the directory stays.

#### `UnicodeNode` and `visitUnicode()` are gone

No parser path ever produced a `UnicodeNode`: a `\u{...}` or `\x{...}` escape
becomes a `CharLiteralNode`. The node is removed, and with it the
`visitUnicode()` method of `NodeVisitorInterface`.

A custom visitor keeps working as it is — an extra `visitUnicode()` method on
your class is simply never called, and you can delete it. Code that names
`RegexParser\Node\UnicodeNode` has to be updated to `CharLiteralNode`, whose
`codePoint` holds the value the `code` string used to spell.

#### `ClassOperationNode` and the class operation tokens are gone

PHP compiles patterns without PCRE2's extended class syntax, so inside a
character class `&&` is two `&` members and `--` a range through `-`:
`[a&&b]` matches `&`, and `[a--b]` is refused as a range out of order. The
parser reads them that way and never builds a class operation, so these are
removed:

  - `RegexParser\Node\ClassOperationNode` and `RegexParser\Node\ClassOperationType`
  - `TokenType::T_CLASS_INTERSECTION` and `TokenType::T_CLASS_SUBTRACTION`, which the lexer no longer produced
  - `NodeVisitorInterface::visitClassOperation()` and its implementations in every visitor of the library

A custom visitor can delete its `visitClassOperation()` method; left in place it
is never called, but with `#[\Override]` on it PHP 8.3 and later refuse the
class. Code that looked for a `ClassOperationNode` in a parsed tree finds the
members and ranges instead.

#### `ReDoSAnalyzerInterface` is gone

Nothing implemented it, `ReDoSAnalyzer` included. Type against `ReDoSAnalyzer`.

#### One target: `PcreTarget` replaces the PHP version id

A pattern is judged for one PHP version and one PCRE2 release, now a value,
`PHPRegex\Parser\PcreTarget`, resolved once by `Regex::create()` and passed to
everything that reads it.

| before | after |
|---|---|
| `new Lexer($versionId)` or `new Lexer(bool)` | internal: `RegexParser::create(['php_version' => ...])->tokenize($regex)` |
| `new Parser($depth, ?int $phpVersionId)` | internal: `RegexParser::create(['php_version' => ...])->parse($regex)` |
| `new ValidatorNodeVisitor($max, $pattern, int $phpVersionId)` | internal: `RegexParser::create(['php_version' => ...])->validate($regex)` |
| `Regex::cacheSeed($regex, int $phpVersionId, $depth)` | gone (see above) |
| `RegexPattern::fromDelimited($regex, ?int)` | `DelimitedPattern::fromDelimited($regex, ?PcreTarget)` |
| `RegexOptions::$phpVersionId`, `$phpVersionExplicit` | `ParserOptions::$target` |
| `new RegexOptions(..., $maxRecursionDepth, int $phpVersionId, bool $phpVersionExplicit)` | `ParserOptions::fromArray(['php_version' => ..., 'pcre_version' => ...])` |
| `Lexer::readsWideRepeatCounts()` | gone: `PcreTarget::pcreAtLeast('10.43')` |

`null` means `PcreTarget::runtime()`, the running PHP and the PCRE2 it links.
`PcreTarget::bundledWith(80200)` is a PHP version with the PCRE2 it bundles.

#### `GroupType` has a new case, and two nodes a new field

`GroupType::ScanSubstring` stands for `(*scs:(1)...)`, PCRE2 10.45: a
`match` over `GroupType` without a `default` arm needs one for it.
`GroupNode::$scannedGroups` lists the groups it scans, and
`SubroutineNode::$returnedGroups` the groups a call returns,
`(?1(2,<name>))` (PCRE2 10.47); both are empty lists otherwise, and both
constructors take them as a last, optional argument.

#### Two new nodes and two visitor methods

A Perl extended class, `(?[ \p{L} - [aeiou] ])` (PCRE2 10.45), is an
`ExtendedCharClassNode` whose expression is an operand or a tree of
`ClassSetOperationNode`s. `NodeVisitorInterface` gains
`visitExtendedCharClass()` and `visitClassSetOperation()`: a visitor extending
`AbstractNodeVisitor` inherits both, one implementing the interface directly
needs them.

#### `generate()` throws when it finds no matching sample

`Regex::generate()` used to return its last attempt when no sample matched,
as for `a(*FAIL)`: a string the pattern does not match. It now throws
`PHPRegex\Generator\SampleGenerationException`. Catch it where a pattern
may match nothing.

#### `php_version` alone always means the PCRE2 that PHP bundles

`php_version` naming the running PHP used to mix two engines: the parser read
the bundled PCRE2, the validator the linked one. It now judges with the bundled
one throughout, as for any other version. Judging for the running engine is the
default, with no option; add `pcre_version` to name the linked release.

#### `php_version` names a release, and the limits are checked where the options are built

`php_version` is read whole. A version is two or three parts, each after the
major at most 99: `"8.2foo"`, `"8.x"`, `"8.2.1.9"` and `"8.2.100"` throw
`InvalidRegexOptionException`, where the library read what came first
(`"8.2.100"` judged PHP 8.3). A PHP_VERSION_ID, an integer or its digits, is
at least 70000: `8` and `82` throw, the message saying to write `80000` or
`"8.0"`, `80200` or `"8.2"`, where they judged a PHP that does not exist. The
regex.json schema and the PHPStan parameter schema refuse the same values. A
Rector target below PHP 7.0 is judged from PHP 7.0.

`new ParserOptions()` throws `InvalidRegexOptionException` for a
`maxPatternLength` or a `maxRecursionDepth` below 1 and a
`maxLookbehindLength` below 0, which `fromArray()` already refused.

#### The snippet of an exception is `$snippet`

`VisualContextInterface::getVisualSnippet()` is gone: it returned what
`RegexException::$snippet` holds. Read the property; the interface still marks
the exceptions that carry one, and declares `getPattern()`.

```diff
-$text = $e->getVisualSnippet();
+$text = (string) $e->snippet;
```

#### `runtime_pcre_validation` needs the running engine as target

It compiles with the running PHP, so combining it with a target that is another
engine now throws `InvalidRegexOptionException` instead of judging with the
wrong one. Drop one of the two options.

#### The PHPStan extension judges for PHPStan's `phpVersion`

The rule used to judge for the PHP running PHPStan and the PCRE2 it links; it
now judges for the `phpVersion` PHPStan analyses the project for, with the
PCRE2 that PHP bundles. Without a `phpVersion` in your PHPStan configuration,
PHPStan takes the PHP running it, so only the PCRE2 moves, from the linked
release to the bundled one. Set PHPStan's `phpVersion` to the PHP your project
runs on, `phpRegex.pcreVersion` for a PHP that links another PCRE2, or
`phpRegex.phpVersion: runtime` to keep the old behaviour.

#### The PHPStan extension reports what PHPStan does not

Enabled by extension-installer, the extension used to report every lint rule
and ReDoS finding, and every invalid pattern a second time next to PHPStan's
own `regexp.pattern`. It now reports, by default, only a pattern the target PHP
refuses while the PHP running PHPStan compiles it, under
`regex.invalidForTarget`. Lint rules and ReDoS analysis are opt-in: include
`vendor/php-regex/regex-phpstan/rules.neon`, or switch each on under `checks`.

The extension is its own package, `php-regex/regex-phpstan`: an include written by
hand moves from `vendor/yoeunes/regex-parser/extension.neon` to
`vendor/php-regex/regex-phpstan/extension.neon` (extension-installer finds it on its
own).

The configuration is `phpVersion`, `pcreVersion` and `checks` only:

| removed | use instead |
|---|---|
| `reportRedos` | `checks.redos.enabled` |
| `redosThreshold` | `checks.redos.threshold` |
| `redosMode`, `checks.redos.mode` | nothing: ReDoS analysis in PHPStan is theoretical |
| `checks.redos.noJit` | nothing: PHPStan never runs a pattern |
| `suggestOptimizations` | `checks.optimizations.enabled` |
| `optimizationConfig` | `checks.optimizations.options` |
| `ignoreParseErrors` | nothing: a pattern PHP cannot compile is PHPStan's to report |
| `noNewMixed` | nothing: it held this library's own declared types to a baseline, and is part of the repository's tests now |

The identifiers `regex.syntax.invalid`, `regex.syntax.delimiter` and
`regex.syntax.empty` are gone (PHPStan reports those patterns as
`regexp.pattern`), and ReDoS findings are reported as `regex.redos` whatever
their severity, instead of `regex.redos.critical` and the like. Update
`ignoreErrors` and baselines accordingly. The rule takes
`new RegexPatternRule(array $config = [], ?PhpVersion $phpVersion = null)`, and
its `IDENTIFIER_SYNTAX_*` and `IDENTIFIER_REDOS_<SEVERITY>` constants are gone:
`IDENTIFIER_INVALID_FOR_TARGET` and `IDENTIFIER_REDOS` name the new ones.

#### ReDoS verdicts are proven, and the PHPStan ReDoS message changed

The ReDoS analysis proves the complexity class of a pattern where its model
covers it, and falls back to the 1.x heuristics elsewhere (see
[the ReDoS guide](docs/guides/redos.md)). Three things move on your side:

- **PHPStan baselines.** The ReDoS message is now one of
  `Exponential backtracking (ReDoS): <pattern>`,
  `Polynomial backtracking (ReDoS): <pattern>` and
  `Potential backtracking (ReDoS): <pattern>`, where 1.x said
  `Potential ReDoS risk (theoretical) (severity: CRITICAL, confidence: HIGH): <pattern>`
  or `Confirmed ReDoS risk (…): <pattern>`. The severity, how the verdict was
  reached and the attack moved to the tip. Regenerate the baseline once:

  ```bash
  vendor/bin/phpstan analyse --generate-baseline
  ```

  The text of each new message stays the same for all of 2.x. Which patterns
  are reported does not: when the analysis improves, an error may appear,
  disappear or change class (Exponential, Polynomial, Potential), so
  regenerate the baseline after an upgrade that changes the analysis. An
  `ignoreErrors` entry on the identifier `regex.redos` keeps working; one
  matching the 1.x message text does not. The `<pattern>` of the message is
  the pattern as the console shows it, escaped and on one line, cut after 50
  characters: a baseline entry written for a pattern holding a control
  character, a bidirectional override, a multi-byte character near the cut, or
  a line break under `x` needs that same regeneration.

  The same regeneration covers the lint issues, whose messages and reported
  set changed in 2.0.0: the useless `m` and `s` flag messages, the lazy
  quantifier under `U` and the redundant class ranges are reworded; false
  positives are gone; and with `redos` on, the nested-quantifier, dot-star
  and overlapping-set issues are no longer reported for a pattern the
  analysis proves linear. Regenerate the baseline once after upgrading, even
  one written by an earlier 2.0 pre-release.
- **Severities.** A pattern the model proves gets the severity of its class:
  exponential `critical`, polynomial of degree 3 or more `high`, of degree 2
  `medium`, linear `safe`. Many unanchored patterns the heuristics rated
  `medium` are now `safe (proven)`, and a few patterns rate higher, each with
  the attack that reproduces it. Thresholds are unchanged; a check that
  compared severities may see different findings on the first run.
- **`regex lint` with ReDoS on reports ReDoS findings.** `regex lint --redos`,
  `checks.redos.enabled` in `regex.json`, Symfony's `regex:lint` with
  `php_regex.redos.enabled` and Laravel's `regex:lint` with `redos.enabled`
  never ran the analysis in 1.x. They do now: expect `regex.lint.redos`
  warnings, and, in confirmed mode, errors for the verdicts PCRE reproduced.

Code that builds a `RedosAnalysis` by hand keeps working: the new constructor
parameters are trailing and defaulted, and such a result says
`proof: heuristic`. `isSafe()` keeps its meaning; use `isProvenSafe()` to
require a proof.

#### The PHPStan ReDoS threshold is `high` by default

With `checks.redos.enabled` (or `rules.neon`) and no `threshold`, the
extension reported only `critical` ReDoS risks; it now reports from `high`,
the default of `regex lint`, the Symfony and Laravel bridges and the language
server. A pattern proven polynomial of degree 3 or more, as
`/\d*\d*\d*$/`, is reported where it was not. To keep the 1.x set:

```neon
parameters:
    phpRegex:
        checks:
            redos:
                threshold: critical
```

#### The pattern extractors moved, and one is renamed

The two ways of finding regex patterns in PHP source now sit together under
`PHPRegex\Linter\Extraction`, with the interface they implement:

  - `RegexParser\Lint\ExtractorInterface` → `PHPRegex\Linter\Extraction\ExtractorInterface`
  - `RegexParser\Lint\TokenBasedExtractionStrategy` → `PHPRegex\Linter\Extraction\TokenBasedExtractionStrategy`
  - `RegexParser\Lint\PhpStanExtractionStrategy` → `PHPRegex\Linter\Extraction\PhpParserExtractionStrategy`

The last one never had anything to do with PHPStan: it reads the source with
nikic/php-parser, which PHPStan happens to bring along. The old names are
gone: import the new ones.

#### Two Symfony bridge classes are renamed

`RegexParser\Bridge\Symfony\Analyzer\Severity` said `pass`, `warn`, `fail`
and `critical` — the outcome of a check, not the severity of a lint issue,
which is what `PHPRegex\Linter\LintSeverity` means. It is now `PHPRegex\Symfony\Analyzer\CheckOutcome`.

`RegexParser\Bridge\Symfony\Analyzer\AnalysisReport` is the sectioned
report of the `regex:security` command and has nothing in common with
`PHPRegex\Toolkit\AnalysisReport`, the result of `Regex::analyze()`. It is now
`PHPRegex\Symfony\Analyzer\SecurityReport`.

Both are marked `@internal`; the commands that build them are the only
callers.

#### The lint CLI command moved to the CLI namespace

`RegexParser\Lint\Command\LintCommand` and `LintOutputRenderer` are the only
two classes of that namespace that needed the console, so the lint domain no
longer depends on the CLI it is called from. They are now
`PHPRegex\Cli\Command\LintCommand` and
`PHPRegex\Cli\Command\LintOutputRenderer`, both internal.

The old names are gone: import the new ones.

#### What reads patterns takes a `RegexParser`

Reading and judging a pattern moved from the `Regex` facade into
`RegexParser`, which the rest of the library now takes instead of the facade:

  - `new RedosAnalyzer(?RegexParser $parser, ...)`
  - `new LanguageSolver(?RegexParser $parser, ...)`, which replaces `RegexSolver` and `RegexLanguageSolver` (see below)
  - `new Transpiler(RegexParser $parser, ...)`
  - `new AnalysisService(RegexParser $parser, ...)`, whose `getRegex()` is now `getParser()`

Pass `$regex->parser()` where you passed a `Regex`, or build one with
`RegexParser::create()`, which takes the options `Regex::create()` takes.
`CharSet` and `CharSetAnalyzer` moved from `RegexParser\ReDoS` to
`PHPRegex\Parser\Hir\CharSet` and `PHPRegex\Parser\Analysis\CharSetAnalyzer`.

#### One automata entry point: `LanguageSolver`

`RegexParser\Automata\Solver\RegexSolver` and `RegexParser\Automata\Api\RegexLanguageSolver` offered the same
questions under two sets of names. Both are gone, with `RegexSolverInterface` and `RegexSolverCompilerInterface`;
`PHPRegex\Automata\LanguageSolver` answers every question they did. The result classes are unchanged.

| 1.x | 2.0 |
|---|---|
| `new RegexSolver($parser, $validator, $dfaBuilder, $dfaCache)` | `new LanguageSolver($parser, $dfaCache)` |
| `new RegexLanguageSolver($solver)`, `RegexLanguageSolver::forRegex($parser, $validator, $dfaBuilder, $dfaCache)` | `new LanguageSolver($parser, $dfaCache)` |
| `RegexSolver::intersection()`, `RegexLanguageSolver::intersectionEmpty()` | `LanguageSolver::intersection()` |
| `RegexSolver::subsetOf()`, `RegexLanguageSolver::subsetOf()` | `LanguageSolver::subsetOf()` |
| `RegexSolver::equivalent()`, `RegexLanguageSolver::equivalent()` | `LanguageSolver::equivalent()` |
| `RegexSolver::compile()` | `LanguageSolver::compile()` |
| `RegexLanguageSolver::prepare()` | `LanguageSolver::compile()`, whose `Dfa` you may ignore |
| `RegexSolverInterface`, `RegexSolverCompilerInterface` | gone: type against `LanguageSolver` |

The solver no longer takes a `RegularSubsetValidator` or a `DfaBuilder`: choose the algorithms through
`SolverOptions`. The public classes of `PHPRegex\Automata` are now `LanguageSolver`, `Options\SolverOptions`,
`Options\MatchMode`, `Determinization\DeterminizationAlgorithm`, `Minimization\MinimizationAlgorithm`, the four
result classes in `Solver\` (`IntersectionResult`, `SubsetResult`, `EquivalenceResult`, `MatchEquivalenceResult`),
`Model\Dfa` and the `Model\DfaState` it hands out, `Solver\DfaCacheInterface`, `Solver\InMemoryDfaCache`,
`TrivialMatchClassifier` with the `TrivialMatch` and `TrivialMatchKind` it returns, and `Language`; every other class
of the namespace is `@internal` and may change in any release.

`Model\DfaState` reads its moves through methods: `transitionFor()` as before, `rangeCount()`, and `ranges()`, which
builds the list of `[first, last, target]` triples the `$ranges` property held. `$ranges` and `$transitions` are gone:
a state holds its ranges as three ints in one list, which takes a Unicode DFA from about 330 bytes a range to about 130,
and an automaton over code points no longer keeps an index of where each range starts. Read `$state->ranges` as
`$state->ranges()`, and `$state->transitions[$byte]` as `$state->transitionFor($byte)`.

#### A custom node implements `getChildren()`

`NodeInterface` gains `getChildren()`. The library's nodes extend
`AbstractNode`, which returns no children; a node of your own that holds
others returns them, in pattern order. Custom visitors should extend
`AbstractNodeVisitor`, never implement `NodeVisitorInterface` directly: a
new kind of node adds a method to the interface.

#### `accept()` has no native return type on any node

`CalloutNode::accept()` and `DefineNode::accept()` declared a native `mixed`
return type; like every other node, they now declare none and return what
the visitor gives, the `@return T` of `NodeInterface::accept()`. Code that
reads the return type through reflection gets `null` for every node.

#### The PHPStan rules take PHPStan's `phpVersion` as it is

The last constructor parameter of `RegexPatternRule` and
`RegexPatternArgumentRule`, `$phpVersionRange`, is typed
`int|array|null`, an `int|array{min: int, max: int}|null` in its
docblock, where it took `mixed`: PHPStan's `phpVersion` parameter, one
version id or a `{min, max}` range. A rule built by hand with anything else
throws `TypeError` from a file that declares `strict_types` (elsewhere PHP's
own coercion applies), where the value was silently ignored.

#### Sequences and alternations hold lists

`SequenceNode::$children` and `AlternationNode::$alternatives` are
`list<NodeInterface>`: both constructors renumber the array they are given,
in the order given, so `new SequenceNode(['x' => $a, 'y' => $b], 0, 2)` holds
`[$a, $b]`. 1.x kept the keys. An extension that kept a key across
`array_filter()` and read it back on the node it built from the result reads
by position instead: `$node->children[0]` for the first child kept.

#### The cache stores trees, in memory by default

`CacheInterface` now takes and gives back the tree itself:

```php
public function write(string $key, RegexNode $ast): void;
public function load(string $key): ?RegexNode;
```

`getTimestamp()` is gone, and so are `CachePayloadDecoder`,
`FilesystemCache::defaultDirectory()` and the file extension argument of
`FilesystemCache`. A custom cache stores the tree as it likes and returns
`null` for anything that is not one. A tree it returns must be one the
library built, stored and read back as is: a tree put together without the
node constructors is not held to the lists above.

Without a `cache` option, `Regex::create()` keeps the latest 1024 trees in
memory (`ArrayCache`) instead of writing files under the system temp
directory. Name a directory to keep trees on disk: `['cache' => '/path']`. A
filesystem cache creates it for its owner only and ignores it when another
user owns it or others can write to it; its files hold data, not PHP.

#### Cached ASTs are rebuilt

`Regex::CACHE_VERSION` is now a fingerprint of the code that builds a tree, so
entries written by 1.x are ignored and the patterns are parsed once more.
Nothing to do; a warm cache directory rebuilds itself.

#### Error codes are an `ErrorCode` enum

`RegexException::$errorCode` is a `PHPRegex\Parser\ErrorCode` and is never
`null`; `ValidationResult::$errorCode` is `?ErrorCode`.
The values of the codes 1.x already had are unchanged, so code comparing strings
moves to `->value` or, better, to the case:

```php
// 1.x
if ('regex.lookbehind.unbounded' === $result->errorCode) { /* ... */ }

// 2.0
if (ErrorCode::LookbehindUnbounded === $result->errorCode) { /* ... */ }
```

`parser.error`, `lexer.error` and `regex.semantic` are gone: each error they
covered has its own code now, such as `regex.charclass.unclosed`,
`regex.group.unclosed` or `regex.delimiter.unclosed`. The full list is in
[Diagnostics: Error Codes](docs/reference/diagnostics.md#error-codes).

The exceptions that judge a pattern take the code as their second, required
argument:

| before | after |
|---|---|
| `new RegexException($message, $position, $snippet, ?string $errorCode, $previous)` | `new RegexException($message, ErrorCode $errorCode, $position, $snippet, $previous)` |
| `new ParserException($message, $position, $pattern)` | `new ParserException($message, ErrorCode $errorCode, $position, $pattern)` |
| `new LexerException($message, $position, $pattern)` | `new LexerException($message, ErrorCode $errorCode, $position, $pattern)` |
| `ParserException::withContext($message, $position, $pattern)` | `ParserException::withContext($message, ErrorCode $errorCode, $position, $pattern)` |
| `LexerException::withContext($message, $position, $pattern)` | `LexerException::withContext($message, ErrorCode $errorCode, $position, $pattern)` |
| `new SemanticErrorException($message, $position, $pattern, $previous, $errorCode, $hint)` | `new SemanticErrorException($message, ErrorCode $errorCode, $position, $pattern, $previous, $hint)` |
| `TokenStream::consume($type, $message)`, `consumeLiteral($value, $message)` | take an `ErrorCode $code` as third argument |

`SyntaxErrorException`, `RecursionLimitException` and `ResourceLimitException`
follow `ParserException`'s constructor. `ComplexityException`, `TranspileException` and
`SampleGenerationException` keep their parameter order and default their code,
so a call without one keeps working; a call that passed the code as a string
passes the `ErrorCode` case instead.

`regex.callout.invalid_type` is gone: no pattern could raise it.

The CLI's JSON output still carries the string value.

#### Optimizer options are typed, and keyed in snake_case

`Regex::optimize()` and `Optimizer::optimize()` take an `OptimizerOptions` value
or an array keyed in snake_case, as `Regex::create()` is. An unknown key or a
value of the wrong type throws `InvalidRegexOptionException`; 1.x ignored the
one and cast the other.

| 1.x key | 2.0 key |
|---|---|
| `canonicalizeCharClasses` | `canonicalize_char_classes` |
| `autoPossessify` | `possessive` |
| `allowAlternationFactorization` | `factorize` |
| `minQuantifierCount` | `min_quantifier_count` |
| `verifyWithAutomata` | `verify_with_automata` |

`digits`, `word` and `ranges` are unchanged. `RegexLintRequest::$optimizations`
and `RegexAnalysisService::suggestOptimizations()` take an `OptimizerOptions`.

`word` no longer writes `[a-zA-Z0-9_]` as `\w`: without `u`, `\w` follows the
`LC_CTYPE` locale once a program calls `setlocale()`, so the two differ under a
Latin-1 locale, and with `u` it matches every letter. The class comes back
with its members sorted, `[0-9A-Z_a-z]`. A test that expected `/\w+/` from
`optimize('/[a-zA-Z0-9_]+/')` expects the class now; write `\w` yourself where
the program runs under the C locale. `[0-9]` no longer becomes `\d` under
`(*UCP)`, as under `u`.

`verify_with_automata` is on by default, as it is for the lint: `optimize()`
returns a rewrite only once the automata prove it writes the same `$matches`,
and the pattern as written when they disprove it or cannot prove it within
their budget. It costs time, about two and a half times as long on average.
Pass `['verify_with_automata' => false]`, or `new OptimizerOptions(verifyWithAutomata: false)`,
to take the rewriter's output unproven, as 1.x did.

#### `ValidationResult::$error` is the message alone

In 1.x a failed validation's `$error` ended with the caret snippet, on the
lines after the message. It is now the message alone; the snippet is in
`$caretSnippet`, as it already was. Code that printed `$error` to show the
caret prints `$caretSnippet` after it; code that cut `$error` at its first
line can drop that step.

The accessors are gone, the deprecated `isValid()` and `getErrorMessage()`
with the getters that repeated a property: read the public readonly property
instead.

| 1.x | 2.0 |
|---|---|
| `ValidationResult::isValid()` | `$isValid` |
| `ValidationResult::getErrorMessage()` | `$error` |
| `ValidationResult::getComplexityScore()` | `$complexityScore` |
| `ValidationResult::getErrorCategory()` | `$category` |
| `ValidationResult::getErrorOffset()` | `$offset` |
| `ValidationResult::getCaretSnippet()` | `$caretSnippet` |
| `ValidationResult::getHint()` | `$hint` |
| `ValidationResult::getErrorCode()` | `$errorCode` |
| `AnalysisReport::errors()`, `lintIssues()`, `redos()`, `highlighted()` | the property of the same name |
| `AnalysisReport::optimizations()`, `explain()` | `$optimization` (one result), `$explanation` |
| `RegexPattern::fromRaw($pattern, $flags, $delimiter)`, `toString()` | `new DelimitedPattern($pattern, $flags, $delimiter)`, `(string) $pattern` |
| `RegexException::getErrorCode()`, `getPosition()`, `getSnippet()` | `$errorCode`, `$position`, `$snippet` |
| `SemanticErrorException::getHint()` | `$hint` |
| `LinterNodeVisitor::getWarnings()`, `getFullPattern()` | `PatternLinter::getIssues()`, each issue's `$message`; the pattern you linted |
| `ReDoSAnalysis::confidenceLevel()` | `RedosAnalysis::$confidence`, never null (`Low` when nothing raised it) |
| `ReDoSAnalysis::getCulpritNode()` | `RedosAnalysis::$culpritNode` |
| `ReDoSAnalysis::getPrimaryHotspot()` | `RedosAnalysis::primaryHotspot()` |

Rector cannot rewrite a method call into a property read, so change these by
hand (PHPStan reports each remaining call).

`new PatternLinter($rules)` (1.x `LinterNodeVisitor`) checks the keys of
the map: one naming a rule the linter does not run throws
`UnknownLintRuleException`, which names the nearest rule, where a typo turned
nothing off. Each key is a rule id without `regex.lint.` or in full.

#### `Regex::highlight()` refuses an unknown format

`highlight()` takes `console` or `html` (or the matching `OutputFormat`
case) and throws `InvalidRegexOptionException` on anything else, `text`
included; 1.x wrote console output for every format but `html`.

#### `PatternPrinter(pretty: true)` lays out like the formatter

`new PatternPrinter(pretty: true)` still normalizes escapes, still lays a
pattern out only under `x`, and never adds `x`; its layout now follows the
rules of [the formatter](docs/guides/formatting.md). A construct that fits the
line stays on one line, where every group and alternation was broken, and a
`#` comment trails the line it was written on. Code that compares its output
with a stored string needs that string written again. To format a pattern for
a reader, keeping its spelling, use `Regex::format()` or `PatternFormatter`.

#### `RedosRepair` has no `$complexity`

`RedosRepairer::repair()` returns only rewrites the backtracking model proves
linear, so `RedosRepair::$complexity` always said `RedosComplexity::Linear`:
it is gone. `isCertified()` is `true === $repair->sameSubjects`, the attempts
proven linear for every repair.

#### `LiteralExtractionResult::$confidence` is an enum

`Regex::literals()` gives its confidence as `LiteralConfidence::High`,
`Medium` or `Low` where 1.x gave the strings `'high'`, `'medium'` and
`'low'`. Compare with the case, or read `->value` for the string.

#### Every offset counts from the pattern body

`ValidationResult::$offset` and `RegexException::$position` count from the
first character of the pattern body, the coordinate PCRE2 uses, for every
error. In 1.x a modifier error and a pattern past `max_pattern_length` counted
from the start of the whole string, delimiter included, and a modifier error
pointed at the start of the modifiers:

| pattern | 1.x | 2.0 |
|---|---|---|
| `/a/imQ` (unknown modifier) | 3, the `i` | 4, the `Q` |
| `/a/b/` (delimiter ending the pattern early) | 2 | 1 |
| `/abcdefghi/` with `max_pattern_length: 10` | 10 | 9 |
| `''`, `'/'`, `'a'` (no body) | 0 | `null` |

Code that placed a caret under these errors in the whole pattern string adds
the length of the leading whitespace and the opening delimiter, as it already
did for every other error. The caret snippet of such an error still shows the
whole pattern as written, its caret under the character at fault.

#### regex.json: one spelling per setting, and nothing unknown

The lint command reads `regex.dist.json` and `regex.json` strictly. A key it
does not know, a lint rule id it does not know, or a value of the wrong kind
is an error: the command lists every one of them at once and exits with code
2 before scanning anything. 1.x ignored an unknown key and accepted the old
spellings; 2.0 refuses them with a plain unknown-key error naming the key
(a "Did you mean" hint appears when a known key is close):

| 1.x | 2.0 |
|---|---|
| `"rules": {"redos": true, "validation": true, "optimization": false}` | `"checks": {"redos": {"enabled": true}, "validation": true, "optimizations": {"enabled": false}}` |
| `"redosMode": "confirmed"` | `"checks": {"redos": {"mode": "confirmed"}}` |
| `"redosThreshold": "high"` | `"checks": {"redos": {"threshold": "high"}}` |
| `"redosNoJit": true`, `"checks": {"redos": {"noJit": true}}` | nothing: the confirmation always runs without JIT |
| `"optimizations": {"digits": true}` | `"checks": {"optimizations": {"options": {"digits": true}}}` |
| `"minSavings": 2` | `"checks": {"optimizations": {"minSavings": 2}}` |
| `"checks": {"redos": {"mode": "off"}}` | `"checks": {"redos": {"enabled": false}}` |
| `"checks": {"redos": true}` (any check as a boolean) | `"checks": {"redos": {"enabled": true}}` |

Three behaviours changed with the keys:

- Setting `mode` or `threshold` no longer switches ReDoS analysis on, and
  setting `minSavings`, `options` or `rules` no longer switches their check on:
  only `enabled` does. A `regex.json` that relied on `"redos": {"mode":
  "confirmed"}` alone adds `"enabled": true`.
- A list in `regex.json` replaces the one in `regex.dist.json` whole. 1.x
  merged them item by item, so `"exclude": ["build"]` over
  `["vendor", "tests"]` gave `["build", "tests"]`; it now gives `["build"]`.
- `threshold` accepts `low`, `medium`, `high` and `critical`, in any case;
  `safe` and `unknown` are refused.

`regex.schema.json` describes the new keys, and `phpVersion` and
`pcreVersion` join them: see below.

#### The lint command judges for the project's lowest PHP

Without `--php-version` or `--pcre-version`, 1.x linted for the PHP running
the command. The lint command now reads the target from `phpVersion` and
`pcreVersion` in `regex.json`, else from `composer.json` (`config.platform.php`,
else the lowest version `require.php` allows), and only then falls back to the
running PHP; the PCRE2 release is the one that PHP bundles unless given.
A project requiring `^8.2` running its CI on PHP 8.4 is now told about a
pattern PHP 8.2 refuses. To lint for the running engine as before, pass its
PHP version and PCRE2 release with `--php-version` and `--pcre-version`, or set
`phpVersion` and `pcreVersion` in `regex.json`.

When the PHP comes from `require.php`, every pattern is also validated on the
later PHP versions the constraint allows, where a rule of the library changes
and where a branch of an OR constraint starts:
a project requiring `>=8.2` is told about a `\K` in a lookaround, which PHP
8.5 refuses. A project green on its floor may so turn red on its ceiling. To
judge one version, name it with `--php-version`. See
[Several PHP versions](docs/guides/cli.md#several-php-versions).

The JSON report gains a `target` key (`{"php": "8.2", "pcre": "10.40",
"source": "composer.json require.php", "range": [...]}`), and each issue a
`target` naming the later PHP that refuses its pattern, `null` for the
others; the console banner shows the target
as a `Target` row under `Runtime`, and the GitHub, Checkstyle and JUnit
formats print the same line on stderr. Single-pattern commands, such as
`analyze`, still judge for the running PHP.

#### The lint reads more patterns

1.x read the patterns written as a literal in a `preg_*()` call. 2.0 reads
more, in `regex lint`, the language server and `regex:lint` of the Symfony
bundle and the Laravel package, so a project clean on 1.x may report findings
on unchanged code:

- the calls of eight library presets, all on by default: `composer-pcre`,
  `nette-utils`, `spatie-regex`, `illuminate-support`, `phpunit`,
  `webmozart-assert`, `beberlei-assert` and `symfony-validator`;
- `RegexIterator`, `RecursiveRegexIterator`, and `filter_var()` or
  `filter_input()` with `FILTER_VALIDATE_REGEXP`;
- a pattern held in a constant, a local variable or a property of the same
  file, reported at the line of its literal.

To read only some presets, list them; a list means exactly those, so a preset
a later 2.x release adds stays off for you:

```json
{
    "extraction": {
        "interop": ["composer-pcre"]
    }
}
```

`[]` (or `--no-interop`) reads the native calls only. The Laravel package
takes the same list under `extraction.interop` in `config/php-regex.php`, the
Symfony bundle under `php_regex.extraction.interop`, the PHPStan extension
under `phpRegex.interop`. A name that is no preset is a configuration error
everywhere, which names the preset it most likely means: `regex lint` and
`regex debug` exit with code 2, the Symfony bundle throws
`InvalidConfigurationException` as the container is built, Laravel's
`regex:lint` stops with `Invalid config/php-regex.php`, PHPStan stops with the
same error, and the language server shows the error and reads as without
`regex.json`. Left out, the name would leave a list that reads fewer calls
than meant, the native calls only when it was the only one. If you followed
the 2.x branch before 2.0, the preset `laravel-str` is now
`illuminate-support`, without an alias:

```
Unknown extraction preset "laravel-str" in --interop: did you mean "illuminate-support"?
```

A finding on a pattern read through a name is silenced with `@regex-ignore`
on the line of its literal, not on the call. See
[the CLI guide](docs/guides/cli.md#patterns-behind-a-wrapper).

#### The lint command's exit codes and streams

- Exit code 2 now means the configuration or the command line cannot be used
  (1.x returned 1, as for a pattern error). This covers an invalid
  `regex.json`, an unknown option or `--format`, and the removed options below.
- `--redos-mode=off` is refused: use `--no-redos`. `--redos-no-jit` is refused:
  the confirmation always runs without JIT.
- Errors go to stderr. With `--format=json` they go to stdout as the error
  envelope, `{"error": "...", "stage": "..."}`, and the progress and status
  lines stay out of stdout, so that stdout always holds one JSON document (see
  [JSON output](#json-output)).
- `--quiet` silences the status lines only. The JSON, GitHub, Checkstyle and
  JUnit reports are printed under it, where 1.x printed nothing. Symfony's
  and Laravel's `regex:lint` do the same.
- `--json` is short for `--format=json`.
- A lint rule of error severity now exits with 1, where every lint issue was
  a warning. Three rules are at that severity, each on a pattern that compiles
  but does not do what it says without `/u`: a multibyte character in a class
  (`unicode.multibyteInClassWithoutU`, `/[é]/`), a quantifier after a
  multibyte character (`unicode.quantifiedMultibyteWithoutU`, `/é+/`) and a
  Unicode property (`unicode.propertyWithoutU`, `/\p{L}/`). The fix is to add
  `/u`. To keep the old exit code, turn them off in `regex.json`:

  ```json
  {
      "checks": {
          "lint": {
              "rules": {
                  "unicode.multibyteInClassWithoutU": false,
                  "unicode.quantifiedMultibyteWithoutU": false,
                  "unicode.propertyWithoutU": false
              }
          }
      }
  }
  ```

  The Symfony and Laravel `regex:lint` commands exit with 1 on these rules
  too, and have no per-rule setting: add `/u`, or put
  `// @regex-ignore-next-line` above the call when bytes are meant.
- The issues follow the severity of their rule in every format: the JSON
  `severity` is `error`, `warning` or `info`, and `stats` always carries
  `errors`, `warnings`, `optimizations`, `redos_errors`, `infos`,
  `lint_errors` and `parser_fallbacks`;
  GitHub writes an info as `notice` and Checkstyle as `info`, where `style`
  and `perf` rules were warnings. The language server sends an info as
  Information, where it sent a Hint, which editors tend to show faintly or
  not at all. A quantified unnamed capturing group, an info, no longer counts
  among the warnings. See
  [Diagnostics](docs/reference/diagnostics.md#severity-in-each-format).

#### JSON output

The JSON the `regex` command prints is part of the backward compatibility
promise for all of 2.x, and 2.0 settles its shape. Every key is snake_case,
every command prints one document ending with a newline, and every failure
prints one error envelope. The
[JSON output reference](docs/reference/json-output.md) lists every key.

| Where | 1.x | 2.0 |
|---|---|---|
| lint result, issue and optimization entry | `fileOffset` | `file_offset` |
| lint issue | `issueId` | `issue_id` |
| lint issue | `type` | `severity` |
| lint issue `validation` | `isValid` | `is_valid` |
| lint issue `validation` | `complexityScore` | `complexity_score` |
| lint issue `validation` | `caretSnippet` | `caret_snippet` |
| lint issue `validation` | `errorCode` | `error_code` |
| `analyze` `validation` | `valid` | `is_valid` |
| baseline entry | `type` | `severity` |
| `runtime.jit` (`analyze`, `debug`, `redos`) | the `pcre.jit` string, as `"1"` | a bool |
| `confirmation.jit_setting` | a string | a bool |
| an error | `{"error": "..."}`, or `{"error": "...", "stage": "analyze"}` and `"debug"` | `{"error": "...", "stage": "..."}`, stage `usage`, `config`, `collect`, `analyze`, `pattern` or `internal`; `analyze` now names the step of `lint` that analyses the patterns, a worker that failed or exited, never the `analyze` command |

The rest of the lint `validation` object (`error`, `category`, `offset`,
`hint`) and the keys of the ReDoS analysis keep their names; only
`confirmation.jit_disable_requested` is gone, with the option it reported.

The same renames hold in PHP. `ValidationResult` implements
`JsonSerializable`, so `json_encode($validationResult)` gives `is_valid`,
`complexity_score`, `caret_snippet` and `error_code`, where 1.x gave the
public property names `isValid`, `complexityScore`, `caretSnippet` and
`errorCode`. `category` and `error_code` are the enum values, as strings. The
keys of `json_encode()` on an `OptimizationResult` or a `TranspileResult` do
not change.

- Every lint issue carries every key, `null` when it does not apply:
  `severity, file, line, column, file_offset, position, issue_id, message,
  hint, tip, source, validation, analysis`. An invalid pattern's `issue_id`
  is its error code, such as `regex.group.unclosed`. `column` is `null` when
  unknown, where 1.x printed `1`.
- The lint report ends with a newline, lists its results sorted by file, line
  and column whatever `--jobs` says, and gives `file` relative to the working
  directory with `/`. `stats` gains `redos_errors`, `infos`, `lint_errors`
  and `parser_fallbacks`; the report gains `target`.
- `analyze` on an invalid pattern prints its document, with `parse.ok` false,
  `validation` saying why, and `redos` and `explain` null. `debug` always
  carries `validation`; on an invalid pattern its `analysis` is null and it
  exits with 1, where 1.x printed an analysis of severity `unknown` and
  exited with 0.
- `transpile` and `redos` on an invalid pattern print the envelope with stage
  `pattern` and the `validation` object, and exit with 1. `transpile`
  validates the pattern first, so a pattern that parses but PHP refuses, such
  as `/(?<=a+)b/`, is refused where 1.x translated it. A valid pattern the
  target cannot express prints the envelope with stage `pattern` and no
  `validation`. `redos --format=json` no longer prints a benchmark of a
  pattern PHP refuses; its console output still measures it.
- Symfony's and Laravel's `regex:transpile` validate the pattern first, and
  in JSON print the CLI's document and envelope. Laravel's
  `{source, target, result, flags, warnings, compatible}` is gone: read
  `pattern` for `result`, and an empty `warnings` for `compatible`. Its
  `{error, details, snippet}` error is the envelope: `error` holds what
  `details` held, and the snippet is `validation.caret_snippet`.
- Laravel's `regex:transpile --target` takes the transpiler's targets:
  `javascript` (or `js`, as in the CLI) and `python` (or `py`). `ruby`, `go`,
  `rust`, `java`, `csharp` and `swift` were listed but never translated: 1.x
  accepted them, then failed with exit code 1. They are an unknown target
  now, a usage error with exit code 2, stage `usage` in JSON.
- Symfony's and Laravel's `regex:lint` print a failure in JSON as the
  envelope with its stage (`usage`, `config`, `collect` or `analyze`), where they
  printed `{"error": "..."}`, or a text error for an option Symfony could not
  use.
- Symfony's `regex:analyze --format=json` writes a section's `meta` and
  `debug` as lists of `{"label": ..., "value": ...}` rows, where they were
  objects keyed by the label, as `{"Routes": 2}`: read
  `array_column($section['meta'], 'value', 'label')` for the 1.x object. An
  option it cannot use prints the envelope, stage `usage`, where it printed a
  text error, and the report is written with Unicode and slashes unescaped,
  ending with one newline. The
  [JSON output reference](docs/reference/json-output.md#symfony-analyze)
  lists every key.
- A usage error is printed as the envelope, stage `usage`, as soon as
  `--format=json` or `--json` is on the command line, wherever it sits.
  `--format` values are case-insensitive, and the last `--format` or
  `--json` wins.
- A path given to `lint` that does not exist is a usage error: exit code 2,
  stage `usage` in JSON. A path listed in `regex.json` that does not exist is
  a configuration error: exit code 2, stage `config`. 1.x scanned nothing,
  reported no pattern and exited with 0.
- The baseline file written by `--generate-baseline` is
  `{"version": 1, "issues": [...]}`, each entry holding
  `{file, line, column, issue_id, message, severity, pattern, pattern_hash}`;
  1.x wrote a plain list, with `type` and a `pattern` always `null`. A 1.x
  baseline no longer loads: it is refused as a usage error, exit code 2 (see
  [`regex lint --baseline`](#regex-lint---baseline)). Regenerate it once so
  that a moved pattern or a reworded message no longer brings an issue back.
- A string is written as itself, its control characters in JSON's own
  escapes. A byte that is not valid UTF-8 comes out as the text `\xNN`, so a
  document can always be encoded.

A script reading the lint report renames the keys of the table, reads
`severity` where it read `type`, and tells a failure from a report by the
top-level `error` key.

#### GitHub and Checkstyle reports point at the pattern

`lint --format=github` writes `col=` as the column of the pattern in the
file, the `column` of the JSON report. 1.x wrote the position of the issue
inside the pattern there, and `1` when it had none, so the annotation landed
on the wrong column. When the column is unknown, as for a pattern not read
from a PHP string, `col=` is left out. The values are escaped as GitHub's
format requires: `%` is written `%25` in the message and in the properties,
where 1.x left it in the message, and `:` and `,` are written `%3A` and
`%2C` in the properties (`file`, `title`), where they ended the value early.

The Checkstyle `column` attribute is the same column, and is left out when
the column is unknown: 1.x wrote the position inside the pattern there too,
or `1`.

The Checkstyle `source` attribute is the issue's identifier, as the JSON
report's `issue_id` (`regex.lint.quantifier.nested`, `regex.group.unclosed`),
and `php-regex` for an issue without one. 1.x wrote `regex-parser.` before
the identifier: a filter on `source` matches the identifier alone now.

#### Every command exits with 0, 1 or 2

Every CLI command now uses the codes of the lint command:

| Code | Meaning                                                              |
|------|----------------------------------------------------------------------|
| `0`  | The command did what it was asked and found nothing wrong            |
| `1`  | The patterns or the files it judged have a problem                   |
| `2`  | The command line or the configuration cannot be used                 |

- A usage error exited with 1 in 1.x and exits with 2 now: an unknown command
  or option, a missing pattern or option value, an unknown `--format`,
  `--target` or `--method`, an invalid `--php-version`, a removed option, an
  `--input-file` that cannot be read, an `--output` file that cannot be
  written, a path given to `lint` that does not exist. `regex` without a
  command and `regex help <unknown>` exit with 2.
- Some results that exited with 0 exit with 1: `analyze` and `parse --validate`
  on an invalid pattern, `debug` on an invalid pattern in every format, a
  semantic error such as `/(?<=a+)b/` included, `transpile` on a pattern that
  parses but PHP refuses, `analyze` and `debug` on a ReDoS verdict that `--redos-mode=confirmed` reproduces on
  the running PCRE at high severity or more, `redos` on a pattern PHP refuses.
  A theoretical ReDoS verdict still exits with 0, proven or not.
- `debug` stops with 2 on a `regex.json` it cannot read, where it ignored it.
- `parse`, `validate`, `explain`, `diagram`, `highlight` and `graph` refuse an
  option they do not know, and accept options before the pattern; `--` ends
  the options.
- The Symfony and Laravel console commands exit with 2 (`Command::INVALID`)
  where an option or the configuration cannot be used, and keep 1 for what
  they found.

A script that tested for 1 to catch any failure should test for a non-zero
code instead.

#### A ReDoS threshold is low, medium, high or critical, everywhere

Every place that takes a ReDoS threshold reads it the same way: `low`,
`medium`, `high` or `critical`, in any case. `safe` and `unknown` are the
verdicts a pattern gets, not thresholds, and are refused like any other word,
with the value quoted in the message.

| Where                                               | 1.x with an unknown value      | 2.0                                  |
|-----------------------------------------------------|--------------------------------|--------------------------------------|
| `--redos-threshold` (`lint`, `analyze`, `debug`)    | refused; `safe` accepted       | refused, `safe` and `unknown` too    |
| `php_regex.redos.threshold` (Symfony)               | refused; `safe` accepted       | refused when the container compiles  |
| `--redos-threshold` of `regex:analyze`, `regex:security` | refused; `safe` accepted  | refused                              |
| `redos.threshold` (Laravel)                         | read as `high`                 | `regex:lint` stops with an error     |
| `checks.redos.threshold` (PHPStan, array wiring)    | read as `critical`             | refused when the rule is built, even with ReDoS off |
| `RegexAnalysisService` `$redosThreshold`            | read as `high`                 | `InvalidRegexOptionException`        |

A Symfony configuration using `threshold: safe` to report every finding
should use `low`.

#### `--format` says `console` for the terminal

| Command | 1.x | 2.0 |
|---|---|---|
| `regex explain`, `regex:explain` (Laravel) | `--format=text` | `--format=console` (the default) |
| `regex diagram` | `--format=text`, `ascii` or `cli` | `--format=console` (the default) |
| `regex highlight` | `--format=cli` | `--format=console` |

The former values are usage errors (exit code 2). `html`, `svg` and `auto`
are unchanged.

#### Symfony: `debug:compare` is gone

The compare command answers to `regex:compare` only; replace the
`debug:compare` alias in scripts and CI jobs.

#### Symfony: the bundle configuration in 2.0

The configuration moves from `regex_parser:` to `php_regex:`, with these keys:

| 1.x                                     | 2.0                                                        |
|-----------------------------------------|------------------------------------------------------------|
| `runtime_pcre_validation: '%kernel.debug%'` (default) | `runtime_pcre_validation: false` (default)   |
| `ide: '%env(default::SYMFONY_IDE)%'` (default) | `ide: null` (default): the editor of `framework.ide`, itself `SYMFONY_IDE` unless set |
| `exclude_paths`                         | `exclude`                                                  |
| `analysis.ignore_patterns`              | `redos.ignored_patterns` (the two lists were merged anyway) |
| `analysis.redos_threshold`              | removed: it was never read                                 |
| —                                       | `php_version`, `pcre_version`: the target of `regex:lint`  |

A 1.x key stops the container compile with Symfony's own unrecognized-option
message, naming the key and the valid options.

`runtime_pcre_validation` no longer follows `kernel.debug`: a debug kernel
compiled every pattern a second time with the running PHP. Set it to `true` to
keep that. It applies to the `php_regex.regex` service only.

`regex:lint` judges for the project's target, like the standalone lint
command: `php_version` / `pcre_version`, else `composer.json` in
`%kernel.project_dir%`, else the running PHP. It never uses
`runtime_pcre_validation`, and its JSON report gains a `target` key. The
`php_regex.regex` service keeps judging for the running PHP. See
[the Symfony guide](docs/guides/symfony.md).

Every service moves from `regex_parser.*` to `php_regex.*`.

The bundle no longer sets container parameters: its configuration goes
straight into its services, so an application can neither read it with
`%...%` or `getParameter()` nor override it with a parameter of the same
name. Read the `php_regex` configuration itself instead (`bin/console
debug:config php_regex`). These 1.x parameters are gone, with no 2.0
counterpart:

| 1.x parameter |
|---|
| `regex_parser.analysis.ignore_patterns` |
| `regex_parser.analysis.redos_threshold` |
| `regex_parser.analysis.warning_threshold` |
| `regex_parser.automata.determinization_algorithm` |
| `regex_parser.automata.minimization_algorithm` |
| `regex_parser.cache` |
| `regex_parser.editor_format` |
| `regex_parser.exclude_paths` |
| `regex_parser.extractor_service` |
| `regex_parser.max_lookbehind_length` |
| `regex_parser.max_pattern_length` |
| `regex_parser.optimizations` |
| `regex_parser.paths` |
| `regex_parser.redos.ignored_patterns` |
| `regex_parser.redos.threshold` |
| `regex_parser.runtime_pcre_validation` |

The 2.0 development snapshots set 22 `php_regex.*` parameters, gone as well:
`php_regex.max_pattern_length`, `php_regex.max_lookbehind_length`,
`php_regex.runtime_pcre_validation`, `php_regex.php_version`,
`php_regex.pcre_version`, `php_regex.locale`, `php_regex.project_dir`,
`php_regex.cache`, `php_regex.extractor_service`, `php_regex.redos.enabled`,
`php_regex.redos.threshold`, `php_regex.redos.ignored_patterns`,
`php_regex.analysis.complexity`, `php_regex.analysis.warning_threshold`,
`php_regex.automata.minimization_algorithm`,
`php_regex.automata.determinization_algorithm`, `php_regex.optimizations`,
`php_regex.paths`, `php_regex.exclude`, `php_regex.report_unmatched_ignores`,
`php_regex.editor_format` and `php_regex.extraction.warnings`.

#### Symfony: the bundle's services are private

Every service of the bundle is private and named `php_regex.*`:

- `$container->get('php_regex.regex')` no longer works: inject
  `PHPRegex\Toolkit\Regex`. The `PHPRegex\Toolkit\Regex` alias stays
  public, and `$container->get(Regex::class)` keeps working.
- The commands (`php_regex.command.lint` and the others) are private: they
  are lazy, listed by `bin/console list` and built when they run.
- The internal classes the bundle registered under their class name are no
  longer autowirable in the application: `PhpFilePatternSource`,
  `RoutePatternSource`, `ValidatorPatternSource`, `RouteConflictAnalyzer`,
  `RouteConflictSuggestionBuilder`, `RouteRequirementNormalizer`,
  `SecurityAccessControlAnalyzer`, `SecurityAccessSuggestionBuilder`,
  `SecurityConfigExtractor`, `SecurityConfigLocator`,
  `SecurityFirewallAnalyzer`, `SecurityPatternNormalizer`,
  `ConsoleReportFormatter`, `JsonReportFormatter`, `RoutesAnalyzer`,
  `SecurityAnalyzer` and the
  `PHPRegex\Linter\Extraction\ExtractorInterface` alias. Only
  `PHPRegex\Toolkit\Regex` and the `ValidRegexValidator` of the `ValidRegex`
  constraint are.
- `RouteControllerFileResolver`, which nothing called, is gone.
- `AnalyzerRegistry`, the `php_regex.bridge_analyzer` tag and
  `AnalyzerInterface::getPriority()` are gone: `regex:analyze` is given its
  two analyzers, routes then security. The tag was never an extension
  point: `AnalyzerInterface` is internal.

#### Laravel: config/php-regex.php replaces config/regex-parser.php

The keys of `config/regex-parser.php` become, in `config/php-regex.php`:

| 1.x                                              | 2.0                                                   |
|--------------------------------------------------|-------------------------------------------------------|
| `'runtime_pcre_validation' => env('APP_DEBUG', false)` | `'runtime_pcre_validation' => false`            |
| `exclude_paths`                                  | `exclude`                                             |
| `analysis.ignore_patterns`                       | `redos.ignored_patterns`                              |
| `analysis.redos_threshold`                       | removed: it was never read                            |
| `REGEX_PARSER_IDE` in `.env`                     | `PHP_REGEX_IDE` (`APP_EDITOR` stays the fallback)     |
| —                                                | `php_version`, `pcre_version`: the target of `regex:lint` |
| `automata.*`, never read                         | the defaults of `regex:compare`                       |

Publish the new file:

```bash
php artisan vendor:publish --tag=php-regex-config
```

then move your settings over from `config/regex-parser.php`, under the keys
above, and delete the old file: nothing reads it and nothing warns about it.
Every key the new file lacks, inside each section too, takes the package
default. A 1.x key copied as it is is silently ignored — unlike the Symfony
bundle, whose container compile refuses an unknown key outright.

`redos.enabled` now switches the ReDoS analysis of `regex:lint` on; 1.x handed
it to another setting and never ran the analysis. `regex:lint` judges for the
project's target (`php_version` / `pcre_version`, else `composer.json` at
`base_path()`, else the running PHP), never with `runtime_pcre_validation`,
and its JSON report gains a `target` key. The `Regex` service keeps judging for
the running PHP. The lint and analysis services are resolved when a command
uses them, so a setting they cannot use stops that command only. See
[the Laravel guide](docs/guides/laravel.md).

#### The language server judges for the workspace's target

1.x judged for the PHP running the server. The server now resolves the target
at `initialize`, for the first workspace folder (else `rootUri`):
`initializationOptions.phpVersion` / `pcreVersion`, else `regex.json` there,
else `composer.json` there, else the running PHP. It logs the target with
`window/logMessage`. A `Regex` passed to `new Server($regex)` is used as it is.
See [the language server guide](docs/guides/lsp.md#target-php-and-pcre2).

#### The ReDoS confirmation has no JIT switch

The confirmation always runs patterns without the JIT, so the switch that
turned the JIT off is gone: `regex analyze` and `regex debug` refuse
`--redos-no-jit`, `ReDoSConfirmOptions` has no `disableJit` argument, and
`ReDoSConfirmation` no longer carries `jitDisableRequested`
(`jit_disable_requested` in its JSON). Drop them; nothing else changes.

#### The ReDoS confirmation options refuse a value they clamped

`ReDoSConfirmOptions`, now `ConfirmationOptions`, raised an out-of-range value
to its floor in silence; it now throws `InvalidRegexOptionException` naming
the option, as `RedosOptions` and `SolverOptions` do: a subject length, a step
count, an iteration count or a PCRE limit below 1, a `maxInputLength` below
`minInputLength`, a `timeoutMs` below 1.0 (or `NAN`), a `previewLength` below 0.
Pass the floor you meant: `minInputLength: 1`, `timeoutMs: 1.0`, and so on.

#### `clearCaches()` replaces `clearValidatorCaches()`

`RegexParser::clearValidatorCaches()` and `Regex::clearValidatorCaches()` are
gone. `clearCaches()` takes their place on both, and empties every
process-wide cache of the library, the validator's among them:

| before | after |
|---|---|
| `$regex->clearValidatorCaches()` | `$regex->clearCaches()` |
| `$parser->clearValidatorCaches()` | `$parser->clearCaches()` |

Each of these caches is bounded now, so a long-running process no longer has
to empty them to keep its memory in check; it still may.

#### Exceptions say whose mistake it is

A mistake of the caller throws an exception implementing
`PHPRegex\Parser\Exception\ExceptionInterface`, so one `catch` holds
every one of them. A bug of the library throws a plain `\LogicException`,
which no caller should catch on purpose.

| what went wrong | before | 2.0 |
|---|---|---|
| `Regex::explain()` with an unknown format | `\InvalidArgumentException` | `InvalidRegexOptionException` |
| `FormatterRegistry::get()` with an unknown name | `\InvalidArgumentException` | `PHPRegex\Linter\LintException` |
| a lint worker that failed, a JSON report that cannot be encoded | `\RuntimeException` | `PHPRegex\Linter\LintException` |
| `GraphGenerator::generate()` with an unknown format, a self-update that cannot go on | `\InvalidArgumentException`, `\RuntimeException` | `PHPRegex\Cli\CliException` |
| the sample generator on a call it cannot follow, as `(?R)`, or an empty class built by hand | `\LogicException`, `\RuntimeException` | `SampleGenerationException` |
| `Regex::generate()` on a pattern that parses but the library judges invalid, as `/(?1)a/` | the visitor exception, or a sample | `SampleGenerationException` (a pattern that does not parse still throws the parser's exception) |

#### A limit of the library is no invalid pattern

`RecursionLimitException` and `ResourceLimitException` no longer extend
`ParserException`: they extend the new `LimitException`, beside it under
`RegexException`. A pattern past `max_pattern_length` or `max_recursion_depth`
is one the library did not read to its end, not one PCRE refuses, so a
`catch (ParserException)` written for grammar errors no longer takes it.
Catch `LimitException` (or `RegexException`) where 1.x caught `ParserException`
to also handle the limits.

`validate()` reports a limit with the new `ValidationErrorCategory::Limit`
(`limit` in JSON), where 1.x said `syntax`; `isValid` stays `false`, since
nothing was checked, and `hint` names the option to raise. The lint reports
it as a warning, `Not analyzed: …`, and no longer as an invalid pattern.

`LintException` and `CliException` extend `\RuntimeException`, so a `catch`
of that class keeps holding them. `InvalidRegexOptionException` extends
`\InvalidArgumentException`. A `catch (\RuntimeException)` around the
sample generator for a subroutine catches `SampleGenerationException`
instead.
