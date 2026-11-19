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
use PHPRegex\Tests\Support\PcreGates;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A lookaround reads nothing, yet it looks at the characters a repetition
 * before it would give back: "a+(?!b)" matches "a" in "aab", where
 * "a++(?!b)" matches nothing. Under the possessive option, a repetition
 * followed by a lookaround, before anything that reads, is kept as written,
 * and the other rewrites of the pattern still apply.
 */
final class PossessiveBeforeLookaroundTest extends TestCase
{
    use PcreGates;

    /**
     * The rows that hold from a PCRE2 newer than the floor: the scan
     * substring assertion comes with 10.45.
     */
    private const NEWER_RELEASES = ['10.45' => ['/^(aac)(*scs:(1)(a+b?))(?=\\2ac)/', '/(ab)(*scs:(1)(a+b))(?=.c)/', '/^(aac)(*scs:(1)(a+b?))(?=\\2ac)[0-9]/']];

    private const SUBJECTS = ['aab', 'aac', 'ab', 'a.b', 'a-', 'ab.c', 'b', '', 'x.y.', 'abc.', "x \ny", "x \nz", 'a_b', 'a__b', 'ab_'];

    #[Test]
    public function test_a_possessive_repetition_before_a_lookahead_matches_less(): void
    {
        $this->assertSame(1, preg_match('/a+(?!b)/', 'aab'), 'Oracle: the repetition gives an "a" back.');
        $this->assertSame(0, preg_match('/a++(?!b)/', 'aab'), 'Oracle: the possessive one does not.');
    }

    /**
     * The lookaround may stand past the end of the group the repetition
     * ends, after items that may match nothing: "(a+b?)(?!c)" matches "a"
     * in "aac", where "(a++b?)(?!c)" matches nothing.
     */
    #[Test]
    #[DataProvider('provideRepetitionsEndingAGroupBeforeALookaround')]
    public function test_a_repetition_ending_a_group_before_a_lookaround_stays_greedy(string $pattern, string $possessive, string $subject): void
    {
        $this->requiresPcre2For($pattern, self::NEWER_RELEASES);

        $this->assertNotSame(self::matched($pattern, $subject), self::matched($possessive, $subject), \sprintf('Oracle: %s and %s match alike on "%s".', $pattern, $possessive, $subject));

        $this->assertSame($pattern, self::rewrite($pattern));
    }

    /**
     * @return iterable<string, array{pattern: string, possessive: string, subject: string}>
     */
    public static function provideRepetitionsEndingAGroupBeforeALookaround(): iterable
    {
        yield 'a capturing group' => ['pattern' => '/(a+b?)(?!c)/', 'possessive' => '/(a++b?)(?!c)/', 'subject' => 'aac'];
        yield 'a branch of an alternation' => ['pattern' => '/(?:x|a+b?)(?!c)/', 'possessive' => '/(?:x|a++b?)(?!c)/', 'subject' => 'aac'];
        yield 'a repeated group' => ['pattern' => '/(?:a+b?)+(?!c)/', 'possessive' => '/(?:a++b?)+(?!c)/', 'subject' => 'aac'];
        yield 'an optional item after the group' => ['pattern' => '/(a+b?)x?(?!c)/', 'possessive' => '/(a++b?)x?(?!c)/', 'subject' => 'aac'];
        yield 'a lookbehind' => ['pattern' => '/(a+b?)(?<!aa)/', 'possessive' => '/(a++b?)(?<!aa)/', 'subject' => 'aa'];
        yield 'a repetition after an item of its group' => ['pattern' => '/(xa+b?)(?!c)/', 'possessive' => '/(xa++b?)(?!c)/', 'subject' => 'xaac'];
        yield 'a group after an atomic one in a group' => ['pattern' => '/((?>y)(a+b?))(?!c)/', 'possessive' => '/((?>y)(a++b?))(?!c)/', 'subject' => 'yaac'];
        // What follows a scan substring backtracks into it: the lookahead
        // has it give back an "a" of group 2.
        yield 'a scan substring' => ['pattern' => '/^(aac)(*scs:(1)(a+b?))(?=\\2ac)/', 'possessive' => '/^(aac)(*scs:(1)(a++b?))(?=\\2ac)/', 'subject' => 'aacaac'];
    }

