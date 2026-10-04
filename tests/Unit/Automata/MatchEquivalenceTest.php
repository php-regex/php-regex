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

use PHPRegex\Automata\Exception\ComplexityException;
use PHPRegex\Automata\LanguageSolver;
use PHPRegex\Automata\Options\SolverOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Two patterns are match-equivalent when preg_match() writes the same
 * $matches for them on every subject: same answer, same match, same groups.
 * Language equivalence is weaker: /a|ab/ and /ab|a/ match the same strings,
 * yet on "ab" the first matches "a" and the second "ab".
 *
 * Every verdict is checked against the engine: an equivalence must hold on
 * each string over the patterns' letters up to a length, a difference must
 * show on the string the solver hands back.
 */
final class MatchEquivalenceTest extends TestCase
{
    private const FLAGS = \PREG_OFFSET_CAPTURE | \PREG_UNMATCHED_AS_NULL;

    #[Test]
    #[DataProvider('provideEquivalentPairs')]
    public function test_match_equivalent_patterns_write_the_same_matches_everywhere(string $left, string $right, string $letters): void
    {
        $result = (new LanguageSolver())->matchEquivalent($left, $right);

        $this->assertTrue($result->isEquivalent, \sprintf('%s and %s write the same matches, yet %s was handed back.', $left, $right, json_encode($result->counterExample)));
        $this->assertNull($result->counterExample);

        foreach (self::subjects($letters, 5) as $subject) {
            $this->assertSame(self::matchesOf($left, $subject), self::matchesOf($right, $subject), \sprintf('%s and %s differ on %s.', $left, $right, json_encode($subject)));
        }
    }

    #[Test]
    #[DataProvider('provideDifferentPairs')]
    public function test_the_counter_example_shows_the_difference(string $left, string $right, string $shortest): void
    {
        $result = (new LanguageSolver())->matchEquivalent($left, $right);

        $this->assertFalse($result->isEquivalent);
        $this->assertSame($shortest, $result->counterExample);
        $this->assertNotSame(self::matchesOf($left, $shortest), self::matchesOf($right, $shortest), \sprintf('%s and %s write the same matches on %s.', $left, $right, json_encode($shortest)));
    }

    #[Test]
    public function test_language_equivalence_misses_what_match_equivalence_sees(): void
    {
        $solver = new LanguageSolver();

        $this->assertTrue($solver->equivalent('/(a|ab)/', '/(ab|a)/')->isEquivalent);
        $this->assertFalse($solver->matchEquivalent('/(a|ab)/', '/(ab|a)/')->isEquivalent);
    }

    #[Test]
    #[DataProvider('provideRefusedPairs')]
    public function test_patterns_outside_the_readable_fragment_are_refused(string $left, string $right, string $reason): void
    {
        $this->expectException(ComplexityException::class);
        $this->expectExceptionMessage($reason);

        (new LanguageSolver())->matchEquivalent($left, $right);
    }

    #[Test]
    public function test_the_search_stops_at_its_budget(): void
    {
        $this->expectException(ComplexityException::class);
        $this->expectExceptionMessage('The match solver explored more than 2 configurations for these patterns.');

        (new LanguageSolver())->matchEquivalent('/(a|b)+c/', '/(?:a|b)+c/', new SolverOptions(maxDfaStates: 2));
    }

    #[Test]
    public function test_the_automaton_stops_at_its_budget(): void
    {
        $this->expectException(ComplexityException::class);
        $this->expectExceptionMessage('The match solver needs more than 5 states for this pattern.');

        (new LanguageSolver())->matchEquivalent('/a{50}/', '/a{50}/', new SolverOptions(maxNfaStates: 5));
    }

    #[Test]
    public function test_the_result_names_the_pcre_release(): void
    {
        $this->assertSame(explode(' ', \PCRE_VERSION)[0], (new LanguageSolver())->matchEquivalent('/a/', '/a/')->pcreVersion);
    }

