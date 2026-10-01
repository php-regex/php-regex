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
use PhpRegex\Parser\Node\NodeInterface;
use PhpRegex\Parser\Node\RegexNode;
use PhpRegex\Parser\NodeVisitorInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RegexNodeTest extends TestCase
{
    /**
     * @return \Iterator<string, array{string, string}>
     */
    public static function data_provider_regex_properties(): \Iterator
    {
        yield 'slash_no_flags' => ['/', ''];
        yield 'hash_with_flags' => ['#', 'im'];
        yield 'brace_delimiter' => ['{', 'u'];
        yield 'paren_delimiter' => ['(', 's'];
        yield 'angled_delimiter' => ['<', 'x'];
        yield 'tilde_delimiter' => ['~', 'A'];
    }

    #[DataProvider('data_provider_regex_properties')]
    public function test_constructor_and_getters(string $delimiter, string $flags): void
    {
        $pattern = new LiteralNode('a', 1, 2);
        $start = 0;
        $end = 3;

        $node = new RegexNode($pattern, $flags, $delimiter, $start, $end);

        $this->assertSame($pattern, $node->pattern);
        $this->assertSame($flags, $node->flags);
        $this->assertSame($delimiter, $node->delimiter);
        $this->assertSame($start, $node->getStartPosition());
        $this->assertSame($end, $node->getEndPosition());
    }

    public function test_accept_visitor_calls_visit_regex(): void
    {
        $pattern = $this->createStub(NodeInterface::class);
        $node = new RegexNode($pattern, 'i', '/', 0, 5);
        $visitor = $this->createMock(NodeVisitorInterface::class);

        $visitor->expects($this->once())
            ->method('visitRegex')
            ->with($this->identicalTo($node))
            ->willReturn('visited');

        $this->assertSame('visited', $node->accept($visitor));
    }
}
