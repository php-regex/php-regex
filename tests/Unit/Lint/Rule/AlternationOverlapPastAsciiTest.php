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
    }

    #[Test]
    public function test_a_byte_past_ascii_read_caselessly_still_meets_itself(): void
    {
        $this->assertSame(1, preg_match("/^(?:\xE9|\xE9x)+$/i", "\xE9x"));

        $this->assertCount(1, $this->charsetOverlapMessages("/(?:\xE9+|\xE9x)+/i"));
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
