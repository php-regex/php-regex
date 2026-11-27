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
use PHPRegex\Automata\Match\PriorityNfaBuilder;
use PHPRegex\Tests\Support\PcreGates;
use PHPRegex\Tests\Support\PhpUnitCompat\PhpUnitForwardCompat;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The match solver reads a lookaround without running it: two patterns that
 * hold one are compared as built, and a possessive quantifier beside one is
 * checked against what may follow it. Where it gave back, a lookahead reads
 * the character it gave back, a negative lookahead or a lookbehind may hold
 * where it did not after the last iteration, and the end of a lookbehind or
 * of a non-atomic lookaround is followed by nothing the solver knows.
 */
final class PossessiveBesideLookaroundTest extends TestCase
{
    use PcreGates;

    use PhpUnitForwardCompat;

    /**
     * The characters of the subjects each pair is run on, every string of
     * them up to five long.
     */
    private const ALPHABET = ['a', 'b', 'c', 'x'];

    #[Test]
    #[DataProvider('providePossessivesThatGiveNothingBack')]
    public function test_a_possessive_that_gives_nothing_back_beside_a_lookaround_is_proven(string $greedy, string $possessive): void
    {
        $this->requiresCompile($possessive);

        foreach (self::subjects() as $subject) {
            $this->assertSame(self::written($greedy, $subject), self::written($possessive, $subject), \sprintf('Oracle: %s and %s differ on "%s".', $greedy, $possessive, $subject));
            $this->assertSame(self::written(self::interpreted($greedy), $subject), self::written(self::interpreted($possessive), $subject), \sprintf('Oracle: %s and %s differ on "%s" without the JIT.', $greedy, $possessive, $subject));
        }

        $solver = new LanguageSolver();

        $this->assertTrue($solver->matchEquivalent($greedy, $possessive)->isEquivalent);
        $this->assertTrue($solver->matchEquivalent($possessive, $greedy)->isEquivalent);
    }

    /**
     * @return iterable<string, array{greedy: string, possessive: string}>
     */
    public static function providePossessivesThatGiveNothingBack(): iterable
    {
        yield 'after a lookahead' => ['greedy' => '/(?=x)xa+b/', 'possessive' => '/(?=x)xa++b/'];
        yield 'after a negative lookahead' => ['greedy' => '/(?!c)xa+b/', 'possessive' => '/(?!c)xa++b/'];
        yield 'after a lookbehind' => ['greedy' => '/(?<=x)a+b/', 'possessive' => '/(?<=x)a++b/'];
        yield 'after a negative lookbehind' => ['greedy' => '/(?<!x)a+b/', 'possessive' => '/(?<!x)a++b/'];
        yield 'after a non-atomic lookahead' => ['greedy' => '/(*napla:x)xa+b/', 'possessive' => '/(*napla:x)xa++b/'];
        yield 'after a non-atomic lookahead, its short form' => ['greedy' => '/(?*x)xa+b/', 'possessive' => '/(?*x)xa++b/'];
        yield 'after a non-atomic lookbehind' => ['greedy' => '/(*naplb:x)a+b/', 'possessive' => '/(*naplb:x)a++b/'];
        yield 'before a lookahead that reads another character' => ['greedy' => '/a+(?=b)/', 'possessive' => '/a++(?=b)/'];
        yield 'before a lookahead and what it reads' => ['greedy' => '/xa+(?=b)b/', 'possessive' => '/xa++(?=b)b/'];
        yield 'before a non-atomic lookahead that reads another character' => ['greedy' => '/a+(*napla:b)/', 'possessive' => '/a++(*napla:b)/'];
        yield 'before a lookahead that may read nothing, then the end' => ['greedy' => '/a+(?=b?)/', 'possessive' => '/a++(?=b?)/'];
        yield 'before a lookahead of the end of the subject' => ['greedy' => '/a+(?=$)/', 'possessive' => '/a++(?=$)/'];
        yield 'before a lookahead in a lookahead' => ['greedy' => '/a+(?=(?=b)b)/', 'possessive' => '/a++(?=(?=b)b)/'];
        yield 'before a negative lookahead, then another character' => ['greedy' => '/a+(?!c)b/', 'possessive' => '/a++(?!c)b/'];
        yield 'before a lookbehind, then another character' => ['greedy' => '/[ab]+(?<=a)c/', 'possessive' => '/[ab]++(?<=a)c/'];
        yield 'before a negative lookbehind, then another character' => ['greedy' => '/a+(?<!aa)b/', 'possessive' => '/a++(?<!aa)b/'];
        yield 'before a lookahead that may read nothing and a negative one, then another character' => ['greedy' => '/a+(?=b?)(?!c)b/', 'possessive' => '/a++(?=b?)(?!c)b/'];
        yield 'before a non-atomic lookahead that may read nothing, then another character' => ['greedy' => '/a+(*napla:b?)b/', 'possessive' => '/a++(*napla:b?)b/'];
        yield 'at the end of a lookahead' => ['greedy' => '/(?=xa+)x/', 'possessive' => '/(?=xa++)x/'];
        yield 'at the end of a lookahead, in a group' => ['greedy' => '/(?=x(a+))x/', 'possessive' => '/(?=x(a++))x/'];
        yield 'at the end of a negative lookahead' => ['greedy' => '/(?!xa+b?)x/', 'possessive' => '/(?!xa++b?)x/'];
        yield 'in a lookahead, before another character' => ['greedy' => '/(?=xa+b)x/', 'possessive' => '/(?=xa++b)x/'];
        yield 'in a lookahead, before the end of the subject' => ['greedy' => '/(?=a+$)a/', 'possessive' => '/(?=a++$)a/'];
        yield 'in a non-atomic lookahead, before another character' => ['greedy' => '/(*napla:xa+b)x/', 'possessive' => '/(*napla:xa++b)x/'];
        yield 'in a lookbehind, before another character' => ['greedy' => '/(?<=xa{1,3}b)c/', 'possessive' => '/(?<=xa{1,3}+b)c/'];
        yield 'at the end of a lookahead in a lookbehind' => ['greedy' => '/(?<=x(?=a+))a/', 'possessive' => '/(?<=x(?=a++))a/'];
    }