    /**
     * @return iterable<string, array{left: string, right: string, letters: string}>
     */
    public static function provideEquivalentPairs(): iterable
    {
        yield 'same pattern' => ['left' => '/(a|b)c/', 'right' => '/(a|b)c/', 'letters' => 'abc'];
        yield 'class and alternation' => ['left' => '/[ab]/', 'right' => '/a|b/', 'letters' => 'abc'];
        yield 'digit shorthand' => ['left' => '/[0-9]+/', 'right' => '/\d+/', 'letters' => '01a'];
        yield 'shorter first, lazy option' => ['left' => '/a|ab/', 'right' => '/a(?:b)??/', 'letters' => 'ab'];
        yield 'longer first, greedy option' => ['left' => '/ab|a/', 'right' => '/a(?:b)?/', 'letters' => 'ab'];
        yield 'groups keep their spans' => ['left' => '/(a|ab)/', 'right' => '/(a(?:b)??)/', 'letters' => 'ab'];
        yield 'disjoint branches in a loop' => ['left' => '/(?:(x)|y)+/', 'right' => '/(?:y|(x))+/', 'letters' => 'xy'];
        yield 'leftmost start decides' => ['left' => '/b|ab/', 'right' => '/ab|b/', 'letters' => 'ab'];
        yield 'lazy star matches empty' => ['left' => '/a*?/', 'right' => '/(?:)/', 'letters' => 'a'];
        yield 'dollar and capital Z' => ['left' => '/ab$/', 'right' => '/ab\Z/', 'letters' => "ab\n"];
        yield 'anchored' => ['left' => '/^a+$/', 'right' => '/^a(?:a*)$/', 'letters' => "a\n"];
        yield 'counted repeat' => ['left' => '/a{2,3}/', 'right' => '/aaa?/', 'letters' => 'a'];
        yield 'caseless' => ['left' => '/ab/i', 'right' => '/[aA][bB]/', 'letters' => 'abAB'];
        yield 'nested groups' => ['left' => '/((a)b)/', 'right' => '/((a)b)/', 'letters' => 'ab'];
        yield 'last iteration kept' => ['left' => '/(?:(a)|b)+/', 'right' => '/(?:(a)|b)+/', 'letters' => 'ab'];
        yield 'end of subject or capital Z after dollar' => ['left' => '/a$(?:\z|\Z)/', 'right' => '/a$/', 'letters' => "a\n"];
        yield 'every branch after dollar wants the very end' => ['left' => '/a$(?:\z|\z)/', 'right' => '/a\z/', 'letters' => "a\n"];
        yield 'group closing after an anchor' => ['left' => '/(a$)/', 'right' => '/(a\Z)/', 'letters' => "a\n"];
    }

    /**
     * @return iterable<string, array{left: string, right: string, shortest: string}>
     */
    public static function provideDifferentPairs(): iterable
    {
        yield 'alternation order, group' => ['left' => '/(a|ab)/', 'right' => '/(ab|a)/', 'shortest' => 'ab'];
        yield 'alternation order' => ['left' => '/a|ab/', 'right' => '/ab|a/', 'shortest' => 'ab'];
        yield 'greedy and lazy' => ['left' => '/(a+)(a)/', 'right' => '/(a+?)(a)/', 'shortest' => 'aaa'];
        yield 'shorter first, greedy option' => ['left' => '/a|ab/', 'right' => '/a(?:b)?/', 'shortest' => 'ab'];
        yield 'a group more' => ['left' => '/(a)/', 'right' => '/(?:a)/', 'shortest' => 'a'];
        yield 'named and numbered' => ['left' => '/(?<n>a)/', 'right' => '/(a)/', 'shortest' => 'a'];
        yield 'dollar and end of subject' => ['left' => '/^ab$/', 'right' => '/^ab\z/', 'shortest' => "ab\n"];
        yield 'group moves' => ['left' => '/a(b)c/', 'right' => '/(a)bc/', 'shortest' => 'abc'];
        yield 'language differs' => ['left' => '/ab/', 'right' => '/ac/', 'shortest' => 'ab'];
        yield 'empty match against none' => ['left' => '/a*/', 'right' => '/a+/', 'shortest' => ''];
    }

    /**
     * @return iterable<string, array{left: string, right: string, reason: string}>
     */
    public static function provideRefusedPairs(): iterable
    {
        yield 'backreference' => ['left' => '/(a)\1/', 'right' => '/(a)a/', 'reason' => 'Backreferences'];
        yield 'lookahead' => ['left' => '/a(?=b)/', 'right' => '/a/', 'reason' => 'Lookarounds'];
        yield 'atomic group' => ['left' => '/(?>a+)b/', 'right' => '/a+b/', 'reason' => 'Atomic groups and possessive quantifiers'];
        yield 'possessive' => ['left' => '/a++b/', 'right' => '/a+b/', 'reason' => 'Atomic groups and possessive quantifiers'];
        yield 'loop on a body that can match empty' => ['left' => '/(a*)*/', 'right' => '/(a*)/', 'reason' => 'A repeated body that can match empty'];
        yield 'word boundary' => ['left' => '/\ba/', 'right' => '/a/', 'reason' => 'Word boundaries'];
        yield 'anchor before more text' => ['left' => '/a$b/', 'right' => '/ab/', 'reason' => 'An end anchor followed by more of the pattern'];
        yield 'anchor before an optional letter' => ['left' => '/a$(?:b|)/', 'right' => '/a/', 'reason' => 'An end anchor followed by more of the pattern'];
        yield 'multiline flag' => ['left' => '/a/m', 'right' => '/a/', 'reason' => 'Unsupported regex flags for the match solver: m.'];
        yield 'utf mode on one side' => ['left' => '/a/u', 'right' => '/a/', 'reason' => 'Both patterns must read the subject the same way'];
    }

    /**
     * @return array<int|string, mixed>|false
     */
    private static function matchesOf(string $pattern, string $subject): array|false
    {
        $matches = [];

        return false === preg_match($pattern, $subject, $matches, self::FLAGS) ? false : $matches;
    }

    /**
     * Every string over the letters, up to the length.
     *
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
