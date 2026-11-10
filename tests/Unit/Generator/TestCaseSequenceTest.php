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

namespace PHPRegex\Tests\Unit\Generator;

use PHPRegex\Generator\TestCaseGenerator;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\SequenceNode;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Tests\Support\CpuClock;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A sequence joins the cases of its children in order, the first three
 * that fit: each case is joined once, when the sequence ends, and a child
 * after a long start copies none of it again.
 */
final class TestCaseSequenceTest extends TestCase
{
    #[Test]
    public function test_a_sequence_joins_the_cases_of_its_children_in_order(): void
    {
        $this->assertSame(1, preg_match('/a{1,3}b{1,3}c/', 'abbc'));
        $this->assertSame(0, preg_match('/a{1,3}b{1,3}c/', 'bbbbd'));

        $this->assertSame([
            'matching' => ['abc', 'abbc', 'abbbc'],
            'non_matching' => ['d', 'bbbbd', 'aaaad'],
        ], self::sequence('/a{1,3}b{1,3}c/')->accept(new TestCaseGenerator()));

        foreach (['x00a', 'x000a', 'x0000a'] as $subject) {
            $this->assertSame(1, preg_match('/x\d{2,}[ab]/', $subject), $subject);
        }
        $this->assertSame(0, preg_match('/x\d{2,}[ab]/', 'y0!'));
        $this->assertSame([
            'matching' => ['x00a', 'x000a', 'x0000a'],
            'non_matching' => ['y0!'],
        ], self::sequence('/x\d{2,}[ab]/')->accept(new TestCaseGenerator()));
    }

    #[Test]
    public function test_a_sequence_of_nothing_is_the_empty_case(): void
    {
        $this->assertSame(['matching' => [''], 'non_matching' => ['']], (new SequenceNode([], 0, 0))->accept(new TestCaseGenerator()));
    }

    /**
     * Fifteen runs of 65,535 letters, 983,025 bytes, then 400 repeats of
     * one to three: joined at each child, every one of them copied the
     * start again, nine cases of it, some 3.5 GB in all. Joined once, the
     * 400 children cost what they cost after a start of 45 letters, and the
     * long start what it costs alone: the two together are the measure, as
     * the start alone takes less than the clock can tell.
     */
    #[Test]
    public function test_children_after_a_long_start_do_not_copy_it_again(): void
    {
        $start = str_repeat('a{65535}', 15);
        $alone = self::sequence('/'.$start.'b/');
        $followed = self::sequence('/'.$start.str_repeat('b{1,3}', 400).'/');
        $afterAShortStart = self::sequence('/'.str_repeat('a{3}', 15).str_repeat('b{1,3}', 400).'/');
        $this->assertSame(0, preg_match('/'.$start.str_repeat('b{1,3}', 400).'/', ''));

        $cases = $followed->accept(new TestCaseGenerator());
        $this->assertSame([
            str_repeat('a', 983_025).str_repeat('b', 400),
            str_repeat('a', 983_025).str_repeat('b', 401),
            str_repeat('a', 983_025).str_repeat('b', 402),
        ], $cases['matching']);
        $this->assertCount(3, $cases['non_matching']);

        [$startAlone, $childrenAlone, $withChildren] = [self::bestTime($alone), self::bestTime($afterAShortStart), self::bestTime($followed)];

        $this->assertLessThan(8.0, $withChildren / max($startAlone + $childrenAlone, 1e-3), \sprintf('The start alone: %.4f s; the 400 children after a short start: %.4f s; after the long one: %.4f s.', $startAlone, $childrenAlone, $withChildren));
    }

    private static function sequence(string $pattern): SequenceNode
    {
        $node = RegexParser::create()->parse($pattern)->pattern;
        self::assertInstanceOf(SequenceNode::class, $node);

        return $node;
    }

    /**
     * The least CPU time of three visits.
     */
    private static function bestTime(NodeInterface $node): float
    {
        $best = \INF;
        for ($turn = 0; $turn < 3; $turn++) {
            $start = CpuClock::seconds();
            $node->accept(new TestCaseGenerator());
            $best = min($best, CpuClock::seconds() - $start);
        }

        return $best;
    }
}
