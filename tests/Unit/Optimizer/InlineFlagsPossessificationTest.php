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

namespace PHPRegex\Tests\Unit\Optimizer;

use PHPRegex\Optimizer\Optimizer;
use PHPRegex\Optimizer\OptimizerOptions;
use PHPRegex\Optimizer\Rewriter;
use PHPRegex\Parser\Printer\PatternPrinter;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A quantifier becomes possessive only where what follows can never start
 * with a character it gives back. The character sets of each node read the
 * flags in force where it stands: an option setting changes them from where
 * it stands to the end of its group, its later alternatives included, and a
 * group a call runs keeps the flags it is written under. "(?i)A" takes the
 * "a" that "a+" would give back, "(?i)C" none. Under "U" a plain quantifier
 * is lazy, which no possessive stands for: an option setting that names it
 * keeps every quantifier of the pattern as written.
 */
final class InlineFlagsPossessificationTest extends TestCase
{
    /**
     * The characters of the subjects each pair is run on, every string of
     * them up to four long.
     */
    private const ALPHABET = ['a', 'A', 'b', 'B', 'c', 'C', 'x'];

    #[Test]
    #[DataProvider('provideRepeatsUnderInlineFlagsFollowedByWhatTheyNeverGiveBack')]
    public function test_a_repeat_under_inline_flags_followed_by_what_it_never_gives_back_is_made_possessive(string $pattern, string $expected): void
    {
        $this->assertSame(self::matchedOnEverySubject($pattern), self::matchedOnEverySubject($expected), \sprintf('Oracle: %s and %s differ.', $pattern, $expected));

        $this->assertSame($expected, self::rewrite($pattern));
    }

    /**
     * @return iterable<string, array{pattern: string, expected: string}>
     */
    public static function provideRepeatsUnderInlineFlagsFollowedByWhatTheyNeverGiveBack(): iterable
    {
        yield 'a group a scoped caseless option holds, called before another letter' => ['pattern' => '/(?i:(?(DEFINE)(?<n>A+b?)))(?&n)c/', 'expected' => '/(?i:(?(DEFINE)(?<n>A++b?)))(?&n)c/'];
        yield 'a group defined after a caseless option, called before it' => ['pattern' => '/(?&n)(?i)c(?(DEFINE)(?<n>a+b?))/', 'expected' => '/(?&n)(?i)c(?(DEFINE)(?<n>a++b?))/'];
        yield 'a caseless option before the repeat' => ['pattern' => '/(?i)a+b?c/', 'expected' => '/(?i)a++b?c/'];
        yield 'a caseless option after the repeat' => ['pattern' => '/a+b?(?i)C/', 'expected' => '/a++b?(?i)C/'];
        yield 'a scoped caseless option around the repeat' => ['pattern' => '/(?i:a+b?)c/', 'expected' => '/(?i:a++b?)c/'];
        yield 'a caseless option turned off before the repeat' => ['pattern' => '/(?-i)a+b?A/i', 'expected' => '/(?-i)a++b?A/i'];
        yield 'the options reset before the repeat' => ['pattern' => '/(?^)a+b?A/i', 'expected' => '/(?^)a++b?A/i'];
        yield 'a caseless option that ends with its group' => ['pattern' => '/(?:(?i)x)a+b?A/', 'expected' => '/(?:(?i)x)a++b?A/'];
        yield 'a dot-all option' => ['pattern' => '/(?s)a+b?c/', 'expected' => '/(?s)a++b?c/'];
        yield 'a multiline option before the end anchor' => ['pattern' => '/(?m)a+b?$/', 'expected' => '/(?m)a++b?$/'];
    }

    /**
     * The flags in force where the repeat stands, or where what follows it
     * stands, let what follows take back what the repeat matched, or the
     * repeat is lazy.
     */
    #[Test]
    #[DataProvider('provideRepeatsUnderInlineFlagsFollowedByWhatTheyGiveBack')]
    public function test_a_repeat_under_inline_flags_followed_by_what_it_gives_back_stays_greedy(string $pattern, string $possessive, string $subject, string $expected): void
    {
        $this->assertNotSame(self::matched($pattern, $subject), self::matched($possessive, $subject), \sprintf('Oracle: %s and %s match alike on "%s".', $pattern, $possessive, $subject));
        $this->assertSame(self::matchedOnEverySubject($pattern), self::matchedOnEverySubject($expected), \sprintf('Oracle: %s and %s differ.', $pattern, $expected));

        $this->assertSame($expected, self::rewrite($pattern));
    }