    #[Test]
    #[DataProvider('providePossessivesThatGiveBack')]
    public function test_a_possessive_that_gives_back_beside_a_lookaround_is_refused(string $greedy, string $possessive, string $witness): void
    {
        $this->requiresCompile($possessive);

        // The interpreter alone: PCRE2's JIT reads the subject as ending
        // where a lookbehind stands.
        $this->assertNotSame(self::written(self::interpreted($greedy), $witness), self::written(self::interpreted($possessive), $witness), \sprintf('Oracle: %s and %s match alike on "%s".', $greedy, $possessive, $witness));

        $this->expectException(ComplexityException::class);
        $this->expectExceptionMessageIsOrContains(PriorityNfaBuilder::ATOMIC_MESSAGE);

        (new LanguageSolver())->matchEquivalent($greedy, $possessive);
    }

    /**
     * @return iterable<string, array{greedy: string, possessive: string, witness: string}>
     */
    public static function providePossessivesThatGiveBack(): iterable
    {
        yield 'before a lookahead that reads its character' => ['greedy' => '/a+(?=a)/', 'possessive' => '/a++(?=a)/', 'witness' => 'aa'];
        yield 'before a lookahead that reads its character, then another' => ['greedy' => '/a+(?=a)[ab]b/', 'possessive' => '/a++(?=a)[ab]b/', 'witness' => 'aab'];
        yield 'before a lookahead in a lookahead that reads its character' => ['greedy' => '/a+(?=(?=a))/', 'possessive' => '/a++(?=(?=a))/', 'witness' => 'aa'];
        yield 'before a non-atomic lookahead that reads its character' => ['greedy' => '/a+(*napla:a)/', 'possessive' => '/a++(*napla:a)/', 'witness' => 'aa'];
        yield 'before a negative lookahead, then the end' => ['greedy' => '/a+(?!b)/', 'possessive' => '/a++(?!b)/', 'witness' => 'aab'];
        yield 'before a negative lookahead in a lookahead, then the end' => ['greedy' => '/a+(?=(?!b))/', 'possessive' => '/a++(?=(?!b))/', 'witness' => 'aab'];
        yield 'before a negative lookahead, then its character' => ['greedy' => '/a+(?!b)a/', 'possessive' => '/a++(?!b)a/', 'witness' => 'aa'];
        yield 'before a lookbehind, then the end' => ['greedy' => '/[ab]+(?<=a)/', 'possessive' => '/[ab]++(?<=a)/', 'witness' => 'ab'];
        yield 'before a negative lookbehind, then the end' => ['greedy' => '/a+(?<!aa)/', 'possessive' => '/a++(?<!aa)/', 'witness' => 'aa'];
        yield 'before a non-atomic lookbehind, then the end' => ['greedy' => '/[ab]+(*naplb:a)/', 'possessive' => '/[ab]++(*naplb:a)/', 'witness' => 'ab'];
        yield 'before a lookahead of the start of the subject' => ['greedy' => '/a*(?=\A)/', 'possessive' => '/a*+(?=\A)/', 'witness' => 'a'];
        yield 'before a negative lookahead, in a lookahead' => ['greedy' => '/(?=a+(?!b))a/', 'possessive' => '/(?=a++(?!b))a/', 'witness' => 'aab'];
        yield 'at the end of a lookbehind' => ['greedy' => '/(?<=xa{1,3})a/', 'possessive' => '/(?<=xa{1,3}+)a/', 'witness' => 'xaaaa'];
        yield 'at the end of a negative lookbehind' => ['greedy' => '/(?<!xa{1,3})ab/', 'possessive' => '/(?<!xa{1,3}+)ab/', 'witness' => 'xaab'];
    }

