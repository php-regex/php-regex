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
use PHPRegex\Parser\RegexParser;
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
