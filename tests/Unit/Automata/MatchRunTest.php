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

namespace PHPRegex\Tests\Unit\Automata;

use PHPRegex\Automata\LanguageSolver;
use PHPRegex\Automata\Match\MatchRun;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * preg_match() searches from offset 0 only; preg_match() with an offset
 * searches past the start of the subject, where "^" and "\A" never hold;
 * preg_match_all(), preg_replace() and preg_split() also retry each empty
 * match anchored where it stands, refusing it empty. Each run is checked
 * against the engine: the second through an offset of 1 past a letter, the
 * retry through "\G(?:...)(?!\G)", which PCRE anchors at the offset and
 * refuses empty there.
 */
final class MatchRunTest extends TestCase
{
    private const FLAGS = \PREG_OFFSET_CAPTURE | \PREG_UNMATCHED_AS_NULL;

    #[Test]
    #[DataProvider('providePairs')]
    public function test_each_run_is_judged_as_the_engine_runs_it(string $left, string $right, string $letters): void
    {
        $solver = new LanguageSolver();

        foreach (MatchRun::cases() as $run) {
            $result = $solver->matchEquivalentIn($run, $left, $right);

            if ($result->isEquivalent) {
                $this->assertNull($result->counterExample);
                foreach (self::subjects($letters, 4) as $subject) {
                    $this->assertSame(self::matchesOf($run, $left, $subject), self::matchesOf($run, $right, $subject), \sprintf('%s and %s differ on %s in the %s run.', $left, $right, json_encode($subject), $run->name));
                }

                continue;
            }

            $this->assertNotNull($result->counterExample, $run->name);
            $this->assertNotSame(self::matchesOf($run, $left, $result->counterExample), self::matchesOf($run, $right, $result->counterExample), \sprintf('%s and %s write the same matches on %s in the %s run.', $left, $right, json_encode($result->counterExample), $run->name));
        }
    }

    /**
     * The pairs preg_match() cannot tell apart from offset 0, and which
     * other calls can.
     */
    #[Test]
    public function test_a_search_from_offset_zero_misses_what_the_other_runs_see(): void
    {
        $solver = new LanguageSolver();

        $this->assertTrue($solver->matchEquivalent('/^[\s\S]|a/', '/^[\s\S]|b/')->isEquivalent);
        $this->assertFalse($solver->matchEquivalentIn(MatchRun::SearchPastSubjectStart, '/^[\s\S]|a/', '/^[\s\S]|b/')->isEquivalent);
        $this->assertNotSame(preg_match_all('/^[\s\S]|a/', 'xa'), preg_match_all('/^[\s\S]|b/', 'xa'));

        $this->assertTrue($solver->matchEquivalent('/a??/', '/(?:)/')->isEquivalent);
        $this->assertFalse($solver->matchEquivalentIn(MatchRun::EmptyMatchRetry, '/a??/', '/(?:)/')->isEquivalent);
        $this->assertNotSame(preg_replace('/a??/', '-', 'a'), preg_replace('/(?:)/', '-', 'a'));
    }

    /**
     * @param list<MatchRun>|null $runs
     */
    #[Test]
    #[DataProvider('provideRunsOfEveryCall')]
    public function test_the_runs_every_call_needs_follow_from_the_patterns(string $left, string $right, ?array $runs): void
    {
        $this->assertSame($runs, (new LanguageSolver())->runsOfEveryCall($left, $right));
    }

    /**
     * @return iterable<string, array{left: string, right: string, letters: string}>
     */
    public static function providePairs(): iterable
    {
        yield 'a start anchor in one branch' => ['left' => '/^[\s\S]|a/', 'right' => '/^[\s\S]|b/', 'letters' => 'ab'];
        yield 'a lazy option and nothing' => ['left' => '/a??/', 'right' => '/(?:)/', 'letters' => 'a'];
        yield 'a lazy star and nothing' => ['left' => '/a*?/', 'right' => '/(?:)/', 'letters' => 'ab'];
        yield 'a class for a shorthand, repeated' => ['left' => '/[0-9]*/', 'right' => '/\d*/', 'letters' => '0a'];
        yield 'anchored branches swapped' => ['left' => '/^a|b/', 'right' => '/b|^a/', 'letters' => 'ab'];
        yield 'an optional anchored letter' => ['left' => '/(?:^a)?b?/', 'right' => '/(?:^a)?b??/', 'letters' => 'ab'];
        yield 'a class for an alternation, anchored' => ['left' => '/^(?:a|b)+$/', 'right' => '/^[ab]+$/', 'letters' => "ab\n"];
        yield 'greedy and lazy' => ['left' => '/(a*)(a?)/', 'right' => '/(a*?)(a?)/', 'letters' => 'a'];
    }

