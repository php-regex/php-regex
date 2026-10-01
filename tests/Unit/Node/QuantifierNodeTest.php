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

use PhpRegex\Parser\Node\LiteralNode;
use PhpRegex\Parser\Node\QuantifierNode;
use PhpRegex\Parser\Node\QuantifierType;
use PhpRegex\Parser\NodeVisitorInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class QuantifierNodeTest extends TestCase
{
    /**
     * @return \Iterator<string, array{\PhpRegex\Parser\Node\LiteralNode, string, \PhpRegex\Parser\Node\QuantifierType}>
     */
    public static function data_provider_quantifiers(): \Iterator
    {
        $node = new LiteralNode('a', 0, 1);

        yield 'greedy_star' => [$node, '*', QuantifierType::T_GREEDY];
        yield 'lazy_plus' => [$node, '+', QuantifierType::T_LAZY];
        yield 'possessive_optional' => [$node, '?', QuantifierType::T_POSSESSIVE];
        yield 'greedy_fixed' => [$node, '{5}', QuantifierType::T_GREEDY];
        yield 'lazy_range' => [$node, '{1,3}', QuantifierType::T_LAZY];
        yield 'possessive_unbounded' => [$node, '{2,}', QuantifierType::T_POSSESSIVE];
        yield 'missing_min_php84' => [$node, '{,3}', QuantifierType::T_GREEDY];
    }

    #[DataProvider('data_provider_quantifiers')]
    public function test_constructor_and_getters(LiteralNode $quantifiedNode, string $quantifier, QuantifierType $type): void
    {
        // Dummy positions since they depend on the pattern string
        $start = 0;
        $end = 5;

        $node = new QuantifierNode($quantifiedNode, $quantifier, $type, $start, $end);

        $this->assertSame($quantifiedNode, $node->node);
        $this->assertSame($quantifier, $node->quantifier);
        $this->assertSame($type, $node->type);
        $this->assertSame($start, $node->getStartPosition());
        $this->assertSame($end, $node->getEndPosition());
    }

    public function test_accept_visitor_calls_visit_quantifier(): void
    {
        $quantifiedNode = new LiteralNode('a', 0, 1);
        $node = new QuantifierNode($quantifiedNode, '*', QuantifierType::T_GREEDY, 0, 2);
        $visitor = $this->createMock(NodeVisitorInterface::class);

        $visitor->expects($this->once())
            ->method('visitQuantifier')
            ->with($this->identicalTo($node))
            ->willReturn('visited');

        $this->assertSame('visited', $node->accept($visitor));
    }
}