    /**
     * @return iterable<string, array{pattern: string, possessive: string, subject: string, expected: string}>
     */
    public static function provideRepeatsUnderInlineFlagsFollowedByWhatTheyGiveBack(): iterable
    {
        yield 'a caseless option before the repeat, the other case after it' => ['pattern' => '/(?i)a+b?A/', 'possessive' => '/(?i)a++b?A/', 'subject' => 'aa', 'expected' => '/(?i)a+b?A/'];
        yield 'a scoped caseless option around the repeat, the other case after it' => ['pattern' => '/(?i:a+b?)A/', 'possessive' => '/(?i:a++b?)A/', 'subject' => 'aA', 'expected' => '/(?i:a+b?)A/'];
        yield 'a caseless option turned off after the repeat' => ['pattern' => '/(?i)a+b?(?-i)A/', 'possessive' => '/(?i)a++b?(?-i)A/', 'subject' => 'aA', 'expected' => '/(?i)a+b?(?-i)A/'];
        // The repeat and the literal after it are built again: "(?:a)+" as
        // "a+", "(?:A)b" as "Ab".
        yield 'a caseless option before items the rewrite builds again' => ['pattern' => '/(?i)(?:a)+(?:A)b/', 'possessive' => '/(?i)a++Ab/', 'subject' => 'aab', 'expected' => '/(?i)a+Ab/'];
        yield 'a caseless option carried to the next alternative' => ['pattern' => '/(?:(?i)x|a+b?)A/', 'possessive' => '/(?:(?i)x|a++b?)A/', 'subject' => 'aA', 'expected' => '/(?:(?i)x|a+b?)A/'];
        yield 'a group a scoped caseless option holds, called before the other case' => ['pattern' => '/(?i:(?(DEFINE)(?<n>a+b?)))(?&n)A/', 'possessive' => '/(?i:(?(DEFINE)(?<n>a++b?)))(?&n)A/', 'subject' => 'aA', 'expected' => '/(?i:(?(DEFINE)(?<n>a+b?)))(?&n)A/'];
        yield 'an ungreedy option' => ['pattern' => '/(?U)a+b?/', 'possessive' => '/(?U)a++b?/', 'subject' => 'aa', 'expected' => '/(?U)a+b?/'];
        yield 'a scoped ungreedy option' => ['pattern' => '/x(?U:a+b?)/', 'possessive' => '/x(?U:a++b?)/', 'subject' => 'xaa', 'expected' => '/x(?U:a+b?)/'];
    }

    /**
     * A rewriter visiting one pattern after another reads the flags of the
     * one it visits: "(?i:a+b?)A" stays greedy after "(?i)c+b?d".
     */
    #[Test]
    public function test_a_rewriter_reads_the_flags_of_each_pattern_it_visits(): void
    {
        $this->assertNotSame(self::matched('/(?i:a+b?)A/', 'aA'), self::matched('/(?i:a++b?)A/', 'aA'), 'Oracle: the possessive gives back no "A".');

        $rewriter = new Rewriter(possessive: true);
        $regex = Regex::create(['cache' => null]);
        $regex->parse('/(?i)c+b?d/')->accept($rewriter);

        $this->assertSame('/(?i:a+b?)A/', $regex->parse('/(?i:a+b?)A/')->accept($rewriter)->accept(new PatternPrinter()));
    }

    /**
     * The check of the result proves the possessive the rewrite offers
     * under an option setting: it ships with the first rewrite.
     *
     * @param list<string> $changes
     */
    #[Test]
    #[DataProvider('provideOptimizationsUnderInlineFlags')]
    public function test_the_optimizer_ships_the_possessive_offered_under_inline_flags(string $pattern, string $expected, array $changes): void
    {
        $this->assertSame(self::matchedOnEverySubject($pattern), self::matchedOnEverySubject($expected), \sprintf('Oracle: %s and %s differ.', $pattern, $expected));

        $result = (new Optimizer(RegexParser::create(['cache' => null])))->optimize($pattern, new OptimizerOptions(possessive: true));

        $this->assertSame($expected, $result->optimized);
        $this->assertSame($changes, $result->changes);
    }

    /**
     * @return iterable<string, array{pattern: string, expected: string, changes: list<string>}>
     */
    public static function provideOptimizationsUnderInlineFlags(): iterable
    {
        yield 'a group a scoped caseless option holds, called before another letter' => ['pattern' => '/(?i:(?(DEFINE)(?<n>A+b?)))(?&n)c/', 'expected' => '/(?i:(?(DEFINE)(?<n>A++b?)))(?&n)c/', 'changes' => [Rewriter::POSSESSIVE]];
        yield 'a group defined after a caseless option, called before it' => ['pattern' => '/(?&n)(?i)c(?(DEFINE)(?<n>a+b?))/', 'expected' => '/(?&n)(?i)c(?(DEFINE)(?<n>a++b?))/', 'changes' => [Rewriter::POSSESSIVE]];
        yield 'a caseless option, a class written as a shorthand' => ['pattern' => '/(?i)[0-9]+b?c/', 'expected' => '/(?i)\d++b?c/', 'changes' => [Rewriter::DIGITS, Rewriter::POSSESSIVE]];
    }