    /**
     * @return iterable<string, array{left: string, right: string, runs: list<MatchRun>|null}>
     */
    public static function provideRunsOfEveryCall(): iterable
    {
        yield 'neither anchored nor empty' => ['left' => '/a/', 'right' => '/b/', 'runs' => [MatchRun::Search]];
        yield 'a start anchor' => ['left' => '/^a/', 'right' => '/a/', 'runs' => [MatchRun::Search, MatchRun::SearchPastSubjectStart]];
        yield 'the start of the subject' => ['left' => '/a/', 'right' => '/\Aa/', 'runs' => [MatchRun::Search, MatchRun::SearchPastSubjectStart]];
        yield 'an empty match' => ['left' => '/a*/', 'right' => '/b/', 'runs' => [MatchRun::Search, MatchRun::EmptyMatchRetry]];
        yield 'both' => ['left' => '/^a*/', 'right' => '/b/', 'runs' => MatchRun::cases()];
        yield 'the start of the match moves with it' => ['left' => '/\Ga/', 'right' => '/a/', 'runs' => [MatchRun::Search]];
        yield 'an end anchor looks right only' => ['left' => '/a$/m', 'right' => '/a\z/', 'runs' => [MatchRun::Search]];
        yield 'a lookahead looks right only' => ['left' => '/a(?=b)/', 'right' => '/a/', 'runs' => [MatchRun::Search]];
        yield 'a lookbehind' => ['left' => '/(?<=a)b/', 'right' => '/b/', 'runs' => null];
        yield 'a negative lookbehind' => ['left' => '/b/', 'right' => '/(?<!a)b/', 'runs' => null];
        yield 'a lookbehind inside a lookahead' => ['left' => '/(?=(?<=a))b/', 'right' => '/b/', 'runs' => null];
        yield 'a word boundary' => ['left' => '/\ba/', 'right' => '/a/', 'runs' => null];
        yield 'a word boundary after the match' => ['left' => '/a\b/', 'right' => '/a/', 'runs' => null];
        yield 'no word boundary' => ['left' => '/a\B/', 'right' => '/a/', 'runs' => null];
        yield 'the start of a line' => ['left' => '/^a/m', 'right' => '/a/', 'runs' => null];
        yield 'the start of a line, inline' => ['left' => '/(?m)^a/', 'right' => '/a/', 'runs' => null];
        yield 'a match start reset' => ['left' => '/a\Kb/', 'right' => '/ab/', 'runs' => null];
        yield 'a lookbehind as a condition' => ['left' => '/(?(?<=a)b|c)/', 'right' => '/b|c/', 'runs' => null];
        yield 'a flag that changes nothing, dropped' => ['left' => '/(?<!x)a/s', 'right' => '/(?<!x)a/', 'runs' => []];
        yield 'a flag that changes nothing, dropped under another' => ['left' => '/^\s*$/sm', 'right' => '/^\s*$/m', 'runs' => []];
        yield 'a flag that changes a start anchor, dropped' => ['left' => '/^a/m', 'right' => '/^a/', 'runs' => null];
        yield 'a flag that changes a dot, dropped' => ['left' => '/(?<!x)./s', 'right' => '/(?<!x)./', 'runs' => null];
        yield 'a flag that changes the subject, dropped' => ['left' => '/(?<!x)a/u', 'right' => '/(?<!x)a/', 'runs' => null];
        yield 'a shorthand for a class' => ['left' => '/(?<=x)[0-9]+/', 'right' => '/(?<=x)\d+/', 'runs' => []];
        yield 'a repeat of one' => ['left' => '/\ba{1}/', 'right' => '/\ba/', 'runs' => []];
        yield 'a class for an alternation' => ['left' => '/(?<=x)(?:a|b)/', 'right' => '/(?<=x)[ab]/', 'runs' => null];
        yield 'another newline convention' => ['left' => '/(*CR)(?<=x)a$/', 'right' => '/(*LF)(?<=x)a$/', 'runs' => null];
    }

    /**
     * What the run writes for the pattern on the subject, offsets counted
     * in the subject the engine reads.
     *
     * @return array<int|string, mixed>|false
     */
    private static function matchesOf(MatchRun $run, string $pattern, string $subject): array|false
    {
        $past = MatchRun::SearchPastSubjectStart === $run || MatchRun::EmptyMatchRetryPastSubjectStart === $run;
        $retry = MatchRun::EmptyMatchRetry === $run || MatchRun::EmptyMatchRetryPastSubjectStart === $run;

        if ($retry) {
            $close = (int) strrpos($pattern, '/');
            $pattern = '/\G(?:'.substr($pattern, 1, $close - 1).')(?!\G)/'.substr($pattern, $close + 1);
        }

        $matches = [];

        return false === preg_match($pattern, ($past ? 'x' : '').$subject, $matches, self::FLAGS, $past ? 1 : 0) ? false : $matches;
    }

    /**
     * @return list<string>
     */
    private static function subjects(string $letters, int $length): array
    {
        $all = [''];
        $layer = [''];
        for ($size = 1; $size <= $length; $size++) {
            $next = [];
            foreach ($layer as $prefix) {
                foreach (str_split($letters) as $letter) {
                    $next[] = $prefix.$letter;
                }
            }
            $all = [...$all, ...$next];
            $layer = $next;
        }

        return $all;
    }
}