    /**
     * Past the end of a non-atomic lookaround the engine reads what follows
     * from where the lookaround stands, and backtracks into its body: a
     * reference reads the "a" group 1 gave back on "xaaba". The solver reads
     * no reference, and knows nothing of what follows such an end.
     */
    #[Test]
    #[DataProvider('providePossessivesEndingANonAtomicLookaround')]
    public function test_a_possessive_ending_a_non_atomic_lookaround_is_refused(string $greedy, string $possessive): void
    {
        $this->assertSame(1, preg_match('/^(*napla:x(a+b?))x[ab-]*\1$/', 'xaaba'), 'Oracle: the greedy repeat gives an "a" back to the reference.');
        $this->assertSame(0, preg_match('/^(*napla:x(a++b?))x[ab-]*\1$/', 'xaaba'), 'Oracle: the possessive repeat keeps it.');

        foreach (self::subjects() as $subject) {
            $this->assertSame(self::written($greedy, $subject), self::written($possessive, $subject), \sprintf('Oracle: %s and %s differ on "%s".', $greedy, $possessive, $subject));
        }

        $this->expectException(ComplexityException::class);
        $this->expectExceptionMessageIsOrContains(PriorityNfaBuilder::ATOMIC_MESSAGE);

        (new LanguageSolver())->matchEquivalent($greedy, $possessive);
    }

    /**
     * @return iterable<string, array{greedy: string, possessive: string}>
     */
    public static function providePossessivesEndingANonAtomicLookaround(): iterable
    {
        yield 'a non-atomic lookahead' => ['greedy' => '/(*napla:x(a+b?))x[ab]*/', 'possessive' => '/(*napla:x(a++b?))x[ab]*/'];
        yield 'a non-atomic lookahead, its short form' => ['greedy' => '/(?*xa+)x/', 'possessive' => '/(?*xa++)x/'];
        yield 'a non-atomic lookahead in a lookahead' => ['greedy' => '/(?=(?*xa+))x/', 'possessive' => '/(?=(?*xa++))x/'];
    }

    #[Test]
    #[DataProvider('provideLookaroundsThatDiffer')]
    public function test_two_patterns_that_hold_other_lookarounds_are_not_compared(string $left, string $right, string $holder, int $offset): void
    {
        $this->requiresCompile($left);
        $this->requiresCompile($right);

        $solver = new LanguageSolver();

        // Each pattern reads like itself, lookaround included.
        $this->assertTrue($solver->matchEquivalent($left, $left)->isEquivalent);
        $this->assertTrue($solver->matchEquivalent($right, $right)->isEquivalent);

        try {
            $solver->matchEquivalent($left, $right);
            $this->fail('The match solver ran a lookaround.');
        } catch (ComplexityException $refusal) {
            $this->assertSame(PriorityNfaBuilder::LOOKAROUND_MESSAGE, $refusal->getMessage());
            $this->assertSame($offset, $refusal->offset);
            $this->assertSame($holder, $refusal->getPattern());
        }
    }

