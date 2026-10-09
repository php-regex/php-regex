<?php

declare(strict_types=1);

/*
 * This file is part of the PHPRegex package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PHPRegex\Tests\Support;

/**
 * The classes the backward compatibility promise covers, package by package.
 * docs/reference/backward-compatibility.md lists the same classes, row by row.
 */
final class PublicSurface
{
    /**
     * Relative to src/<Package>/, without ".php".
     */
    public const CLASSES = [
        'Automata' => [
            'LanguageSolver', 'Options/SolverOptions', 'Options/MatchMode',
            'Determinization/DeterminizationAlgorithm', 'Minimization/MinimizationAlgorithm',
            'Solver/EquivalenceResult', 'Solver/IntersectionResult', 'Solver/SubsetResult', 'Solver/MatchEquivalenceResult',
            'Model/Dfa', 'Model/DfaState', 'Solver/DfaCacheInterface', 'Solver/InMemoryDfaCache',
            'Exception/ComplexityException',
            'TrivialMatchClassifier', 'TrivialMatch', 'TrivialMatchKind', 'Language',
        ],
        'Cli' => [],
        'Explain' => [
            'TextExplainer', 'HtmlExplainer', 'AsciiTreeRenderer', 'MermaidRenderer', 'RailroadSvgRenderer',
            'Highlighter/ConsoleHighlighter', 'Highlighter/HtmlHighlighter',
        ],
        'Generator' => ['SampleGenerator', 'TestCaseGenerator', 'SampleGenerationException'],
        'LanguageServer' => [],
        'Laravel' => ['PHPRegexServiceProvider', 'Facades/Regex'],
        'Linter' => ['PatternLinter', 'LintSeverity', 'LintException', 'Rule/RuleViolation'],
        'Optimizer' => ['Optimizer', 'OptimizerOptions', 'OptimizationResult', 'Modernizer', 'RedosRepairer', 'RedosRepair'],
        'Parser' => [
            'RegexParser', 'Attribute/RegexPattern', 'ParserOptions', 'PcreTarget', 'PcreFeature', 'ErrorCode', 'DelimitedPattern',
            'TolerantParseResult', 'NodeVisitorInterface', 'AbstractNodeVisitor', 'AbstractTraversingVisitor',
            'NodeWalker', 'NodeFinder', 'TraversalAction',
            'Token/Token', 'Token/TokenStream', 'Token/TokenType',
            'Validation/ValidationResult', 'Validation/ValidationErrorCategory',
            'Printer/PatternPrinter', 'Printer/NodeDumper',
            'Analysis/ComplexityScorer', 'Analysis/GroupNumbering', 'Analysis/GroupNumberingCollector',
            'Analysis/LengthRangeCalculator', 'Analysis/LiteralExtractor', 'Analysis/LiteralExtractionResult',
            'Analysis/LiteralSet', 'Analysis/MetricsCollector',
            'Analysis/CaptureShapeAnalyzer', 'Analysis/CaptureShape', 'Analysis/CaptureGroupShape', 'Analysis/Participation',
            'Analysis/RequiredLiteralAnalyzer',
            'Analysis/PatternInfoAnalyzer', 'Analysis/PatternInfo', 'NewlineConvention', 'BsrConvention',
            'Validation/CompatibilityChecker', 'Validation/PatternCompatibility', 'Validation/TargetVerdict',
            'Cache/CacheInterface', 'Cache/RemovableCacheInterface', 'Cache/ArrayCache', 'Cache/NullCache',
            'Cache/FilesystemCache', 'Cache/PsrCacheAdapter', 'Cache/PsrSimpleCacheAdapter',
            'Engine/PcreEngine', 'Engine/PcreError', 'Engine/PcreLimits', 'Engine/PcreMatch',
            'Exception/ExceptionInterface', 'Exception/RegexException', 'Exception/LexerException',
            'Exception/ParserException', 'Exception/SyntaxErrorException', 'Exception/SemanticErrorException',
            'Exception/RecursionLimitException', 'Exception/ResourceLimitException',
            'Exception/InvalidRegexOptionException', 'Exception/CacheException',
            'Node/*',
        ],
        'PHPStan' => ['RegexPatternRule', 'RegexPatternArgumentRule'],
        'Psalm' => ['Plugin'],
        'Rector' => ['PregMatchToStringComparisonRector', 'PregReplaceToStrReplaceRector', 'PregSplitToExplodeRector', 'EscapeLiteralBraceRector', 'Set/RegexSetList'],
        'Redos' => [
            'RedosAnalyzer', 'RedosAnalysis', 'RedosSeverity', 'RedosMode', 'RedosConfidence',
            'Finding', 'Hotspot', 'Heatmap', 'Confirmation', 'ConfirmationSample', 'ConfirmationOptions',
            'ConfirmationRunner', 'ConfirmationRunnerInterface',
            'RedosComplexity', 'RedosProof', 'RedosWitness', 'RedosOptions', 'RedosSearchCost',
        ],
        'Symfony' => ['PHPRegexBundle'],
        'Toolkit' => ['Regex', 'AnalysisReport', 'OutputFormat'],
        'Transpiler' => ['Transpiler', 'TranspileOptions', 'TranspileResult', 'TranspileException'],
    ];

    /**
     * The result objects: final value objects a public method returns and no
     * extension point asks users to build, so their constructors say
     * "@internal". Relative to src/<Package>/, without ".php".
     * docs/reference/backward-compatibility.md lists the same classes.
     */
    public const RESULTS = [
        'Automata' => [
            'Solver/EquivalenceResult', 'Solver/IntersectionResult', 'Solver/SubsetResult', 'Solver/MatchEquivalenceResult',
            'Language', 'TrivialMatch',
        ],
        'Optimizer' => ['OptimizationResult', 'RedosRepair'],
        'Parser' => [
            'TolerantParseResult', 'Validation/ValidationResult', 'Analysis/GroupNumbering',
            'Analysis/LiteralExtractionResult', 'Analysis/CaptureShape', 'Analysis/CaptureGroupShape',
            'Engine/PcreMatch', 'Engine/PcreError',
            'Analysis/PatternInfo', 'Validation/PatternCompatibility', 'Validation/TargetVerdict',
        ],
        'Redos' => ['RedosAnalysis', 'Finding', 'Hotspot', 'RedosWitness', 'RedosSearchCost', 'Confirmation', 'ConfirmationSample'],
        'Linter' => ['Rule/RuleViolation'],
        'Toolkit' => ['AnalysisReport'],
        'Transpiler' => ['TranspileResult'],
    ];
}
