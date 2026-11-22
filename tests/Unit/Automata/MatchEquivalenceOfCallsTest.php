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
use PHPRegex\Tests\Support\PcreGates;
use PHPRegex\Tests\Support\PhpUnitCompat\PhpUnitForwardCompat;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A call that runs a group, "(?1)", "(?-1)", "(?+1)", "(?&n)", "(?P>n)" or
 * "\g<n>", runs the body of the group where the call stands. From PCRE2
 * 10.30, older than any release PHP bundles, the engine backtracks into it:
 * "/(a|ab)(?1)c/" matches "aabc", the call giving "a" up for "ab". The
 * groups inside the body are given back the values they had before the
 * call, and the body keeps the options it was written under: "/(a)(?i)(?1)/"
 * finds nothing in "aA".
 *
 * A call that may run its own group again, a call of the whole pattern, a
 * call of a group the normal form leaves out, as a DEFINE holds it, and a
 * call that returns groups stay refused.
 *
 * Checked on PHP 8.4.28 with PCRE2 10.49.
 */
final class MatchEquivalenceOfCallsTest extends TestCase
{
    use PcreGates;

    use PhpUnitForwardCompat;

    private const FLAGS = \PREG_OFFSET_CAPTURE | \PREG_UNMATCHED_AS_NULL;

    /**
     * The rows that hold from a PCRE2 newer than the floor: a call that
     * returns groups is PCRE2 10.47 syntax.
     */
    private const NEWER_RELEASES = ['10.47' => ['/((a))c(?1(2))/']];

    #[Test]
    #[DataProvider('provideCallsReadInPlace')]
    public function test_a_call_runs_the_body_of_its_group_where_it_stands(string $left, string $right, string $letters): void
    {
        foreach (self::subjects($letters, 6) as $subject) {
            // One packaged engine keeps, without the JIT, what a call
            // captured: the rows stand on PCRE2 as released.
            if (self::matchesOf($left, $subject) !== self::matchesOf($right, $subject)) {
                $this->requiresCallCapturesGivenBack($left);
            }

            $this->assertSame(self::matchesOf($left, $subject), self::matchesOf($right, $subject), \sprintf('Oracle: %s and %s differ on %s.', $left, $right, json_encode($subject)));
        }

        $result = (new LanguageSolver())->matchEquivalent($left, $right);

        $this->assertTrue($result->isEquivalent, \sprintf('%s and %s write the same matches, yet %s was handed back.', $left, $right, json_encode($result->counterExample)));
        $this->assertNull($result->counterExample);
    }

