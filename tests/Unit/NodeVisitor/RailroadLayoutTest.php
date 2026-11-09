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

namespace PHPRegex\Tests\Unit\NodeVisitor;

use PHPRegex\Explain\RailroadLayoutVisitor;
use PHPRegex\Explain\RailroadSvgRenderer;
use PHPRegex\Parser\Node\AlternationNode;
use PHPRegex\Parser\Node\DotNode;
use PHPRegex\Parser\Node\SequenceNode;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Tests\Support\LinearTimeAssertions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The railroad diagram is laid out, then written: the layout visitor gives
 * the boxes, paths and texts of the whole pattern, terminals included, and
 * the renderer writes them as SVG on a padded canvas, one element each.
 */
final class RailroadLayoutTest extends TestCase
{
    use LinearTimeAssertions;

    /**
     * The padding the renderer puts around the layout, on each side.
     */
    private const CANVAS_PADDING = 18;

    #[Test]
    #[DataProvider('providePatterns')]
    public function test_the_renderer_writes_the_layout_of_the_whole_pattern(string $pattern): void
    {
        $tree = RegexParser::create(['cache' => null])->parse($pattern);
        $layout = (new RailroadLayoutVisitor())->visitRegex($tree);
        $svg = (string) $tree->accept(new RailroadSvgRenderer());
        $this->assertNotSame('', $svg);

        $document = new \DOMDocument();
        $this->assertTrue($document->loadXML($svg));
        $root = $document->documentElement;
        $this->assertInstanceOf(\DOMElement::class, $root);
        $this->assertSame((string) ($layout['width'] + 2 * self::CANVAS_PADDING), $root->getAttribute('width'));
        $this->assertSame((string) ($layout['height'] + 2 * self::CANVAS_PADDING), $root->getAttribute('height'));

        // The canvas, then each box and each node, as a rectangle.
        $this->assertCount(1 + \count($layout['boxes']) + \count($layout['nodes']), $document->getElementsByTagName('rect'));
        $this->assertCount(\count($layout['paths']), $document->getElementsByTagName('path'));
        $this->assertCount(\count($layout['markers']), $document->getElementsByTagName('circle'));

        $lines = 0;
        foreach ($layout['nodes'] as $node) {
            $lines += \count($node['labelLines'] ?? [$node['label']]);
        }
        $this->assertCount($lines + \count($layout['texts']), $document->getElementsByTagName('text'));

        // The start terminal sits on the left edge of the layout, moved by the padding.
        $start = $document->getElementsByTagName('circle')->item(\count($layout['markers']) - 2);
        $this->assertInstanceOf(\DOMElement::class, $start);
        $this->assertSame('terminal start', $start->getAttribute('class'));
        $this->assertSame((string) (7 + self::CANVAS_PADDING), $start->getAttribute('cx'));
    }

    /**
     * Each child is laid out once: merging the whole layout so far for each
     * child made four times the children some sixteen times the work.
     */
    #[Test]
    #[DataProvider('provideWideLayouts')]
    public function test_wide_layout_composition_scales_with_its_geometry(bool $alternation): void
    {
        $this->assertLinearTime(static function (int $count) use ($alternation): void {
            $children = array_fill(0, $count, new DotNode(0, 1));
            $tree = $alternation
                ? new AlternationNode($children, 0, $count)
                : new SequenceNode($children, 0, $count);
            $tree->accept(new RailroadLayoutVisitor());
        }, 8000, $alternation ? 'An alternation of dots' : 'A sequence of dots');
    }

    #[Test]
    #[DataProvider('provideWideLayouts')]
    public function test_wide_layout_composition_keeps_every_child(bool $alternation): void
    {
        $count = 4000;
        $children = array_fill(0, $count, new DotNode(0, 1));
        $tree = $alternation
            ? new AlternationNode($children, 0, $count)
            : new SequenceNode($children, 0, $count);
        $layout = $tree->accept(new RailroadLayoutVisitor());

        $this->assertCount($count, $layout['nodes']);
        $this->assertCount($alternation ? 2 * $count + 2 : $count - 1, $layout['paths']);
    }

