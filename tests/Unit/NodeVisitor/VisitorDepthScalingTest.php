<?php

declare(strict_types=1);

/*
 * This file is part of the RegexParser package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace RegexParser\Tests\Unit\NodeVisitor;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RegexParser\NodeVisitor\AsciiTreeVisitor;
use RegexParser\NodeVisitor\CompilerNodeVisitor;
use RegexParser\NodeVisitor\ComplexityScoreNodeVisitor;
use RegexParser\NodeVisitor\ConsoleHighlighterVisitor;
use RegexParser\NodeVisitor\DumperNodeVisitor;
use RegexParser\NodeVisitor\ExplainNodeVisitor;
use RegexParser\NodeVisitor\HtmlExplainNodeVisitor;
use RegexParser\NodeVisitor\HtmlHighlighterVisitor;
use RegexParser\NodeVisitor\LengthRangeNodeVisitor;
use RegexParser\NodeVisitor\LinterNodeVisitor;
use RegexParser\NodeVisitor\LiteralExtractorNodeVisitor;
use RegexParser\NodeVisitor\MermaidNodeVisitor;
use RegexParser\NodeVisitor\MetricsNodeVisitor;
use RegexParser\NodeVisitor\ModernizerNodeVisitor;
use RegexParser\NodeVisitor\NodeVisitorInterface;
use RegexParser\NodeVisitor\OptimizerNodeVisitor;
use RegexParser\NodeVisitor\RailroadSvgVisitor;
use RegexParser\NodeVisitor\ReDoSProfileNodeVisitor;
use RegexParser\NodeVisitor\TestCaseGeneratorNodeVisitor;
use RegexParser\Regex;

/**
 * A visitor takes time in proportion to the tree: one that visits a child
 * twice doubles its work at every level, and a pattern of a hundred bytes
 * nested forty deep never finishes. The sample generator is left out: its
 * sample itself grows with the depth, up to the length it refuses to pass.
 */
final class VisitorDepthScalingTest extends TestCase
{
    /**
     * @param NodeVisitorInterface<mixed> $visitor
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
     * @return iterable<string, array{NodeVisitorInterface<mixed>}>
     */
    public static function provideVisitors(): iterable
    {
        yield 'AsciiTreeVisitor' => [new AsciiTreeVisitor()];
        yield 'CompilerNodeVisitor' => [new CompilerNodeVisitor()];
        yield 'ComplexityScoreNodeVisitor' => [new ComplexityScoreNodeVisitor()];
        yield 'ConsoleHighlighterVisitor' => [new ConsoleHighlighterVisitor()];
        yield 'DumperNodeVisitor' => [new DumperNodeVisitor()];
        yield 'ExplainNodeVisitor' => [new ExplainNodeVisitor()];
        yield 'HtmlExplainNodeVisitor' => [new HtmlExplainNodeVisitor()];
        yield 'HtmlHighlighterVisitor' => [new HtmlHighlighterVisitor()];
        yield 'LengthRangeNodeVisitor' => [new LengthRangeNodeVisitor()];
        yield 'LinterNodeVisitor' => [new LinterNodeVisitor()];
        yield 'LiteralExtractorNodeVisitor' => [new LiteralExtractorNodeVisitor()];
        yield 'MermaidNodeVisitor' => [new MermaidNodeVisitor()];
        yield 'MetricsNodeVisitor' => [new MetricsNodeVisitor()];
        yield 'ModernizerNodeVisitor' => [new ModernizerNodeVisitor()];
        yield 'OptimizerNodeVisitor' => [new OptimizerNodeVisitor()];
        yield 'RailroadSvgVisitor' => [new RailroadSvgVisitor()];
        yield 'ReDoSProfileNodeVisitor' => [new ReDoSProfileNodeVisitor()];
        yield 'TestCaseGeneratorNodeVisitor' => [new TestCaseGeneratorNodeVisitor()];
    }

    /**
     * Visiting a quantified child once gives the explanation it gave when
     * the child was visited again to indent it.
     */
    #[Test]
    #[DataProvider('provideNestedExplanations')]
    public function test_a_nested_explanation_is_unchanged(string $pattern, string $explanation): void
    {
        $this->assertSame($explanation, Regex::create(['cache' => null])->parse($pattern)->accept(new ExplainNodeVisitor()));
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
