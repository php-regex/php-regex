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
 * A text read in two cases meets another character by character, each in
 * the case in force where it stands, past its second character too, and
 * under u each character whole: "ö" read caselessly meets no "é", though
 * both start with the same byte. The finding names the first two branches
 * that meet.
 */
final class AlternationOverlapCaseOfEachCharacterTest extends TestCase
{
    /**
     * @param list<string>             $branches the branches of the alternation, each written as the pattern holds it
     * @param array<string, list<int>> $starting the branches that start each subject, each read alone in its place
     */
    #[Test]
    #[DataProvider('provideBranches')]
    public function test_a_text_read_in_two_cases_meets_another_character_by_character(array $branches, string $flags, array $starting, string $message): void
    {
        // Oracle: each branch is run alone in its place, the others failing
        // first, so that an option setting one carries into the next still
        // holds there; the JIT and the interpreter agree.
        $started = [];
        foreach (array_keys($starting) as $subject) {
            $started[$subject] = self::branchesStarting($branches, $flags, (string) $subject);
        }
        $this->assertSame($starting, $started, 'Oracle: the branches each subject starts.');

        $pattern = '/(?:'.implode('|', $branches).')+/'.$flags;
        $linter = new PatternLinter();
        Regex::create(['cache' => null])->parse($pattern)->accept($linter);

        $this->assertContains($message, array_map(static fn ($issue): string => $issue->message, $linter->getIssues()), $pattern);
    }

    /**
     * @return iterable<string, array{branches: list<string>, flags: string, starting: array<string, list<int>>, message: string}>
     */
    public static function provideBranches(): iterable
    {
        yield 'a letter read caselessly as the third character' => [
            'branches' => ['abC', 'ab(?i)c'],
            'flags' => '',
            'starting' => ['abC' => [0, 1], 'abc' => [1]],
            'message' => 'Alternation branches "abC" and "abc" overlap.',
        ];
        yield 'letters read caselessly past the first, under u' => [
            'branches' => ['aBC', 'a(?i)bc'],
            'flags' => 'u',
            'starting' => ['aBC' => [0, 1], 'abc' => [1]],
            'message' => 'Alternation branches "aBC" and "abc" overlap.',
        ];
        yield 'a letter read caselessly beside another of the same first byte, under u' => [
            'branches' => ['aé', 'a(?i:ö)', 'aéx'],
            'flags' => 'u',
            'starting' => ['aéx' => [0, 2], 'aö' => [1], 'aÖ' => [1]],
            'message' => 'Alternation branches "aé" and "aéx" overlap.',
        ];
    }

    /**
     * The branches that match at the start of the subject, each alone in
     * its place in the alternation.
     *
     * @param list<string> $branches
     *
     * @return list<int>
     */
    private static function branchesStarting(array $branches, string $flags, string $subject): array
    {
        $starting = [];
        foreach (array_keys($branches) as $index) {
            $alone = [];
            foreach ($branches as $other => $branch) {
                $alone[] = $other === $index ? $branch : '(*FAIL)'.$branch;
            }

            $pattern = '/^(?:'.implode('|', $alone).')/'.$flags;
            $jit = preg_match($pattern, $subject);
            self::assertSame($jit, preg_match('/(*NO_JIT)'.substr($pattern, 1), $subject), $pattern.' on '.$subject);
            if (1 === $jit) {
                $starting[] = $index;
            }
        }

        return $starting;
    }
}
