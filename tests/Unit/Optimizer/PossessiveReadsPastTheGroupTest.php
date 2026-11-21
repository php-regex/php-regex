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
 * Where the items after a repetition in its group may match nothing, what
 * follows the group follows the repetition: "(a+b?)a" gives an "a" back to
 * the "a" after the group on "aa", where "(a++b?)a" matches nothing. The
 * possessive rewrite reads past the end of the groups around the
 * repetition, up to a character it never gives back, the end of the
 * pattern, or the end of an atomic group or a lookaround; the body of a
 * group that must run again follows its own end. "$" and "\Z" read
 * nothing but a newline the repetition may have given back.
 */
final class PossessiveReadsPastTheGroupTest extends TestCase
{
    use PcreGates;

    /**
     * The rows that hold from a PCRE2 newer than the floor: the scan
     * substring assertion comes with 10.45.
     */
    private const NEWER_RELEASES = ['10.45' => ['/^(aac)(*scs:(1)(a+b?))\\2c/']];

    /**
     * The characters of the subjects each pair is run on, every string of
     * them up to four long.
     */
    private const ALPHABET = ['a', 'b', 'c', 'x', '1', 'A', ' ', "\n"];

    #[Test]
    #[DataProvider('provideRepetitionsFollowedByWhatTheyGiveBack')]
    public function test_a_repetition_whose_group_is_followed_by_what_it_gives_back_stays_greedy(string $pattern, string $possessive, string $subject, string $rewritten): void
    {
        $this->requiresPcre2For($pattern, self::NEWER_RELEASES);

        $this->assertNotSame(self::matched($pattern, $subject), self::matched($possessive, $subject), \sprintf('Oracle: %s and %s match alike on "%s".', $pattern, $possessive, $subject));
        $this->assertSame(self::matchedOnEverySubject($pattern), self::matchedOnEverySubject($rewritten), \sprintf('Oracle: %s and %s differ.', $pattern, $rewritten));

        $this->assertSame($rewritten, self::rewrite($pattern));
    }

    /**
     * @return iterable<string, array{pattern: string, possessive: string, subject: string, rewritten: string}>
     */
    public static function provideRepetitionsFollowedByWhatTheyGiveBack(): iterable
    {
        yield 'a character after the group' => ['pattern' => '/(a+b?)a/', 'possessive' => '/(a++b?)a/', 'subject' => 'aa', 'rewritten' => '/(a+b?)a/'];
        yield 'a character after a repeated group' => ['pattern' => '/(?:a+b?)*a$/', 'possessive' => '/(?:a++b?)*a$/', 'subject' => 'aa', 'rewritten' => '/(?:a+b?)*a$/'];
        yield 'a digit after the group' => ['pattern' => '/(?:[0-9]+b?)1/', 'possessive' => '/(?:\d++b?)1/', 'subject' => '11', 'rewritten' => '/(?:\d+b?)1/'];
        yield 'a reference to the group' => ['pattern' => '/(a+b?)\1/', 'possessive' => '/(a++b?)\1/', 'subject' => 'aa', 'rewritten' => '/(a+b?)\1/'];
        yield 'a reference past a scan substring' => ['pattern' => '/^(aac)(*scs:(1)(a+b?))\2c/', 'possessive' => '/^(aac)(*scs:(1)(a++b?))\2c/', 'subject' => 'aacac', 'rewritten' => '/^(aac)(*scs:(1)(a+b?))\2c/'];
        yield 'a character after the group around the group' => ['pattern' => '/((?:a+b?)c?)a/', 'possessive' => '/((?:a++b?)c?)a/', 'subject' => 'aa', 'rewritten' => '/((?:a+b?)c?)a/'];
        yield 'a character after an alternation' => ['pattern' => '/(?:x|a+b?)a/', 'possessive' => '/(?:x|a++b?)a/', 'subject' => 'aa', 'rewritten' => '/(?:x|a+b?)a/'];
        yield 'a star after the repetition' => ['pattern' => '/(a+b*)a/', 'possessive' => '/(a++b*)a/', 'subject' => 'aa', 'rewritten' => '/(a+b*)a/'];
        yield 'a count from zero after the repetition' => ['pattern' => '/(a+b{0,3})a/', 'possessive' => '/(a++b{0,3})a/', 'subject' => 'aa', 'rewritten' => '/(a+b{0,3})a/'];
        yield 'an alternation that may match nothing after the repetition' => ['pattern' => '/(a+(?:b|c?))a/', 'possessive' => '/(a++(?:b|c?))a/', 'subject' => 'aa', 'rewritten' => '/(a+(?:b|c?))a/'];
        yield 'a comment between the group and the character' => ['pattern' => '/(a+b?)(?#c)a/', 'possessive' => '/(a++b?)(?#c)a/', 'subject' => 'aa', 'rewritten' => '/(a+b?)(?#c)a/'];
        yield 'the other case of the letter, caseless' => ['pattern' => '/(a+b?)A/i', 'possessive' => '/(a++b?)A/i', 'subject' => 'aa', 'rewritten' => '/(a+b?)A/i'];
        yield 'a character after the group, extended' => ['pattern' => '/(a+ b?) a/x', 'possessive' => '/(a++ b?) a/x', 'subject' => 'aa', 'rewritten' => '/(a+ b?) a/x'];
        yield 'a newline past the end anchor' => ['pattern' => '/(\n+b?)$\n/', 'possessive' => '/(\n++b?)$\n/', 'subject' => "\n\n", 'rewritten' => '/(\n+b?)$\n/'];
        yield 'the body of a group that must run again' => ['pattern' => '/(?:a+b?){2}c/', 'possessive' => '/(?:a++b?){2}c/', 'subject' => 'aac', 'rewritten' => '/(?:a+b?){2}c/'];
        // The group itself ends with a letter "c" is not: the repeat of it
        // is made possessive.
        yield 'the body of a group that must run at least twice' => ['pattern' => '/(?:a+b?){2,}c/', 'possessive' => '/(?:a++b?){2,}+c/', 'subject' => 'aac', 'rewritten' => '/(?:a+b?){2,}+c/'];
        yield 'what follows the last run of a group that must run again' => ['pattern' => '/(?:ca+b?){2}a/', 'possessive' => '/(?:ca++b?){2}a/', 'subject' => 'cacaa', 'rewritten' => '/(?:ca+b?){2}a/'];
    }