    /**
     * @return iterable<string, array{pattern: string, possessive: string}>
     */
    public static function provideInlineFlagsAfterTheQuantifier(): iterable
    {
        yield 'inside a script run' => ['pattern' => '/a+(*sr:(?i)A)/', 'possessive' => '/a++(*sr:(?i)A)/'];
        yield 'inside an atomic script run' => ['pattern' => '/a+(*atomic_script_run:(?i)A)/', 'possessive' => '/a++(*atomic_script_run:(?i)A)/'];
        yield 'scoped inside a group' => ['pattern' => '/a+(?:(?i)A)/', 'possessive' => '/a++(?:(?i)A)/'];
        yield 'inside a DEFINE body the call runs' => ['pattern' => '/a+(?(DEFINE)(?<d>(?i)A))(?&d)/', 'possessive' => '/a++(?(DEFINE)(?<d>(?i)A))(?&d)/'];
    }

    #[Test]
    #[DataProvider('provideInlineFlagsAfterTheQuantifier')]
    public function test_a_quantifier_before_an_inline_flag_scope_stays_greedy(string $pattern, string $possessive): void
    {
        // Oracle: the possessive form gives no "a" back to "(?i)A".
        $this->assertSame(1, preg_match($pattern, 'aa', $match));
        $this->assertSame('aa', $match[0]);
        $this->assertSame(0, preg_match($possessive, 'aa'));

        $optimized = Regex::create(['cache' => null])->parse($pattern)->accept(new Rewriter(possessive: true))->accept(new PatternPrinter());

        $this->assertSame($pattern, $optimized);
    }

    /**
     * The walk itself reaches every container, a DEFINE body and a script
     * run included: through the optimizer the charset analyzer refuses
     * those containers first, so only the walk shows it.
     *
     * @return iterable<string, array{pattern: string, flags: bool}>
     */
    public static function provideContainers(): iterable
    {
        yield 'flag group in a DEFINE body' => ['pattern' => '/(?(DEFINE)(?<d>(?i)x))a/', 'flags' => true];
        yield 'scoped flag group in a DEFINE body' => ['pattern' => '/(?(DEFINE)(?<d>(?i:x)))a/', 'flags' => true];
        yield 'flag group in a script run' => ['pattern' => '/a(*sr:(?i)A)/', 'flags' => true];
        yield 'flag group in a lookahead' => ['pattern' => '/a(?=(?i)A)/', 'flags' => true];
        yield 'DEFINE body without flags' => ['pattern' => '/(?(DEFINE)(?<d>x))a/', 'flags' => false];
        yield 'script run without flags' => ['pattern' => '/a(*sr:A)/', 'flags' => false];
    }

    #[Test]
    #[DataProvider('provideContainers')]
    public function test_contains_inline_flags_walks_every_container(string $pattern, bool $flags): void
    {
        $this->assertNotFalse(@preg_match($pattern, ''));

        $rewriter = new Rewriter();
        $flagRewrites = (new \ReflectionProperty($rewriter, 'flagRewrites'))->getValue($rewriter);
        $this->assertIsObject($flagRewrites);
        $root = Regex::create(['cache' => null])->parse($pattern)->pattern;

        $this->assertSame($flags, (new \ReflectionMethod($flagRewrites, 'containsInlineFlags'))->invoke($flagRewrites, $root));
    }

    private static function rewrite(string $pattern): string
    {
        return Regex::create(['cache' => null])->parse($pattern)->accept(new Rewriter(possessive: true))->accept(new PatternPrinter());
    }

    /**
     * What preg_match() answers on every string of the alphabet up to four
     * characters long, with the text and the offset of the match and of
     * each group.
     *
     * @return list<array{int|false, array<array-key, mixed>}>
     */
    private static function matchedOnEverySubject(string $pattern): array
    {
        $answers = [];
        $subjects = [''];
        for ($length = 0; $length <= 4; $length++) {
            $longer = [];
            foreach ($subjects as $subject) {
                $answers[] = self::matched($pattern, $subject);
                foreach (self::ALPHABET as $character) {
                    $longer[] = $subject.$character;
                }
            }

            $subjects = $longer;
        }

        return $answers;
    }

    /**
     * What preg_match() answers, with the text and the offset of the match
     * and of each group.
     *
     * @return array{int|false, array<array-key, mixed>}
     */
    private static function matched(string $pattern, string $subject): array
    {
        $result = preg_match($pattern, $subject, $matches, \PREG_OFFSET_CAPTURE);

        return [$result, $matches];
    }
}
