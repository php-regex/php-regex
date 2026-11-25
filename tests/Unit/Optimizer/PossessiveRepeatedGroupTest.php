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
 * A repeated group gives back whole runs, which start as its body does, and
 * a run its body may match another way may end elsewhere: "(?:b|ba)*c"
 * takes "ba" as one run on "bac" once "b" alone left the "a" to the "c",
 * which "(?:b|ba)*+c" never tries, matching "c" alone, and "(?:ab)+ab"
 * gives a run of "ab" back to the "ab" after it on "abab". The possessive
 * rewrite makes a group's repeat possessive only where every run of its
 * body matches one way, characters and literals in a row, and what follows
 * the repeat never starts as a run does; the other repeats of the pattern
 * are read as before, and ship without it.
 */
final class PossessiveRepeatedGroupTest extends TestCase
{
    /**
     * The characters of the subjects each pair is run on, every string of
     * them up to four long.
     */
    private const ALPHABET = ['a', 'b', 'c', 'e', 'A', '1', ';', '-', "\n"];

    #[Test]
    #[DataProvider('provideRepeatedGroupsFollowedByWhatTheyGiveBack')]
    public function test_a_repeated_group_followed_by_what_it_gives_back_stays_greedy(string $pattern, string $possessive, string $subject): void
    {
        $this->assertNotSame(self::matched($pattern, $subject), self::matched($possessive, $subject), \sprintf('Oracle: %s and %s match alike on "%s".', $pattern, $possessive, $subject));

        $rewriter = new Rewriter(possessive: true);
        $this->assertSame($pattern, Regex::create(['cache' => null])->parse($pattern)->accept($rewriter)->accept(new PatternPrinter()));
        $this->assertNotContains(Rewriter::POSSESSIVE, $rewriter->changes(), 'The rewrite offers no possessive to refuse.');
    }

    /**
     * @return iterable<string, array{pattern: string, possessive: string, subject: string}>
     */
    public static function provideRepeatedGroupsFollowedByWhatTheyGiveBack(): iterable
    {
        yield 'a run that may take more' => ['pattern' => '/(?:b|ba)*c/', 'possessive' => '/(?:b|ba)*+c/', 'subject' => 'bac'];
        yield 'a run that may take more, before an optional item' => ['pattern' => '/(?:b|ba)*x?c/', 'possessive' => '/(?:b|ba)*+x?c/', 'subject' => 'bac'];
        yield 'a run that may take more, the shorter branch first' => ['pattern' => '/(?:a|ab)*c/', 'possessive' => '/(?:a|ab)*+c/', 'subject' => 'abc'];
        yield 'a run given back whole' => ['pattern' => '/(?:ab)+ab/', 'possessive' => '/(?:ab)++ab/', 'subject' => 'abab'];
        yield 'a run given back whole, caseless' => ['pattern' => '/(?:ab)+Ab/i', 'possessive' => '/(?:ab)++Ab/i', 'subject' => 'abab'];
        yield 'a run given back whole, from a count of two' => ['pattern' => '/(?:ab){2,}ab/', 'possessive' => '/(?:ab){2,}+ab/', 'subject' => 'ababab'];
        yield 'an atomic run given back whole' => ['pattern' => '/(?>ab)+ab/', 'possessive' => '/(?>ab)++ab/', 'subject' => 'abab'];
    }

    /**
     * Where a run may end inside the body, the possessive may hold and stay
     * unproven by the check of the result, which would refuse it with every
     * other possessive of the pattern: the group stays greedy, the repeats
     * inside and around it are read as before.
     */
    #[Test]
    #[DataProvider('provideRepeatedGroupsWhoseRunsMatchMoreThanOneWay')]
    public function test_a_repeated_group_whose_runs_match_more_than_one_way_stays_greedy(string $pattern, string $possessive, string $rewritten): void
    {
        $this->assertSame(self::matchedOnEverySubject($pattern), self::matchedOnEverySubject($possessive), \sprintf('Oracle: %s and %s differ.', $pattern, $possessive));
        $this->assertSame(self::matchedOnEverySubject($pattern), self::matchedOnEverySubject($rewritten), \sprintf('Oracle: %s and %s differ.', $pattern, $rewritten));

        $this->assertSame($rewritten, Regex::create(['cache' => null])->parse($pattern)->accept(new Rewriter(possessive: true))->accept(new PatternPrinter()));
    }

