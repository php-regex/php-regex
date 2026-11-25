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
 * Where the items after a repetition in its group may match nothing, or
 * none follows it there, what follows the group follows the repetition:
 * "(a+b?)a" and "(a+)a" give an "a" back to the "a" after the group on "aa",
 * where "(a++b?)a" and "(a++)a" match nothing. The possessive rewrite reads
 * past the end of the groups around the repetition, of the alternation
 * whose branch it ends, up to a character it never gives back, the end of
 * the pattern, or the end of an atomic group or a lookaround; the body of a
 * group that must run again follows its own end. "$" and "\Z" read nothing
 * but a newline the repetition may have given back.
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
        // The star ends the group before a letter it never takes: it is
        // made possessive.
        yield 'a star after the repetition' => ['pattern' => '/(a+b*)a/', 'possessive' => '/(a++b*)a/', 'subject' => 'aa', 'rewritten' => '/(a+b*+)a/'];
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
        // No character follows "\z": the space that ends the group is
        // made possessive as well.
        yield 'the end of the subject' => ['pattern' => '/^(\d+\s*)\z/', 'possessive' => '/^(\d++\s*+)\z/'];
        yield 'a character after the group around the group' => ['pattern' => '/((?:a+b?)c?)x/', 'possessive' => '/((?:a++b?)c?)x/'];
        yield 'a character after an alternation' => ['pattern' => '/(?:x|a+b?)c/', 'possessive' => '/(?:x|a++b?)c/'];
        yield 'a comment between the group and the character' => ['pattern' => '/(a+b?)(?#a)c/', 'possessive' => '/(a++b?)(?#a)c/'];
        yield 'a character of another letter, caseless' => ['pattern' => '/(a+b?)c/i', 'possessive' => '/(a++b?)c/i'];
        yield 'a character after the group, extended' => ['pattern' => '/(a+ b?) c/x', 'possessive' => '/(a++b?)c/x'];
        yield 'a character after the group, under u' => ['pattern' => '/(a+b?)c/u', 'possessive' => '/(a++b?)c/u'];
        yield 'the end of an atomic group' => ['pattern' => '/(?>a+b?)a/', 'possessive' => '/(?>a++b?)a/'];
        yield 'the end of a lookahead' => ['pattern' => '/(?=a+b?)a/', 'possessive' => '/(?=a++b?)a/'];
        yield 'another character starting a group that must run again' => ['pattern' => '/(?:xa+b?){2}c/', 'possessive' => '/(?:xa++b?){2}c/'];
        // The next run starts with the "a" the repetition gives back: the
        // repetition stays greedy, the group made possessive.
        yield 'a group that may run again' => ['pattern' => '/(?:a+b?)+c/', 'possessive' => '/(?:a+b?)++c/'];
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

    /**
     * A repetition that ends its sequence, or stands alone in its group, its
     * branch or the pattern, is followed by what follows them.
     */
    #[Test]
    #[DataProvider('provideRepetitionsEndingTheirSequenceFollowedByWhatTheyNeverGiveBack')]
    public function test_a_repetition_ending_its_sequence_followed_by_what_it_never_gives_back_is_made_possessive(string $pattern, string $expected): void
    {
        $this->assertSame(self::matchedOnEverySubject($pattern), self::matchedOnEverySubject($expected), \sprintf('Oracle: %s and %s differ.', $pattern, $expected));

        $this->assertSame($expected, self::rewrite($pattern));
    }

    /**
     * @return iterable<string, array{pattern: string, expected: string}>
     */
    public static function provideRepetitionsEndingTheirSequenceFollowedByWhatTheyNeverGiveBack(): iterable
    {
        yield 'a repetition alone in its group' => ['pattern' => '/(a+)c/', 'expected' => '/(a++)c/'];
        yield 'a repetition ending its group' => ['pattern' => '/(xa+)c/', 'expected' => '/(xa++)c/'];
        yield 'a repetition alone in a group in a group' => ['pattern' => '/((a+))c/', 'expected' => '/((a++))c/'];
        yield 'a repetition alone in a non-capturing group' => ['pattern' => '/(?:a+)c/', 'expected' => '/(?:a++)c/'];
        yield 'a repetition ending an alternative' => ['pattern' => '/(?:x|a+)c/', 'expected' => '/(?:x|a++)c/'];
        yield 'a repetition ending an alternative beside a count of one' => ['pattern' => '/(?:x{1}|a+)c/', 'expected' => '/(?:x|a++)c/'];
        yield 'a repetition ending a branch of a conditional' => ['pattern' => '/(x)?(?(1)a+|b)c/', 'expected' => '/(x)?(?(1)a++|b)c/'];
        yield 'a repetition ending the pattern' => ['pattern' => '/xa+/', 'expected' => '/xa++/'];
        yield 'a repetition alone in the pattern' => ['pattern' => '/a+/', 'expected' => '/a++/'];
        yield 'a repetition alone in a group before the end anchor' => ['pattern' => '/(a+)$/', 'expected' => '/(a++)$/'];
        yield 'a repetition ending the pattern after \K' => ['pattern' => '/x\Ka+/', 'expected' => '/x\Ka++/'];
        yield 'a star alone in its group' => ['pattern' => '/(a*)c/', 'expected' => '/(a*+)c/'];
        yield 'a count from two alone in its group' => ['pattern' => '/(a{2,})c/', 'expected' => '/(a{2,}+)c/'];
        yield 'a repetition ending an atomic group' => ['pattern' => '/(?>xa+)\w/', 'expected' => '/(?>xa++)\w/'];
        yield 'a repetition alone in a lookahead' => ['pattern' => '/(?=a+)a/', 'expected' => '/(?=a++)a/'];
        yield 'a repetition ending a group that must run again' => ['pattern' => '/(?:xa+){2}/', 'expected' => '/(?:xa++){2}/'];
        yield 'a repetition alone in a branch of a group that runs once at most' => ['pattern' => '/(b+|ba)?c/', 'expected' => '/(b++|ba)?c/'];
        yield 'a repetition alone in its group, caseless' => ['pattern' => '/(a+)c/i', 'expected' => '/(a++)c/i'];
        yield 'a repetition alone in its group, under u' => ['pattern' => '/(a+)c/u', 'expected' => '/(a++)c/u'];
    }

    #[Test]
    #[DataProvider('provideRepetitionsEndingTheirSequenceFollowedByWhatTheyGiveBack')]
    public function test_a_repetition_ending_its_sequence_followed_by_what_it_gives_back_stays_greedy(string $pattern, string $possessive, string $subject, string $expected): void
    {
        $this->assertNotSame(self::matched($pattern, $subject), self::matched($possessive, $subject), \sprintf('Oracle: %s and %s match alike on "%s".', $pattern, $possessive, $subject));
        $this->assertSame(self::matchedOnEverySubject($pattern), self::matchedOnEverySubject($expected), \sprintf('Oracle: %s and %s differ.', $pattern, $expected));

        $this->assertSame($expected, self::rewrite($pattern));
    }

    /**
     * @return iterable<string, array{pattern: string, possessive: string, subject: string, expected: string}>
     */
    public static function provideRepetitionsEndingTheirSequenceFollowedByWhatTheyGiveBack(): iterable
    {
        yield 'a character after the group' => ['pattern' => '/(a+)a/', 'possessive' => '/(a++)a/', 'subject' => 'aa', 'expected' => '/(a+)a/'];
        yield 'the other case after the group, caseless' => ['pattern' => '/(a+)A/i', 'possessive' => '/(a++)A/i', 'subject' => 'aa', 'expected' => '/(a+)A/i'];
        yield 'an item that may match nothing, then a character after the group' => ['pattern' => '/(a+)b?a/', 'possessive' => '/(a++)b?a/', 'subject' => 'aa', 'expected' => '/(a+)b?a/'];
        yield 'a character after an alternation' => ['pattern' => '/(?:x|a+)a/', 'possessive' => '/(?:x|a++)a/', 'subject' => 'aa', 'expected' => '/(?:x|a+)a/'];
        yield 'a group that must run again' => ['pattern' => '/(?:a+){2}/', 'possessive' => '/(?:a++){2}/', 'subject' => 'aa', 'expected' => '/(?:a+){2}/'];
        // A run of the group may take what the repetition gave back, and
        // match on past it: "bbac" is "b", "ba", "c".
        yield 'a group that may run again' => ['pattern' => '/(b+|ba)*c/', 'possessive' => '/(b++|ba)*c/', 'subject' => 'bbac', 'expected' => '/(b+|ba)*c/'];
        yield 'a group that may run twice' => ['pattern' => '/(b+|ba){1,2}c/', 'possessive' => '/(b++|ba){1,2}c/', 'subject' => 'bbac', 'expected' => '/(b+|ba){1,2}c/'];
        yield 'a reference to the group a run of it takes again' => ['pattern' => '/(a+)+c\1/', 'possessive' => '/(a++)+c\1/', 'subject' => 'aaca', 'expected' => '/(a+)+c\1/'];
        // A repeated group gives back whole runs, which need not start with
        // a character it ends with: "b" ends a run of "ba" on "bac".
        yield 'a repeated group alone in its group' => ['pattern' => '/((?:b|ba)*)c/', 'possessive' => '/((?:b|ba)*+)c/', 'subject' => 'bac', 'expected' => '/((?:b|ba)*)c/'];
        yield 'a lookahead after the group' => ['pattern' => '/(a+)(?=a)/', 'possessive' => '/(a++)(?=a)/', 'subject' => 'aa', 'expected' => '/(a+)(?=a)/'];
        yield '\K after the group' => ['pattern' => '/(a+)\Ka/', 'possessive' => '/(a++)\Ka/', 'subject' => 'aa', 'expected' => '/(a+)\Ka/'];
        yield 'a reference to the group' => ['pattern' => '/(a+)\1/', 'possessive' => '/(a++)\1/', 'subject' => 'aa', 'expected' => '/(a+)\1/'];
        yield 'a conditional after the group' => ['pattern' => '/(a+)(?(1)a|b)/', 'possessive' => '/(a++)(?(1)a|b)/', 'subject' => 'aa', 'expected' => '/(a+)(?(1)a|b)/'];
        yield 'a caseless option after the group' => ['pattern' => '/(a+)(?i)A/', 'possessive' => '/(a++)(?i)A/', 'subject' => 'aa', 'expected' => '/(a+)(?i)A/'];
        // Under "U" a plain quantifier is lazy: at the end of the pattern
        // it matches one "a".
        yield 'the end of the pattern, under U' => ['pattern' => '/xa+/U', 'possessive' => '/xa++/U', 'subject' => 'xaa', 'expected' => '/xa+/U'];
        yield 'the end of the pattern, after an ungreedy option' => ['pattern' => '/(?U)xa+/', 'possessive' => '/(?U)xa++/', 'subject' => 'xaa', 'expected' => '/(?U)xa+/'];
    }

    /**
     * @param list<string> $changes
     */
    #[Test]
    #[DataProvider('provideOptimizationsOfRepetitionsEndingTheirSequence')]
    public function test_the_optimizer_ships_the_possessive_of_a_repetition_ending_its_sequence(string $pattern, string $expected, array $changes): void
    {
        $this->assertSame(self::matchedOnEverySubject($pattern), self::matchedOnEverySubject($expected), \sprintf('Oracle: %s and %s differ.', $pattern, $expected));

        $result = (new Optimizer(RegexParser::create(['cache' => null])))->optimize($pattern, new OptimizerOptions(possessive: true));

        $this->assertSame($expected, $result->optimized);
        $this->assertSame($changes, $result->changes);
    }

    /**
     * @return iterable<string, array{pattern: string, expected: string, changes: list<string>}>
     */
    public static function provideOptimizationsOfRepetitionsEndingTheirSequence(): iterable
    {
        yield 'a repetition alone in its group' => ['pattern' => '/(a+)c/', 'expected' => '/(a++)c/', 'changes' => [Rewriter::POSSESSIVE]];
        yield 'a repetition ending the pattern' => ['pattern' => '/x[0-9]+/', 'expected' => '/x\d++/', 'changes' => [Rewriter::DIGITS, Rewriter::POSSESSIVE]];
        // The sequence reads its repetition once the items after it are
        // rewritten.
        yield 'a repetition before a group that changes nothing' => ['pattern' => '/[0-9]+(?:c)/', 'expected' => '/\d++c/', 'changes' => [Rewriter::DIGITS, Rewriter::GROUP_REMOVED, Rewriter::POSSESSIVE]];
        // The repeated group that ends the pattern stays greedy: the check
        // of the result proves no possessive group, and would take the
        // other possessive with it.
        yield 'a repeated group ending the pattern' => ['pattern' => '/[ab]{2,}(?:c{2}c*)*/', 'expected' => '/[ab]{2,}+(?:c{2}c*)*/', 'changes' => [Rewriter::POSSESSIVE]];
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
