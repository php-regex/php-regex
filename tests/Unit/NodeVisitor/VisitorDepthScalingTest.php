<?php

declare(strict_types=1);

/*
 * This file is part of the PhpRegex package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpRegex\Tests\Unit\NodeVisitor;

use PhpRegex\Explain\AsciiTreeRenderer;
use PhpRegex\Explain\Highlighter\ConsoleHighlighter;
use PhpRegex\Explain\Highlighter\HtmlHighlighter;
use PhpRegex\Explain\HtmlExplainer;
use PhpRegex\Explain\MermaidRenderer;
use PhpRegex\Explain\RailroadSvgRenderer;
use PhpRegex\Explain\TextExplainer;
use PhpRegex\Generator\TestCaseGenerator;
use PhpRegex\Linter\PatternLinter;
use PhpRegex\Optimizer\Modernizer;
use PhpRegex\Optimizer\Rewriter;
use PhpRegex\Parser\Analysis\ComplexityScorer;
use PhpRegex\Parser\Analysis\LengthRangeCalculator;
use PhpRegex\Parser\Analysis\LiteralExtractor;
use PhpRegex\Parser\Analysis\MetricsCollector;
use PhpRegex\Parser\NodeVisitorInterface;
use PhpRegex\Parser\Printer\NodeDumper;
use PhpRegex\Parser\Printer\PatternPrinter;
use PhpRegex\Redos\RedosProfiler;
use PhpRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A visitor takes time in proportion to the tree: one that visits a child
 * twice doubles its work at every level, and a pattern of a hundred bytes
 * nested forty deep never finishes. The sample generator is left out: its
 * sample itself grows with the depth, up to the length it refuses to pass.
 */
final class VisitorDepthScalingTest extends TestCase
{
    /**
     * @param \PhpRegex\Parser\NodeVisitorInterface<mixed> $visitor
     */
    #[Test]
    #[DataProvider('provideVisitors')]
    public function test_a_deeply_nested_pattern_is_visited_in_linear_time(NodeVisitorInterface $visitor): void
    {
        $depth = 40;
        $pattern = '/'.str_repeat('(?:', $depth).'a'.str_repeat(')*', $depth).'/';
        $tree = Regex::create(['cache' => null])->parse($pattern);

        $start = hrtime(true);
        $tree->accept($visitor);

        $this->assertLessThan(1.0, (hrtime(true) - $start) / 1e9);
    }

    /**
     * @return iterable<string, array{\PhpRegex\Parser\NodeVisitorInterface<mixed>}>
     */
    public static function provideVisitors(): iterable
    {
        yield 'AsciiTreeRenderer' => [new AsciiTreeRenderer()];
        yield 'PatternPrinter' => [new PatternPrinter()];
        yield 'ComplexityScorer' => [new ComplexityScorer()];
        yield 'ConsoleHighlighter' => [new ConsoleHighlighter()];
        yield 'NodeDumper' => [new NodeDumper()];
        yield 'TextExplainer' => [new TextExplainer()];
        yield 'HtmlExplainer' => [new HtmlExplainer()];
        yield 'HtmlHighlighter' => [new HtmlHighlighter()];
        yield 'LengthRangeCalculator' => [new LengthRangeCalculator()];
        yield 'PatternLinter' => [new PatternLinter()];
        yield 'LiteralExtractor' => [new LiteralExtractor()];
        yield 'MermaidRenderer' => [new MermaidRenderer()];
        yield 'MetricsCollector' => [new MetricsCollector()];
        yield 'Modernizer' => [new Modernizer()];
        yield 'Rewriter' => [new Rewriter()];
        yield 'RailroadSvgRenderer' => [new RailroadSvgRenderer()];
        yield 'RedosProfiler' => [new RedosProfiler()];
        yield 'TestCaseGenerator' => [new TestCaseGenerator()];
    }

    /**
     * Visiting a quantified child once gives the explanation it gave when
     * the child was visited again to indent it.
     */
    #[Test]
    #[DataProvider('provideNestedExplanations')]
    public function test_a_nested_explanation_is_unchanged(string $pattern, string $explanation): void
    {
        $this->assertSame($explanation, Regex::create(['cache' => null])->parse($pattern)->accept(new TextExplainer()));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideNestedExplanations(): iterable
    {
        yield '/(?:a|bc)*/' => ['/(?:a|bc)*/', implode("\n", [
            'Regex matches',
            '  Start Quantified Group (zero or more times)',
            '    Non-capturing group',
            '        EITHER',
            '          \'a\'',
            '        OR',
            '          \'b\'',
            '          \'c\'',
            '    End group',
            '  End Quantified Group',
        ])];
        yield '/(?:(?:ab)+c)*/' => ['/(?:(?:ab)+c)*/', implode("\n", [
            'Regex matches',
            '  Start Quantified Group (zero or more times)',
            '    Non-capturing group',
            '      Start Quantified Group (one or more times)',
            '        Non-capturing group',
            '          \'a\'',
            '          \'b\'',
            '        End group',
            '      End Quantified Group',
            '      \'c\'',
            '    End group',
            '  End Quantified Group',
        ])];
        yield '/(a(?:b|c)+)?d{2,3}/' => ['/(a(?:b|c)+)?d{2,3}/', implode("\n", [
            'Regex matches',
            '  Start Quantified Group (once or not at all)',
            '    Capturing group',
            '      \'a\'',
            '      Start Quantified Group (one or more times)',
            '        Non-capturing group',
            '            EITHER',
            '              \'b\'',
            '            OR',
            '              \'c\'',
            '        End group',
            '      End Quantified Group',
            '    End group',
            '  End Quantified Group',
            '    \'d\' (at least 2 but not more than 3 times)',
        ])];
        yield '/(?:(?:(?:x)*)+)?/' => ['/(?:(?:(?:x)*)+)?/', implode("\n", [
            'Regex matches',
            '  Start Quantified Group (once or not at all)',
            '    Non-capturing group',
            '      Start Quantified Group (one or more times)',
            '        Non-capturing group',
            '          Start Quantified Group (zero or more times)',
            '            Non-capturing group',
            '              \'x\'',
            '            End group',
            '          End Quantified Group',
            '        End group',
            '      End Quantified Group',
            '    End group',
            '  End Quantified Group',
        ])];
    }
}
