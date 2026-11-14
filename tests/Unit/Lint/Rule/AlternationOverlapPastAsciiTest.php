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

namespace PHPRegex\Tests\Unit\Lint\Rule;

use PHPRegex\Linter\PatternLinter;
use PHPRegex\Tests\Support\PcreGates;
use PHPRegex\Tests\Support\ProbeDelimiters;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Branches starting past ASCII overlap when PCRE reads a character both
 * take, not when their first bytes meet: under u two characters share a
 * UTF-8 lead byte, and a byte read caselessly folds as the target's tables
 * say, never into every other set.
 */
final class AlternationOverlapPastAsciiTest extends TestCase
{
    use PcreGates;

    /**
     * @param list<string> $firsts the character each branch starts with
     */
    #[Test]
    #[DataProvider('provideBranchesPcreTellsApart')]
    public function test_branches_no_character_starts_both_are_not_reported(string $pattern, string $branches, string $flags, array $firsts): void
    {
        // The oracle: the first character of each branch starts that branch
        // and no other.
        foreach (explode('|', $branches) as $j => $branch) {
            foreach ($firsts as $i => $first) {
                $this->assertSame($i === $j ? 1 : 0, preg_match('/^(?:'.$branch.')/'.$flags, $first), \sprintf('%s on %s', $branch, bin2hex($first)));
            }
        }

        $this->assertSame([], $this->charsetOverlapMessages($pattern), $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string, branches: string, flags: string, firsts: list<string>}>
     */
    public static function provideBranchesPcreTellsApart(): iterable
    {
        // "ö" is C3 B6 and "è" C3 A8: one lead byte, two characters.
        yield 'two characters sharing a lead byte under u' => ['pattern' => '/(?:ö|è)+/u', 'branches' => 'ö|è', 'flags' => 'u', 'firsts' => ['ö', 'è']];
        yield 'a repeated character beside another sharing its lead byte under u' => ['pattern' => '/(?:é+|à)+/u', 'branches' => 'é+|à', 'flags' => 'u', 'firsts' => ['é', 'à']];
        // preg_match("/\xE9/i", "c") and preg_match("/\xE9/i", "\xC9") are 0
        // in the C locale: the byte folds into no ASCII letter.
        yield 'a byte past ASCII read caselessly beside letters' => ['pattern' => "/(?:\xE9+|c|b+)+/i", 'branches' => "\xE9+|c|b+", 'flags' => 'i', 'firsts' => ["\xE9", 'c', 'b']];
        yield 'a letter beside a byte past ASCII read caselessly' => ['pattern' => "/(?:c|\xE9)+/i", 'branches' => "c|\xE9", 'flags' => 'i', 'firsts' => ['c', "\xE9"]];
        // PCRE reads the first character through the group a branch opens
        // with: an atomic group, or a group setting options for its body.
        yield 'an atomic group sharing a lead byte under u' => ['pattern' => '/(?:(?>ö)|è)+x/u', 'branches' => '(?>ö)|è', 'flags' => 'u', 'firsts' => ['ö', 'è']];
        yield 'a caseless group sharing a lead byte under u' => ['pattern' => '/(?:(?i:ö)|è)+x/u', 'branches' => '(?i:ö)|è', 'flags' => 'u', 'firsts' => ['ö', 'è']];
    }

    #[Test]
    public function test_a_byte_past_ascii_read_caselessly_still_meets_itself(): void
    {
        $this->assertSame(1, preg_match("/^(?:\xE9|\xE9x)+$/i", "\xE9x"));

        $this->assertCount(1, $this->charsetOverlapMessages("/(?:\xE9+|\xE9x)+/i"));
    }

    /**
     * Read through the group a branch opens with, the options it sets
     * included: under "(?i:", "Ö" starts the branch "ö" does.
     */
    #[Test]
    public function test_a_caseless_group_meets_the_other_case_under_u(): void
    {
        $this->assertSame(1, preg_match('/^(?i:öa)/u', 'Öa'));
        $this->assertSame(1, preg_match('/^Öb/u', 'Öb'));

        $this->assertCount(1, $this->charsetOverlapMessages('/(?:(?i:öa)|Öb)+x/u'));
    }

    /**
     * The first characters of a branch are those of each item PCRE may
     * start it with: past an optional item to what follows it, through
     * every branch of a branch reset group, and a character written as
     * itself under the options of "a", which restrict the types and the
     * POSIX classes alone.
     *
     * @param list<array{string, string, int}> $oracle a branch read alone, a subject, whether it starts there
     */
    #[Test]
    #[DataProvider('provideBranchesReadThroughTheirFirstItems')]
    public function test_the_first_characters_are_read_through_the_first_items(string $pattern, array $oracle, int $findings): void
    {
        // PCRE2 reads the options of "a" from 10.43.
        $this->requiresPcre2For($pattern, ['10.43' => ['/(?:(?a:ö)|è)+x/u', '/(?:(?a:öa)|öb)+x/u', '/(?:(?ai:öa)|Öb)+x/u', '/(?:(?a:\p{Lu})x|\p{L}y)+z/u', '/(?:(?a:[ö])x|èy)+z/u', '/(?:(?a:\w)x|éy)+z/u', '/(?:(?aW:\w)x|éy)+z/u', '/(?:(?aD:\w)x|éy)+z/u', '/(?:(?aD:\w)x|éy)+z/uD', '/(?:(?aS:\w)x|éy)+z/uS', '/(?:(?a:\w)x|éy)+z/uD', '/(?aD)(?:\wx|éy)+z/uD']]);

        foreach ($oracle as [$branch, $subject, $starts]) {
            $this->assertSame($starts, preg_match('/^(?:'.$branch.')/u', $subject), $branch.' on '.$subject);
        }

        $this->assertCount($findings, $this->charsetOverlapMessages($pattern), $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string, oracle: list<array{string, string, int}>, findings: int}>
     */
    public static function provideBranchesReadThroughTheirFirstItems(): iterable
    {
        yield 'an optional first item, apart' => ['pattern' => '/(?:ö?è|ê)+x/u', 'oracle' => [['ö?è', 'öè', 1], ['ö?è', 'è', 1], ['ö?è', 'ê', 0], ['ê', 'ö', 0], ['ê', 'è', 0]], 'findings' => 0];
        yield 'an optional first item, meeting the other branch' => ['pattern' => '/(?:ö?è|öb)+x/u', 'oracle' => [['ö?è', 'öè', 1], ['öb', 'öb', 1]], 'findings' => 1];
        yield 'an optional first item, meeting through what follows it' => ['pattern' => '/(?:ö?è|èb)+x/u', 'oracle' => [['ö?è', 'è', 1], ['èb', 'èb', 1]], 'findings' => 1];
        yield 'a branch reset group, apart' => ['pattern' => '/(?:(?|ö|ê)|è)+x/u', 'oracle' => [['(?|ö|ê)', 'ö', 1], ['(?|ö|ê)', 'ê', 1], ['(?|ö|ê)', 'è', 0]], 'findings' => 0];
        yield 'a branch reset group, meeting the other branch' => ['pattern' => '/(?:(?|ö|ê)a|êb)+x/u', 'oracle' => [['(?|ö|ê)a', 'êa', 1], ['êb', 'êb', 1]], 'findings' => 1];
        yield 'a character under the options of a, apart' => ['pattern' => '/(?:(?a:ö)|è)+x/u', 'oracle' => [['(?a:ö)', 'ö', 1], ['(?a:ö)', 'è', 0], ['è', 'ö', 0]], 'findings' => 0];
        yield 'a character under the options of a, meeting the other branch' => ['pattern' => '/(?:(?a:öa)|öb)+x/u', 'oracle' => [['(?a:öa)', 'öa', 1], ['öb', 'öb', 1]], 'findings' => 1];
        yield 'a bare option group before the first character' => ['pattern' => '/(?:(?i)öa|Öb)+x/u', 'oracle' => [['(?i)öa', 'Öa', 1], ['Öb', 'Öb', 1]], 'findings' => 1];
        yield 'an alternation with an empty branch first' => ['pattern' => '/(?:(?:|ö)è|ê)+x/u', 'oracle' => [['(?:|ö)è', 'öè', 1], ['(?:|ö)è', 'è', 1], ['(?:|ö)è', 'ê', 0], ['ê', 'ö', 0], ['ê', 'è', 0]], 'findings' => 0];
        yield 'an item repeated no time first' => ['pattern' => '/(?:(?:x){0}öa|èb)+x/u', 'oracle' => [['(?:x){0}öa', 'öa', 1], ['(?:x){0}öa', 'è', 0], ['èb', 'ö', 0]], 'findings' => 0];
        // The options of "a" leave the Unicode properties as they are:
        // preg_match('/^(?a:\p{Lu})/u', 'É') is 1 where (?a:\w) gives 0.
        yield 'a property under the options of a, meeting the other branch' => ['pattern' => '/(?:(?a:\p{Lu})x|\p{L}y)+z/u', 'oracle' => [['(?a:\p{Lu})x', 'Éx', 1], ['\p{L}y', 'Éy', 1]], 'findings' => 1];
        // A class or a type is probed under the very options of "a" that
        // stand where it does: (?aW) restricts \w to ASCII, (?aD) leaves it.
        yield 'a class under the options of a, apart' => ['pattern' => '/(?:(?a:[ö])x|èy)+z/u', 'oracle' => [['(?a:[ö])x', 'öx', 1], ['(?a:[ö])x', 'èx', 0], ['èy', 'öy', 0]], 'findings' => 0];
        yield 'a type under the options of a, apart' => ['pattern' => '/(?:(?a:\w)x|éy)+z/u', 'oracle' => [['(?a:\w)x', 'éx', 0], ['éy', 'éy', 1]], 'findings' => 0];
        yield 'a type under the word option of a, apart' => ['pattern' => '/(?:(?aW:\w)x|éy)+z/u', 'oracle' => [['(?aW:\w)x', 'éx', 0], ['éy', 'éy', 1]], 'findings' => 0];
        yield 'a type under the digit option of a, meeting the other branch' => ['pattern' => '/(?:(?aD:\w)x|éy)+z/u', 'oracle' => [['(?aD:\w)x', 'éx', 1], ['éy', 'éy', 1]], 'findings' => 1];
        // The options of "a" are read from the option groups alone: the
        // pattern's own /D and /S are no "(?aD)" or "(?aS)".
        yield 'the digit option of a beside the pattern\'s /D, meeting the other branch' => ['pattern' => '/(?:(?aD:\w)x|éy)+z/uD', 'oracle' => [['(?aD:\w)x', 'éx', 1], ['éy', 'éy', 1]], 'findings' => 1];
        yield 'the space option of a beside the pattern\'s /S, meeting the other branch' => ['pattern' => '/(?:(?aS:\w)x|éy)+z/uS', 'oracle' => [['(?aS:\w)x', 'éx', 1], ['éy', 'éy', 1]], 'findings' => 1];
        yield 'every option of a beside the pattern\'s /D, apart' => ['pattern' => '/(?:(?a:\w)x|éy)+z/uD', 'oracle' => [['(?a:\w)x', 'éx', 0], ['éy', 'éy', 1]], 'findings' => 0];
        yield 'the digit option of a set before the alternation, beside the pattern\'s /D' => ['pattern' => '/(?aD)(?:\wx|éy)+z/uD', 'oracle' => [['(?aD)\wx', 'éx', 1], ['éy', 'éy', 1]], 'findings' => 1];
        yield 'a caseless character under the options of a' => ['pattern' => '/(?:(?ai:öa)|Öb)+x/u', 'oracle' => [['(?ai:öa)', 'Öa', 1], ['Öb', 'Öb', 1]], 'findings' => 1];
    }

    /**
     * Where no probe can be written for a branch, the first bytes are
     * compared: under u two characters sharing a lead byte meet there. Not
     * proven, kept: a repeated alternation PCRE cannot read stays reported.
     * A class holding every delimiter the probe can be written with is one
     * such branch.
     */
    #[Test]
    public function test_a_branch_pcre_cannot_be_asked_about_is_read_by_its_bytes(): void
    {
        $class = ProbeDelimiters::classOfEveryOne('è');
        $this->assertSame(1, preg_match('{^'.$class.'$}u', 'è'));
        $this->assertSame(0, preg_match('{^'.$class.'$}u', 'ö'));

        $this->assertCount(1, $this->charsetOverlapMessages('{(?:ö|'.$class.')+x}u'));
    }

    #[Test]
    public function test_two_characters_under_u_that_a_class_holds_both_overlap(): void
    {
        $this->assertSame(1, preg_match('/^[à-ÿ]$/u', 'é'));

        $this->assertCount(1, $this->charsetOverlapMessages('/(?:[à-ÿ]+|é)+/u'));
    }

    /**
     * @return list<string>
     */
    private function charsetOverlapMessages(string $pattern): array
    {
        $linter = new PatternLinter();
        Regex::create(['cache' => null])->parse($pattern)->accept($linter);

        $messages = [];
        foreach ($linter->getIssues() as $issue) {
            if (str_contains($issue->message, 'have overlapping character sets')) {
                $messages[] = $issue->message;
            }
        }

        return $messages;
    }
}