    /**
     * @return iterable<string, array{alternation: bool}>
     */
    public static function provideWideLayouts(): iterable
    {
        yield 'sequence' => ['alternation' => false];
        yield 'alternation' => ['alternation' => true];
    }

    /**
     * @param list<string> $nodeLabels
     * @param list<string> $texts
     * @param list<string> $boxes
     */
    #[Test]
    #[DataProvider('provideComposedLayouts')]
    public function test_composition_keeps_the_geometry_of_nested_children(string $pattern, array $nodeLabels, array $texts, array $boxes, int $paths): void
    {
        $layout = RegexParser::create(['cache' => null])->parse($pattern)->accept(new RailroadLayoutVisitor());

        $this->assertSame($nodeLabels, array_column($layout['nodes'], 'label'));
        $this->assertSame($texts, array_map(static fn (array $text): string => $text['class'].': '.$text['text'], $layout['texts']));
        $this->assertSame($boxes, array_column($layout['boxes'], 'class'));
        $this->assertCount($paths, $layout['paths']);
    }

    /**
     * A composed layout keeps the markers of its children, moved with them:
     * a whole diagram, its two terminals included, laid out twice in a row
     * or one above the other, keeps all four.
     */
    #[Test]
    #[DataProvider('provideCompositions')]
    public function test_composition_keeps_the_markers_of_its_children(string $method): void
    {
        $visitor = new RailroadLayoutVisitor();
        $diagram = RegexParser::create(['cache' => null])->parse('/a/')->accept($visitor);
        $this->assertCount(2, $diagram['markers']);

        $layout = (new \ReflectionMethod(RailroadLayoutVisitor::class, $method))->invoke($visitor, [$diagram, $diagram]);

        $this->assertSame(['terminal start', 'terminal end', 'terminal start', 'terminal end'], array_column($layout['markers'], 'class'));
    }

    /**
     * @return iterable<string, array{method: string}>
     */
    public static function provideCompositions(): iterable
    {
        yield 'a sequence' => ['method' => 'layoutSequence'];
        yield 'an alternation' => ['method' => 'layoutAlternation'];
    }

    /**
     * @return iterable<string, array{pattern: string, nodeLabels: list<string>, texts: list<string>, boxes: list<string>, paths: int}>
     */
    public static function provideComposedLayouts(): iterable
    {
        yield 'groups and a quantifier in a sequence' => [
            'pattern' => '/x(a(b)+)c/',
            'nodeLabels' => ['x', 'a', 'b', 'c'],
            'texts' => ['group-label: Group #2', 'quantifier-label: 1 or more times', 'group-label: Group #1'],
            'boxes' => ['group-box', 'group-box'],
            'paths' => 10,
        ];
        yield 'groups and quantifiers in an alternation' => [
            'pattern' => '/(a(b)+)|(?:d)?e/',
            'nodeLabels' => ['a', 'b', 'd', 'e'],
            'texts' => ['group-label: Group #2', 'quantifier-label: 1 or more times', 'group-label: Group #1', 'group-label: Group (non-capturing)', 'quantifier-label: 0 or 1 time'],
            'boxes' => ['group-box', 'group-box', 'group-box'],
            'paths' => 18,
        ];
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function providePatterns(): iterable
    {
        yield 'a literal' => ['pattern' => '/a/'];
        yield 'an alternation in a repeated group' => ['pattern' => '/(a|bc)+d/'];
        yield 'flags and a class' => ['pattern' => '/^[a-z]{2,5}(?:foo|bar)?$/im'];
        yield 'a conditional' => ['pattern' => '/(a)?(?(1)b|c)/'];
        yield 'a long run wrapped' => ['pattern' => '/abcdefghijklmnopqrstuvwxyz0123456789/'];
    }
}