    /**
     * @return iterable<string, array{left: string, right: string, letters: string}>
     */
    public static function provideCallsReadInPlace(): iterable
    {
        // Nothing the call or the group is followed by starts with "a": the
        // possessive gives back nothing wherever the group runs.
        yield 'a possessive in a group called by number' => ['left' => '/(a+b?)c(?1)d/', 'right' => '/(a++b?)c(?1)d/', 'letters' => 'abcd'];
        yield 'a possessive in a group called back' => ['left' => '/(a+b?)c(?-1)d/', 'right' => '/(a++b?)c(?-1)d/', 'letters' => 'abcd'];
        yield 'a possessive in a group called forward' => ['left' => '/(?+1)c(a+b?)d/', 'right' => '/(?+1)c(a++b?)d/', 'letters' => 'abcd'];
        yield 'a possessive in a group called by name' => ['left' => '/(?<n>a+b?)c(?&n)d/', 'right' => '/(?<n>a++b?)c(?&n)d/', 'letters' => 'abcd'];
        yield 'a possessive in a group named R' => ['left' => '/(?<R>a+b?)c(?&R)d/', 'right' => '/(?<R>a++b?)c(?&R)d/', 'letters' => 'abcd'];
        yield 'a possessive in a group called with \g' => ['left' => '/(?<n>a+b?)c\g<n>d/', 'right' => '/(?<n>a++b?)c\g<n>d/', 'letters' => 'abcd'];
        yield 'a possessive in a group called the Python way' => ['left' => '/(?<n>a+b?)c(?P>n)d/', 'right' => '/(?<n>a++b?)c(?P>n)d/', 'letters' => 'abcd'];
        yield 'a possessive in a group called in a loop' => ['left' => '/(a+b?)(?:c(?1))+d/', 'right' => '/(a++b?)(?:c(?1))+d/', 'letters' => 'abcd'];
        // "aabc": the call gives "a" up for "ab", as its body spelled in place does.
        yield 'a call the engine backtracks into' => ['left' => '/(a|ab)(?1)c/', 'right' => '/(a|ab)(?:a|ab)c/', 'letters' => 'abc'];
        yield 'a group called twice' => ['left' => '/(a)(?1){2}/', 'right' => '/(a)aa/', 'letters' => 'ab'];
        yield 'a group called any number of times' => ['left' => '/(ab)c(?1)*/', 'right' => '/(ab)c(?:ab)*/', 'letters' => 'abc'];
        // A group repeated no time runs only where a call runs it.
        yield 'a possessive in a group repeated no time' => ['left' => '/(?:(a+b?)){0}c(?1)d/', 'right' => '/(?:(a++b?)){0}c(?1)d/', 'letters' => 'abcd'];
        yield 'a call of a group that calls another' => ['left' => '/((a)b)(?1)(?2)/', 'right' => '/((a)b)aba/', 'letters' => 'ab'];
        yield 'a call of the first group a number names in a branch reset' => ['left' => '/(?|(a)|(b))(?1)/', 'right' => '/(?|(a)|(b))a/', 'letters' => 'ab'];
        yield 'a call under the options of its group' => ['left' => '/(a)(?i)(?1)/', 'right' => '/(a)(?i)(?-i:a)/', 'letters' => 'aAb'];
        // The call writes nothing in the groups it runs: they are given
        // back what they held.
        yield 'a call of a group' => ['left' => '/(a|b)c(?1)/', 'right' => '/(a|b)c[ab]/', 'letters' => 'abc'];
        yield 'a call of a group holding another' => ['left' => '/((a)|b)c(?1)/', 'right' => '/((a)|b)c[ab]/', 'letters' => 'abc'];
    }

    #[Test]
    #[DataProvider('provideCallsThatWriteOtherMatches')]
    public function test_the_counter_example_shows_what_a_call_changes(string $left, string $right, string $shortest): void
    {
        $this->assertNotSame(self::matchesOf($left, $shortest), self::matchesOf($right, $shortest), \sprintf('Oracle: %s and %s write the same matches on %s.', $left, $right, json_encode($shortest)));

        $result = (new LanguageSolver())->matchEquivalent($left, $right);

        $this->assertFalse($result->isEquivalent);
        $this->assertSame($shortest, $result->counterExample);
    }

    /**
     * @return iterable<string, array{left: string, right: string, shortest: string}>
     */
    public static function provideCallsThatWriteOtherMatches(): iterable
    {
        yield 'a call that gives up a branch' => ['left' => '/(a|ab)(?1)c/', 'right' => '/(a|ab)ac/', 'shortest' => 'aabc'];
        yield 'a call against the group spelled again' => ['left' => '/(a)c(?1)/', 'right' => '/(a)c(a)/', 'shortest' => 'aca'];
        yield 'a call under the options of its group, not of where it stands' => ['left' => '/(a)(?i)(?1)/', 'right' => '/(a)(?i)a/', 'shortest' => 'aA'];
    }

    /**
     * Where a row names a witness, the engine writes different matches for
     * the two patterns on it: the refusal stands on a real difference.
     */
    #[Test]
    #[DataProvider('provideCallsNotRead')]
    public function test_a_call_the_solver_does_not_run_in_place_is_refused(string $left, string $right, string $reason, ?string $witness = null): void
    {
        $this->requiresPcre2For($left, self::NEWER_RELEASES);

        if (null !== $witness) {
            $this->assertNotSame(self::matchesOf($left, $witness), self::matchesOf($right, $witness), \sprintf('Oracle: %s and %s write the same matches on %s.', $left, $right, json_encode($witness)));
        }

        $this->expectException(ComplexityException::class);
        $this->expectExceptionMessageIsOrContains($reason);

        (new LanguageSolver())->matchEquivalent($left, $right);
    }

