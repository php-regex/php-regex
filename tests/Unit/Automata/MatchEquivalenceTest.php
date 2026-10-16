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
use PHPRegex\Automata\Options\MatchMode;
use PHPRegex\Automata\Options\SolverOptions;
use PHPRegex\Tests\Support\PcreGates;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Two patterns are match-equivalent when preg_match() writes the same
 * $matches for them on every subject: same answer, same match, same groups.
 * It implies equivalence in partial mode (the same subjects hold a match),
 * yet neither implies nor follows from equivalence in full mode, which
 * compares the strings matched whole: /a|ab/ and /ab|a/ match the same
 * strings, yet on "ab" the first matches "a" and the second "ab"; /a|ab/ and
 * /a/ write the same matches, yet only the first matches "ab" whole.
 *
 * Every verdict is checked against the engine: an equivalence must hold on
 * each string over the patterns' letters up to a length, a difference must
 * show on the string the solver hands back.
 */
final class MatchEquivalenceTest extends TestCase
{
    use PcreGates;

    private const FLAGS = \PREG_OFFSET_CAPTURE | \PREG_UNMATCHED_AS_NULL;

    #[Test]
    #[DataProvider('provideEquivalentPairs')]
    public function test_match_equivalent_patterns_write_the_same_matches_everywhere(string $left, string $right, string $letters): void
    {
        // The "r" modifier is PCRE2 10.43 and read from PHP 8.4: an older
        // engine or an older PHP refuses the flag.
        if ('/k/iur' === $left) {
            $this->requiresPhp('8.4', $left, 'does not read the r modifier');
            $this->requiresPcre2('10.43', $left);
        }

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
        // The "r" modifier is PCRE2 10.43 and read from PHP 8.4: an older
        // engine or an older PHP refuses the flag.
        if ('/k/iur' === $left) {
            $this->requiresPhp('8.4', $left, 'does not read the r modifier');
            $this->requiresPcre2('10.43', $left);
        }

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
    public function test_match_equivalence_misses_what_whole_string_equivalence_sees(): void
    {
        $solver = new LanguageSolver();

        // preg_match() stops at the first alternative that leads to a match:
        // on "ab", /a|ab/ matches "a" as /a/ does, though only /a|ab/ can
        // match "ab" whole.
        $this->assertSame(self::matchesOf('/a|ab/', 'ab'), self::matchesOf('/a/', 'ab'));
        $this->assertTrue($solver->matchEquivalent('/a|ab/', '/a/')->isEquivalent);

        $result = $solver->equivalent('/a|ab/', '/a/');

        $this->assertFalse($result->isEquivalent);
        $this->assertSame('ab', $result->leftOnlyExample);
    }

    #[Test]
    public function test_match_equivalence_implies_partial_mode_equivalence(): void
    {
        $solver = new LanguageSolver();
        $partial = new SolverOptions(matchMode: MatchMode::Partial);

        $this->assertTrue($solver->matchEquivalent('/a|ab/', '/a/')->isEquivalent);
        $this->assertTrue($solver->equivalent('/a|ab/', '/a/', $partial)->isEquivalent);
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

    /**
     * A configuration of a wide alternation holds a thread per branch, so a
     * few hundred of them are already a lot of work: the transition budget
     * bounds the threads stepped, as it bounds the determinization.
     */
    #[Test]
    public function test_the_search_stops_at_its_transition_budget(): void
    {
        $branches = implode('|', array_map(static fn (int $i): string => 'x'.\chr(0x61 + $i % 26).\chr(0x61 + intdiv($i, 26)), range(0, 59)));

        try {
            (new LanguageSolver())->matchEquivalent('/(?:'.$branches.')/', '/(?:'.$branches.'|zz)/', new SolverOptions(maxTransitionsProcessed: 100));
            $this->fail('The search went past its transition budget.');
        } catch (ComplexityException $e) {
            $this->assertSame('match exploration', $e->getDiagnostic()['phase'] ?? null);
            $this->assertSame(100, $e->getDiagnostic()['limit'] ?? null);
        }

        $this->assertFalse((new LanguageSolver())->matchEquivalent('/(?:'.$branches.')/', '/(?:'.$branches.'|zz)/')->isEquivalent, 'The default budget settles it.');
    }

    #[Test]
    public function test_the_automaton_stops_at_its_budget(): void
    {
        $this->expectException(ComplexityException::class);
        $this->expectExceptionMessage('The match solver needs more than 5 states for this pattern.');

        (new LanguageSolver())->matchEquivalent('/a{50}/', '/a{50}/', new SolverOptions(maxNfaStates: 5));
    }

    #[Test]
    public function test_patterns_that_read_alike_are_equivalent_without_a_search(): void
    {
        // [0-9] and \d, [a-zA-Z0-9_] and \w read as the same automaton: no
        // subject has to be tried, and a budget of one configuration does.
        $options = new SolverOptions(maxDfaStates: 1);
        $solver = new LanguageSolver();

        $this->assertTrue($solver->matchEquivalent('/^([0-9]+)-([a-zA-Z0-9_]+)$/', '/^(\d+)-(\w+)$/', $options)->isEquivalent);
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
        yield 'caseless restricted' => ['left' => '/k/iur', 'right' => '/[kK]/u', 'letters' => 'kKa'];
        yield 'nested groups' => ['left' => '/((a)b)/', 'right' => '/((a)b)/', 'letters' => 'ab'];
        yield 'last iteration kept' => ['left' => '/(?:(a)|b)+/', 'right' => '/(?:(a)|b)+/', 'letters' => 'ab'];
        yield 'end of subject or capital Z after dollar' => ['left' => '/a$(?:\z|\Z)/', 'right' => '/a$/', 'letters' => "a\n"];
        yield 'every branch after dollar wants the very end' => ['left' => '/a$(?:\z|\z)/', 'right' => '/a\z/', 'letters' => "a\n"];
        yield 'group closing after an anchor' => ['left' => '/(a$)/', 'right' => '/(a\Z)/', 'letters' => "a\n"];
        yield 'possessive before a letter it never takes' => ['left' => '/a++b/', 'right' => '/a+b/', 'letters' => 'ab'];
        yield 'possessive class before a letter it never takes' => ['left' => '/(\d++)a/', 'right' => '/(\d+)a/', 'letters' => '01a'];
        yield 'possessive counted repeat' => ['left' => '/a{1,3}+b/', 'right' => '/a{1,3}b/', 'letters' => 'ab'];
        yield 'possessive at the end of the pattern' => ['left' => '/(a++)/', 'right' => '/(a+)/', 'letters' => 'ab'];
        yield 'possessive before an optional letter it never takes' => ['left' => '/a++b?/', 'right' => '/a+b?/', 'letters' => 'ab'];
        yield 'possessive before a dollar' => ['left' => '/a++$/', 'right' => '/a+$/', 'letters' => "ab\n"];
        yield 'possessive over a fixed sequence' => ['left' => '/(?:ab)++c/', 'right' => '/(?:ab)+c/', 'letters' => 'abc'];
        yield 'possessive over a sequence of a letter and a class' => ['left' => '/(?:a[bc])++d/', 'right' => '/(?:a[bc])+d/', 'letters' => 'abcd'];
        yield 'possessive over a group' => ['left' => '/(a)++b/', 'right' => '/(a)+b/', 'letters' => 'ab'];
        yield 'possessive before branches that both may be skipped' => ['left' => '/a++(?:b?|c?)d/', 'right' => '/a+(?:b?|c?)d/', 'letters' => 'abcd'];
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
        // "/k/iu" matches the Kelvin sign, "/k/iur" does not.
        yield 'caseless restricted or not' => ['left' => '/k/iur', 'right' => '/k/iu', 'shortest' => "\u{212A}"];
    }

    /**
     * @return iterable<string, array{left: string, right: string, reason: string}>
     */
    public static function provideRefusedPairs(): iterable
    {
        yield 'backreference' => ['left' => '/(a)\1/', 'right' => '/(a)a/', 'reason' => 'Backreferences'];
        yield 'lookahead' => ['left' => '/a(?=b)/', 'right' => '/a/', 'reason' => 'Lookarounds'];
        yield 'atomic group' => ['left' => '/(?>a+)b/', 'right' => '/a+b/', 'reason' => 'Atomic groups and possessive quantifiers'];
        yield 'possessive before what it would give back' => ['left' => '/a++a/', 'right' => '/a+a/', 'reason' => 'Atomic groups and possessive quantifiers'];
        yield 'possessive whose body gives back' => ['left' => '/(?:ab?)++b/', 'right' => '/(?:ab?)+b/', 'reason' => 'Atomic groups and possessive quantifiers'];
        yield 'possessive in a loop that takes it again' => ['left' => '/(?:a++b?)+/', 'right' => '/(?:a+b?)+/', 'reason' => 'Atomic groups and possessive quantifiers'];
        yield 'possessive over a newline before a dollar' => ['left' => '/[a\n]++$/', 'right' => '/[a\n]+$/', 'reason' => 'Atomic groups and possessive quantifiers'];
        yield 'possessive before a start anchor' => ['left' => '/a*+\Ab/', 'right' => '/a*\Ab/', 'reason' => 'Atomic groups and possessive quantifiers'];
        yield 'possessive before an alternation' => ['left' => '/a++(?:a|b)/', 'right' => '/a+(?:a|b)/', 'reason' => 'Atomic groups and possessive quantifiers'];
        yield 'loop on a body that can match empty' => ['left' => '/(a*)*/', 'right' => '/(a*)/', 'reason' => 'A repeated body that can match empty'];
        yield 'word boundary' => ['left' => '/\ba/', 'right' => '/a/', 'reason' => 'Word boundaries'];
        yield 'anchor before more text' => ['left' => '/a$b/', 'right' => '/ab/', 'reason' => 'An end anchor followed by more of the pattern'];
        yield 'anchor before an optional letter' => ['left' => '/a$(?:b|)/', 'right' => '/a/', 'reason' => 'An end anchor followed by more of the pattern'];
        yield 'multiline flag' => ['left' => '/a/m', 'right' => '/a/', 'reason' => 'Unsupported regex flags for the match solver: m.'];
        yield 'utf mode on one side' => ['left' => '/a/u', 'right' => '/a/', 'reason' => 'Both patterns must read the subject the same way'];
        // PHP 8.4.26 / PCRE2 10.49: on "ab\r\n" the left one captures "\r"
        // in $2 and the right one does not match: the dot reads a "\r"
        // alone but not one before a "\n".
        yield 'a dot under (*CRLF)' => ['left' => '/(*CRLF)(.*)(\r?)\n/', 'right' => '/(*CRLF)(.*)()\n/', 'reason' => 'Under (*CRLF)'];
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