    /**
     * @return iterable<string, array{pattern: string, possessive: string, rewritten: string}>
     */
    public static function provideRepeatedGroupsWhoseRunsMatchMoreThanOneWay(): iterable
    {
        yield 'a run ending in a repetition' => ['pattern' => '/[a-z]+(?:-[a-z]+)*$/', 'possessive' => '/[a-z]++(?:-[a-z]++)*+$/', 'rewritten' => '/[a-z]++(?:-[a-z]++)*$/'];
        yield 'a run ending in an optional letter' => ['pattern' => '/(?:a+b?)+c/', 'possessive' => '/(?:a+b?)++c/', 'rewritten' => '/(?:a+b?)+c/'];
        yield 'a run ending in an optional letter, from a count of two' => ['pattern' => '/(?:a+b?){2,}c/', 'possessive' => '/(?:a+b?){2,}+c/', 'rewritten' => '/(?:a+b?){2,}c/'];
    }

    /**
     * The possessive the rewrite no longer offers no longer takes the
     * others of the pattern with it, which the check of the result proves.
     *
     * @param list<string> $changes
     */
    #[Test]
    #[DataProvider('provideOtherPossessives')]
    public function test_the_optimizer_ships_the_other_possessives_of_the_pattern(string $pattern, string $optimized, array $changes): void
    {
        $this->assertSame(self::matchedOnEverySubject($pattern), self::matchedOnEverySubject($optimized), \sprintf('Oracle: %s and %s differ.', $pattern, $optimized));

        $result = (new Optimizer(RegexParser::create(['cache' => null])))->optimize($pattern, new OptimizerOptions(possessive: true));

        $this->assertSame($optimized, $result->optimized);
        $this->assertSame($changes, $result->changes);
    }

    /**
     * @return iterable<string, array{pattern: string, optimized: string, changes: list<string>}>
     */
    public static function provideOtherPossessives(): iterable
    {
        yield 'a run ending in a repetition' => ['pattern' => '/[a-z]+(?:-[a-z]+)*$/', 'optimized' => '/[a-z]++(?:-[a-z]++)*$/', 'changes' => [Rewriter::POSSESSIVE]];
        yield 'a run that may take more' => ['pattern' => '/(?:b|ba)*c[0-9]+;/', 'optimized' => '/(?:b|ba)*c\d++;/', 'changes' => [Rewriter::DIGITS, Rewriter::POSSESSIVE]];
        yield 'a run that may take more, the shorter branch first' => ['pattern' => '/(?:a|ab)*c[0-9]+;/', 'optimized' => '/(?:a|ab)*c\d++;/', 'changes' => [Rewriter::DIGITS, Rewriter::POSSESSIVE]];
        yield 'a run given back whole' => ['pattern' => '/(?:ab)+ab[0-9]+;/', 'optimized' => '/(?:ab)+ab\d++;/', 'changes' => [Rewriter::DIGITS, Rewriter::POSSESSIVE]];
    }

    /**
     * A run of a body that matches one way is given back whole, from the
     * character it starts with: what follows the repeat is read against
     * that character, not the one a run ends with.
     */
    #[Test]
    #[DataProvider('provideRepeatedGroupsWhoseRunsMatchOneWay')]
    public function test_a_repeated_group_whose_runs_match_one_way_is_made_possessive(string $pattern, string $optimized): void
    {
        $this->assertSame(self::matchedOnEverySubject($pattern), self::matchedOnEverySubject($optimized), \sprintf('Oracle: %s and %s differ.', $pattern, $optimized));

        $result = (new Optimizer(RegexParser::create(['cache' => null])))->optimize($pattern, new OptimizerOptions(possessive: true));

        $this->assertSame($optimized, $result->optimized);
        $this->assertSame([Rewriter::POSSESSIVE], $result->changes);
    }

    /**
     * @return iterable<string, array{pattern: string, optimized: string}>
     */
    public static function provideRepeatedGroupsWhoseRunsMatchOneWay(): iterable
    {
        yield 'a literal' => ['pattern' => '/(?:ab)+c/', 'optimized' => '/(?:ab)++c/'];
        yield 'a literal after a character' => ['pattern' => '/c(?:ab)+e/', 'optimized' => '/c(?:ab)++e/'];
        yield 'a literal before the character it ends with' => ['pattern' => '/(?:ab)+b/', 'optimized' => '/(?:ab)++b/'];
        yield 'a literal before the character it ends with, from a count of two' => ['pattern' => '/(?:ab){2,}b/', 'optimized' => '/(?:ab){2,}+b/'];
        yield 'a letter and a class' => ['pattern' => '/(?:a[bc])+b/', 'optimized' => '/(?:a[bc])++b/'];
        yield 'a group in the body' => ['pattern' => '/(?:a(?:bc)e)+b/', 'optimized' => '/(?:a(?:bc)e)++b/'];
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
