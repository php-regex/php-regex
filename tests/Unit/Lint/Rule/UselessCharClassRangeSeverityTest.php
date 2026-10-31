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

use PHPRegex\Linter\LintSeverity;
use PHPRegex\Linter\PatternLinter;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A range of one character, [a-a], is a mistake worth a warning. One of
 * two consecutive characters, [a-b] or [\x{5C4}-\x{5C5}], is correct as
 * written and no shorter as a list: the rule has nothing to say about it,
 * and a generated Unicode table holds them by the thousand.
 */
final class UselessCharClassRangeSeverityTest extends TestCase
{
    private const RULE = 'regex.lint.range.useless';

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideRangesOfOneCharacter(): iterable
    {
        yield 'one character' => ['pattern' => '/[a-a]/'];
        yield 'one digit' => ['pattern' => '/[0-0]/'];
        yield 'one code point under u' => ['pattern' => '/[\x{5C4}-\x{5C4}]/u'];
        yield 'one byte written two ways' => ['pattern' => '/[\x61-a]/'];
    }

    #[Test]
    #[DataProvider('provideRangesOfOneCharacter')]
    public function test_a_range_of_one_character_is_a_warning(string $pattern): void
    {
        $issues = self::rangeIssues($pattern);

        $this->assertCount(1, $issues, $pattern);
        $this->assertSame(LintSeverity::Warning, $issues[0]->severity, $pattern);
        $this->assertSame('Character range is unnecessary; list the characters explicitly.', $issues[0]->message);
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideRangesOfTwoCharactersOrMore(): iterable
    {
        yield 'two characters' => ['pattern' => '/[a-b]/'];
        yield 'two digits' => ['pattern' => '/[0-1]/'];
        yield 'two code points under u' => ['pattern' => '/[\x{5C4}-\x{5C5}]/u'];
        yield 'two bytes without u' => ['pattern' => '/[\x{5C4}-\x{5C5}]/'];
        yield 'two characters in a negated class' => ['pattern' => '/[^a-b]/'];
        yield 'three characters' => ['pattern' => '/[a-c]/'];
        yield 'the digits' => ['pattern' => '/[0-9]/'];
    }

    #[Test]
    #[DataProvider('provideRangesOfTwoCharactersOrMore')]
    public function test_a_range_of_two_characters_or_more_is_correct_as_written(string $pattern): void
    {
        $this->assertSame([], self::rangeIssues($pattern), $pattern);
    }

    /**
     * @return list<object{severity: LintSeverity, message: string}>
     */
    private static function rangeIssues(string $pattern): array
    {
        $linter = new PatternLinter();
        Regex::create(['cache' => null])->parse($pattern)->accept($linter);

        return array_values(array_filter($linter->getIssues(), static fn (object $issue): bool => self::RULE === $issue->id));
    }
}
