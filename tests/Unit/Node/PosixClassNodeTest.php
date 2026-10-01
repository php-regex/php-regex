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

use PHPRegex\Parser\Node\PosixClassNode;
use PHPRegex\Parser\NodeVisitorInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PosixClassNodeTest extends TestCase
{
    /**
     * @return \Iterator<string, array{string, int, int}>
     */
    public static function data_provider_posix_classes(): \Iterator
    {
        yield 'alnum' => ['alnum', 1, 9];
        yield 'digit' => ['digit', 1, 9];
        yield 'upper' => ['upper', 1, 9];
        yield 'negated_alpha' => ['^alpha', 1, 12];
    }

    #[DataProvider('data_provider_posix_classes')]
    public function test_constructor_and_getters(string $class, int $start, int $end): void
    {
        $node = new PosixClassNode($class, $start, $end);

        $this->assertSame($class, $node->class);
        $this->assertSame($start, $node->getStartPosition());
        $this->assertSame($end, $node->getEndPosition());
    }

    public function test_accept_visitor_calls_visit_posix_class(): void
    {
        $node = new PosixClassNode('digit', 0, 10);
        $visitor = $this->createMock(NodeVisitorInterface::class);

        $visitor->expects($this->once())
            ->method('visitPosixClass')
            ->with($this->identicalTo($node))
            ->willReturn('visited');

        $this->assertSame('visited', $node->accept($visitor));
    }
}
