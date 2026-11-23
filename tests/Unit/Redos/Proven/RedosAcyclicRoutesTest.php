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

namespace PHPRegex\Tests\Unit\Redos\Proven;

use PHPRegex\Redos\RedosAnalyzer;
use PHPRegex\Redos\RedosComplexity;
use PHPRegex\Redos\RedosProof;
use PHPRegex\Tests\Support\PcreAttemptSteps;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A group that may read nothing in two ways, "(?:b{0}|)", gives the
 * attempt two routes to what follows it, and k of them in a row 2^k. An
 * acyclic stretch read in more ways than the margin is out of the model,
 * whatever the loop around it: every route counts, not only the two the
 * model lists for one pair of states. After "^…a+", thirteen such groups
 * before the dot make one attempt on one "a" and a newline try 2^21 ways,
 * 2 097 153 steps in the interpreter, past PHP's backtrack limit, where the
 * stretch was counted as read in two ways.
 */
final class RedosAcyclicRoutesTest extends TestCase
{
    #[Test]
    #[DataProvider('provideEmptyRoutesAroundALoop')]
    public function test_every_empty_route_counts_in_an_acyclic_stretch(int $before, int $after, bool $proven): void
    {
        $pattern = '/^'.self::emptyGroups($before, 0).'a+'.self::emptyGroups($after, 11).'./';
        $counted = PcreAttemptSteps::pinned('/(*NO_START_OPT)'.substr($pattern, 1));
        $one = PcreAttemptSteps::steps($counted, "a\n");
        $this->assertGreaterThanOrEqual(2 ** max($before, $after), $one, \sprintf('Oracle: %s takes %d steps on one "a" and a newline.', $pattern, $one));
        $short = PcreAttemptSteps::steps($counted, str_repeat('a', 8)."\n");
        $long = PcreAttemptSteps::steps($counted, str_repeat('a', 16)."\n");
        $this->assertLessThan(5 * $short, 2 * $long, \sprintf('Oracle: %s takes %d then %d steps on 8 then 16 "a" and a newline.', $pattern, $short, $long));

        $analysis = (new RedosAnalyzer())->analyze($pattern);
        $expected = $proven ? [RedosProof::Proven, RedosComplexity::Linear] : [RedosProof::Heuristic, RedosComplexity::Unknown];
        $this->assertSame($expected, [$analysis->proof, $analysis->complexity], $pattern.' is "'.$analysis->headline().'".');
    }

    /**
     * @return iterable<string, array{before: int, after: int, proven: bool}>
     */
    public static function provideEmptyRoutesAroundALoop(): iterable
    {
        yield 'three groups on each side' => ['before' => 3, 'after' => 3, 'proven' => true];
        yield 'ten groups after the loop' => ['before' => 0, 'after' => 10, 'proven' => true];
        yield 'eleven groups after the loop' => ['before' => 0, 'after' => 11, 'proven' => false];
        yield 'ten groups before the loop' => ['before' => 10, 'after' => 0, 'proven' => true];
        yield 'eleven groups before the loop' => ['before' => 11, 'after' => 0, 'proven' => false];
        // 2 097 153 steps on one "a" and a newline: PHP gives up.
        yield 'eight groups before and thirteen after' => ['before' => 8, 'after' => 13, 'proven' => false];
        // Each side within the margin, 2^11 together.
        yield 'five groups before and six after' => ['before' => 5, 'after' => 6, 'proven' => false];
        yield 'five groups on each side' => ['before' => 5, 'after' => 5, 'proven' => true];
    }

    /**
     * The routes multiply along the whole attempt: a stretch that ends at a
     * loop or at a lookaround does not start the count again after it. Ten
     * groups on each side of "a+" give one attempt on one "a" and a newline
     * 2^20 ways, past PHP's backtrack limit, though each stretch alone is
     * within the margin; so do ten before a lookahead and ten in its body,
     * which the attempt reads once for each way to the lookahead.
     */
    #[Test]
    #[DataProvider('provideRoutesAcrossAWholeAttempt')]
    public function test_the_routes_multiply_across_loops_and_lookarounds(string $pattern, string $subject): void
    {
        $counted = PcreAttemptSteps::pinned('/(*NO_START_OPT)'.substr($pattern, 1));
        $this->assertFalse(PcreAttemptSteps::searchUnderLimit($counted, $subject, 1_000_000), 'Oracle: '.$pattern.' finishes on '.json_encode($subject).' under PHP\'s default backtrack limit.');

        $analysis = (new RedosAnalyzer())->analyze($pattern);
        $this->assertFalse($analysis->isProvenSafe(), $pattern.' is "'.$analysis->headline().'".');
    }

    /**
     * @return iterable<string, array{pattern: string, subject: string}>
     */
    public static function provideRoutesAcrossAWholeAttempt(): iterable
    {
        $before = self::emptyGroups(10, 0);
        $after = self::emptyGroups(10, 11);

        yield 'ten groups on each side of a loop' => ['pattern' => '/^'.$before.'a+'.$after.'./', 'subject' => "a\n"];
        yield 'of a possessive loop' => ['pattern' => '/^'.$before.'a++'.$after.'./', 'subject' => "a\n"];
        yield 'of a loop of word characters' => ['pattern' => '/^'.$before.'\w+'.$after.'./', 'subject' => "a\n"];
        yield 'of a lazy loop' => ['pattern' => '/^'.$before.'a+?'.$after.'./', 'subject' => "a\n"];
        yield 'around two loops' => ['pattern' => '/^'.$before.'a+'.$after.'b+'.$before.'./', 'subject' => "ab\n"];
        yield 'before a lookahead and in its body' => ['pattern' => '/^'.$before.'(?='.$after.'.)/', 'subject' => "\n"];
    }

    /**
     * Groups that may read nothing in two ways, each written apart so that
     * no two of them are one state of the model.
     */
    private static function emptyGroups(int $count, int $offset): string
    {
        $groups = '';
        for ($group = 0; $group < $count; $group++) {
            $groups .= '(?:'.\chr(98 + ($group + $offset) % 24).'{0}|)';
        }

        return $groups;
    }
}
