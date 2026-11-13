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
use PHPRegex\Parser\Node\RegexNode;
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
    /**
     * @param array{string, string} $subjects a subject each branch starts, both with one character
     */
    #[Test]
    #[DataProvider('provideOverlaps')]
    public function test_the_message_names_the_two_branches_that_overlap(string $pattern, string $first, string $second, string $flags, array $subjects): void
    {
        // The oracle: each branch, as the message quotes it, takes its
        // subject, and the two subjects start with the same character.
        $this->assertSame(1, preg_match('/^(?:'.$first.')/'.$flags, $subjects[0]), $first);
        $this->assertSame(1, preg_match('/^(?:'.$second.')/'.$flags, $subjects[1]), $second);
        $this->assertSame(1, preg_match('/^(.)/s'.$flags, $subjects[0], $lead));
        $this->assertSame(1, preg_match('/^(.)/s'.$flags, $subjects[1], $other));
        $this->assertSame($lead[1], $other[1]);

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

    #[Test]
    public function test_a_tree_without_its_source_quotes_the_branches_as_printed(): void
    {
        $this->assertSame(1, preg_match('/^\d+/', '3'));
        $this->assertSame(1, preg_match('/^[0-4]a/', '3a'));

        $ast = Regex::create(['cache' => null])->parse('/(?:\d+|[0-4]a)+/');
        $linter = new PatternLinter();
        (new RegexNode($ast->pattern, $ast->flags, $ast->delimiter, $ast->startPosition, $ast->endPosition))->accept($linter);

        $messages = array_values(array_filter(array_map(static fn ($issue): string => $issue->message, $linter->getIssues()), static fn (string $message): bool => str_contains($message, 'have overlapping character sets')));

        $this->assertSame(['Alternation branches "\\d+" and "[0-4]a" have overlapping character sets, which may cause unnecessary backtracking.'], $messages);
    }

    /**
     * @return iterable<string, array{pattern: string, first: string, second: string, flags: string, subjects: array{string, string}}>
     */
    public static function provideOverlaps(): iterable
    {
        yield 'a negated class beside an escaped backslash' => [
            'pattern' => '/(?:"(?:[^"]|\\\\.)+")+/',
            'first' => '[^"]',
            'second' => '\\\\.',
            'flags' => '',
            'subjects' => ['\\', '\\a'],
        ];
        // Under u a character is spelled as written, as the literal
        // findings print it.
        yield 'a character past ASCII under u' => [
            'pattern' => '/(?:é+|\w)+/u',
            'first' => 'é+',
            'second' => '\w',
            'flags' => 'u',
            'subjects' => ['é', 'é'],
        ];
        // Without u each byte past ASCII is "\xHH".
        yield 'a byte past ASCII without u' => [
            'pattern' => "/(?:[\xE0-\xEF]+|\xE9)+/",
            'first' => '[\xE0-\xEF]+',
            'second' => '\xE9',
            'flags' => '',
            'subjects' => ["\xE9", "\xE9"],
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