    /**
     * @return iterable<string, array{left: string, right: string, holder: string, offset: int}>
     */
    public static function provideLookaroundsThatDiffer(): iterable
    {
        yield 'a lookahead and a negative one' => ['left' => '/(?=a)[ab]/', 'right' => '/(?!a)[ab]/', 'holder' => '/(?=a)[ab]/', 'offset' => 0];
        yield 'a lookahead and a lookbehind' => ['left' => '/b(?=a)[ab]/', 'right' => '/b(?<=a)[ab]/', 'holder' => '/b(?=a)[ab]/', 'offset' => 1];
        yield 'a lookbehind and a negative one' => ['left' => '/(?<=a)b/', 'right' => '/(?<!a)b/', 'holder' => '/(?<=a)b/', 'offset' => 0];
        yield 'a lookahead and a non-atomic one' => ['left' => '/(?=(a|ab))ab/', 'right' => '/(?*(a|ab))ab/', 'holder' => '/(?=(a|ab))ab/', 'offset' => 0];
        yield 'a lookbehind and a non-atomic one' => ['left' => '/(?<=(a|ab))b/', 'right' => '/(?<*(a|ab))b/', 'holder' => '/(?<=(a|ab))b/', 'offset' => 0];
        yield 'two lookaheads of other bodies' => ['left' => '/ab(?=a|b)/', 'right' => '/ab(?=[ab])/', 'holder' => '/ab(?=a|b)/', 'offset' => 2];
        yield 'a lookahead on the left only' => ['left' => '/(?=a)a/', 'right' => '/a/', 'holder' => '/(?=a)a/', 'offset' => 0];
        yield 'a lookahead on the right only' => ['left' => '/a/', 'right' => '/b(?=a)a/', 'holder' => '/b(?=a)a/', 'offset' => 1];
        yield 'the first of two lookaheads' => ['left' => '/x(?=a)(?=[ab])a/', 'right' => '/x(?=a)(?=a|b)a/', 'holder' => '/x(?=a)(?=[ab])a/', 'offset' => 1];
    }

    /**
     * What the engine tells apart, the solver does not hold alike: each row
     * differs in one lookaround only.
     */
    #[Test]
    #[DataProvider('provideLookaroundsTheEngineTellsApart')]
    public function test_lookarounds_the_engine_tells_apart_are_never_held_alike(string $left, string $right, string $witness): void
    {
        $this->requiresCompile($left);
        $this->requiresCompile($right);

        $this->assertNotSame(self::written($left, $witness), self::written($right, $witness), \sprintf('Oracle: %s and %s match alike on "%s".', $left, $right, $witness));

        $this->expectException(ComplexityException::class);
        $this->expectExceptionMessageIsOrContains(PriorityNfaBuilder::LOOKAROUND_MESSAGE);

        (new LanguageSolver())->matchEquivalent($left, $right);
    }

    /**
     * @return iterable<string, array{left: string, right: string, witness: string}>
     */
    public static function provideLookaroundsTheEngineTellsApart(): iterable
    {
        yield 'a lookahead and a negative one' => ['left' => '/(?=a)[ab]/', 'right' => '/(?!a)[ab]/', 'witness' => 'b'];
        yield 'a lookahead and a lookbehind' => ['left' => '/b(?=a)[ab]/', 'right' => '/b(?<=a)[ab]/', 'witness' => 'ba'];
        yield 'a lookbehind and a negative one' => ['left' => '/(?<=a)b/', 'right' => '/(?<!a)b/', 'witness' => 'b'];
        yield 'a negative lookahead and a negative lookbehind' => ['left' => '/a(?!a)[ab]/', 'right' => '/a(?<!a)[ab]/', 'witness' => 'ab'];
    }

    /**
     * No run from the start of a subject shows what a lookbehind reads
     * before a search from an offset. Two patterns the solver builds one
     * automaton for run alike wherever a search starts: they need no run.
     */
    #[Test]
    #[DataProvider('provideLookbehindPatternsBuiltAlike')]
    public function test_two_patterns_that_hold_a_lookbehind_and_build_one_automaton_need_no_run(string $left, string $right): void
    {
        foreach (self::subjects() as $subject) {
            for ($offset = 0; $offset <= \strlen($subject); $offset++) {
                $this->assertSame(self::written($left, $subject, $offset), self::written($right, $subject, $offset), \sprintf('Oracle: %s and %s differ on "%s" from %d.', $left, $right, $subject, $offset));
            }
        }

        $this->assertSame([], (new LanguageSolver())->runsOfEveryCall($left, $right));
    }

    /**
     * @return iterable<string, array{left: string, right: string}>
     */
    public static function provideLookbehindPatternsBuiltAlike(): iterable
    {
        yield 'a possessive after a lookbehind' => ['left' => '/(?<=x)a+b/', 'right' => '/(?<=x)a++b/'];
        yield 'a possessive after a negative lookbehind' => ['left' => '/(?<!x)a+b/', 'right' => '/(?<!x)a++b/'];
        yield 'a possessive before a lookbehind' => ['left' => '/a+b(?<=ab)/', 'right' => '/a++b(?<=ab)/'];
        yield 'a possessive after the start of the subject and a lookbehind' => ['left' => '/^x*(?<!x)a+b/', 'right' => '/^x*(?<!x)a++b/'];
        yield 'a possessive in a group a DEFINE holds, a lookbehind in the pattern' => ['left' => '/(?<=x)b(?(DEFINE)(?<n>a+b))/', 'right' => '/(?<=x)b(?(DEFINE)(?<n>a++b))/'];
    }

