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

namespace PHPRegex\Tests\Unit\Packages;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The backward compatibility promise covers the classes listed here, package
 * by package, and no other: every other class says @internal, so a reader of
 * the code and a static analyser both see where the promise ends.
 */
final class PublicSurfaceTest extends TestCase
{
    /**
     * Relative to src/<Package>/, without ".php".
     */
    private const PUBLIC = [
        'Automata' => [
            'LanguageSolver', 'Options/SolverOptions', 'Options/MatchMode',
            'Determinization/DeterminizationAlgorithm', 'Minimization/MinimizationAlgorithm',
            'Solver/EquivalenceResult', 'Solver/IntersectionResult', 'Solver/SubsetResult',
            'Model/Dfa', 'Model/DfaState', 'Solver/DfaCacheInterface', 'Solver/InMemoryDfaCache',
            'Exception/ComplexityException',
        ],
        'Cli' => [],
        'Explain' => [
            'TextExplainer', 'HtmlExplainer', 'AsciiTreeRenderer', 'MermaidRenderer', 'RailroadSvgRenderer',
            'Highlighter/ConsoleHighlighter', 'Highlighter/HtmlHighlighter',
        ],
        'Generator' => ['SampleGenerator', 'TestCaseGenerator', 'SampleGenerationException'],
        'LanguageServer' => [],
        'Laravel' => ['PHPRegexServiceProvider', 'Facades/Regex'],
        'Linter' => ['PatternLinter', 'Diagnostic', 'DiagnosticType', 'LintSeverity', 'LintException', 'Rule/RuleViolation'],
        'Optimizer' => ['Optimizer', 'OptimizerOptions', 'OptimizationResult', 'Modernizer'],
        'Parser' => [
            'RegexParser', 'ParserOptions', 'PcreTarget', 'PcreFeature', 'ErrorCode', 'DelimitedPattern',
            'TolerantParseResult', 'NodeVisitorInterface', 'AbstractNodeVisitor', 'AbstractTraversingVisitor',
            'NodeWalker', 'NodeFinder', 'TraversalAction',
            'Token/Token', 'Token/TokenStream', 'Token/TokenType',
            'Validation/ValidationResult', 'Validation/ValidationErrorCategory',
            'Printer/PatternPrinter', 'Printer/NodeDumper',
            'Analysis/ComplexityScorer', 'Analysis/GroupNumbering', 'Analysis/GroupNumberingCollector',
            'Analysis/LengthRangeCalculator', 'Analysis/LiteralExtractor', 'Analysis/LiteralExtractionResult',
            'Analysis/LiteralSet', 'Analysis/MetricsCollector',
            'Cache/CacheInterface', 'Cache/RemovableCacheInterface', 'Cache/ArrayCache', 'Cache/NullCache',
            'Cache/FilesystemCache', 'Cache/PsrCacheAdapter', 'Cache/PsrSimpleCacheAdapter',
            'Engine/PcreEngine', 'Engine/PcreError', 'Engine/PcreLimits', 'Engine/PcreMatch',
            'Exception/ExceptionInterface', 'Exception/RegexException', 'Exception/LexerException',
            'Exception/ParserException', 'Exception/SyntaxErrorException', 'Exception/SemanticErrorException',
            'Exception/RecursionLimitException', 'Exception/ResourceLimitException',
            'Exception/InvalidRegexOptionException', 'Exception/CacheException',
            'Node/*',
        ],
        'PHPStan' => ['RegexPatternRule'],
        'Redos' => [
            'RedosAnalyzer', 'RedosAnalysis', 'RedosSeverity', 'RedosMode', 'RedosConfidence',
            'Finding', 'Hotspot', 'Heatmap', 'Confirmation', 'ConfirmationSample', 'ConfirmationOptions',
            'ConfirmationRunner', 'ConfirmationRunnerInterface',
            'RedosComplexity', 'RedosProof', 'RedosWitness', 'RedosOptions',
        ],
        'Symfony' => ['PHPRegexBundle'],
        'Toolkit' => ['Regex', 'AnalysisReport', 'OutputFormat'],
        'Transpiler' => ['Transpiler', 'TranspileOptions', 'TranspileResult', 'TranspileException'],
    ];

    #[Test]
    #[DataProvider('provideClasses')]
    public function test_a_class_is_public_or_says_it_is_internal(string $file, bool $public): void
    {
        $doc = self::classDocBlock((string) file_get_contents($file));
        $internal = 1 === preg_match('~^\s*\*\s*@internal\b~m', $doc);

        if ($public) {
            $this->assertFalse($internal, $file.' is public: it must not say @internal');
        } else {
            $this->assertTrue($internal, $file.' is not public: it must say @internal');
        }
    }

    #[Test]
    public function test_every_listed_class_exists(): void
    {
        $missing = [];
        foreach (self::PUBLIC as $package => $classes) {
            foreach ($classes as $class) {
                if (!str_ends_with($class, '*') && !is_file(self::src().'/'.$package.'/'.$class.'.php')) {
                    $missing[] = $package.'/'.$class;
                }
            }
        }

        $this->assertSame([], $missing);
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function provideClasses(): iterable
    {
        foreach (self::PUBLIC as $package => $public) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::src().'/'.$package, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if (!$file instanceof \SplFileInfo || 'php' !== $file->getExtension()) {
                    continue;
                }
                $code = (string) file_get_contents($file->getPathname());
                if (1 !== preg_match('~^(?:final |abstract |readonly )*(?:class|interface|trait|enum) ~m', $code)) {
                    continue;
                }
                $relative = substr($file->getPathname(), \strlen(self::src().'/'.$package.'/'), -4);
                $isPublic = \in_array($relative, $public, true)
                    || \in_array(\dirname($relative).'/*', $public, true);

                yield $package.'/'.$relative => [$file->getPathname(), $isPublic];
            }
        }
    }

    /**
     * The docblock right above the class declaration, or ''.
     */
    private static function classDocBlock(string $code): string
    {
        if (1 !== preg_match('~(/\*\*(?:(?!\*/).)*\*/)\s*(?:#\[[^\]]*\]\s*)*(?:final |abstract |readonly )*(?:class|interface|trait|enum) ~s', $code, $match)) {
            return '';
        }

        return $match[1];
    }

    private static function src(): string
    {
        return \dirname(__DIR__, 3).'/src';
    }
}
