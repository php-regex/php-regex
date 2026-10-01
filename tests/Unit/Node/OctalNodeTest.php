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

final class OctalNodeTest extends TestCase
{
    /**
     * @return \Iterator<string, array{string, int, int, int}>
     */
    public static function data_provider_octal(): \Iterator
    {
        yield 'single_digit' => ['\\o{7}', 7, 0, 5];
        yield 'three_digits' => ['\\o{777}', 0o777, 0, 7];
        yield 'zero' => ['\\o{0}', 0, 0, 5];
    }

    #[DataProvider('data_provider_octal')]
    public function test_constructor_and_getters(string $original, int $codePoint, int $start, int $end): void
    {
        $node = new CharLiteralNode($original, $codePoint, CharLiteralType::Octal, $start, $end);

        $this->assertSame($original, $node->originalRepresentation);
        $this->assertSame($codePoint, $node->codePoint);
        $this->assertSame(CharLiteralType::Octal, $node->type);
        $this->assertSame($start, $node->getStartPosition());
        $this->assertSame($end, $node->getEndPosition());
    }

    public function test_accept_visitor_calls_visit_char_literal(): void
    {
        $node = new CharLiteralNode('\\o{77}', 0o77, CharLiteralType::Octal, 0, 6);
        $visitor = $this->createMock(NodeVisitorInterface::class);

        $visitor->expects($this->once())
            ->method('visitCharLiteral')
            ->with($this->identicalTo($node))
            ->willReturn('visited');

        $this->assertSame('visited', $node->accept($visitor));
    }
}
