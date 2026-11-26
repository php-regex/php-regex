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
 * The paths one attempt tries at once are counted stretch by stretch: each
 * loop is entered by the ways every stretch before it reaches it, added up
 * whether or not one input brings them together. A chain of loops each
 * reached both from the loop before it and around that loop, such as the
 * numbers of a version, doubles that sum at each loop, though no input
 * enters any of them twice. Past the margin that way, the attempt is read
 * whole on one input, its loops included: the paths that stand together
 * after each character, in the order the engine tries them, up to a
 * success it stops at.
 *
 * Engine: PHP 8.4.28 / PCRE2 10.49, the interpreter, without
 * auto-possession, the steps of one attempt read from the match limit.
 */
final class RedosWholeAttemptReadTest extends TestCase
{
    private const SEMVER = '(?P<major>0|[1-9]\d*)\.(?P<minor>0|[1-9]\d*)\.(?P<patch>0|[1-9]\d*)(?:-(?P<prerelease>(?:0|[1-9]\d*|\d*[a-zA-Z-][0-9a-zA-Z-]*)(?:\.(?:0|[1-9]\d*|\d*[a-zA-Z-][0-9a-zA-Z-]*))*))?(?:\+(?P<buildmetadata>[0-9a-zA-Z-]+(?:\.[0-9a-zA-Z-]+)*))?$';

    /**
     * Eleven numbers joined by dots: the stretches count 1,024 ways into
     * the last loop, twice as many at each number, and one input stands at
     * two items at most, the loop of a number and the dot after it.
     */
    private const CHAIN = '[1-9]\d*(?:\.[1-9]\d*){10}';

    private const CHAIN_SUBJECT = '1.2.3.4.5.6.7.8.9.1.2';

    /**
     * The pattern of semver.org takes two to three steps for each character
     * of a prerelease that ends on a "!": 189 steps on sixteen "0a0." and
     * 717 on sixty-four. Numbers joined by dots take one step for each
     * digit of the last one.
     */
    #[Test]
    #[DataProvider('provideLoopsNoInputEntersTwice')]
    public function test_loops_no_input_enters_twice_are_read_as_one_path(string $pattern, string $head, string $pump, string $tail): void
    {
        $counted = self::counted($pattern);
        $short = PcreAttemptSteps::steps($counted, $head.str_repeat($pump, 16).$tail);
        $long = PcreAttemptSteps::steps($counted, $head.str_repeat($pump, 64).$tail);
        $this->assertLessThan(5 * $short, $long, \sprintf('Oracle: %s takes %d then %d steps on 16 then 64 pumps.', $pattern, $short, $long));
        $this->assertLessThan(2_000, $long, \sprintf('Oracle: %s takes %d steps on 64 pumps.', $pattern, $long));

        $analysis = (new RedosAnalyzer())->analyze($pattern);
        $this->assertSame([RedosProof::Proven, RedosComplexity::Linear], [$analysis->proof, $analysis->complexity], $pattern.' is "'.$analysis->headline().'".');
    }

    /**
     * @return iterable<string, array{pattern: string, head: string, pump: string, tail: string}>
     */
    public static function provideLoopsNoInputEntersTwice(): iterable
    {
        yield 'the semver.org pattern, a prerelease of letters and digits' => ['pattern' => '/^'.self::SEMVER.'/', 'head' => '0.0.0-', 'pump' => '0a0.', 'tail' => '!'];
        yield 'the semver.org pattern, a prerelease of numbers' => ['pattern' => '/^'.self::SEMVER.'/', 'head' => '0.0.0-', 'pump' => '1.', 'tail' => '!'];
        yield 'the semver.org pattern, build metadata' => ['pattern' => '/^'.self::SEMVER.'/', 'head' => '0.0.0-1+', 'pump' => 'a.', 'tail' => '!'];
        yield 'the semver.org pattern between carets, searched' => ['pattern' => '^'.self::SEMVER.'^', 'head' => '0.0.0-', 'pump' => '0a0.', 'tail' => '!'];
        yield 'nine numbers joined by dots' => ['pattern' => '/^(?:0|[1-9]\d*)(?:\.(?:0|[1-9]\d*)){8}$/', 'head' => '1.1.1.1.1.1.1.1.', 'pump' => '1', 'tail' => '?'];
        yield 'eleven numbers joined by dots' => ['pattern' => '/^'.self::CHAIN.'!/', 'head' => '1.1.1.1.1.1.1.1.1.1.', 'pump' => '1', 'tail' => '?'];
    }

