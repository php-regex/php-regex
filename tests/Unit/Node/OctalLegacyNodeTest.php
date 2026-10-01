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

final class OctalLegacyNodeTest extends TestCase
{
    /**
     * @return \Iterator<string, array{string, int, int, int}>
     */
    public static function data_provider_octal_legacy(): \Iterator
    {
        yield 'single_digit' => ['7', 7, 0, 2];
        yield 'two_digits' => ['01', 1, 0, 3];
        yield 'three_digits' => ['777', 0o777, 0, 4];
    }

    #[DataProvider('data_provider_octal_legacy')]
    public function test_constructor_and_getters(string $original, int $codePoint, int $start, int $end): void
    {
        $node = new CharLiteralNode($original, $codePoint, CharLiteralType::OCTAL_LEGACY, $start, $end);

        $this->assertSame($original, $node->originalRepresentation);
        $this->assertSame($codePoint, $node->codePoint);
        $this->assertSame(CharLiteralType::OCTAL_LEGACY, $node->type);
        $this->assertSame($start, $node->getStartPosition());
        $this->assertSame($end, $node->getEndPosition());
    }

    public function test_accept_visitor_calls_visit_char_literal(): void
    {
        $node = new CharLiteralNode('77', 0o77, CharLiteralType::OCTAL_LEGACY, 0, 3);
        $visitor = $this->createMock(NodeVisitorInterface::class);

        $visitor->expects($this->once())
            ->method('visitCharLiteral')
            ->with($this->identicalTo($node))
            ->willReturn('visited');

        $this->assertSame('visited', $node->accept($visitor));
    }
}
