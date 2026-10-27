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

use PHPRegex\Explain\RailroadSvgRenderer;
use PHPRegex\Parser\RegexParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A label longer than a node holds is wrapped onto lines, and a node is as
 * wide as its longest line: both count the columns a monospace font gives
 * the characters of a UTF-8 label, two for a full-width one, never its
 * bytes, so no line ends inside a character.
 */
final class RailroadLabelWrapTest extends TestCase
{
    /**
     * The width of a column of the label font, and the padding of a node
     * on each side of its label.
     */
    private const COLUMN = 7;

    private const PADDING = 12;

    #[Test]
    #[DataProvider('provideLabels')]
    public function test_a_long_label_is_wrapped_between_characters(string $pattern, string $text, int $lineCount, int $columns): void
    {
        [$document, $lines] = $this->render($pattern);

        $this->assertStringNotContainsString("\u{FFFD}", implode('', $lines));
        $this->assertSame($text, implode('', $lines));
        $this->assertCount($lineCount, $lines);
        $widest = 0;
        foreach ($lines as $line) {
            $this->assertTrue(mb_check_encoding($line, 'UTF-8'));
            $this->assertLessThanOrEqual(28, mb_strwidth($line, 'UTF-8'), $line);
            $widest = max($widest, mb_strwidth($line, 'UTF-8'));
        }
        $this->assertSame($columns, $widest);

        $node = $document->getElementsByTagName('rect')->item(1);
        $this->assertInstanceOf(\DOMElement::class, $node);
        // The run's node, or the callout's.
        $this->assertContains($node->getAttribute('class'), ['node literal', 'node']);
        $this->assertSame((string) ($widest * self::COLUMN + 2 * self::PADDING), $node->getAttribute('width'));
    }

    /**
     * @return iterable<string, array{pattern: string, text: string, lineCount: int, columns: int}>
     */
    public static function provideLabels(): iterable
    {
        // 21 characters fit on one line, where their 41 bytes took two.
        yield 'letters of two bytes after one of one' => ['pattern' => '/a'.str_repeat('é', 20).'/u', 'text' => 'a'.str_repeat('é', 20), 'lineCount' => 1, 'columns' => 21];
        yield 'full-width characters of three bytes' => ['pattern' => '/'.str_repeat('日', 15).'/u', 'text' => str_repeat('日', 15), 'lineCount' => 2, 'columns' => 28];
        yield 'letters past the line in a run' => ['pattern' => '/'.str_repeat('é', 30).'x/u', 'text' => str_repeat('é', 30).'x', 'lineCount' => 2, 'columns' => 28];
        yield 'letters past the line in a word' => ['pattern' => '/(?C"'.str_repeat('é', 30).'")/', 'text' => 'Callout(?C="'.str_repeat('é', 30).'")', 'lineCount' => 3, 'columns' => 28];
    }

    /**
     * A label that is no UTF-8, the bytes of a callout without u, is wrapped
     * by bytes, each one column wide.
     */
    #[Test]
    public function test_a_label_of_bytes_is_wrapped_by_bytes(): void
    {
        [$document, $lines] = $this->render('/(?C"'.str_repeat("\xE9", 30).'")/');

        $this->assertCount(3, $lines);
        $node = $document->getElementsByTagName('rect')->item(1);
        $this->assertInstanceOf(\DOMElement::class, $node);
        $this->assertSame((string) (28 * self::COLUMN + 2 * self::PADDING), $node->getAttribute('width'));
    }

    /**
     * @return array{0: \DOMDocument, 1: list<string>}
     */
    private function render(string $pattern): array
    {
        $svg = (string) RegexParser::create(['cache' => null])->parse($pattern)->accept(new RailroadSvgRenderer());
        $this->assertNotSame('', $svg);
        $document = new \DOMDocument();
        $this->assertTrue($document->loadXML($svg));

        $lines = [];
        foreach ($document->getElementsByTagName('text') as $element) {
            if ('label' === $element->getAttribute('class')) {
                $lines[] = $element->textContent;
            }
        }

        return [$document, $lines];
    }
}
