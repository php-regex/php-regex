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

namespace PHPRegex\Tests\Unit\Node;

use PHPRegex\Parser\Node\CharTypeNode;
use PHPRegex\Parser\NodeVisitorInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CharTypeNodeTest extends TestCase
{
    public static function data_provider_char_types(): \Iterator
    {
        yield 'digit' => ['d', 0, 2];
        yield 'not_digit' => ['D', 3, 5];
        yield 'whitespace' => ['s', 6, 8];
        yield 'not_word' => ['W', 9, 11];
        yield 'horizontal_whitespace' => ['h', 12, 14];
        yield 'extended_grapheme_cluster' => ['X', 15, 17];
        yield 'single_byte' => ['C', 18, 20];
    }

    #[DataProvider('data_provider_char_types')]
    public function test_constructor_and_getters(string $value, int $start, int $end): void
    {
        $node = new CharTypeNode($value, $start, $end);

        $this->assertSame($value, $node->value);
        $this->assertSame($start, $node->getStartPosition());
        // L'end position inclut le backslash (\d -> longueur 2)
        $this->assertSame($end, $node->getEndPosition());
    }

    public function test_accept_visitor_calls_visit_char_type(): void
    {
        $node = new CharTypeNode('d', 0, 2);
        $visitor = $this->createMock(NodeVisitorInterface::class);

        $visitor->expects($this->once())
            ->method('visitCharType')
            ->with($this->identicalTo($node))
            ->willReturn('visited');

        $this->assertSame('visited', $node->accept($visitor));
    }
}
