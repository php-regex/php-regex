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

use PHPRegex\Parser\Node\PcreVerbNode;
use PHPRegex\Parser\NodeVisitorInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PcreVerbNodeTest extends TestCase
{
    /**
     * @return \Iterator<string, array{string, int, int}>
     */
    public static function data_provider_pcre_verbs(): \Iterator
    {
        yield 'fail' => ['FAIL', 0, 8];
        yield 'accept' => ['ACCEPT', 5, 13];
        yield 'mark_with_name' => ['MARK:FOO', 10, 20];
        yield 'commit' => ['COMMIT', 0, 10];
        yield 'define' => ['DEFINE', 0, 10];
        yield 'then' => ['THEN', 0, 8];
        yield 'cr_newline' => ['CR', 0, 7];
        yield 'lf_newline' => ['LF', 0, 7];
        yield 'crlf_newline' => ['CRLF', 0, 9];
        yield 'notempty' => ['NOTEMPTY', 0, 13];
        yield 'notempty_atstart' => ['NOTEMPTY_ATSTART', 0, 19];
    }

    #[DataProvider('data_provider_pcre_verbs')]
    public function test_constructor_and_getters(string $verb, int $start, int $end): void
    {
        $node = new PcreVerbNode($verb, $start, $end);

        $this->assertSame($verb, $node->verb);
        $this->assertSame($start, $node->getStartPosition());
        $this->assertSame($end, $node->getEndPosition());
    }

    public function test_accept_visitor_calls_visit_pcre_verb(): void
    {
        $node = new PcreVerbNode('FAIL', 0, 8);
        $visitor = $this->createMock(NodeVisitorInterface::class);

        $visitor->expects($this->once())
            ->method('visitPcreVerb')
            ->with($this->identicalTo($node))
            ->willReturn('visited');

        $this->assertSame('visited', $node->accept($visitor));
    }
}
