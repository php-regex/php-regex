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
 * stretch was counted as read in two ways. The routes that die at a check
 * count as those that go on, and the ways one input is read multiply
 * across the whole attempt, as the routes do.
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
     * Without an empty route, the stretch still counts the ways it reads one
     * input: eleven copies of "(?:a|a)" written out read eleven "a" in 2^11
     * ways, 2 049 steps on them and a "!" in the interpreter, past the
     * margin, though each state follows the one before by a single route.
     */
    #[Test]
    public function test_copies_written_out_count_the_ways_they_read_one_input(): void
    {
        $pattern = '/^'.str_repeat('(?:a|a)', 11).'b/';
        $counted = PcreAttemptSteps::pinned('/(*NO_START_OPT)'.substr($pattern, 1));
        $steps = PcreAttemptSteps::steps($counted, str_repeat('a', 11).'!');
        $this->assertGreaterThanOrEqual(2 ** 11, $steps, \sprintf('Oracle: %s takes %d steps on eleven "a" and a "!".', $pattern, $steps));

        $analysis = (new RedosAnalyzer())->analyze($pattern);
        $this->assertSame(RedosProof::Heuristic, $analysis->proof, $pattern.' is "'.$analysis->headline().'".');
    }

    /**
     * A route that dies at a check costs the engine as much as one that
     * goes on: "\b" between two word characters leaves nothing to read,
     * yet each of the 1,024 routes ten groups give to it is walked. Ten
     * groups before "x" and ten after it, before "\ba", take one attempt on
     * "xa" 1,048,577 steps, with no loop at all; past a loop, after a loop
     * read in two ways or at its exits, the routes that die multiply the
     * ways into them all the same.
     */
    #[Test]
    #[DataProvider('provideRoutesThatDieAtACheck')]
    public function test_the_routes_that_die_at_a_check_count_along_the_attempt(string $pattern, string $subject): void
    {
        $counted = PcreAttemptSteps::pinned('/(*NO_START_OPT)'.substr($pattern, 1));
        $this->assertFalse(PcreAttemptSteps::searchUnderLimit($counted, $subject, 1_000_000), 'Oracle: '.$pattern.' finishes on '.json_encode($subject).' under PHP\'s default backtrack limit.');

        $analysis = (new RedosAnalyzer())->analyze($pattern);
        $this->assertFalse($analysis->isProvenSafe(), $pattern.' is "'.$analysis->headline().'".');
    }

    /**
     * @return iterable<string, array{pattern: string, subject: string}>
     */
    public static function provideRoutesThatDieAtACheck(): iterable
    {
        $before = self::emptyGroups(10, 0);
        $after = self::emptyGroups(10, 11);

        yield 'on each side of a character' => ['pattern' => '/^'.$before.'x'.$after.'\ba/', 'subject' => 'xa'];
        yield 'fifteen after the character' => ['pattern' => '/^'.$before.'x'.self::emptyGroups(14, 10).'(?:b{0}c{0}|)\ba/', 'subject' => 'xa'];
        yield 'on each side of a loop' => ['pattern' => '/^'.$before.'a+'.$after.'\ba/', 'subject' => 'aa'];
        yield 'at boundaries a route may skip' => ['pattern' => '/^'.$before.'a+'.self::boundaryGroups(10, 11).'\w/', 'subject' => 'a!'];
        // 2,048 routes die at "\b" after each "a": 1,026,550 steps on 500.
        yield 'inside a loop' => ['pattern' => '/^(?:a(?:'.self::emptyGroups(11, 0).'\b)?)+x/', 'subject' => str_repeat('a', 500).'!'];
        yield 'out of a loop read in two ways' => ['pattern' => '/^'.self::emptyGroups(3, 20).'(?:a|a)+(?:'.self::emptyGroups(16, 0).'\bx|.)/', 'subject' => "a\n"];
        yield 'out of a loop read in two ways, six groups before it' => ['pattern' => '/^'.self::emptyGroups(6, 0).'(?:a|a)+(?:'.self::emptyGroups(13, 6).'\bx|.)/', 'subject' => "a\n"];
        yield 'out of a loop read in two ways, after copies written out' => ['pattern' => '/^'.self::twoWays('b', 10, 0).'x+(?:a|a)+(?:'.self::emptyGroups(12, 0).'\bx|.)/', 'subject' => str_repeat('b', 10)."xa\n"];
    }

    /**
     * The ways a stretch reads one input multiply those of the next, as the
     * empty routes do: a loop or a lookaround between them does not start
     * the count again. Ten copies of an "a" written two ways, then "x+",
     * then ten groups before a dot read ten "a", an "x" and a newline in
     * 2^20 ways, each stretch within the margin.
     */
    #[Test]
    #[DataProvider('provideWaysAcrossAWholeAttempt')]
    public function test_the_ways_one_input_is_read_multiply_across_loops_and_lookarounds(string $pattern, string $subject): void
    {
        $counted = PcreAttemptSteps::pinned('/(*NO_START_OPT)'.substr($pattern, 1));
        $this->assertFalse(PcreAttemptSteps::searchUnderLimit($counted, $subject, 1_000_000), 'Oracle: '.$pattern.' finishes on '.json_encode($subject).' under PHP\'s default backtrack limit.');

        $analysis = (new RedosAnalyzer())->analyze($pattern);
        $this->assertFalse($analysis->isProvenSafe(), $pattern.' is "'.$analysis->headline().'".');
    }

    /**
     * @return iterable<string, array{pattern: string, subject: string}>
     */
    public static function provideWaysAcrossAWholeAttempt(): iterable
    {
        $tenA = self::twoWays('a', 10, 0);

        yield 'copies, a loop, then empty groups' => ['pattern' => '/^'.$tenA.'x+'.self::emptyGroups(10, 11).'./', 'subject' => str_repeat('a', 10)."x\n"];
        yield 'five copies around each of three loops' => [
            'pattern' => '/^'.self::twoWays('a', 5, 0).'x+'.self::twoWays('a', 5, 3).'y+'.self::twoWays('a', 5, 6).'z+'.self::twoWays('a', 5, 9).'./',
            'subject' => "aaaaaxaaaaayaaaaazaaaaa\n",
        ];
        yield 'empty groups before a lookahead, copies in its body' => ['pattern' => '/^'.self::emptyGroups(10, 0).'(?='.self::twoWays('a', 10, 3).'\n)/', 'subject' => str_repeat('a', 10).'!'];
        yield 'copies before a lookahead, empty groups in its body' => ['pattern' => '/^'.$tenA.'(?='.self::emptyGroups(10, 11).'.)/', 'subject' => str_repeat('a', 10)."\n"];
        yield 'copies around two loops, then a loop read in two ways' => [
            'pattern' => '/^'.self::twoWays('b', 10, 0).'y+'.self::twoWays('b', 10, 3).'x+(?:a|a)+./',
            'subject' => str_repeat('b', 10).'y'.str_repeat('b', 10)."xa\n",
        ];
        // Each loop leaves to five groups, 32 ways; forty read side by side,
        // 1,280 at each "a": 1,280,001 steps on a thousand.
        yield 'forty loops read side by side' => ['pattern' => self::sideBySide(40), 'subject' => str_repeat('a', 1000).'?'];
    }

    /**
     * Paths that leave a loop at different times and meet at one position
     * add up: "a+" leaves at each of sixteen "a", "a{0,15}" reads the rest,
     * and the sixteen ways reach "b+" together. Five such pairs read sixteen
     * of each letter in 16^5 ways, 9,437,177 steps on them and a newline in
     * the interpreter. So do paths that enter a loop at different times and
     * go on in it together: the 26 prefixes of the alphabet before
     * "[a-z]+" enter it 26 times on "abc...z", 1,019,227 steps with 600 more
     * "a" and a "?".
     */
    #[Test]
    #[DataProvider('providePathsMetAtOnePosition')]
    public function test_the_paths_of_a_loop_at_different_times_add_up(string $pattern, string $subject): void
    {
        $counted = PcreAttemptSteps::pinned('/(*NO_START_OPT)'.substr($pattern, 1));
        $this->assertFalse(PcreAttemptSteps::searchUnderLimit($counted, $subject, 1_000_000), 'Oracle: '.$pattern.' finishes on '.json_encode($subject).' under PHP\'s default backtrack limit.');

        $analysis = (new RedosAnalyzer())->analyze($pattern);
        $this->assertFalse($analysis->isProvenSafe(), $pattern.' is "'.$analysis->headline().'".');
    }

    /**
     * @return iterable<string, array{pattern: string, subject: string}>
     */
    public static function providePathsMetAtOnePosition(): iterable
    {
        $sixteen = implode('', array_map(static fn (string $letter): string => str_repeat($letter, 16), ['a', 'b', 'c', 'd', 'e']));

        yield 'copies after each of five loops' => ['pattern' => '/^a+a{0,15}b+b{0,15}c+c{0,15}d+d{0,15}e+e{0,15}f/', 'subject' => $sixteen."\nf"];
        yield 'any letter after each of five loops' => ['pattern' => '/^a+[a-z]{0,15}b+[a-z]{0,15}c+[a-z]{0,15}d+[a-z]{0,15}e+[a-z]{0,15}!/', 'subject' => $sixteen."\n!"];
        yield 'prefixes entering a loop at each position' => ['pattern' => '/^(?:'.implode('|', self::prefixes(26)).')[a-z]+'.self::emptyGroups(6, 11).'!/', 'subject' => implode('', range('a', 'z')).str_repeat('a', 600).'?'];
    }

    /**
     * Within the margin, the routes that die and the ways one input is
     * read stay linear and proven so: one group before "a+", four groups
     * on each side of "x" before "\ba", four copies on each side of "x+", a
     * "\B\b" no character meets, a success "(*NOTEMPTY)" refuses right
     * after "\K", a "\G" met past the attempt start, a lookahead read in
     * place, whose body ends nowhere, and copies that read "ab" or "ac",
     * each input one way.
     */
    #[Test]
    #[DataProvider('provideWaysWithinTheMargin')]
    public function test_the_ways_within_the_margin_stay_proven(string $pattern, string $pump, string $tail): void
    {
        $counted = PcreAttemptSteps::pinned('/(*NO_START_OPT)'.substr($pattern, 1));
        $short = PcreAttemptSteps::steps($counted, str_repeat($pump, 8).$tail);
        $long = PcreAttemptSteps::steps($counted, str_repeat($pump, 16).$tail);
        $this->assertLessThan(5 * $short, 2 * $long, \sprintf('Oracle: %s takes %d then %d steps on 8 then 16 pumps.', $pattern, $short, $long));
        $this->assertLessThan(20_000, $long, \sprintf('Oracle: %s takes %d steps on 16 pumps.', $pattern, $long));

        $analysis = (new RedosAnalyzer())->analyze($pattern);
        $this->assertSame([RedosProof::Proven, RedosComplexity::Linear], [$analysis->proof, $analysis->complexity], $pattern.' is "'.$analysis->headline().'".');
    }

    /**
     * @return iterable<string, array{pattern: string, pump: string, tail: string}>
     */
    public static function provideWaysWithinTheMargin(): iterable
    {
        yield 'four groups on each side of a character' => ['pattern' => '/^'.self::emptyGroups(4, 0).'x'.self::emptyGroups(4, 11).'\ba/', 'pump' => 'x', 'tail' => 'a'];
        yield 'four groups on each side of a loop' => ['pattern' => '/^'.self::emptyGroups(4, 0).'a+'.self::emptyGroups(4, 11).'\ba/', 'pump' => 'a', 'tail' => 'a'];
        yield 'four copies on each side of a loop' => ['pattern' => '/^'.self::twoWays('a', 4, 0).'x+'.self::twoWays('a', 4, 3).'./', 'pump' => 'x', 'tail' => "aaaa\n"];
        yield 'twenty loops read side by side' => ['pattern' => self::sideBySide(20), 'pump' => 'a', 'tail' => '?'];
        // Loops that read other characters are never left at once.
        yield 'copies after two loops' => ['pattern' => '/^a+a{0,3}b+b{0,3}c/', 'pump' => 'a', 'tail' => 'bbbbbb!'];
        yield 'prefixes entering a loop at each position' => ['pattern' => '/^(?:'.implode('|', self::prefixes(4)).')[a-z]+'.self::emptyGroups(4, 11).'!/', 'pump' => 'a', 'tail' => '?'];
        yield 'one group before a loop' => ['pattern' => '/'.self::emptyGroups(1, 0).'a+!/', 'pump' => 'a', 'tail' => '?'];
        yield 'a boundary no character meets' => ['pattern' => '/^a+(?:\B\b|)!/', 'pump' => 'a', 'tail' => '?'];
        yield 'a success refused right after \K' => ['pattern' => '/(*NOTEMPTY)^a+\K/', 'pump' => 'a', 'tail' => '?'];
        yield 'a continuation met past the attempt start' => ['pattern' => '/^(?:a\G|a)+!/', 'pump' => 'a', 'tail' => '?'];
        yield 'a lookahead read in place' => ['pattern' => '/^\w+(?=\s*$)/', 'pump' => 'a', 'tail' => '!'];
        yield 'copies read one way at a time, before a lookahead' => ['pattern' => '/^x+'.str_repeat('(?:ab|ac)', 11).'(?=!)/', 'pump' => 'x', 'tail' => str_repeat('ab', 11).'?'];
        yield 'a chain of loops before a loop of digits' => ['pattern' => '/^'.self::chainOfLoops(7).'\d+'.self::emptyGroups(7, 11).'./', 'pump' => '1', 'tail' => "\n"];
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

    /**
     * Groups that hold a word boundary or read nothing, each written apart.
     */
    private static function boundaryGroups(int $count, int $offset): string
    {
        $groups = '';
        for ($group = 0; $group < $count; $group++) {
            $groups .= '(?:\b|'.\chr(98 + ($group + $offset) % 24).'{0})';
        }

        return $groups;
    }

    /**
     * Alternatives that each loop on "a", then leave to five groups that
     * read nothing in two ways, before a "!".
     */
    private static function sideBySide(int $count): string
    {
        $alternatives = [];
        for ($alternative = 0; $alternative < $count; $alternative++) {
            $groups = '';
            for ($group = 0; $group < 5; $group++) {
                $groups .= '(?:'.\chr(98 + ($alternative * 5 + $group) % 24).'{0}|)';
            }

            $alternatives[] = 'a+'.$groups;
        }

        return '/^(?:'.implode('|', $alternatives).')!/';
    }

    /**
     * Loops that may read nothing, each on a character of its own.
     */
    private static function chainOfLoops(int $count): string
    {
        $loops = '';
        for ($loop = 0; $loop < $count; $loop++) {
            $loops .= '(?:'.\chr(116 + $loop % 7).'*)';
        }

        return $loops;
    }

    /**
     * The first prefixes of the alphabet, the shortest first.
     *
     * @return list<string>
     */
    private static function prefixes(int $count): array
    {
        $prefixes = [];
        for ($length = 1; $length <= $count; $length++) {
            $prefixes[] = implode('', \array_slice(range('a', 'z'), 0, $length));
        }

        return $prefixes;
    }

    /**
     * Copies of one character, each read in two ways and written apart.
     */
    private static function twoWays(string $character, int $count, int $offset): string
    {
        $spellings = ['(?:%1$s|[%1$s])', '(?:[%1$s]|%1$s)', '(?:%1$s|(?:%1$s))', '(?:(?:%1$s)|%1$s)', '(?:%1$s|%1$s{1})', '(?:%1$s{1}|%1$s)', '(?:%1$s|[%1$s%1$s])',
            '(?:[%1$s%1$s]|%1$s)', '(?:%1$s|%1$s{1,1})', '(?:%1$s{1,1}|%1$s)', '(?:%1$s|(?:[%1$s]))', '(?:(?:[%1$s])|%1$s)', '(?:%1$s|[%1$s]{1})', '(?:[%1$s]{1}|%1$s)'];
        $copies = '';
        for ($copy = 0; $copy < $count; $copy++) {
            $copies .= \sprintf($spellings[($copy + $offset) % \count($spellings)], $character);
        }

        return $copies;
    }
}
