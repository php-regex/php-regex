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
use PHPRegex\Tests\Support\PhpUnitCompat\PhpUnitForwardCompat;
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

    use PhpUnitForwardCompat;

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

    /**
     * Where a row names a witness, the engine writes different matches for
     * the two patterns on it: the refusal stands on a real difference.
     */
    #[Test]
    #[DataProvider('provideRefusedPairs')]
    public function test_patterns_outside_the_readable_fragment_are_refused(string $left, string $right, string $reason, ?string $witness = null): void
    {
        if (null !== $witness) {
            $this->assertNotSame(self::matchesOf($left, $witness), self::matchesOf($right, $witness), \sprintf('%s and %s write the same matches on %s.', $left, $right, json_encode($witness)));
        }

        $this->expectException(ComplexityException::class);
        $this->expectExceptionMessageIsOrContains($reason);

        (new LanguageSolver())->matchEquivalent($left, $right);
    }

    /**
     * PHP 8.4.27 / PCRE2 10.49: preg_match_all() writes an empty group 2
     * on "ac" for the left one, which the right one does not have. The
     * group stands inside a scan, which the tree does not take apart.
     */
    #[Test]
    public function test_a_group_hidden_in_a_body_repeated_no_time_is_refused(): void
    {
        $left = '/(a)(?:(*scs:(1)(b))){0}c/';
        $this->requiresPcre2('10.45', $left);
        $this->assertSame(1, preg_match_all($left, 'ac', $matches));
        $this->assertSame([['ac'], ['a'], ['']], $matches);

        $this->expectException(ComplexityException::class);
        $this->expectExceptionMessageIsOrContains('Backreferences, subroutines, conditionals, callouts and control verbs');

        (new LanguageSolver())->matchEquivalent($left, '/(a)c/');
    }

    #[Test]
    public function test_the_search_stops_at_its_budget(): void
    {
        $this->expectException(ComplexityException::class);
        $this->expectExceptionMessageIsOrContains('The match solver explored more than 2 configurations for these patterns.');

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
    public function test_end_anchor_validation_visits_shared_empty_continuations_once(): void
    {
        // Only thirty splits, but walking each path separately visits over
        // a billion continuations before the comparison budget even starts.
        $pattern = '/$'.str_repeat('(?:|)', 30).'/';
        foreach (self::subjects("a\n", 3) as $subject) {
            $this->assertSame(self::matchesOf('/$/', $subject), self::matchesOf($pattern, $subject), \sprintf('The engine tells them apart on %s.', json_encode($subject)));
        }

        $this->assertTrue((new LanguageSolver())->matchEquivalent($pattern, '/$/')->isEquivalent);
    }

    #[Test]
    public function test_the_automaton_stops_at_its_budget(): void
    {
        $this->expectException(ComplexityException::class);
        $this->expectExceptionMessageIsOrContains('The match solver needs more than 5 states for this pattern.');

        (new LanguageSolver())->matchEquivalent('/a{50}/', '/a{50}/', new SolverOptions(maxNfaStates: 5));
    }

    #[Test]
    #[DataProvider('provideStateBudgetPatterns')]
    public function test_every_state_allocation_obeys_the_budget(string $pattern, string $subject, int $states): void
    {
        // PCRE compiles the pattern and matches the subject.
        $this->assertSame(1, preg_match($pattern, $subject));
        $solver = new LanguageSolver();
        $this->assertTrue($solver->matchEquivalent($pattern, $pattern, new SolverOptions(maxNfaStates: $states))->isEquivalent);

        $this->expectException(ComplexityException::class);
        $this->expectExceptionMessageIsOrContains('The match solver needs more than');
        $solver->matchEquivalent($pattern, $pattern, new SolverOptions(maxNfaStates: $states - 1));
    }

    /**
     * @return iterable<string, array{pattern: string, subject: string, states: int}>
     */
    public static function provideStateBudgetPatterns(): iterable
    {
        yield 'literal' => ['pattern' => '/abcdef/', 'subject' => 'abcdef', 'states' => 7];
        yield 'split' => ['pattern' => '/a|b/', 'subject' => 'b', 'states' => 4];
        yield 'capture tags' => ['pattern' => '/(a)/', 'subject' => 'a', 'states' => 4];
        yield 'anchor' => ['pattern' => '/^a/', 'subject' => 'ab', 'states' => 3];
    }

    #[Test]
    public function test_large_unicode_literal_stops_at_the_default_state_budget(): void
    {
        $literal = implode('', array_map(static fn (int $code): string => mb_chr($code, 'UTF-8'), range(0x1000, 0x1000 + 14999)));
        $this->assertSame(1, preg_match('/'.$literal.'/u', $literal));
        $this->assertSame(0, preg_match('/'.$literal.'/u', 'b'));

        $this->expectException(ComplexityException::class);
        $this->expectExceptionMessageIsOrContains('The match solver needs more than 5000 states');
        (new LanguageSolver())->matchEquivalent('/'.$literal.'/u', '/b/u');
    }

    #[Test]
    public function test_unicode_alphabet_construction_obeys_the_work_budget(): void
    {
        $literal = implode('', array_map(static fn (int $code): string => mb_chr($code, 'UTF-8'), range(0x1000, 0x13FF)));
        $this->assertSame(1, preg_match('/'.$literal.'/u', $literal));
        $this->assertSame(0, preg_match('/'.$literal.'/u', 'b'));

        $this->expectException(ComplexityException::class);
        $this->expectExceptionMessageIsOrContains('work budget exceeded during match exploration');
        (new LanguageSolver())->matchEquivalent('/'.$literal.'/u', '/b/u', new SolverOptions(maxDfaStates: 1));
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
        // A group repeated no time still takes its number and its name:
        // preg_match_all() writes it empty, PREG_UNMATCHED_AS_NULL null.
        yield 'a named group repeated no time' => ['left' => '/(?<x>a){0}b/', 'right' => '/b/', 'shortest' => 'b'];
        yield 'a group repeated zero to zero times' => ['left' => '/(a){0,0}b/', 'right' => '/b/', 'shortest' => 'b'];
        yield 'a group inside a group repeated no time' => ['left' => '/(?:c(a)){0}b/', 'right' => '/b/', 'shortest' => 'b'];
        yield 'a group repeated no time, another name' => ['left' => '/(?<x>a){0}b/', 'right' => '/(?<y>a){0}b/', 'shortest' => 'b'];
        yield 'dollar and end of subject' => ['left' => '/^ab$/', 'right' => '/^ab\z/', 'shortest' => "ab\n"];
        yield 'group moves' => ['left' => '/a(b)c/', 'right' => '/(a)bc/', 'shortest' => 'abc'];
        yield 'language differs' => ['left' => '/ab/', 'right' => '/ac/', 'shortest' => 'ab'];
        yield 'empty match against none' => ['left' => '/a*/', 'right' => '/a+/', 'shortest' => ''];
        // "/k/iu" matches the Kelvin sign, "/k/iur" does not.
        yield 'caseless restricted or not' => ['left' => '/k/iur', 'right' => '/k/iu', 'shortest' => "\u{212A}"];
    }

    /**
     * @return iterable<string, array{left: string, right: string, reason: string, witness?: string}>
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
        // PHP 8.4.27 / PCRE2 10.49: on "\tb" the left one of each of the
        // first four finds no match, the right one "b": PCRE2 reads a group
        // repeated no time to choose where it tries a match.
        yield 'a lookahead in a group repeated no time' => ['left' => '/(?:x|(?=a)){0}\w/', 'right' => '/\w/', 'reason' => 'in a group repeated no time still moves where PCRE2 tries a match'];
        yield 'a start anchor in a group repeated no time' => ['left' => '/(?:x|^){0}b/', 'right' => '/b/', 'reason' => 'in a group repeated no time still moves where PCRE2 tries a match'];
        yield 'a subject start in a group repeated zero to zero times' => ['left' => '/(?:x|\A){0,0}b/', 'right' => '/b/', 'reason' => 'in a group repeated no time still moves where PCRE2 tries a match'];
        yield 'a lookahead in a group repeated no time, in a capture' => ['left' => '/((?:x|(?=a)){0})\w/', 'right' => '/()\w/', 'reason' => 'in a group repeated no time still moves where PCRE2 tries a match'];
        yield 'a callout in a group repeated no time' => ['left' => '/(?:x|(?C1)){0}b/', 'right' => '/b/', 'reason' => 'Backreferences, subroutines, conditionals, callouts and control verbs'];
        yield 'a conditional in a group repeated no time' => ['left' => '/(?:x|(?(?=a)a|b)){0}c/', 'right' => '/c/', 'reason' => 'Backreferences, subroutines, conditionals, callouts and control verbs'];
        yield 'anchor before more text' => ['left' => '/a$b/', 'right' => '/ab/', 'reason' => 'An end anchor followed by more of the pattern'];
        yield 'anchor before an optional letter' => ['left' => '/a$(?:b|)/', 'right' => '/a/', 'reason' => 'An end anchor followed by more of the pattern'];
        // Two reasons hold: the anchor is refused where it is read, the
        // possessive only once the whole pattern is built.
        yield 'anchor before more text, after a possessive that gives back' => ['left' => '/a++a$b/', 'right' => '/ab/', 'reason' => 'An end anchor followed by more of the pattern'];
        // PHP 8.4.27 / PCRE2 10.49: on "a\n" the left one matches "a\n" at
        // 0, the right one "\n" at 1. A loop that may be skipped reads what
        // follows it after its body as a loop that may not.
        yield 'anchor in a loop of at least one' => ['left' => '/(?:a$)+\n/', 'right' => '/\n/', 'reason' => 'An end anchor followed by more of the pattern'];
        yield 'anchor in a loop of any count' => ['left' => '/(?:a$)*\n/', 'right' => '/\n/', 'reason' => 'An end anchor followed by more of the pattern'];
        yield 'anchor in a lazy loop of any count' => ['left' => '/(?:a$)*?\n/', 'right' => '/\n/', 'reason' => 'An end anchor followed by more of the pattern'];
        yield 'anchor in an open count from zero' => ['left' => '/(?:a$){0,}\n/', 'right' => '/\n/', 'reason' => 'An end anchor followed by more of the pattern'];
        yield 'anchor in a loop that iterates again' => ['left' => '/(?:a$)*/', 'right' => '/(?:a$)?/', 'reason' => 'An end anchor followed by more of the pattern'];
        yield 'letter on a sibling after shared empty branches' => ['left' => '/$(?:'.str_repeat('(?:|)', 30).'|a)/', 'right' => '/$/', 'reason' => 'An end anchor followed by more of the pattern'];
        yield 'start anchor after empty branches' => ['left' => '/$'.str_repeat('(?:|)', 30).'\\A/', 'right' => '/$/', 'reason' => 'An end anchor followed by more of the pattern'];
        yield 'multiline flag' => ['left' => '/a/m', 'right' => '/a/', 'reason' => 'Unsupported regex flags for the match solver: m.'];
        yield 'utf mode on one side' => ['left' => '/a/u', 'right' => '/a/', 'reason' => 'Both patterns must read the subject the same way'];
        // On "ab\r\n" the left one captures "\r" in $2 and the right one
        // does not match: the dot reads a "\r" alone but not one before a
        // "\n".
        yield 'a dot under (*CRLF)' => ['left' => '/(*CRLF)(.*)(\r?)\n/', 'right' => '/(*CRLF)(.*)()\n/', 'reason' => 'Under (*CRLF)', 'witness' => "ab\r\n"];
        // "$" and "\Z" stand before the final newline the convention sets.
        // On "a\n" the left one finds no match under (*CR) and (*NUL), the
        // right one "a"; on "a\r" the left one finds "a" under (*ANYCRLF)
        // and (*ANY), the right one nothing; on "a\r" the lazy loop matches
        // "a", the greedy one "a\r".
        $newline = 'A newline convention other than "\n" moves where';
        yield 'a dollar under (*CR)' => ['left' => '/(*CR)a$/', 'right' => '/a$/', 'reason' => $newline, 'witness' => "a\n"];
        yield 'a dollar under (*NUL)' => ['left' => '/(*NUL)a$/', 'right' => '/a$/', 'reason' => $newline, 'witness' => "a\n"];
        yield 'a dollar under (*ANYCRLF)' => ['left' => '/(*ANYCRLF)a$/', 'right' => '/a$/', 'reason' => $newline, 'witness' => "a\r"];
        yield 'a dollar under (*ANY)' => ['left' => '/(*ANY)a$/', 'right' => '/a$/', 'reason' => $newline, 'witness' => "a\r"];
        yield 'a capital Z under (*CR)' => ['left' => '/(*CR)a\Z/', 'right' => '/a\Z/', 'reason' => $newline, 'witness' => "a\n"];
        yield 'a dollar under (*CR) on the right' => ['left' => '/a$/', 'right' => '/(*CR)a$/', 'reason' => $newline, 'witness' => "a\n"];
        yield 'a dollar under (*CR) after another option' => ['left' => '/(*UTF)(*CR)a$/', 'right' => '/(*UTF)a$/', 'reason' => $newline, 'witness' => "a\n"];
        yield 'a lazy loop before a dollar under (*CR)' => ['left' => '/(*CR)a\r*?(?:$|b)/', 'right' => '/(*CR)a\r*(?:$|b)/', 'reason' => $newline, 'witness' => "a\r"];
        // On "b", without the JIT, the left one finds no match, or "" at 1
        // under (*NOTEMPTY_ATSTART), the right one "" at 0. The JIT ignores
        // the option, so the rows that leave the JIT on differ only under
        // pcre.jit=0 (SolverOnHirRefusalsTest runs both).
        $notEmpty = '(*NOTEMPTY) and (*NOTEMPTY_ATSTART) refuse';
        yield 'not empty without the JIT' => ['left' => '/(*NO_JIT)(*NOTEMPTY)a?/', 'right' => '/a?/', 'reason' => $notEmpty, 'witness' => 'b'];
        yield 'not empty at start without the JIT' => ['left' => '/(*NO_JIT)(*NOTEMPTY_ATSTART)a?/', 'right' => '/a?/', 'reason' => $notEmpty, 'witness' => 'b'];
        yield 'not empty' => ['left' => '/(*NOTEMPTY)a?/', 'right' => '/a?/', 'reason' => $notEmpty];
        yield 'not empty at start' => ['left' => '/(*NOTEMPTY_ATSTART)a?/', 'right' => '/a?/', 'reason' => $notEmpty];
        yield 'not empty on the right' => ['left' => '/a/', 'right' => '/(*NOTEMPTY)a/', 'reason' => $notEmpty];
    }

    /**
     * The newline a convention sets is read where no "$" or "\Z" reads it,
     * and the last convention the options name is the one that holds:
     * "/(*CR)(*LF)a$/" finds "a" in "a\n". The engine answers first: the
     * two patterns write the same matches on every string over "a", "b",
     * "\r" and "\n" up to a length exactly where they are equivalent, and
     * a difference the solver hands back shows there too.
     */
    #[Test]
    #[DataProvider('provideNewlineConventionsLeftUnread')]
    public function test_a_newline_convention_no_end_anchor_reads_is_answered(string $left, string $right, bool $equivalent): void
    {
        $alike = true;
        foreach (self::subjects("ab\r\n", 4) as $subject) {
            $alike = $alike && self::matchesOf($left, $subject) === self::matchesOf($right, $subject);
        }
        $this->assertSame($equivalent, $alike, \sprintf('%s and %s.', $left, $right));

        $result = (new LanguageSolver())->matchEquivalent($left, $right);

        $this->assertSame($equivalent, $result->isEquivalent);
        if (null !== $result->counterExample) {
            $this->assertNotSame(self::matchesOf($left, $result->counterExample), self::matchesOf($right, $result->counterExample));
        }
    }

    /**
     * @return iterable<string, array{left: string, right: string, equivalent: bool}>
     */
    public static function provideNewlineConventionsLeftUnread(): iterable
    {
        yield 'no end anchor' => ['left' => '/(*CR)ab/', 'right' => '/ab/', 'equivalent' => true];
        yield 'a dot' => ['left' => '/(*CR)a./', 'right' => '/a./', 'equivalent' => false];
        yield 'an end of subject' => ['left' => '/(*CR)a\z/', 'right' => '/a\z/', 'equivalent' => true];
        yield 'a line feed named last' => ['left' => '/(*CR)(*LF)a$/', 'right' => '/a$/', 'equivalent' => true];
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
