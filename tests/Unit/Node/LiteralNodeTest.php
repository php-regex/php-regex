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

use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\NodeVisitorInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LiteralNodeTest extends TestCase
{
    public static function data_provider_literals(): \Iterator
    {
        // Simple literals
        yield 'simple_char' => ['a', 0, 1];
        yield 'number' => ['1', 5, 6];
        // Escaped metacharacters (the stored value is the character itself)
        yield 'escaped_star' => ['*', 2, 4];
        // stored as '*', the position covers '\*'
        yield 'escaped_backslash' => ['\\', 10, 12];
        // stored as '\', the position covers '\\\\' in the regex source
        yield 'long_string' => ['http', 0, 4];
    }

    #[DataProvider('data_provider_literals')]
    public function test_constructor_and_getters(string $value, int $start, int $end): void
    {
        $node = new LiteralNode($value, $start, $end);

        $this->assertSame($value, $node->value);
        $this->assertSame($start, $node->getStartPosition());
        $this->assertSame($end, $node->getEndPosition());
    }

    public function test_accept_visitor_calls_visit_literal(): void
    {
        $node = new LiteralNode('x', 0, 1);
        $visitor = $this->createMock(NodeVisitorInterface::class);

        $visitor->expects($this->once())
            ->method('visitLiteral')
            ->with($this->identicalTo($node))
            ->willReturn('visited');

        $this->assertSame('visited', $node->accept($visitor));
    }
}
