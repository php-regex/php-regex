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
 * The message of a character-set overlap names the two branches that
 * overlap, spelled as the pattern holds them: a pattern holding three
 * alternations that each overlap, as a quoted-string matcher does, gives
 * three findings a reader tells apart.
 */
final class AlternationOverlapCharsetSpellingTest extends TestCase
{
    #[Test]
    #[DataProvider('provideOverlaps')]
    public function test_the_message_names_the_two_branches_that_overlap(string $pattern, string $first, string $second): void
    {
        $message = $this->charsetOverlapMessages($pattern)[0] ?? null;

        $this->assertNotNull($message, $pattern);
        $this->assertSame(
            \sprintf('Alternation branches "%s" and "%s" have overlapping character sets, which may cause unnecessary backtracking.', $first, $second),
            $message,
            $pattern,
        );
    }

    #[Test]
    public function test_three_alternations_that_each_overlap_give_three_findings_a_reader_tells_apart(): void
    {
        // One quoted-string matcher per kind of quote, as a changelog
        // parser writes them: each holds one alternation whose branches
        // overlap on the backslash.
        $pattern = '/^\[([^]]+)\]: *(\S+(?: +(?:"(?:[^"]|\\\\.)+"|\'(?:[^\']|\\\\.)+\'|\((?:[^()]|\\\\.)+\)))?)\s*\Z/m';

        $messages = $this->charsetOverlapMessages($pattern);

        $this->assertCount(3, $messages, $pattern);
        $this->assertSame(array_unique($messages), $messages, 'The three findings tell their alternation apart.');
    }

    /**
     * @return iterable<string, array{pattern: string, first: string, second: string}>
     */
    public static function provideOverlaps(): iterable
    {
        yield 'a negated class beside an escaped backslash' => [
            'pattern' => '/(?:"(?:[^"]|\\\\.)+")+/',
            'first' => '[^"]',
            'second' => '\\\\\\\\.',
        ];
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
