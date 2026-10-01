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

use PHPRegex\Parser\Node\SubroutineNode;
use PHPRegex\Parser\NodeVisitorInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SubroutineNodeTest extends TestCase
{
    /**
     * @return \Iterator<string, array{string, string}>
     */
    public static function data_provider_subroutines(): \Iterator
    {
        yield 'recursive_full' => ['R', ''];
        yield 'recursive_entire' => ['0', ''];
        yield 'numeric_absolute' => ['1', ''];
        yield 'numeric_relative' => ['-1', ''];
        yield 'named_ampersand' => ['name', '&'];
        yield 'named_pythonesque' => ['name', 'P>'];
        yield 'named_g_ref' => ['name', 'g'];
    }

    #[DataProvider('data_provider_subroutines')]
    public function test_constructor_and_getters(string $ref, string $syntax): void
    {
        $node = new SubroutineNode($ref, $syntax, 0, 5);

        $this->assertSame($ref, $node->reference);
        $this->assertSame($syntax, $node->syntax);
        $this->assertSame(0, $node->getStartPosition());
        $this->assertSame(5, $node->getEndPosition());
    }

    public function test_accept_visitor_calls_visit_subroutine(): void
    {
        $node = new SubroutineNode('R', '', 0, 3);
        $visitor = $this->createMock(NodeVisitorInterface::class);

        $visitor->expects($this->once())
            ->method('visitSubroutine')
            ->with($this->identicalTo($node))
            ->willReturn('visited');

        $this->assertSame('visited', $node->accept($visitor));
    }
}
