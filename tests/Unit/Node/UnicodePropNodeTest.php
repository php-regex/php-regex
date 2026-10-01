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

use PHPRegex\Parser\Node\UnicodePropNode;
use PHPRegex\Parser\NodeVisitorInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UnicodePropNodeTest extends TestCase
{
    /**
     * @return \Iterator<string, array{string, int, int}>
     */
    public static function data_provider_unicode_props(): \Iterator
    {
        yield 'simple_prop' => ['L', 0, 2];
        yield 'bracketed_prop' => ['Nd', 0, 6];
        yield 'negated_prop' => ['^L', 0, 3];
        yield 'bracketed_negated_prop' => ['^Nd', 0, 7];
    }

    #[DataProvider('data_provider_unicode_props')]
    public function test_constructor_and_getters(string $prop, int $start, int $end): void
    {
        $node = new UnicodePropNode($prop, $start, $end);

        $this->assertSame($prop, $node->prop);
        $this->assertSame($start, $node->getStartPosition());
        $this->assertSame($end, $node->getEndPosition());
    }

    public function test_accept_visitor_calls_visit_unicode_prop(): void
    {
        $node = new UnicodePropNode('L', 0, 2);
        $visitor = $this->createMock(NodeVisitorInterface::class);

        $visitor->expects($this->once())
            ->method('visitUnicodeProp')
            ->with($this->identicalTo($node))
            ->willReturn('visited');

        $this->assertSame('visited', $node->accept($visitor));
    }
}