    /**
     * @return iterable<string, array{left: string, right: string, reason: string, witness?: string}>
     */
    public static function provideCallsNotRead(): iterable
    {
        $call = 'A call that may run its own group again, of a group a DEFINE holds, or that returns groups';
        $possessive = 'Atomic groups and possessive quantifiers cut the paths PCRE would try';

        // The possessive gives nothing back where the group stands, before
        // "c", and is read again where the call runs it: there the engine
        // backtracks into the call, and gives an "a" back to what follows.
        yield 'a possessive in a group called before what it gives back' => ['left' => '/(a++b?)c(?1)a/', 'right' => '/(a+b?)c(?1)a/', 'reason' => $possessive, 'witness' => 'acaa'];
        // The second run of the call needs an "a" the first one took.
        yield 'a possessive in a group called twice' => ['left' => '/(a++b?)c(?1){2}d/', 'right' => '/(a+b?)c(?1){2}d/', 'reason' => $possessive, 'witness' => 'acaad'];
        yield 'a group calling itself' => ['left' => '/(a(?1)?b)/', 'right' => '/(a(?:ab)?b)/', 'reason' => $call, 'witness' => 'aaabbb'];
        yield 'two groups calling each other' => ['left' => '/(a(?2)?)(b(?1)?)/', 'right' => '/(a(?:b)?)(b(?:a)?)/', 'reason' => $call, 'witness' => 'abbab'];
        yield 'a group calling itself through a group it calls' => ['left' => '/(a(?2)?)c(b(?-2))/', 'right' => '/(a(?:ba)?)c(ba)/', 'reason' => $call, 'witness' => 'acbaba'];
        yield 'the whole pattern' => ['left' => '/a(?R)?b/', 'right' => '/a(?:ab)?b/', 'reason' => $call, 'witness' => 'aaabbb'];
        yield 'the whole pattern by number' => ['left' => '/a(?0)?b/', 'right' => '/a(?:ab)?b/', 'reason' => $call, 'witness' => 'aaabbb'];
        yield 'the whole pattern with \g' => ['left' => '/a\g<0>?b/', 'right' => '/a(?:ab)?b/', 'reason' => $call, 'witness' => 'aaabbb'];
        // "(?R)" calls the whole pattern even where a group is named R.
        yield 'the whole pattern beside a group named R' => ['left' => '/(?<R>x)?a(?R)?b/', 'right' => '/(?<R>x)?a(?:x)?b/', 'reason' => $call, 'witness' => 'aabb'];
        yield 'a group a DEFINE holds' => ['left' => '/(?&n)c(?(DEFINE)(?<n>a+b?))/', 'right' => '/a+b?c/', 'reason' => $call];
        // Group 2 keeps what the call matched: "a" at 2.
        yield 'a call that returns a group inside it' => ['left' => '/((a))c(?1(2))/', 'right' => '/((a))ca/', 'reason' => $call, 'witness' => 'aca'];
        // A call matches what the body of its group matches: one that may
        // match nothing stops the loop after an empty turn.
        yield 'a loop over a call of a group that may match nothing' => ['left' => '/(a?)c(?1)+/', 'right' => '/(a?)c(?:a?)+/', 'reason' => 'A repeated body that can match empty'];
        // A back reference may match nothing as well: it is no call.
        yield 'a loop over a back reference' => ['left' => '/(a)\1+b/', 'right' => '/(a)a+b/', 'reason' => 'A repeated body that can match empty'];
        // A group a call runs is read where it stands too.
        yield 'a group holding a backreference' => ['left' => '/(a)(b\1)(?2)/', 'right' => '/(a)(b\1)ba/', 'reason' => 'Backreferences, subroutines, conditionals, callouts and control verbs'];
        yield 'a group inside a lookahead' => ['left' => '/(?=(a))a(?1)/', 'right' => '/(?=(a))aa/', 'reason' => 'Lookarounds'];
    }

    /**
     * @return array<array-key, mixed>|false
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