    /**
     * A bounded repeat whose copies read one input in two ways is read as a
     * loop, which holds more paths at each round where the engine stops at
     * the last copy. Past the margin with such a loop, the copies are read
     * as written. Two patterns of mpdf's Indic shaper, every repeat bounded
     * and every part after the first letter optional: 67 and 68 steps on
     * the text of four copies, no more on the text of eight.
     */
    #[Test]
    #[DataProvider('provideBoundedRepeatsReadInTwoWays')]
    public function test_a_bounded_repeat_read_in_two_ways_is_counted_as_its_copies(string $pattern, string $copies, string $every): void
    {
        $counted = self::counted($pattern);
        foreach ([$copies, $copies.$copies, $every] as $text) {
            $steps = PcreAttemptSteps::steps($counted, $text.'!');
            $this->assertLessThan(100, $steps, \sprintf('Oracle: %s takes %d steps on %s.', $pattern, $steps, $text));
        }

        $analysis = (new RedosAnalyzer())->analyze($pattern);
        $this->assertSame([RedosProof::Proven, RedosComplexity::Linear], [$analysis->proof, $analysis->complexity], $pattern.' is "'.$analysis->headline().'".');
        $this->assertStringContainsString('{0,4}', implode(' ', $analysis->abstractions), 'The repeat read as a loop is still named.');
    }

    /**
     * @return iterable<string, array{pattern: string, copies: string, every: string}>
     */
    public static function provideBoundedRepeatsReadInTwoWays(): iterable
    {
        yield 'mpdf, src/Shaper/Indic.php line 313' => [
            'pattern' => '/^(RH|r)?V((Z?F)?[N]{0,2})?(J|([ZJ]?G(JN?)?[CR]J?((Z?F)?[N]{0,2})?){0,4}((([ZJ]?G(JN?)?)|GZ)|(GJ)?([ZJ]{0,3}MN?(H|JHJR)?){0,4})?(G([CR]J?((Z?F)?[N]{0,2})?|V))?(SZ?)?[v]{0,2})/',
            'copies' => 'VGCJGCJGCJGCJ',
            'every' => 'rVZFNNGJNCJZFNNGJNCJZFNNGJNCJZFNNGJNCJZFNNGJGJZZZMNJHJRSZvv',
        ];
        yield 'mpdf, src/Shaper/Indic.php line 317' => [
            'pattern' => '/^(RH|r)?((Z?F)?[N]{0,2})?(([ZJ]?G(JN?)?)[CR]J?((Z?F)?[N]{0,2})?){0,4}((([ZJ]?G(JN?)?)|GZ)|(GJ)?([ZJ]{0,3}MN?(H|JHJR)?){0,4})(G([CR]J?((Z?F)?[N]{0,2})?|V))?(SZ?)?[v]{0,2}/',
            'copies' => 'GCJGCJGCJGCJ',
            'every' => 'RHZFNNZGJNCJZFNNZGJNCJZFNNZGJNCJZFNNZGJNCJZFNNGJZZZMNHGCJZFNNSZvv',
        ];
    }

