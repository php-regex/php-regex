# Upgrading from 1.x to 2.0

2.0 is a new major line. The 1.x line stays on the
[`1.x` branch](https://github.com/php-regex/regex-parser/tree/1.x), with its own
upgrade notes; this guide covers moving from 1.3 to 2.0. For every change, see
[CHANGELOG.md](CHANGELOG.md).

### Names

Every class moved from `RegexParser\` to `PhpRegex\`, under the package it
ships in, and many took a name that says what they are. Enum cases are in
PascalCase, and a few methods are renamed. The sections after this one name
classes as 1.3 did; the tables at the end of this one give each 2.0 name.

A Rector set makes these changes in your code. Add it to your `rector.php`
and run Rector once:

```php
$rectorConfig->sets([__DIR__.'/vendor/php-regex/toolkit/Resources/rector/upgrade-2.0.php']);
```

It renames classes, enum cases and the renamed methods. It does not change
what the tables mark as removed, the configuration keys below, the error codes
(see "Error codes are an `ErrorCode` enum") or the offsets (see "Every offset
counts from the pattern body").

The configuration takes the PhpRegex name:

| 1.3 | 2.0 |
|---|---|
| Symfony: `RegexParserBundle`, configured under `regex_parser:` | `PhpRegex\Symfony\PhpRegexBundle`, configured under `php_regex:` |
| Symfony: services and parameters `regex_parser.*` | `php_regex.*` |
| PHPStan: parameter `regexParser` | `phpRegex` |
| PHPStan: rule `RegexParserRule` | `PhpRegex\PHPStan\RegexPatternRule` |
| Laravel: `RegexParserServiceProvider` | `PhpRegex\Laravel\PhpRegexServiceProvider` |
| Laravel: `config/regex-parser.php`, key `regex-parser` | `config/php-regex.php`, key `php-regex` |
| Laravel: publish tag `regex-parser-config` | `php-regex-config` |
| Laravel: container entries `regex-parser.*` | `php-regex.*` |

Laravel loads a `config/regex-parser.php` left from 1.3 but nothing reads it;
the provider raises a deprecation while it is there. Publish the new file,
move your settings to it, and delete the old one.

<!-- upgrade-map:start (php tests/Tools/write_upgrade_map.php) -->

| 1.3 | 2.0 |
|---|---|
| `RegexParser\AnalysisReport` | `PhpRegex\Toolkit\AnalysisReport` |
| `RegexParser\Automata\Alphabet\CharSet` | `PhpRegex\Automata\Alphabet\CharSet` |
| `RegexParser\Automata\Api\RegexLanguageSolver` | `PhpRegex\Automata\LanguageSolver` |
| `RegexParser\Automata\AstToNfaTransformer` | `PhpRegex\Automata\Transform\AstToNfaTransformer` |
| `RegexParser\Automata\AstToNfaTransformerInterface` | `PhpRegex\Automata\Transform\AstToNfaTransformerInterface` |
| `RegexParser\Automata\Builder\DfaBuilder` | `PhpRegex\Automata\Builder\DfaBuilder` |
| `RegexParser\Automata\Builder\NfaBuilder` | `PhpRegex\Automata\Builder\NfaBuilder` |
| `RegexParser\Automata\CharSet` | `PhpRegex\Automata\Alphabet\CharSet` |
| `RegexParser\Automata\Determinization\DeterminizationAlgorithm` | `PhpRegex\Automata\Determinization\DeterminizationAlgorithm` |
| `RegexParser\Automata\Determinization\DeterminizationAlgorithmFactory` | `PhpRegex\Automata\Determinization\DeterminizationAlgorithmFactory` |
| `RegexParser\Automata\Determinization\DeterminizationAlgorithmInterface` | `PhpRegex\Automata\Determinization\DeterminizationAlgorithmInterface` |
| `RegexParser\Automata\Determinization\SubsetConstruction` | `PhpRegex\Automata\Determinization\SubsetConstruction` |
| `RegexParser\Automata\Determinization\SubsetConstructionIndexed` | `PhpRegex\Automata\Determinization\SubsetConstructionIndexed` |
| `RegexParser\Automata\Determinization\WorkBudgetAwareDeterminizationAlgorithmInterface` | `PhpRegex\Automata\Determinization\WorkBudgetAwareDeterminizationAlgorithmInterface` |
| `RegexParser\Automata\Dfa` | `PhpRegex\Automata\Model\Dfa` |
| `RegexParser\Automata\DfaBuilder` | `PhpRegex\Automata\Builder\DfaBuilder` |
| `RegexParser\Automata\DfaMinimizer` | `PhpRegex\Automata\Minimization\DfaMinimizer` |
| `RegexParser\Automata\DfaState` | `PhpRegex\Automata\Model\DfaState` |
| `RegexParser\Automata\EquivalenceResult` | `PhpRegex\Automata\Solver\EquivalenceResult` |
| `RegexParser\Automata\HopcroftWorklist` | `PhpRegex\Automata\Minimization\HopcroftWorklist` |
| `RegexParser\Automata\IntersectionResult` | `PhpRegex\Automata\Solver\IntersectionResult` |
| `RegexParser\Automata\MatchMode` | `PhpRegex\Automata\Options\MatchMode` |
| `RegexParser\Automata\MinimizationAlgorithm` | `PhpRegex\Automata\Minimization\MinimizationAlgorithm` |
| `RegexParser\Automata\MinimizationAlgorithmFactory` | `PhpRegex\Automata\Minimization\MinimizationAlgorithmFactory` |
| `RegexParser\Automata\MinimizationAlgorithmInterface` | `PhpRegex\Automata\Minimization\MinimizationAlgorithmInterface` |
| `RegexParser\Automata\Minimization\DfaMinimizer` | `PhpRegex\Automata\Minimization\DfaMinimizer` |
| `RegexParser\Automata\Minimization\HopcroftWorklist` | `PhpRegex\Automata\Minimization\HopcroftWorklist` |
| `RegexParser\Automata\Minimization\MinimizationAlgorithm` | `PhpRegex\Automata\Minimization\MinimizationAlgorithm` |
| `RegexParser\Automata\Minimization\MinimizationAlgorithmFactory` | `PhpRegex\Automata\Minimization\MinimizationAlgorithmFactory` |
| `RegexParser\Automata\Minimization\MinimizationAlgorithmInterface` | `PhpRegex\Automata\Minimization\MinimizationAlgorithmInterface` |
| `RegexParser\Automata\Minimization\MoorePartitionRefinement` | `PhpRegex\Automata\Minimization\MoorePartitionRefinement` |
| `RegexParser\Automata\Minimization\WorkBudgetAwareMinimizationAlgorithmInterface` | `PhpRegex\Automata\Minimization\WorkBudgetAwareMinimizationAlgorithmInterface` |
| `RegexParser\Automata\Model\Dfa` | `PhpRegex\Automata\Model\Dfa` |
| `RegexParser\Automata\Model\DfaState` | `PhpRegex\Automata\Model\DfaState` |
| `RegexParser\Automata\Model\Nfa` | `PhpRegex\Automata\Model\Nfa` |
| `RegexParser\Automata\Model\NfaFragment` | `PhpRegex\Automata\Model\NfaFragment` |
| `RegexParser\Automata\Model\NfaState` | `PhpRegex\Automata\Model\NfaState` |
| `RegexParser\Automata\Model\NfaTransition` | `PhpRegex\Automata\Model\NfaTransition` |
| `RegexParser\Automata\MoorePartitionRefinement` | `PhpRegex\Automata\Minimization\MoorePartitionRefinement` |
| `RegexParser\Automata\Nfa` | `PhpRegex\Automata\Model\Nfa` |
| `RegexParser\Automata\NfaBuilder` | `PhpRegex\Automata\Builder\NfaBuilder` |
| `RegexParser\Automata\NfaFragment` | `PhpRegex\Automata\Model\NfaFragment` |
| `RegexParser\Automata\NfaState` | `PhpRegex\Automata\Model\NfaState` |
| `RegexParser\Automata\NfaTransition` | `PhpRegex\Automata\Model\NfaTransition` |
| `RegexParser\Automata\Options\MatchMode` | `PhpRegex\Automata\Options\MatchMode` |
| `RegexParser\Automata\Options\SolverOptions` | `PhpRegex\Automata\Options\SolverOptions` |
| `RegexParser\Automata\RegexSolver` | `PhpRegex\Automata\LanguageSolver` |
| `RegexParser\Automata\RegularSubsetValidator` | `PhpRegex\Automata\Transform\RegularSubsetValidator` |
| `RegexParser\Automata\SolverOptions` | `PhpRegex\Automata\Options\SolverOptions` |
| `RegexParser\Automata\Solver\DfaCacheInterface` | `PhpRegex\Automata\Solver\DfaCacheInterface` |
| `RegexParser\Automata\Solver\EquivalenceResult` | `PhpRegex\Automata\Solver\EquivalenceResult` |
| `RegexParser\Automata\Solver\InMemoryDfaCache` | `PhpRegex\Automata\Solver\InMemoryDfaCache` |
| `RegexParser\Automata\Solver\IntersectionResult` | `PhpRegex\Automata\Solver\IntersectionResult` |
| `RegexParser\Automata\Solver\RegexSolver` | `PhpRegex\Automata\LanguageSolver` |
| `RegexParser\Automata\Solver\SubsetResult` | `PhpRegex\Automata\Solver\SubsetResult` |
| `RegexParser\Automata\SubsetResult` | `PhpRegex\Automata\Solver\SubsetResult` |
| `RegexParser\Automata\Support\WorkBudget` | `PhpRegex\Automata\Support\WorkBudget` |
| `RegexParser\Automata\Transform\AstToNfaTransformer` | `PhpRegex\Automata\Transform\AstToNfaTransformer` |
| `RegexParser\Automata\Transform\AstToNfaTransformerInterface` | `PhpRegex\Automata\Transform\AstToNfaTransformerInterface` |
| `RegexParser\Automata\Transform\RegularSubsetValidator` | `PhpRegex\Automata\Transform\RegularSubsetValidator` |
| `RegexParser\Automata\Unicode\CodePointHelper` | `PhpRegex\Automata\Unicode\CodePointHelper` |
| `RegexParser\Bridge\PHPStan\RegexParserRule` | `PhpRegex\PHPStan\RegexPatternRule` |
| `RegexParser\Bridge\Symfony\Analyzer\AnalysisContext` | `PhpRegex\Symfony\Analyzer\AnalysisContext` |
| `RegexParser\Bridge\Symfony\Analyzer\AnalysisIssue` | `PhpRegex\Symfony\Analyzer\AnalysisIssue` |
| `RegexParser\Bridge\Symfony\Analyzer\AnalysisNotice` | `PhpRegex\Symfony\Analyzer\AnalysisNotice` |
| `RegexParser\Bridge\Symfony\Analyzer\AnalysisReport` | `PhpRegex\Symfony\Analyzer\SecurityReport` |
| `RegexParser\Bridge\Symfony\Analyzer\AnalyzerInterface` | `PhpRegex\Symfony\Analyzer\AnalyzerInterface` |
| `RegexParser\Bridge\Symfony\Analyzer\AnalyzerRegistry` | `PhpRegex\Symfony\Analyzer\AnalyzerRegistry` |
| `RegexParser\Bridge\Symfony\Analyzer\Formatter\ConsoleReportFormatter` | `PhpRegex\Symfony\Analyzer\Formatter\ConsoleReportFormatter` |
| `RegexParser\Bridge\Symfony\Analyzer\Formatter\JsonReportFormatter` | `PhpRegex\Symfony\Analyzer\Formatter\JsonReportFormatter` |
| `RegexParser\Bridge\Symfony\Analyzer\IssueDetail` | `PhpRegex\Symfony\Analyzer\IssueDetail` |
| `RegexParser\Bridge\Symfony\Analyzer\ReportSection` | `PhpRegex\Symfony\Analyzer\ReportSection` |
| `RegexParser\Bridge\Symfony\Analyzer\RoutesAnalyzer` | `PhpRegex\Symfony\Analyzer\RoutesAnalyzer` |
| `RegexParser\Bridge\Symfony\Analyzer\SecurityAnalyzer` | `PhpRegex\Symfony\Analyzer\SecurityAnalyzer` |
| `RegexParser\Bridge\Symfony\Analyzer\Severity` | `PhpRegex\Symfony\Analyzer\CheckOutcome` |
| `RegexParser\Bridge\Symfony\Command\CompareCommand` | `PhpRegex\Symfony\Command\CompareCommand` |
| `RegexParser\Bridge\Symfony\Command\RegexAnalyzeCommand` | `PhpRegex\Symfony\Command\AnalyzeCommand` |
| `RegexParser\Bridge\Symfony\Command\RegexLintCommand` | `PhpRegex\Symfony\Command\LintCommand` |
| `RegexParser\Bridge\Symfony\Command\RegexRoutesCommand` | `PhpRegex\Symfony\Command\RoutesCommand` |
| `RegexParser\Bridge\Symfony\Command\RegexSecurityCommand` | `PhpRegex\Symfony\Command\SecurityCommand` |
| `RegexParser\Bridge\Symfony\Command\RegexTranspileCommand` | `PhpRegex\Symfony\Command\TranspileCommand` |
| `RegexParser\Bridge\Symfony\DependencyInjection\Configuration` | `PhpRegex\Symfony\DependencyInjection\Configuration` |
| `RegexParser\Bridge\Symfony\DependencyInjection\RegexParserExtension` | `PhpRegex\Symfony\DependencyInjection\PhpRegexExtension` |
| `RegexParser\Bridge\Symfony\Extractor\RouteRegexPatternSource` | `PhpRegex\Symfony\Extractor\RoutePatternSource` |
| `RegexParser\Bridge\Symfony\Extractor\ValidatorRegexPatternSource` | `PhpRegex\Symfony\Extractor\ValidatorPatternSource` |
| `RegexParser\Bridge\Symfony\Output\SymfonyConsoleFormatter` | `PhpRegex\Symfony\Output\SymfonyConsoleFormatter` |
| `RegexParser\Bridge\Symfony\RegexParserBundle` | `PhpRegex\Symfony\PhpRegexBundle` |
| `RegexParser\Bridge\Symfony\Routing\RouteConflictAnalyzer` | `PhpRegex\Symfony\Routing\RouteConflictAnalyzer` |
| `RegexParser\Bridge\Symfony\Routing\RouteConflictReport` | `PhpRegex\Symfony\Routing\RouteConflictReport` |
| `RegexParser\Bridge\Symfony\Routing\RouteConflictSuggestionBuilder` | `PhpRegex\Symfony\Routing\RouteConflictSuggestionBuilder` |
| `RegexParser\Bridge\Symfony\Routing\RouteControllerFileResolver` | `PhpRegex\Symfony\Routing\RouteControllerFileResolver` |
| `RegexParser\Bridge\Symfony\Routing\RouteRequirementNormalizer` | `PhpRegex\Symfony\Routing\RouteRequirementNormalizer` |
| `RegexParser\Bridge\Symfony\Security\SecurityAccessControlAnalyzer` | `PhpRegex\Symfony\Security\SecurityAccessControlAnalyzer` |
| `RegexParser\Bridge\Symfony\Security\SecurityAccessControlReport` | `PhpRegex\Symfony\Security\SecurityAccessControlReport` |
| `RegexParser\Bridge\Symfony\Security\SecurityAccessSuggestionBuilder` | `PhpRegex\Symfony\Security\SecurityAccessSuggestionBuilder` |
| `RegexParser\Bridge\Symfony\Security\SecurityConfigExtractor` | `PhpRegex\Symfony\Security\SecurityConfigExtractor` |
| `RegexParser\Bridge\Symfony\Security\SecurityConfigLocator` | `PhpRegex\Symfony\Security\SecurityConfigLocator` |
| `RegexParser\Bridge\Symfony\Security\SecurityFirewallAnalyzer` | `PhpRegex\Symfony\Security\SecurityFirewallAnalyzer` |
| `RegexParser\Bridge\Symfony\Security\SecurityFirewallReport` | `PhpRegex\Symfony\Security\SecurityFirewallReport` |
| `RegexParser\Bridge\Symfony\Security\SecurityPatternNormalizer` | `PhpRegex\Symfony\Security\SecurityPatternNormalizer` |
| `RegexParser\Cache\ArrayCache` | `PhpRegex\Parser\Cache\ArrayCache` |
| `RegexParser\Cache\CacheInterface` | `PhpRegex\Parser\Cache\CacheInterface` |
| `RegexParser\Cache\FilesystemCache` | `PhpRegex\Parser\Cache\FilesystemCache` |
| `RegexParser\Cache\NullCache` | `PhpRegex\Parser\Cache\NullCache` |
| `RegexParser\Cache\PsrCacheAdapter` | `PhpRegex\Parser\Cache\PsrCacheAdapter` |
| `RegexParser\Cache\PsrSimpleCacheAdapter` | `PhpRegex\Parser\Cache\PsrSimpleCacheAdapter` |
| `RegexParser\Cache\RemovableCacheInterface` | `PhpRegex\Parser\Cache\RemovableCacheInterface` |
| `RegexParser\Cli\Application` | `PhpRegex\Cli\Application` |
| `RegexParser\Cli\Command\AbstractCommand` | `PhpRegex\Cli\Command\AbstractCommand` |
| `RegexParser\Cli\Command\AnalyzeCommand` | `PhpRegex\Cli\Command\AnalyzeCommand` |
| `RegexParser\Cli\Command\ClearCacheCommand` | `PhpRegex\Cli\Command\ClearCacheCommand` |
| `RegexParser\Cli\Command\CommandInterface` | `PhpRegex\Cli\Command\CommandInterface` |
| `RegexParser\Cli\Command\CompareCommand` | `PhpRegex\Cli\Command\CompareCommand` |
| `RegexParser\Cli\Command\DebugCommand` | `PhpRegex\Cli\Command\DebugCommand` |
| `RegexParser\Cli\Command\DiagramCommand` | `PhpRegex\Cli\Command\DiagramCommand` |
| `RegexParser\Cli\Command\ExplainCommand` | `PhpRegex\Cli\Command\ExplainCommand` |
| `RegexParser\Cli\Command\GraphCommand` | `PhpRegex\Cli\Command\GraphCommand` |
| `RegexParser\Cli\Command\HelpCommand` | `PhpRegex\Cli\Command\HelpCommand` |
| `RegexParser\Cli\Command\HighlightCommand` | `PhpRegex\Cli\Command\HighlightCommand` |
| `RegexParser\Cli\Command\ParseCommand` | `PhpRegex\Cli\Command\ParseCommand` |
| `RegexParser\Cli\Command\RedosCommand` | `PhpRegex\Cli\Command\RedosCommand` |
| `RegexParser\Cli\Command\SelfUpdateCommand` | `PhpRegex\Cli\Command\SelfUpdateCommand` |
| `RegexParser\Cli\Command\TranspileCommand` | `PhpRegex\Cli\Command\TranspileCommand` |
| `RegexParser\Cli\Command\ValidateCommand` | `PhpRegex\Cli\Command\ValidateCommand` |
| `RegexParser\Cli\Command\VersionCommand` | `PhpRegex\Cli\Command\VersionCommand` |
| `RegexParser\Cli\ConsoleStyle` | `PhpRegex\Cli\ConsoleStyle` |
| `RegexParser\Cli\GlobalOptions` | `PhpRegex\Cli\GlobalOptions` |
| `RegexParser\Cli\GlobalOptionsParser` | `PhpRegex\Cli\GlobalOptionsParser` |
| `RegexParser\Cli\Graph\GraphGenerator` | `PhpRegex\Cli\Graph\GraphGenerator` |
| `RegexParser\Cli\Graph\GraphvizDumper` | `PhpRegex\Cli\Graph\GraphvizDumper` |
| `RegexParser\Cli\Graph\MermaidDumper` | `PhpRegex\Cli\Graph\MermaidDumper` |
| `RegexParser\Cli\Input` | `PhpRegex\Cli\Input` |
| `RegexParser\Cli\Output` | `PhpRegex\Cli\Output` |
| `RegexParser\Cli\ParsedGlobalOptions` | `PhpRegex\Cli\ParsedGlobalOptions` |
| `RegexParser\Cli\SelfUpdate\SelfUpdater` | `PhpRegex\Cli\SelfUpdate\SelfUpdater` |
| `RegexParser\Exception\ComplexityException` | `PhpRegex\Automata\Exception\ComplexityException` |
| `RegexParser\Exception\InvalidRegexOptionException` | `PhpRegex\Parser\Exception\InvalidRegexOptionException` |
| `RegexParser\Exception\LexerException` | `PhpRegex\Parser\Exception\LexerException` |
| `RegexParser\Exception\ParserException` | `PhpRegex\Parser\Exception\ParserException` |
| `RegexParser\Exception\RecursionLimitException` | `PhpRegex\Parser\Exception\RecursionLimitException` |
| `RegexParser\Exception\RegexException` | `PhpRegex\Parser\Exception\RegexException` |
| `RegexParser\Exception\RegexParserExceptionInterface` | `PhpRegex\Parser\Exception\ExceptionInterface` |
| `RegexParser\Exception\ResourceLimitException` | `PhpRegex\Parser\Exception\ResourceLimitException` |
| `RegexParser\Exception\SemanticErrorException` | `PhpRegex\Parser\Exception\SemanticErrorException` |
| `RegexParser\Exception\SyntaxErrorException` | `PhpRegex\Parser\Exception\SyntaxErrorException` |
| `RegexParser\Exception\TranspileException` | `PhpRegex\Transpiler\TranspileException` |
| `RegexParser\Exception\VisualContextTrait` | `PhpRegex\Parser\Exception\VisualContextTrait` |
| `RegexParser\GroupNumbering` | `PhpRegex\Parser\Analysis\GroupNumbering` |
| `RegexParser\GroupNumberingCollector` | `PhpRegex\Parser\Analysis\GroupNumberingCollector` |
| `RegexParser\Internal\PatternParser` | `PhpRegex\Parser\Internal\PatternParser` |
| `RegexParser\Lexer` | `PhpRegex\Parser\Lexer` |
| `RegexParser\LintIssue` | `PhpRegex\Linter\Rule\RuleViolation` |
| `RegexParser\Lint\Command\LintArgumentParser` | `PhpRegex\Linter\Config\LintArgumentParser` |
| `RegexParser\Lint\Command\LintArguments` | `PhpRegex\Linter\Config\LintArguments` |
| `RegexParser\Lint\Command\LintCommand` | `PhpRegex\Cli\Command\LintCommand` |
| `RegexParser\Lint\Command\LintConfigLoader` | `PhpRegex\Linter\Config\LintConfigLoader` |
| `RegexParser\Lint\Command\LintConfigResult` | `PhpRegex\Linter\Config\LintConfigResult` |
| `RegexParser\Lint\Command\LintDefaultsBuilder` | `PhpRegex\Linter\Config\LintDefaultsBuilder` |
| `RegexParser\Lint\Command\LintExtractorFactory` | `PhpRegex\Linter\Config\LintExtractorFactory` |
| `RegexParser\Lint\Command\LintOutputRenderer` | `PhpRegex\Cli\Command\LintOutputRenderer` |
| `RegexParser\Lint\Command\LintParseResult` | `PhpRegex\Linter\Config\LintParseResult` |
| `RegexParser\Lint\ExtractorInterface` | `PhpRegex\Linter\Extraction\ExtractorInterface` |
| `RegexParser\Lint\Formatter\AbstractOutputFormatter` | `PhpRegex\Linter\Formatter\AbstractOutputFormatter` |
| `RegexParser\Lint\Formatter\CheckstyleFormatter` | `PhpRegex\Linter\Formatter\CheckstyleFormatter` |
| `RegexParser\Lint\Formatter\ConsoleFormatter` | `PhpRegex\Linter\Formatter\ConsoleFormatter` |
| `RegexParser\Lint\Formatter\FormatterRegistry` | `PhpRegex\Linter\Formatter\FormatterRegistry` |
| `RegexParser\Lint\Formatter\GithubFormatter` | `PhpRegex\Linter\Formatter\GithubFormatter` |
| `RegexParser\Lint\Formatter\JsonFormatter` | `PhpRegex\Linter\Formatter\JsonFormatter` |
| `RegexParser\Lint\Formatter\JunitFormatter` | `PhpRegex\Linter\Formatter\JunitFormatter` |
| `RegexParser\Lint\Formatter\LinkFormatter` | `PhpRegex\Linter\Formatter\LinkFormatter` |
| `RegexParser\Lint\Formatter\OutputConfiguration` | `PhpRegex\Linter\Formatter\OutputConfiguration` |
| `RegexParser\Lint\Formatter\OutputFormatterInterface` | `PhpRegex\Linter\Formatter\OutputFormatterInterface` |
| `RegexParser\Lint\Formatter\RelativePathHelper` | `PhpRegex\Linter\Formatter\RelativePathHelper` |
| `RegexParser\Lint\PhpRegexPatternSource` | `PhpRegex\Linter\Source\PhpFilePatternSource` |
| `RegexParser\Lint\PhpStanExtractionStrategy` | `PhpRegex\Linter\Extraction\PhpParserExtractionStrategy` |
| `RegexParser\Lint\RegexAnalysisService` | `PhpRegex\Linter\AnalysisService` |
| `RegexParser\Lint\RegexLintReport` | `PhpRegex\Linter\LintReport` |
| `RegexParser\Lint\RegexLintRequest` | `PhpRegex\Linter\LintRequest` |
| `RegexParser\Lint\RegexLintService` | `PhpRegex\Linter\LintService` |
| `RegexParser\Lint\RegexPatternExtractor` | `PhpRegex\Linter\PatternExtractor` |
| `RegexParser\Lint\RegexPatternOccurrence` | `PhpRegex\Linter\PatternOccurrence` |
| `RegexParser\Lint\RegexPatternSourceCollection` | `PhpRegex\Linter\Source\PatternSourceCollection` |
| `RegexParser\Lint\RegexPatternSourceContext` | `PhpRegex\Linter\Source\PatternSourceContext` |
| `RegexParser\Lint\RegexPatternSourceInterface` | `PhpRegex\Linter\Source\PatternSourceInterface` |
| `RegexParser\Lint\TokenBasedExtractionStrategy` | `PhpRegex\Linter\Extraction\TokenBasedExtractionStrategy` |
| `RegexParser\LiteralExtractionResult` | `PhpRegex\Parser\Analysis\LiteralExtractionResult` |
| `RegexParser\LiteralSet` | `PhpRegex\Parser\Analysis\LiteralSet` |
| `RegexParser\NodeVisitor\AbstractNodeVisitor` | `PhpRegex\Parser\AbstractNodeVisitor` |
| `RegexParser\NodeVisitor\AsciiTreeVisitor` | `PhpRegex\Explain\AsciiTreeRenderer` |
| `RegexParser\NodeVisitor\CompilerNodeVisitor` | `PhpRegex\Parser\Printer\PatternPrinter` |
| `RegexParser\NodeVisitor\ComplexityScoreNodeVisitor` | `PhpRegex\Parser\Analysis\ComplexityScorer` |
| `RegexParser\NodeVisitor\ConsoleHighlighterVisitor` | `PhpRegex\Explain\Highlighter\ConsoleHighlighter` |
| `RegexParser\NodeVisitor\DumperNodeVisitor` | `PhpRegex\Parser\Printer\NodeDumper` |
| `RegexParser\NodeVisitor\ExplainNodeVisitor` | `PhpRegex\Explain\TextExplainer` |
| `RegexParser\NodeVisitor\HighlighterVisitor` | `PhpRegex\Explain\Highlighter\AbstractHighlighter` |
| `RegexParser\NodeVisitor\HtmlExplainNodeVisitor` | `PhpRegex\Explain\HtmlExplainer` |
| `RegexParser\NodeVisitor\HtmlHighlighterVisitor` | `PhpRegex\Explain\Highlighter\HtmlHighlighter` |
| `RegexParser\NodeVisitor\LengthRangeNodeVisitor` | `PhpRegex\Parser\Analysis\LengthRangeCalculator` |
| `RegexParser\NodeVisitor\LinterNodeVisitor` | `PhpRegex\Linter\PatternLinter` |
| `RegexParser\NodeVisitor\LiteralExtractorNodeVisitor` | `PhpRegex\Parser\Analysis\LiteralExtractor` |
| `RegexParser\NodeVisitor\MermaidNodeVisitor` | `PhpRegex\Explain\MermaidRenderer` |
| `RegexParser\NodeVisitor\MetricsNodeVisitor` | `PhpRegex\Parser\Analysis\MetricsCollector` |
| `RegexParser\NodeVisitor\ModernizerNodeVisitor` | `PhpRegex\Optimizer\Modernizer` |
| `RegexParser\NodeVisitor\NodeVisitorInterface` | `PhpRegex\Parser\NodeVisitorInterface` |
| `RegexParser\NodeVisitor\OptimizerNodeVisitor` | `PhpRegex\Optimizer\Rewriter` |
| `RegexParser\NodeVisitor\RailroadSvgVisitor` | `PhpRegex\Explain\RailroadSvgRenderer` |
| `RegexParser\NodeVisitor\ReDoSProfileNodeVisitor` | `PhpRegex\Redos\RedosProfiler` |
| `RegexParser\NodeVisitor\SampleGeneratorNodeVisitor` | `PhpRegex\Generator\SampleGenerator` |
| `RegexParser\NodeVisitor\TestCaseGeneratorNodeVisitor` | `PhpRegex\Generator\TestCaseGenerator` |
| `RegexParser\NodeVisitor\ValidatorNodeVisitor` | `PhpRegex\Parser\Validation\Validator` |
| `RegexParser\Node\AbstractNode` | `PhpRegex\Parser\Node\AbstractNode` |
| `RegexParser\Node\AlternationNode` | `PhpRegex\Parser\Node\AlternationNode` |
| `RegexParser\Node\AnchorNode` | `PhpRegex\Parser\Node\AnchorNode` |
| `RegexParser\Node\AssertionNode` | `PhpRegex\Parser\Node\AssertionNode` |
| `RegexParser\Node\BackrefNode` | `PhpRegex\Parser\Node\BackrefNode` |
| `RegexParser\Node\CalloutNode` | `PhpRegex\Parser\Node\CalloutNode` |
| `RegexParser\Node\CharClassNode` | `PhpRegex\Parser\Node\CharClassNode` |
| `RegexParser\Node\CharLiteralNode` | `PhpRegex\Parser\Node\CharLiteralNode` |
| `RegexParser\Node\CharLiteralType` | `PhpRegex\Parser\Node\CharLiteralType` |
| `RegexParser\Node\CharTypeNode` | `PhpRegex\Parser\Node\CharTypeNode` |
| `RegexParser\Node\CommentNode` | `PhpRegex\Parser\Node\CommentNode` |
| `RegexParser\Node\ConditionalNode` | `PhpRegex\Parser\Node\ConditionalNode` |
| `RegexParser\Node\ControlCharNode` | `PhpRegex\Parser\Node\ControlCharNode` |
| `RegexParser\Node\DefineNode` | `PhpRegex\Parser\Node\DefineNode` |
| `RegexParser\Node\DotNode` | `PhpRegex\Parser\Node\DotNode` |
| `RegexParser\Node\GroupNode` | `PhpRegex\Parser\Node\GroupNode` |
| `RegexParser\Node\GroupType` | `PhpRegex\Parser\Node\GroupType` |
| `RegexParser\Node\KeepNode` | `PhpRegex\Parser\Node\KeepNode` |
| `RegexParser\Node\LimitMatchNode` | `PhpRegex\Parser\Node\LimitMatchNode` |
| `RegexParser\Node\LiteralNode` | `PhpRegex\Parser\Node\LiteralNode` |
| `RegexParser\Node\NodeInterface` | `PhpRegex\Parser\Node\NodeInterface` |
| `RegexParser\Node\PcreVerbNode` | `PhpRegex\Parser\Node\PcreVerbNode` |
| `RegexParser\Node\PosixClassNode` | `PhpRegex\Parser\Node\PosixClassNode` |
| `RegexParser\Node\QuantifierNode` | `PhpRegex\Parser\Node\QuantifierNode` |
| `RegexParser\Node\QuantifierType` | `PhpRegex\Parser\Node\QuantifierType` |
| `RegexParser\Node\RangeNode` | `PhpRegex\Parser\Node\RangeNode` |
| `RegexParser\Node\RegexNode` | `PhpRegex\Parser\Node\RegexNode` |
| `RegexParser\Node\ScriptRunNode` | `PhpRegex\Parser\Node\ScriptRunNode` |
| `RegexParser\Node\SequenceNode` | `PhpRegex\Parser\Node\SequenceNode` |
| `RegexParser\Node\SubroutineNode` | `PhpRegex\Parser\Node\SubroutineNode` |
| `RegexParser\Node\UnicodePropNode` | `PhpRegex\Parser\Node\UnicodePropNode` |
| `RegexParser\Node\VersionConditionNode` | `PhpRegex\Parser\Node\VersionConditionNode` |
| `RegexParser\OptimizationResult` | `PhpRegex\Optimizer\OptimizationResult` |
| `RegexParser\Parser` | `PhpRegex\Parser\Syntax\TokenParser` |
| `RegexParser\ProblemType` | `PhpRegex\Linter\DiagnosticType` |
| `RegexParser\ReDoS\CharSet` | `PhpRegex\Parser\Analysis\ByteCharSet` |
| `RegexParser\ReDoS\CharSetAnalyzer` | `PhpRegex\Parser\Analysis\CharSetAnalyzer` |
| `RegexParser\ReDoS\ReDoSAnalysis` | `PhpRegex\Redos\RedosAnalysis` |
| `RegexParser\ReDoS\ReDoSAnalyzer` | `PhpRegex\Redos\RedosAnalyzer` |
| `RegexParser\ReDoS\ReDoSConfidence` | `PhpRegex\Redos\RedosConfidence` |
| `RegexParser\ReDoS\ReDoSConfirmOptions` | `PhpRegex\Redos\ConfirmationOptions` |
| `RegexParser\ReDoS\ReDoSConfirmation` | `PhpRegex\Redos\Confirmation` |
| `RegexParser\ReDoS\ReDoSConfirmationRunner` | `PhpRegex\Redos\ConfirmationRunner` |
| `RegexParser\ReDoS\ReDoSConfirmationRunnerInterface` | `PhpRegex\Redos\ConfirmationRunnerInterface` |
| `RegexParser\ReDoS\ReDoSConfirmationSample` | `PhpRegex\Redos\ConfirmationSample` |
| `RegexParser\ReDoS\ReDoSFinding` | `PhpRegex\Redos\Finding` |
| `RegexParser\ReDoS\ReDoSHeatmap` | `PhpRegex\Redos\Heatmap` |
| `RegexParser\ReDoS\ReDoSHotspot` | `PhpRegex\Redos\Hotspot` |
| `RegexParser\ReDoS\ReDoSInputGenerator` | `PhpRegex\Redos\Internal\InputGenerator` |
| `RegexParser\ReDoS\ReDoSMode` | `PhpRegex\Redos\RedosMode` |
| `RegexParser\ReDoS\ReDoSSeverity` | `PhpRegex\Redos\RedosSeverity` |
| `RegexParser\Regex` | `PhpRegex\Toolkit\Regex` |
| `RegexParser\RegexOptions` | `PhpRegex\Parser\ParserOptions` |
| `RegexParser\RegexPattern` | `PhpRegex\Parser\DelimitedPattern` |
| `RegexParser\RegexProblem` | `PhpRegex\Linter\Diagnostic` |
| `RegexParser\Runtime\PcreRuntimeInfo` | `PhpRegex\Cli\PcreRuntimeInfo` |
| `RegexParser\Severity` | `PhpRegex\Linter\LintSeverity` |
| `RegexParser\Token` | `PhpRegex\Parser\Token\Token` |
| `RegexParser\TokenStream` | `PhpRegex\Parser\Token\TokenStream` |
| `RegexParser\TokenType` | `PhpRegex\Parser\Token\TokenType` |
| `RegexParser\TolerantParseResult` | `PhpRegex\Parser\TolerantParseResult` |
| `RegexParser\Transpiler\RegexTranspiler` | `PhpRegex\Transpiler\Transpiler` |
| `RegexParser\Transpiler\Target\JavaScript\JavaScriptCompilerVisitor` | `PhpRegex\Transpiler\Target\JavaScript\JavaScriptPrinter` |
| `RegexParser\Transpiler\Target\JavaScript\JavaScriptTarget` | `PhpRegex\Transpiler\Target\JavaScript\JavaScriptTarget` |
| `RegexParser\Transpiler\Target\Python\PythonCompilerVisitor` | `PhpRegex\Transpiler\Target\Python\PythonPrinter` |
| `RegexParser\Transpiler\Target\Python\PythonTarget` | `PhpRegex\Transpiler\Target\Python\PythonTarget` |
| `RegexParser\Transpiler\Target\TargetRegistry` | `PhpRegex\Transpiler\Target\TargetRegistry` |
| `RegexParser\Transpiler\Target\TranspileTargetInterface` | `PhpRegex\Transpiler\Target\TargetInterface` |
| `RegexParser\Transpiler\TranspileContext` | `PhpRegex\Transpiler\TranspileContext` |
| `RegexParser\Transpiler\TranspileOptions` | `PhpRegex\Transpiler\TranspileOptions` |
| `RegexParser\Transpiler\TranspileResult` | `PhpRegex\Transpiler\TranspileResult` |
| `RegexParser\ValidationErrorCategory` | `PhpRegex\Parser\Validation\ValidationErrorCategory` |
| `RegexParser\ValidationResult` | `PhpRegex\Parser\Validation\ValidationResult` |
| `RegexParser\Automata\RegexSolverInterface` | removed: type against `PhpRegex\Automata\LanguageSolver` |
| `RegexParser\Automata\Solver\RegexSolverCompilerInterface` | removed: type against `PhpRegex\Automata\LanguageSolver` |
| `RegexParser\Automata\Solver\RegexSolverInterface` | removed: type against `PhpRegex\Automata\LanguageSolver` |
| `RegexParser\Node\ClassOperationNode` | removed: no replacement: PHP reads "&&" and "--" in a class as members and ranges, so no pattern ever produced it |
| `RegexParser\Node\ClassOperationType` | removed: no replacement: it only typed ClassOperationNode |
| `RegexParser\Node\UnicodeNode` | removed: use `PhpRegex\Parser\Node\CharLiteralNode`, which every \x{...} and \u{...} escape already became |
| `RegexParser\ReDoS\ReDoSAnalyzerInterface` | removed: type against `PhpRegex\Redos\RedosAnalyzer` |

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

<!-- upgrade-map:end -->

### Breaking Changes

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
`RegexParser\PcreTarget`, resolved once by `Regex::create()` and passed to
everything that reads it.

| before | after |
|---|---|
| `new Lexer($versionId)` or `new Lexer(bool)` | `new Lexer(?PcreTarget $target = null)` |
| `new Parser($depth, ?int $phpVersionId)` | `new Parser($depth, ?PcreTarget $target)` |
| `new ValidatorNodeVisitor($max, $pattern, int $phpVersionId)` | `new ValidatorNodeVisitor($max, $pattern, ?PcreTarget $target)` |
| `Regex::tokenize($regex, ?int $phpVersionId)` | `Regex::tokenize($regex, ?PcreTarget $target)` |
| `Regex::cacheSeed($regex, int $phpVersionId, $depth)` | `Regex::cacheSeed($regex, PcreTarget $target, $depth)` |
| `RegexPattern::fromDelimited($regex, ?int)`, `PatternParser::extractPatternAndFlags($regex, ?int)` | take `?PcreTarget` |
| `RegexOptions::$phpVersionId`, `$phpVersionExplicit` | `RegexOptions::$target` |
| `new RegexOptions(..., $maxRecursionDepth, int $phpVersionId, bool $phpVersionExplicit)` | `new RegexOptions(..., $maxRecursionDepth, ?PcreTarget $target)` |
| `Lexer::readsWideRepeatCounts()` | gone: `PcreTarget::pcreAtLeast('10.43')` |

`null` means `PcreTarget::runtime()`, the running PHP and the PCRE2 it links.
`PcreTarget::bundledWith(80200)` is a PHP version with the PCRE2 it bundles.

#### `GroupType` has a new case, and two nodes a new field

`GroupType::T_GROUP_SCAN_SUBSTRING` stands for `(*scs:(1)...)`, PCRE2 10.45: a
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
`RegexParser\Exception\SampleGenerationException`. Catch it where a pattern
may match nothing.

#### `php_version` alone always means the PCRE2 that PHP bundles

`php_version` naming the running PHP used to mix two engines: the parser read
the bundled PCRE2, the validator the linked one. It now judges with the bundled
one throughout, as for any other version. Judging for the running engine is the
default, with no option; add `pcre_version` to name the linked release.

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
runs on, `regexParser.pcreVersion` for a PHP that links another PCRE2, or
`regexParser.phpVersion: runtime` to keep the old behaviour.

#### The PHPStan extension reports what PHPStan does not

Enabled by extension-installer, the extension used to report every lint rule
and ReDoS finding, and every invalid pattern a second time next to PHPStan's
own `regexp.pattern`. It now reports, by default, only a pattern the target PHP
refuses while the PHP running PHPStan compiles it, under
`regex.invalidForTarget`. Lint rules and ReDoS analysis are opt-in: include
`vendor/yoeunes/regex-parser/rules.neon`, or switch each on under `checks`.

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

The identifiers `regex.syntax.invalid`, `regex.syntax.delimiter` and
`regex.syntax.empty` are gone (PHPStan reports those patterns as
`regexp.pattern`), and ReDoS findings are reported as `regex.redos` whatever
their severity, instead of `regex.redos.critical` and the like. Update
`ignoreErrors` and baselines accordingly. The rule takes
`new RegexParserRule(array $config = [], ?PhpVersion $phpVersion = null)`, and
its `IDENTIFIER_SYNTAX_*` and `IDENTIFIER_REDOS_<SEVERITY>` constants are gone:
`IDENTIFIER_INVALID_FOR_TARGET` and `IDENTIFIER_REDOS` name the new ones.

#### The pattern extractors moved, and one is renamed

The two ways of finding regex patterns in PHP source now sit together under
`RegexParser\Lint\Extraction`, with the interface they implement:

  - `RegexParser\Lint\ExtractorInterface` → `RegexParser\Lint\Extraction\ExtractorInterface`
  - `RegexParser\Lint\TokenBasedExtractionStrategy` → `RegexParser\Lint\Extraction\TokenBasedExtractionStrategy`
  - `RegexParser\Lint\PhpStanExtractionStrategy` → `RegexParser\Lint\Extraction\PhpParserExtractionStrategy`

The last one never had anything to do with PHPStan: it reads the source with
nikic/php-parser, which PHPStan happens to bring along. The old names are
gone: import the new ones.

#### Two Symfony bridge classes are renamed

`RegexParser\Bridge\Symfony\Analyzer\Severity` said `pass`, `warn`, `fail`
and `critical` — the outcome of a check, not the severity of a lint issue,
which is what `RegexParser\Severity` means. It is now `CheckOutcome`.

`RegexParser\Bridge\Symfony\Analyzer\AnalysisReport` is the sectioned
report of the `regex:security` command and has nothing in common with
`RegexParser\AnalysisReport`, the result of `Regex::analyze()`. It is now
`SecurityReport`.

Both are marked `@internal`; the commands that build them are the only
callers.

#### The lint CLI command moved to the CLI namespace

`RegexParser\Lint\Command\LintCommand` and `LintOutputRenderer` are the only
two classes of that namespace that needed the console, so the lint domain no
longer depends on the CLI it is called from. They are now
`RegexParser\Cli\Command\LintCommand` and
`RegexParser\Cli\Command\LintOutputRenderer`.

The old names are gone: import the new ones.

#### What reads patterns takes a `RegexParser`

Reading and judging a pattern moved from the `Regex` facade into
`RegexParser`, which the rest of the library now takes instead of the facade:

  - `new ReDoSAnalyzer(?RegexParser $parser, ...)`
  - `new LanguageSolver(?RegexParser $parser, ...)`, which replaces `RegexSolver` and `RegexLanguageSolver` (see below)
  - `new RegexTranspiler(RegexParser $parser, ...)`
  - `new RegexAnalysisService(RegexParser $parser, ...)`, whose `getRegex()` is now `getParser()`

Pass `$regex->parser()` where you passed a `Regex`, or build one with
`RegexParser::create()`, which takes the options `Regex::create()` takes.
`CharSet` and `CharSetAnalyzer` moved from `RegexParser\ReDoS` to
`RegexParser\Analysis`.

#### One automata entry point: `LanguageSolver`

`RegexParser\Automata\Solver\RegexSolver` and `RegexParser\Automata\Api\RegexLanguageSolver` offered the same
questions under two sets of names. Both are gone, with `RegexSolverInterface` and `RegexSolverCompilerInterface`;
`RegexParser\Automata\LanguageSolver` answers every question they did. The result classes are unchanged.

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
`SolverOptions`. The public classes of `RegexParser\Automata` are now `LanguageSolver`, `Options\SolverOptions`,
`Options\MatchMode`, `Determinization\DeterminizationAlgorithm`, `Minimization\MinimizationAlgorithm`,
`Solver\IntersectionResult`, `Solver\SubsetResult`, `Solver\EquivalenceResult`, `Model\Dfa`,
`Solver\DfaCacheInterface` and `Solver\InMemoryDfaCache`; every other class of the namespace is `@internal` and may
change in any release.

#### A custom node implements `getChildren()`

`NodeInterface` gains `getChildren()`. The library's nodes extend
`AbstractNode`, which returns no children; a node of your own that holds
others returns them, in pattern order. Custom visitors should extend
`AbstractNodeVisitor`, never implement `NodeVisitorInterface` directly: a
new kind of node adds a method to the interface.

#### The cache stores trees, in memory by default

`CacheInterface` now takes and gives back the tree itself:

```php
public function write(string $key, RegexNode $ast): void;
public function load(string $key): ?RegexNode;
```

`getTimestamp()` is gone, and so are `CachePayloadDecoder`,
`FilesystemCache::defaultDirectory()` and the file extension argument of
`FilesystemCache`. A custom cache stores the tree as it likes and returns
`null` for anything that is not one.

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

`RegexException::getErrorCode()` returns a `RegexParser\ErrorCode` and is never
`null`; `ValidationResult::$errorCode` and `getErrorCode()` are `?ErrorCode`.
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
follow `ParserException`. `ComplexityException`, `TranspileException` and
`SampleGenerationException` keep their parameter order and default their code,
so a call without one keeps working; a call that passed the code as a string
passes the `ErrorCode` case instead.

`regex.callout.invalid_type` is gone: no pattern could raise it.

The CLI's JSON output and `RegexProblem::$code` still carry the string value.

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

#### `ValidationResult::$error` is the message alone

In 1.x a failed validation's `$error` ended with the caret snippet, on the
lines after the message. It is now the message alone; the snippet is in
`$caretSnippet`, as it already was. Code that printed `$error` to show the
caret prints `$caretSnippet` after it; code that cut `$error` at its first
line can drop that step.

#### Every offset counts from the pattern body

`ValidationResult::$offset` and `RegexException::getPosition()` count from the
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
2 before scanning anything. 1.x ignored an unknown key and still read the old
spellings; 2.0 refuses them and names the key to use:

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

The JSON report gains a `target` key (`{"php": "8.2", "pcre": "10.40",
"source": "composer.json require.php"}`); the other formats print the same line
on stderr. Single-pattern commands, such as `analyze`, still judge for the
running PHP.

#### The lint command's exit codes and streams

- Exit code 2 now means the configuration or the command line cannot be used
  (1.x returned 1, as for a pattern error). This covers an invalid
  `regex.json`, an unknown option or `--format`, and the removed options below.
- `--redos-mode=off` is refused: use `--no-redos`. `--redos-no-jit` is refused:
  the confirmation always runs without JIT.
- Errors go to stderr. With `--format=json` they go to stdout as
  `{"error": "..."}`, and the progress and status lines stay out of stdout, so
  that stdout always holds one JSON document.

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
  written. `regex` without a command and `regex help <unknown>` exit with 2.
- Some results that exited with 0 exit with 1: `analyze` and `parse --validate`
  on an invalid pattern, `debug` on a pattern that does not parse, `analyze`
  and `debug` on a ReDoS risk that `--redos-mode=confirmed` confirms at high
  severity or more, `redos` on a pattern PHP refuses. A theoretical ReDoS
  finding still exits with 0.
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
| `regex_parser.redos.threshold` (Symfony)            | refused; `safe` accepted       | refused when the container compiles  |
| `--redos-threshold` of `regex:analyze`, `regex:security` | refused; `safe` accepted  | refused                              |
| `redos.threshold` (Laravel)                         | read as `high`                 | `regex:lint` stops with an error     |
| `checks.redos.threshold` (PHPStan, array wiring)    | read as `critical`             | refused when the rule is built, even with ReDoS off |
| `RegexAnalysisService` `$redosThreshold`            | read as `high`                 | `InvalidRegexOptionException`        |

A Symfony configuration using `threshold: safe` to report every finding
should use `low`.

#### Symfony: the bundle configuration in 2.0

| 1.x                                     | 2.0                                                        |
|-----------------------------------------|------------------------------------------------------------|
| `runtime_pcre_validation: '%kernel.debug%'` (default) | `runtime_pcre_validation: false` (default)   |
| `exclude_paths`                         | `exclude`                                                  |
| `analysis.ignore_patterns`              | `redos.ignored_patterns` (the two lists were merged anyway) |
| `analysis.redos_threshold`              | removed: it was never read                                 |
| —                                       | `php_version`, `pcre_version`: the target of `regex:lint`  |

A 1.x key stops the container compile with a message naming the key that
replaces it.

`runtime_pcre_validation` no longer follows `kernel.debug`: a debug kernel
compiled every pattern a second time with the running PHP. Set it to `true` to
keep that. It applies to the `regex_parser.regex` service only.

`regex:lint` judges for the project's target, like the standalone lint
command: `php_version` / `pcre_version`, else `composer.json` in
`%kernel.project_dir%`, else the running PHP. It never uses
`runtime_pcre_validation`, and its JSON report gains a `target` key. The
`regex_parser.regex` service keeps judging for the running PHP. See
[the Symfony guide](docs/guides/symfony.md).

The container parameters `regex_parser.analysis.redos_threshold`,
`regex_parser.analysis.ignore_patterns` and `regex_parser.exclude_paths` are
gone; `regex_parser.exclude`, `regex_parser.php_version` and
`regex_parser.pcre_version` are new.

#### Laravel: config/regex-parser.php in 2.0

| 1.x                                              | 2.0                                                   |
|--------------------------------------------------|-------------------------------------------------------|
| `'runtime_pcre_validation' => env('APP_DEBUG', false)` | `'runtime_pcre_validation' => false`            |
| `exclude_paths`                                  | `exclude`                                             |
| `analysis.ignore_patterns`                       | `redos.ignored_patterns`                              |
| `analysis.redos_threshold`                       | removed: it was never read                            |
| —                                                | `php_version`, `pcre_version`: the target of `regex:lint` |
| `automata.*`, never read                         | the defaults of `regex:compare`                       |

Re-publish the file:

```bash
php artisan vendor:publish --tag=regex-parser-config --force
```

A file published by 1.x keeps working: every key it lacks, inside each section
too, takes the package default, and `regex:lint` prints a warning for each 1.x
key it still holds, with the key that replaces it. The old key is not read. A
published file still has `'runtime_pcre_validation' => env('APP_DEBUG', false)`
until you change it.

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
`RegexParser\Exception\RegexParserExceptionInterface`, so one `catch` holds
every one of them. A bug of the library throws a plain `\LogicException`,
which no caller should catch on purpose.

| what went wrong | before | 2.0 |
|---|---|---|
| `Regex::explain()` with an unknown format | `\InvalidArgumentException` | `InvalidRegexOptionException` |
| `FormatterRegistry::get()` with an unknown name | `\InvalidArgumentException` | `RegexParser\Lint\LintException` |
| a lint worker that failed, a JSON report that cannot be encoded | `\RuntimeException` | `RegexParser\Lint\LintException` |
| `GraphGenerator::generate()` with an unknown format, a self-update that cannot go on | `\InvalidArgumentException`, `\RuntimeException` | `RegexParser\Cli\CliException` |
| the sample generator on a call it cannot follow, as `(?R)`, or an empty class built by hand | `\LogicException`, `\RuntimeException` | `SampleGenerationException` |
| `Regex::generate()` on a pattern the library judges invalid, as `/(?1)a/` | the parse or visitor exception, or a sample | `SampleGenerationException` |
| a `TokenStream` read or moved past its bounds | `\RuntimeException` | `\LogicException` |

`LintException` and `CliException` extend `\RuntimeException`, so a `catch`
of that class keeps holding them. `InvalidRegexOptionException` extends
`\InvalidArgumentException`. A `catch (\RuntimeException)` around a token
stream, or around the sample generator for a subroutine, catches
`\LogicException` or `SampleGenerationException` instead.
