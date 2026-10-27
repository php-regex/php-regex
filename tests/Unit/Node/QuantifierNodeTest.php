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
use PHPRegex\Parser\Node\QuantifierBounds;
use PHPRegex\Parser\Node\QuantifierNode;
use PHPRegex\Parser\Node\QuantifierType;
use PHPRegex\Parser\NodeVisitorInterface;
use PHPRegex\Parser\RegexParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class QuantifierNodeTest extends TestCase
{
    /**
     * @return \Iterator<string, array{LiteralNode, string, QuantifierType}>
     */
    public static function data_provider_quantifiers(): \Iterator
    {
        $node = new LiteralNode('a', 0, 1);

        yield 'greedy_star' => [$node, '*', QuantifierType::Greedy];
        yield 'lazy_plus' => [$node, '+', QuantifierType::Lazy];
        yield 'possessive_optional' => [$node, '?', QuantifierType::Possessive];
        yield 'greedy_fixed' => [$node, '{5}', QuantifierType::Greedy];
        yield 'lazy_range' => [$node, '{1,3}', QuantifierType::Lazy];
        yield 'possessive_unbounded' => [$node, '{2,}', QuantifierType::Possessive];
        yield 'missing_min_php84' => [$node, '{,3}', QuantifierType::Greedy];
    }

    #[Test]
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

    #[Test]
    public function test_accept_visitor_calls_visit_quantifier(): void
    {
        $quantifiedNode = new LiteralNode('a', 0, 1);
        $node = new QuantifierNode($quantifiedNode, '*', QuantifierType::Greedy, 0, 2);
        $visitor = $this->createMock(NodeVisitorInterface::class);

        $visitor->expects($this->once())
            ->method('visitQuantifier')
            ->with($this->identicalTo($node))
            ->willReturn('visited');

        $this->assertSame('visited', $node->accept($visitor));
    }

    /**
     * @param array{int, ?int}|null $expected
     */
    #[Test]
    #[DataProvider('provideBounds')]
    public function test_the_bounds_are_read_from_the_quantifier(string $quantifier, ?array $expected): void
    {
        $node = new QuantifierNode(new LiteralNode('a', 0, 1), $quantifier, QuantifierType::Greedy, 0, 1 + \strlen($quantifier));

        $this->assertEquals(QuantifierBounds::parse($quantifier), $node->bounds);
        $this->assertSame($expected, null === $node->bounds ? null : [$node->bounds->min, $node->bounds->max]);
    }

    /**
     * @return iterable<string, array{quantifier: string, expected: array{int, ?int}|null}>
     */
    public static function provideBounds(): iterable
    {
        yield 'a star' => ['quantifier' => '*', 'expected' => [0, null]];
        yield 'a plus' => ['quantifier' => '+', 'expected' => [1, null]];
        yield 'a question mark' => ['quantifier' => '?', 'expected' => [0, 1]];
        yield 'an exact count' => ['quantifier' => '{5}', 'expected' => [5, 5]];
        yield 'a range' => ['quantifier' => '{1,3}', 'expected' => [1, 3]];
        yield 'an open range' => ['quantifier' => '{2,}', 'expected' => [2, null]];
        yield 'a missing least' => ['quantifier' => '{,3}', 'expected' => [0, 3]];
        yield 'spaces inside the braces' => ['quantifier' => '{ 2 , 4 }', 'expected' => [2, 4]];
        yield 'a lazy suffix kept in the spelling' => ['quantifier' => '{2,4}?', 'expected' => [2, 4]];
        yield 'empty braces' => ['quantifier' => '{}', 'expected' => null];
        yield 'a lone comma' => ['quantifier' => '{,}', 'expected' => null];
        yield 'no quantifier at all' => ['quantifier' => 'x', 'expected' => null];
    }

    #[Test]
    public function test_a_parsed_quantifier_carries_its_bounds(): void
    {
        $node = RegexParser::create(['cache' => null])->parse('/a{2,5}?/')->pattern;

        $this->assertInstanceOf(QuantifierNode::class, $node);
        $this->assertInstanceOf(QuantifierBounds::class, $node->bounds);
        $this->assertSame(2, $node->bounds->min);
        $this->assertSame(5, $node->bounds->max);
        $this->assertSame(QuantifierType::Lazy, $node->type);
    }
}