    /**
     * A repeat that stands within the margin as a loop is not read again
     * as its copies. Twelve copies of an "a" read in two ways, before a tail
     * that takes whatever follows, hold more than 1,024 ways to read eight
     * "a", none of which the engine tries after the first: that one reaches
     * the end of the subject, 59 then 155 steps with sixteen then sixty-four
     * characters after twelve "a".
     */
    #[Test]
    public function test_a_bounded_repeat_within_the_margin_as_a_loop_stays_proven(): void
    {
        $pattern = '/^x(?:ab?|a){1,12}(?:.|\n)*\z/';
        $counted = self::counted($pattern);
        $short = PcreAttemptSteps::steps($counted, 'x'.str_repeat('a', 12).str_repeat('?', 16));
        $long = PcreAttemptSteps::steps($counted, 'x'.str_repeat('a', 12).str_repeat('?', 64));
        $this->assertLessThan(5 * $short, $long, \sprintf('Oracle: %s takes %d then %d steps on 16 then 64 characters after twelve "a".', $pattern, $short, $long));
        $this->assertLessThan(200, $long, \sprintf('Oracle: %s takes %d steps on 64 characters after twelve "a".', $pattern, $long));

        $analysis = (new RedosAnalyzer())->analyze($pattern);
        $this->assertSame([RedosProof::Proven, RedosComplexity::Linear], [$analysis->proof, $analysis->complexity], $pattern.' is "'.$analysis->headline().'".');
    }

    /**
     * The copies settle the attempt only for a pattern without lookaround,
     * whose own search is all one attempt runs, and where they fit the
     * states the analysis may build. A lookahead whose body holds 2,048
     * routes beside the repeat takes 2,077 steps on "q?", and sixteen copies
     * of 132 characters before as many routes 2,054 on one copy: both stay
     * with the heuristics.
     */
    #[Test]
    #[DataProvider('provideBoundedRepeatsLeftAsCounted')]
    public function test_a_bounded_repeat_is_left_as_counted_where_its_copies_do_not_settle_the_attempt(string $pattern, string $subject): void
    {
        $steps = PcreAttemptSteps::steps(self::counted($pattern), $subject);
        $this->assertGreaterThanOrEqual(2_048, $steps, \sprintf('Oracle: %s takes %d steps on %s.', $pattern, $steps, $subject));

        $analysis = (new RedosAnalyzer())->analyze($pattern);
        $this->assertSame([RedosProof::Heuristic, RedosComplexity::Unknown], [$analysis->proof, $analysis->complexity], $pattern.' is "'.$analysis->headline().'".');
    }

    /**
     * @return iterable<string, array{pattern: string, subject: string}>
     */
    public static function provideBoundedRepeatsLeftAsCounted(): iterable
    {
        $routes = self::emptyGroups(11, 0);

        yield 'beside a lookahead whose body holds 2,048 routes' => [
            'pattern' => '/^(?:q(?='.$routes.'y)|(RH|r)?((Z?F)?[N]{0,2})?(([ZJ]?G(JN?)?)[CR]J?((Z?F)?[N]{0,2})?){0,4}((([ZJ]?G(JN?)?)|GZ)|(GJ)?([ZJ]{0,3}MN?(H|JHJR)?){0,4})(G([CR]J?((Z?F)?[N]{0,2})?|V))?(SZ?)?[v]{0,2})/',
            'subject' => 'q?',
        ];
        yield 'sixteen copies of 132 characters' => ['pattern' => '/^(?:(?:a|a)x{130}){0,16}(?:'.$routes.'y)?/', 'subject' => 'a'.str_repeat('x', 130).'?'];
    }

    /**
     * The engine tries the paths in order and stops at the first that
     * reaches the end of the pattern: after "a", an empty branch before
     * eleven groups and a "b" ends the attempt in 3 steps, whatever follows,
     * and the 2,048 routes to the "b" are never walked. They are when they
     * stand first (2,050 steps on "ac"), when the end waits for the next
     * character, and where the success is refused: one that reads nothing,
     * at the attempt start, which preg_match() without $matches and the
     * functions that look for every match try again, or one "(*NOTEMPTY)"
     * refuses after "\K".
     */
    #[Test]
    #[DataProvider('provideSuccessesBeforeAndAfterManyRoutes')]
    public function test_nothing_is_tried_after_a_success_the_engine_stops_at(string $pattern, string $subject, bool $walked): void
    {
        $counted = PcreAttemptSteps::counted('/(*NO_START_OPT)'.substr($pattern, 1));
        $underLimit = PcreAttemptSteps::searchUnderLimit($counted, $subject, 1_000);
        $this->assertSame($walked, false === $underLimit, \sprintf('Oracle: %s on %s under a limit of 1,000 steps gives %s.', $pattern, json_encode($subject), json_encode($underLimit)));
        if (!$walked) {
            foreach (['a', 'ab', 'ac', 'aa', 'xa', 'xab'] as $other) {
                $this->assertNotFalse(PcreAttemptSteps::searchUnderLimit($counted, $other, 100), \sprintf('Oracle: %s takes more than 100 steps on %s.', $pattern, $other));
                $this->assertLessThan(100, PcreAttemptSteps::steps($counted, $other), \sprintf('Oracle: %s with $matches takes more than 100 steps on %s.', $pattern, $other));
            }
        }

        $analysis = (new RedosAnalyzer())->analyze($pattern);
        $expected = $walked ? [RedosProof::Heuristic, RedosComplexity::Unknown] : [RedosProof::Proven, RedosComplexity::Linear];
        $this->assertSame($expected, [$analysis->proof, $analysis->complexity], $pattern.' is "'.$analysis->headline().'".');
    }