    #[Test]
    #[DataProvider('provideRepetitionsFollowedByWhatTheyNeverGiveBack')]
    public function test_a_repetition_whose_group_is_followed_by_what_it_never_gives_back_is_made_possessive(string $pattern, string $possessive): void
    {
        $this->assertSame(self::matchedOnEverySubject($pattern), self::matchedOnEverySubject($possessive), \sprintf('Oracle: %s and %s differ.', $pattern, $possessive));

        $this->assertSame($possessive, self::rewrite($pattern));
    }

    /**
     * @return iterable<string, array{pattern: string, possessive: string}>
     */
    public static function provideRepetitionsFollowedByWhatTheyNeverGiveBack(): iterable
    {
        yield 'a character after the group' => ['pattern' => '/(a+b?)c/', 'possessive' => '/(a++b?)c/'];
        yield 'the end anchor after the group' => ['pattern' => '/^(\d+\s*)$/', 'possessive' => '/^(\d++\s*)$/'];
        yield 'the end anchor in the same sequence' => ['pattern' => '/\d+\s*$/', 'possessive' => '/\d++\s*$/'];
        yield 'the end anchor under m' => ['pattern' => '/^(\d+\s*)$/m', 'possessive' => '/^(\d++\s*)$/m'];
        yield 'the end of the subject or before its last newline' => ['pattern' => '/^(\d+\s*)\Z/', 'possessive' => '/^(\d++\s*)\Z/'];
        yield 'the end of the subject' => ['pattern' => '/^(\d+\s*)\z/', 'possessive' => '/^(\d++\s*)\z/'];
        yield 'a character after the group around the group' => ['pattern' => '/((?:a+b?)c?)x/', 'possessive' => '/((?:a++b?)c?)x/'];
        yield 'a character after an alternation' => ['pattern' => '/(?:x|a+b?)c/', 'possessive' => '/(?:x|a++b?)c/'];
        yield 'a comment between the group and the character' => ['pattern' => '/(a+b?)(?#a)c/', 'possessive' => '/(a++b?)(?#a)c/'];
        yield 'a character of another letter, caseless' => ['pattern' => '/(a+b?)c/i', 'possessive' => '/(a++b?)c/i'];
        yield 'a character after the group, extended' => ['pattern' => '/(a+ b?) c/x', 'possessive' => '/(a++b?)c/x'];
        yield 'a character after the group, under u' => ['pattern' => '/(a+b?)c/u', 'possessive' => '/(a++b?)c/u'];
        yield 'the end of an atomic group' => ['pattern' => '/(?>a+b?)a/', 'possessive' => '/(?>a++b?)a/'];
        yield 'the end of a lookahead' => ['pattern' => '/(?=a+b?)a/', 'possessive' => '/(?=a++b?)a/'];
        yield 'another character starting a group that must run again' => ['pattern' => '/(?:xa+b?){2}c/', 'possessive' => '/(?:xa++b?){2}c/'];
        yield 'a group that may run again' => ['pattern' => '/(?:a+b?)+c/', 'possessive' => '/(?:a++b?)++c/'];
    }

    /**
     * The rewrites the check proves ship: the possessive no longer offered
     * where what follows the group takes back what the repetition matched,
     * the other rewrites of the pattern are not withdrawn with it.
     *
     * @param list<string> $changes
     */
    #[Test]
    #[DataProvider('provideOptimizations')]
    public function test_the_optimizer_ships_the_rewrite_the_check_proves(string $pattern, string $optimized, array $changes): void
    {
        $this->assertSame(self::matchedOnEverySubject($pattern), self::matchedOnEverySubject($optimized), \sprintf('Oracle: %s and %s differ.', $pattern, $optimized));

        $result = (new Optimizer(RegexParser::create(['cache' => null])))->optimize($pattern, new OptimizerOptions(possessive: true));

        $this->assertSame($optimized, $result->optimized);
        $this->assertSame($changes, $result->changes);
    }

    /**
     * @return iterable<string, array{pattern: string, optimized: string, changes: list<string>}>
     */
    public static function provideOptimizations(): iterable
    {
        yield 'a character after the group' => ['pattern' => '/(a+b?)a[0-9]/', 'optimized' => '/(a+b?)a\d/', 'changes' => [Rewriter::DIGITS]];
        yield 'a digit after the group' => ['pattern' => '/(a+b?)[0-9]/', 'optimized' => '/(a++b?)\d/', 'changes' => [Rewriter::POSSESSIVE, Rewriter::DIGITS]];
        yield 'the end anchor after the group' => ['pattern' => '/^(\d+\s*)$/', 'optimized' => '/^(\d++\s*)$/', 'changes' => [Rewriter::POSSESSIVE]];
        yield 'the end anchor in the same sequence' => ['pattern' => '/[0-9]+\s*$/', 'optimized' => '/\d++\s*$/', 'changes' => [Rewriter::DIGITS, Rewriter::POSSESSIVE]];
        yield 'a comment after the group, laid out as written' => ['pattern' => "/(a+ b?) # note\n c/x", 'optimized' => "/(a++ b?) # note\n c/x", 'changes' => [Rewriter::POSSESSIVE]];
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
