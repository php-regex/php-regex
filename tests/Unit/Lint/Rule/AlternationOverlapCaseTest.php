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
use PHPRegex\Tests\Support\PcreInterpreter;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Under i a literal branch reads its letters in either case: "a" and "A"
 * overlap, and repeated they backtrack exponentially. PHP 8.4.26 / PCRE2
 * 10.49, the interpreter: each overlapping row exhausts the backtrack limit
 * on 30 letters then "!".
 */
final class AlternationOverlapCaseTest extends TestCase
{
    private const RULE = 'regex.lint.alternation.overlap';

    #[Test]
    #[DataProvider('provideCaselessOverlaps')]
    public function test_literal_branches_that_overlap_in_another_case_are_reported(string $pattern, string $letter): void
    {
        $this->assertFalse(@preg_match(PcreInterpreter::pattern($pattern), str_repeat($letter, 30).'!'), $pattern);

        $this->assertContains(self::RULE, $this->ids($pattern), $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string, letter: string}>
     */
    public static function provideCaselessOverlaps(): iterable
    {
        yield 'two cases of one letter' => ['pattern' => '/^(?:a|A)+$/i', 'letter' => 'a'];
        yield 'one branch caseless' => ['pattern' => '/^(?:a|(?i:A))+$/', 'letter' => 'a'];
        yield 'a prefix in another case' => ['pattern' => '/^(?:ab|AB)+$/i', 'letter' => 'ab'];
        yield 'two cases of a letter under u' => ['pattern' => '/^(?:é|É)+$/iu', 'letter' => 'é'];
        yield 'a sigma and a final sigma under u' => ['pattern' => '/^(?:σ|ς)+$/iu', 'letter' => 'σ'];
    }

    #[Test]
    public function test_literal_branches_in_two_cases_without_i_are_not_reported(): void
    {
        $this->assertSame(0, preg_match('/^(?:a|A)+$/', str_repeat('a', 30).'!'));

        $this->assertNotContains(self::RULE, $this->ids('/^(?:a|A)+$/'));
    }

    /**
     * @return list<string>
     */
    private function ids(string $pattern): array
    {
        $linter = new PatternLinter();
        Regex::create(['cache' => null])->parse($pattern)->accept($linter);

        return array_values(array_map(static fn ($issue): string => $issue->id, $linter->getIssues()));
    }
}