    /**
     * @return iterable<string, array{pattern: string, subject: string, walked: bool}>
     */
    public static function provideSuccessesBeforeAndAfterManyRoutes(): iterable
    {
        $routes = self::emptyGroups(11, 1).'b';

        yield 'a success before the routes' => ['pattern' => '/^a(?:|'.$routes.')/', 'subject' => 'ac', 'walked' => false];
        yield 'the routes before the success' => ['pattern' => '/^a(?:'.$routes.'|)/', 'subject' => 'ac', 'walked' => true];
        yield 'a success that reads nothing, at the attempt start' => ['pattern' => '/^(?:|'.$routes.')/', 'subject' => 'c', 'walked' => true];
        yield 'a lazy group' => ['pattern' => '/^a(?:'.$routes.')??/', 'subject' => 'ac', 'walked' => false];
        yield 'a greedy group' => ['pattern' => '/^a(?:'.$routes.')?/', 'subject' => 'ac', 'walked' => true];
        yield 'a group the U flag makes lazy' => ['pattern' => '/^a(?:'.$routes.')?/U', 'subject' => 'ac', 'walked' => false];
        yield 'a success after a loop' => ['pattern' => '/^a+(?:|'.$routes.')/', 'subject' => 'aac', 'walked' => false];
        yield 'a success after a loop read in two ways' => ['pattern' => '/^(?:a|a)+(?:|'.$routes.')/', 'subject' => 'aac', 'walked' => false];
        yield 'a success in a later attempt of a search' => ['pattern' => '/a(?:|'.$routes.')/', 'subject' => 'xac', 'walked' => false];
        yield 'an optional character before the success' => ['pattern' => '/^a(?:|'.$routes.')c?/', 'subject' => 'ac', 'walked' => false];
        yield 'a match \K leaves empty past the attempt start' => ['pattern' => '/^a\K(?:|'.$routes.')/', 'subject' => 'ac', 'walked' => false];
        yield 'a success (*NOTEMPTY) refuses after \K' => ['pattern' => '/(*NOTEMPTY)^a(?:\K|'.$routes.')/', 'subject' => 'ac', 'walked' => true];
        yield 'a success that read a character under (*NOTEMPTY)' => ['pattern' => '/(*NOTEMPTY)^a(?:|'.$routes.')/', 'subject' => 'ac', 'walked' => false];
        yield 'a success that reads nothing under (*NOTEMPTY_ATSTART)' => ['pattern' => '/(*NOTEMPTY_ATSTART)^(?:|'.$routes.')/', 'subject' => 'c', 'walked' => true];
        yield 'an end the next character decides' => ['pattern' => '/^a(?:|'.$routes.')$/', 'subject' => 'aa', 'walked' => true];
        yield 'an end the next character decides, the routes one character later' => ['pattern' => '/^a(?:$|b'.self::emptyGroups(11, 1).'c)/', 'subject' => 'ab?', 'walked' => true];
        yield 'a boundary the next character decides' => ['pattern' => '/^a(?:|'.$routes.')\b/', 'subject' => 'aa', 'walked' => true];
        yield 'a success in the body of a lookahead' => ['pattern' => '/^x(?=a(?:|'.$routes.'))/', 'subject' => 'xac', 'walked' => false];
    }

