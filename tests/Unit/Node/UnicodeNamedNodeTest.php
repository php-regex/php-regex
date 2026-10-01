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

namespace PhpRegex\Tests\Unit\Node;

use PhpRegex\Parser\Node\CharLiteralNode;
use PhpRegex\Parser\Node\CharLiteralType;
use PhpRegex\Parser\NodeVisitorInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UnicodeNamedNodeTest extends TestCase
{
    /**
     * @return \Iterator<string, array{string, int, int, int}>
     */
    public static function data_provider_unicode_named(): \Iterator
    {
        yield 'latin_capital_a' => ['\\N{LATIN CAPITAL LETTER A}', 65, 0, 25];
        yield 'snowman' => ['\\N{SNOWMAN}', 9731, 0, 12];
    }

    #[DataProvider('data_provider_unicode_named')]
    public function test_constructor_and_getters(string $original, int $codePoint, int $start, int $end): void
    {
        $node = new CharLiteralNode($original, $codePoint, CharLiteralType::UNICODE_NAMED, $start, $end);

        $this->assertSame($original, $node->originalRepresentation);
        $this->assertSame($codePoint, $node->codePoint);
        $this->assertSame(CharLiteralType::UNICODE_NAMED, $node->type);
        $this->assertSame($start, $node->getStartPosition());
        $this->assertSame($end, $node->getEndPosition());
    }

    public function test_accept_visitor_calls_visit_char_literal(): void
    {
        $node = new CharLiteralNode('\\N{LATIN CAPITAL LETTER A}', 65, CharLiteralType::UNICODE_NAMED, 0, 25);
        $visitor = $this->createMock(NodeVisitorInterface::class);

        $visitor->expects($this->once())
            ->method('visitCharLiteral')
            ->with($this->identicalTo($node))
            ->willReturn('visited');

        $this->assertSame('visited', $node->accept($visitor));
    }
}
