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

use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Parser\Node\UnicodePropNode;
use PHPRegex\Parser\NodeVisitorInterface;
use PHPRegex\Parser\Printer\PatternPrinter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class UnicodePropNodeTest extends TestCase
{
    /**
     * The canonical constructor: the property text, whether it was written
     * between braces, the positions, and the negated syntax it was written
     * with. The shapes are the ones the parser stores, verified against the
     * engine: \p{^L} keeps its caret inside the braces, \P{L} is the negated
     * syntax over the same property.
     *
     * @return iterable<string, array{prop: string, hasBraces: bool, start: int, end: int, negatedSyntax: bool, printed: string}>
     */
    public static function provideCanonicalShapes(): iterable
    {
        yield 'a bare property, \pL' => ['prop' => 'L', 'hasBraces' => false, 'start' => 1, 'end' => 3, 'negatedSyntax' => false, 'printed' => '/\pL/'];
        yield 'a caret inside the braces, \p{^L}' => ['prop' => '{^L}', 'hasBraces' => true, 'start' => 1, 'end' => 6, 'negatedSyntax' => false, 'printed' => '/\p{^L}/'];
        yield 'the negated syntax, \P{L}' => ['prop' => '{^L}', 'hasBraces' => true, 'start' => 1, 'end' => 5, 'negatedSyntax' => true, 'printed' => '/\P{L}/'];
        yield 'the bare negated syntax, \PL' => ['prop' => '^L', 'hasBraces' => false, 'start' => 1, 'end' => 3, 'negatedSyntax' => true, 'printed' => '/\PL/'];
        yield 'a braced property, \p{Nd}' => ['prop' => '{Nd}', 'hasBraces' => true, 'start' => 1, 'end' => 6, 'negatedSyntax' => false, 'printed' => '/\p{Nd}/'];
    }

    #[Test]
    #[DataProvider('provideCanonicalShapes')]
    public function test_the_canonical_constructor_builds_and_positions_the_node(string $prop, bool $hasBraces, int $start, int $end, bool $negatedSyntax, string $printed): void
    {
        $node = new UnicodePropNode($prop, $hasBraces, $start, $end, $negatedSyntax);

        $this->assertSame($prop, $node->prop);
        $this->assertSame($hasBraces, $node->hasBraces);
        $this->assertSame($negatedSyntax, $node->negatedSyntax);
        $this->assertSame($start, $node->getStartPosition());
        $this->assertSame($end, $node->getEndPosition());
        $this->assertSame($printed, (new PatternPrinter())->visitRegex(new RegexNode($node, '', '/', 0, $end)));
    }

    /**
     * The end position defaults to the start one, as every leaf node's does.
     */
    #[Test]
    public function test_the_end_position_defaults_to_the_start_position(): void
    {
        $node = new UnicodePropNode('L', false, 4);

        $this->assertSame(4, $node->getStartPosition());
        $this->assertSame(4, $node->getEndPosition());
        $this->assertFalse($node->hasBraces);
        $this->assertFalse($node->negatedSyntax);
    }

    /**
     * The 1.x three-argument form is not quietly reshuffled any more: an
     * integer where the boolean belongs is an engine error the moment the
     * object is built, never a node whose hasBraces holds a number.
     */
    #[Test]
    public function test_an_integer_second_argument_is_a_type_error(): void
    {
        $typeError = null;

        try {
            new UnicodePropNode('\p{L}', 12, 15);
        } catch (\TypeError $typeError) {
        }

        $this->assertInstanceOf(\TypeError::class, $typeError, 'The 3-argument int form must raise a TypeError.');
        $this->assertStringContainsString('hasBraces', $typeError->getMessage());
    }

    #[Test]
    public function test_accept_visitor_calls_visit_unicode_prop(): void
    {
        $node = new UnicodePropNode('L', false, 0, 2);
        $visitor = $this->createMock(NodeVisitorInterface::class);

        $visitor->expects($this->once())
            ->method('visitUnicodeProp')
            ->with($this->identicalTo($node))
            ->willReturn('visited');

        $this->assertSame('visited', $node->accept($visitor));
    }
}