    /**
     * A repetition is made possessive where no lookaround may follow it
     * before a character it never gives back: past such a character its
     * group reads, at the end of the pattern, or at the end of an atomic
     * group or a lookaround, which nothing after it backtracks into.
     */
    #[Test]
    #[DataProvider('provideRepetitionsNoLookaroundFollows')]
    public function test_a_repetition_no_lookaround_follows_is_made_possessive(string $pattern, string $possessive): void
    {
        $this->requiresPcre2For($pattern, self::NEWER_RELEASES);

        foreach (self::SUBJECTS as $subject) {
            $this->assertSame(self::matched($pattern, $subject), self::matched($possessive, $subject), \sprintf('Oracle: %s and %s differ on "%s".', $pattern, $possessive, $subject));
        }

        $this->assertSame($possessive, self::rewrite($pattern));
    }

    /**
     * @return iterable<string, array{pattern: string, possessive: string}>
     */
    public static function provideRepetitionsNoLookaroundFollows(): iterable
    {
        yield 'a character its group reads' => ['pattern' => '/(a+b)(?!c)/', 'possessive' => '/(a++b)(?!c)/'];
        yield 'the end of the pattern' => ['pattern' => '/a+b?/', 'possessive' => '/a++b?/'];
        yield 'the end of an atomic group' => ['pattern' => '/(?>a+b?)(?!c)/', 'possessive' => '/(?>a++b?)(?!c)/'];
        yield 'the end of a lookahead' => ['pattern' => '/(?=a+b?)(?!c)/', 'possessive' => '/(?=a++b?)(?!c)/'];
        yield 'a character a scan substring reads' => ['pattern' => '/(ab)(*scs:(1)(a+b))(?=.c)/', 'possessive' => '/(ab)(*scs:(1)(a++b))(?=.c)/'];
    }

    #[Test]
    #[DataProvider('provideRepetitionsBeforeALookaround')]
    public function test_a_repetition_before_a_lookaround_stays_greedy(string $pattern, string $optimized): void
    {
        $this->requiresPcre2For($pattern, self::NEWER_RELEASES);

        foreach (self::SUBJECTS as $subject) {
            $this->assertSame(self::matched($pattern, $subject), self::matched($optimized, $subject), \sprintf('Oracle: %s and %s differ on "%s".', $pattern, $optimized, $subject));
        }

        $result = (new Optimizer(RegexParser::create(['cache' => null])))->optimize($pattern, new OptimizerOptions(possessive: true));

        $this->assertSame($optimized, $result->optimized);
    }

    /**
     * @return iterable<string, array{pattern: string, optimized: string}>
     */
    public static function provideRepetitionsBeforeALookaround(): iterable
    {
        yield 'a negative lookahead' => ['pattern' => '/a+(?!b)/', 'optimized' => '/a+(?!b)/'];
        yield 'a negative lookahead on the class it repeats' => ['pattern' => '/[a-z.-]*(?![a-z.])/', 'optimized' => '/[\-.a-z]*(?![.a-z])/'];
        yield 'a negative lookahead on another letter' => ['pattern' => '/[a-z.-]*(?!b)/', 'optimized' => '/[\-.a-z]*(?!b)/'];
        yield 'a negative lookbehind' => ['pattern' => '/[a-z.-]*(?<!b)/', 'optimized' => '/[\-.a-z]*(?<!b)/'];
        yield 'a lookahead in a group' => ['pattern' => '/[a-z.-]*(?:(?!b))/', 'optimized' => '/[\-.a-z]*(?:(?!b))/'];
        yield 'a lookahead that left the other rewrites undone' => ['pattern' => '/x\s+(?!y)/s', 'optimized' => '/x\s+(?!y)/'];
        yield 'a lookahead at the end of a loop body' => ['pattern' => '/^[a-z](?:[a-z0-9_](?!__))*[a-z0-9]+$/', 'optimized' => '/^[a-z](?:[0-9_a-z](?!__))*[0-9a-z]+$/'];
        yield 'a lookahead past the group the repetition ends' => ['pattern' => '/(a+b?)(?!c)/s', 'optimized' => '/(a+b?)(?!c)/'];
        yield 'a lookahead past a scan substring' => ['pattern' => '/^(aac)(*scs:(1)(a+b?))(?=\\2ac)[0-9]/', 'optimized' => '/^(aac)(*scs:(1)(a+b?))(?=\\2ac)\\d/'];
    }

    private static function rewrite(string $pattern): string
    {
        return Regex::create(['cache' => null])->parse($pattern)->accept(new Rewriter(possessive: true))->accept(new PatternPrinter());
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