    /**
     * PCRE2 makes a character repeated before a possessive repeat of a group,
     * or before an atomic group, that may match nothing, possessive as if the
     * group could not be skipped: "a+" never gives back the "a" the branch
     * needs after it. The success the reading finds after "aa" is then not
     * the engine's, which walks the 2,048 routes of the second branch (2,052
     * steps on "aa?"), where without auto-possession the first branch
     * matches in 8. Nothing is cut in such a pattern.
     */
    #[Test]
    #[DataProvider('provideSuccessesAPossessiveRepeatMisses')]
    public function test_no_success_is_cut_in_a_pattern_that_holds_a_possessive_repeat(string $pattern): void
    {
        $written = PcreAttemptSteps::steps('/(*NO_JIT)'.substr($pattern, 1).'A', 'aa?');
        $this->assertGreaterThanOrEqual(2_048, $written, \sprintf('Oracle: %s takes %d steps on "aa?".', $pattern, $written));
        $this->assertSame(0, preg_match($pattern, 'aa?'), 'Oracle: '.$pattern.' finds no match in "aa?".');
        $visible = PcreAttemptSteps::steps(PcreAttemptSteps::pinned($pattern), 'aa?');
        $this->assertLessThan(20, $visible, \sprintf('Oracle: %s takes %d steps on "aa?" without auto-possession.', $pattern, $visible));

        $analysis = (new RedosAnalyzer())->analyze($pattern);
        $this->assertSame([RedosProof::Heuristic, RedosComplexity::Unknown], [$analysis->proof, $analysis->complexity], $pattern.' is "'.$analysis->headline().'".');
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideSuccessesAPossessiveRepeatMisses(): iterable
    {
        $routes = self::emptyGroups(11, 1).'c';

        yield 'a possessive repeat of a group' => ['pattern' => '/^(?:a+(?:b)?+a|aa'.$routes.')/'];
        yield 'an atomic group' => ['pattern' => '/^(?:a+(?>(?:b)?)a|aa'.$routes.')/'];
    }

    /**
     * What stands at once is held to the margin as the stretches are: 1,024
     * paths at most. The engine walks each of them, about one step for each
     * route at each character, which the oracle reads on a subject that
     * meets them: nine groups before the chain take 8,193 steps on sixteen
     * digits, ten 16,385. The paths into a lookahead multiply those of its
     * body, the routes that die count as those that go on, and the routes
     * through a mark are those the reading itself met.
     */
    #[Test]
    #[DataProvider('providePathsAtTheMargin')]
    public function test_the_paths_read_whole_are_held_to_the_margin(string $pattern, string $subject, int $routes, bool $proven): void
    {
        $counted = PcreAttemptSteps::pinned('/(*NO_START_OPT)'.substr($pattern, 1));
        $steps = PcreAttemptSteps::steps($counted, $subject);
        $this->assertGreaterThanOrEqual($routes, $steps, \sprintf('Oracle: %s takes %d steps on %s.', $pattern, $steps, json_encode($subject)));
        $this->assertLessThan(2 * $routes + 100, $steps, \sprintf('Oracle: %s takes %d steps on %s.', $pattern, $steps, json_encode($subject)));

        $analysis = (new RedosAnalyzer())->analyze($pattern);
        $expected = $proven ? [RedosProof::Proven, RedosComplexity::Linear] : [RedosProof::Heuristic, RedosComplexity::Unknown];
        $this->assertSame($expected, [$analysis->proof, $analysis->complexity], $pattern.' is "'.$analysis->headline().'".');
    }

    /**
     * @return iterable<string, array{pattern: string, subject: string, routes: int, proven: bool}>
     */
    public static function providePathsAtTheMargin(): iterable
    {
        $chain = self::CHAIN.'!';
        $digits = str_repeat('1', 16).'?';
        // 1,023 routes, 512 down to one, that die at "\B" before a digit,
        // or reach an "x".
        $dying = implode('|', array_map(static fn (int $groups): string => self::emptyGroups($groups, $groups).'\Bx', range(9, 0)));
        $reaching = implode('|', array_map(static fn (int $groups): string => self::emptyGroups($groups, $groups).'x', range(9, 0)));

        yield 'nine groups before the chain' => ['pattern' => '/^'.self::emptyGroups(9, 0).$chain.'/', 'subject' => $digits, 'routes' => 16 * 512, 'proven' => true];
        yield 'ten groups before the chain' => ['pattern' => '/^'.self::emptyGroups(10, 0).$chain.'/', 'subject' => $digits, 'routes' => 16 * 1024, 'proven' => false];
        yield '1,023 routes to a character at the attempt start' => ['pattern' => '/^(?:'.$reaching.'|y'.$chain.')/', 'subject' => '?', 'routes' => 1023, 'proven' => true];
        yield '1,024 routes to a character at the attempt start' => ['pattern' => '/^(?:'.$reaching.'|x|y'.$chain.')/', 'subject' => '?', 'routes' => 1024, 'proven' => false];
        yield '1,023 routes that die at the attempt start' => ['pattern' => '/^(?:'.$dying.'|'.$chain.')/', 'subject' => '1?', 'routes' => 1023, 'proven' => true];
        yield '1,024 routes that die at the attempt start' => ['pattern' => '/^(?:'.$dying.'|\By|'.$chain.')/', 'subject' => '1?', 'routes' => 1024, 'proven' => false];
        yield 'sixteen routes that die after a character thirty-two reach' => ['pattern' => '/^'.self::emptyGroups(5, 0).'x(?:'.self::emptyGroups(4, 5).'\by|'.$chain.')/', 'subject' => 'x1?', 'routes' => 32 * 16, 'proven' => true];
        yield 'thirty-two routes that die after a character thirty-two reach' => ['pattern' => '/^'.self::emptyGroups(5, 0).'x(?:'.self::emptyGroups(5, 5).'\by|'.$chain.')/', 'subject' => 'x1?', 'routes' => 32 * 32, 'proven' => false];
        yield 'two characters read at once, 256 routes that die after each' => ['pattern' => '/^(?:x(?:'.self::emptyGroups(8, 0).'\by|'.$chain.')|x(?:'.self::emptyGroups(8, 9).'\bz|'.$chain.'))/', 'subject' => 'x1?', 'routes' => 2 * 256, 'proven' => true];
        yield 'two characters read at once, 512 routes that die after each' => ['pattern' => '/^(?:x(?:'.self::emptyGroups(9, 0).'\by|'.$chain.')|x(?:'.self::emptyGroups(9, 9).'\bz|'.$chain.'))/', 'subject' => 'x1?', 'routes' => 2 * 512, 'proven' => false];
        yield 'eight routes after a character, then sixty-four' => ['pattern' => '/^'.self::emptyGroups(3, 0).'x'.self::emptyGroups(6, 3).$chain.'/', 'subject' => 'x'.$digits, 'routes' => 16 * 512, 'proven' => true];
        yield 'eight routes after a character, then 128' => ['pattern' => '/^'.self::emptyGroups(3, 0).'x'.self::emptyGroups(7, 3).$chain.'/', 'subject' => 'x'.$digits, 'routes' => 16 * 1024, 'proven' => false];
        yield 'two routes after a character, then 256' => ['pattern' => '/^'.self::emptyGroups(1, 0).'x'.self::emptyGroups(8, 3).$chain.'/', 'subject' => 'x'.$digits, 'routes' => 16 * 512, 'proven' => true];
        yield '256 paths, then three routes to a character' => ['pattern' => '/^'.$chain.self::emptyGroups(8, 0).'x(?:||)yz/', 'subject' => self::CHAIN_SUBJECT.'!x?', 'routes' => 256 * 3, 'proven' => true];
        yield '512 paths, then three routes to a character' => ['pattern' => '/^'.$chain.self::emptyGroups(9, 0).'x(?:||)yz/', 'subject' => self::CHAIN_SUBJECT.'!x?', 'routes' => 512 * 3, 'proven' => false];
        yield 'a character that reads anything beside one that reads an x, 256 routes each' => ['pattern' => '/^(?:'.self::emptyGroups(8, 0).'.|'.self::emptyGroups(8, 9).'x)y'.$chain.'/s', 'subject' => 'xy'.$digits, 'routes' => 16 * 512, 'proven' => true];
        yield 'a character that reads anything beside one that reads an x, 512 routes each' => ['pattern' => '/^(?:'.self::emptyGroups(9, 0).'.|'.self::emptyGroups(9, 9).'x)y'.$chain.'/s', 'subject' => 'xy'.$digits, 'routes' => 16 * 1024, 'proven' => false];
        // Two lists of paths that hold the same items, or as many paths at
        // other items, are each read.
        yield 'a branch of one route beside one of 512' => ['pattern' => '/^(?:x|y'.self::emptyGroups(9, 0).')'.$chain.'/', 'subject' => 'y'.$digits, 'routes' => 16 * 512, 'proven' => true];
        yield 'a branch of one route beside one of 1,024' => ['pattern' => '/^(?:x|y'.self::emptyGroups(10, 0).')'.$chain.'/', 'subject' => 'y'.$digits, 'routes' => 16 * 1024, 'proven' => false];
        yield 'a branch of 1,024 routes beside one of one' => ['pattern' => '/^(?:x'.self::emptyGroups(10, 0).'|y)'.$chain.'/', 'subject' => 'x'.$digits, 'routes' => 16 * 1024, 'proven' => false];
        yield 'a branch of two characters beside one with 2,048 routes after them' => ['pattern' => '/^(?:xa|yb'.self::emptyGroups(11, 0).'c)'.$chain.'/', 'subject' => 'yb?', 'routes' => 2048, 'proven' => false];
        yield 'a branch with 2,048 routes after two characters beside one without' => ['pattern' => '/^(?:xa'.self::emptyGroups(11, 0).'c|yb)'.$chain.'/', 'subject' => 'xa?', 'routes' => 2048, 'proven' => false];
        yield 'thirty-two paths into a lookahead, sixteen routes in its body' => ['pattern' => '/^'.self::emptyGroups(5, 0).'(?='.self::emptyGroups(4, 5).$chain.')/', 'subject' => $digits, 'routes' => 16 * 512, 'proven' => true];
        yield 'thirty-two paths into a lookahead, thirty-two routes in its body' => ['pattern' => '/^'.self::emptyGroups(5, 0).'(?='.self::emptyGroups(5, 5).$chain.')/', 'subject' => $digits, 'routes' => 16 * 1024, 'proven' => false];
        yield 'sixteen paths into a lookahead at the attempt start, thirty-two routes in its body' => ['pattern' => '/^'.self::emptyGroups(4, 0).'(?='.self::emptyGroups(5, 4).'y)'.$chain.'/', 'subject' => '1?', 'routes' => 16 * 32, 'proven' => true];
        yield 'sixteen paths into a lookahead at the attempt start, 128 routes in its body' => ['pattern' => '/^'.self::emptyGroups(4, 0).'(?='.self::emptyGroups(7, 4).'y)'.$chain.'/', 'subject' => '1?', 'routes' => 16 * 128, 'proven' => false];
        yield 'a lookahead after the chain, 256 routes in its body' => ['pattern' => '/^'.self::CHAIN.'(?='.self::emptyGroups(8, 0).'!)/', 'subject' => self::CHAIN_SUBJECT.'?', 'routes' => 256, 'proven' => true];
        yield 'four paths through the chain, eight routes to a lookahead, thirty-two in its body' => ['pattern' => '/^'.self::emptyGroups(2, 0).self::CHAIN.self::emptyGroups(3, 2).'(?='.self::emptyGroups(5, 5).'!)/', 'subject' => self::CHAIN_SUBJECT.'?', 'routes' => 4 * 8 * 32, 'proven' => true];
        yield 'four paths through the chain, eight routes to a lookahead, sixty-four in its body' => ['pattern' => '/^'.self::emptyGroups(2, 0).self::CHAIN.self::emptyGroups(3, 2).'(?='.self::emptyGroups(6, 5).'!)/', 'subject' => self::CHAIN_SUBJECT.'?', 'routes' => 4 * 8 * 64, 'proven' => false];
        // The most routes through a mark at once, whichever list of paths
        // is read last.
        yield 'thirty-two paths after an x, one after a y, into a lookahead of sixteen routes' => ['pattern' => '/^(?:'.self::emptyGroups(5, 0).'x|y)(?='.self::emptyGroups(4, 5).'!)'.$chain.'/', 'subject' => 'x?', 'routes' => 32 * 16, 'proven' => true];
        yield 'thirty-two paths after an x, one after a y, into a lookahead of sixty-four routes' => ['pattern' => '/^(?:'.self::emptyGroups(5, 0).'x|y)(?='.self::emptyGroups(6, 5).'!)'.$chain.'/', 'subject' => 'x?', 'routes' => 32 * 64, 'proven' => false];
        yield 'one path after an x, thirty-two after a y, into a lookahead of sixty-four routes' => ['pattern' => '/^(?:x|'.self::emptyGroups(5, 0).'y)(?='.self::emptyGroups(6, 5).'!)'.$chain.'/', 'subject' => 'y?', 'routes' => 32 * 64, 'proven' => false];
    }

    /**
     * The reading has an allowance of its own, 16,384 steps: one for each
     * item of each list of paths, one for each class the item reads, and one
     * for each item listed after it each time it reads; past it the attempt
     * stays as the stretches count it. With the chain and eight letters after
     * it, which take 156, each dot of a run takes sixteen and each "x" three:
     * 1,012 dots and twelve "x" take the allowance to its last step, 1,013
     * dots and seven "x" one more. Both are linear on the engine, which reads
     * a digit of the last number in one step.
     */
    #[Test]
    #[DataProvider('provideReadingsAtTheEndOfTheirAllowance')]
    public function test_the_reading_is_given_up_past_its_allowance(string $pattern, string $head, bool $proven): void
    {
        $counted = PcreAttemptSteps::pinned('/(*NO_START_OPT)'.substr($pattern, 1));
        $short = PcreAttemptSteps::steps($counted, $head.str_repeat('1', 16).'?');
        $long = PcreAttemptSteps::steps($counted, $head.str_repeat('1', 64).'?');
        $this->assertLessThan(5 * $short, $long, \sprintf('Oracle: the chain behind %d characters takes %d then %d steps on 16 then 64 digits.', \strlen($head), $short, $long));
        $this->assertLessThan(200, $long, \sprintf('Oracle: the chain behind %d characters takes %d steps on 64 digits.', \strlen($head), $long));

        $analysis = (new RedosAnalyzer())->analyze($pattern);
        $expected = $proven ? [RedosProof::Proven, RedosComplexity::Linear] : [RedosProof::Heuristic, RedosComplexity::Unknown];
        $this->assertSame($expected, [$analysis->proof, $analysis->complexity], 'The chain behind '.\strlen($head).' characters is "'.$analysis->headline().'".');
    }

    /**
     * @return iterable<string, array{pattern: string, head: string, proven: bool}>
     */
    public static function provideReadingsAtTheEndOfTheirAllowance(): iterable
    {
        $numbers = '1.1.1.1.1.1.1.1.1.1.';

        yield '16,384 steps' => ['pattern' => '/^.{1012}x{12}'.self::CHAIN.'!abcdefgh/', 'head' => str_repeat('-', 1012).str_repeat('x', 12).$numbers, 'proven' => true];
        yield '16,385 steps' => ['pattern' => '/^.{1013}x{7}'.self::CHAIN.'!abcdefgh/', 'head' => str_repeat('-', 1013).str_repeat('x', 7).$numbers, 'proven' => false];
    }

    /**
     * The pattern with its steps counted, its attempt pinned when it is
     * anchored at the start: one that is not is searched, and the count is
     * the costliest attempt's.
     */
    private static function counted(string $pattern): string
    {
        $open = $pattern[0].'(*NO_START_OPT)'.substr($pattern, 1);

        return '^' === $pattern[1] ? PcreAttemptSteps::pinned($open) : PcreAttemptSteps::counted($open);
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