    #[Test]
    #[DataProvider('provideLookbehindPatternsNotBuiltAlike')]
    public function test_two_patterns_that_hold_a_lookbehind_and_build_other_automata_have_no_run(string $left, string $right, ?string $witness): void
    {
        if (null !== $witness) {
            $this->assertNotSame(self::written($left, $witness), self::written($right, $witness), \sprintf('Oracle: %s and %s match alike on "%s".', $left, $right, $witness));
        }

        $this->assertNull((new LanguageSolver())->runsOfEveryCall($left, $right));
    }

    /**
     * @return iterable<string, array{left: string, right: string, witness: string|null}>
     */
    public static function provideLookbehindPatternsNotBuiltAlike(): iterable
    {
        yield 'a possessive that gives back' => ['left' => '/(?<=x)a+a/', 'right' => '/(?<=x)a++a/', 'witness' => 'xaa'];
        yield 'a possessive before a lookbehind, then the end' => ['left' => '/[ab]+(?<=a)/', 'right' => '/[ab]++(?<=a)/', 'witness' => 'ab'];
        yield 'another character' => ['left' => '/(?<=x)a/', 'right' => '/(?<=x)b/', 'witness' => 'xa'];
        // The same matches, yet not built alike: left to the proof that reads each part alone.
        yield 'a class for an alternation' => ['left' => '/(?<=x)(?:a|b)c/', 'right' => '/(?<=x)[ab]c/', 'witness' => null];
        // The match solver reads no word boundary.
        yield 'a possessive beside a word boundary' => ['left' => '/(?<=x)\ba+b/', 'right' => '/(?<=x)\ba++b/', 'witness' => null];
    }

    #[Test]
    public function test_an_end_anchor_ending_a_lookahead_is_read(): void
    {
        $this->assertSame(1, preg_match('/(?=a$)a/', 'ba'), 'Oracle: the lookahead holds before the last "a".');
        $this->assertSame(0, preg_match('/(?=a$)a/', 'ab'), 'Oracle: and nowhere else.');

        $this->assertTrue((new LanguageSolver())->matchEquivalent('/(?=a$)a/', '/(?=a$)(?:a)/')->isEquivalent);
    }

    /**
     * An end anchor reads where the subject ends: more of the pattern after
     * it stays refused inside a lookahead as outside one, whatever branch
     * ends the lookahead first.
     */
    #[Test]
    public function test_an_end_anchor_before_more_of_a_lookahead_is_refused(): void
    {
        $this->assertSame(1, preg_match('/(?=a$(?:\n|))a/', "a\n"), 'Oracle: the newline is read past the anchor.');
        $this->assertSame(1, preg_match('/(?=a$(?:\n|))a/', 'a'), 'Oracle: or nothing is.');

        $this->expectException(ComplexityException::class);
        $this->expectExceptionMessageIsOrContains(PriorityNfaBuilder::TRAILING_ANCHOR_MESSAGE);

        (new LanguageSolver())->matchEquivalent('/(?=a$(?:\n|))a/', '/(?=a$(?:\n|))(?:a)/');
    }

    /**
     * What preg_match() answers, with the text and the offset of the match
     * and of each group.
     *
     * @return array{int|false, array<array-key, mixed>}
     */
    private static function written(string $pattern, string $subject, int $offset = 0): array
    {
        $result = preg_match($pattern, $subject, $matches, \PREG_OFFSET_CAPTURE | \PREG_UNMATCHED_AS_NULL, $offset);

        return [$result, $matches];
    }

    /**
     * The pattern as PCRE2's interpreter runs it, whatever pcre.jit says.
     */
    private static function interpreted(string $pattern): string
    {
        return $pattern[0].'(*NO_JIT)'.substr($pattern, 1);
    }

    /**
     * @return list<string>
     */
    private static function subjects(): array
    {
        $subjects = [''];
        for ($from = 0, $length = 1; $length <= 5; $length++) {
            $to = \count($subjects);
            for ($i = $from; $i < $to; $i++) {
                foreach (self::ALPHABET as $character) {
                    $subjects[] = $subjects[$i].$character;
                }
            }
            $from = $to;
        }

        return $subjects;
    }
}
