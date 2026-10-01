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

namespace PhpRegex\Tests\Unit\Node;

use PhpRegex\Parser\Node\BackrefNode;
use PhpRegex\Parser\NodeVisitorInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BackrefNodeTest extends TestCase
{
    public static function data_provider_backrefs(): \Iterator
    {
        // Numeric references (format \1, \2...)
        yield 'numeric_ref' => ['1', 5, 7];
        yield 'two_digit_ref' => ['10', 8, 11];
        // Named references (stored raw without \k)
        yield 'named_k_lt_gt_ref' => ['k<name>', 1, 9];
        yield 'named_k_brace_ref' => ['k{name}', 1, 9];
    }

    #[DataProvider('data_provider_backrefs')]
    public function test_constructor_and_getters(string $ref, int $start, int $end): void
    {
        $node = new BackrefNode($ref, $start, $end);

        $this->assertSame($ref, $node->ref);
        $this->assertSame($start, $node->getStartPosition());
        $this->assertSame($end, $node->getEndPosition());
    }

    public function test_accept_visitor_calls_visit_backref(): void
    {
        $node = new BackrefNode('1', 0, 2);
        $visitor = $this->createMock(NodeVisitorInterface::class);

        $visitor->expects($this->once())
            ->method('visitBackref')
            ->with($this->identicalTo($node))
            ->willReturn('visited');

        $this->assertSame('visited', $node->accept($visitor));
    }
}
