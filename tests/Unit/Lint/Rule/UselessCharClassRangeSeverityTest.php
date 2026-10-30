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
 * A range of one character, [a-a], is a mistake worth a warning; one of
 * two consecutive characters, [a-b] or [\x{5C4}-\x{5C5}], is correct as
 * written, a generated table says it so by the thousand, and is only
 * worth telling.
 */
final class UselessCharClassRangeSeverityTest extends TestCase
{
    private const RULE = 'regex.lint.range.useless';

    /**
     * @return iterable<string, array{pattern: string, severity: LintSeverity}>
     */
    public static function provideRanges(): iterable
    {
        yield 'one character' => ['pattern' => '/[a-a]/', 'severity' => LintSeverity::Warning];
        yield 'two characters' => ['pattern' => '/[a-b]/', 'severity' => LintSeverity::Info];
        yield 'two digits' => ['pattern' => '/[0-1]/', 'severity' => LintSeverity::Info];
        yield 'two code points under u' => ['pattern' => '/[\x{5C4}-\x{5C5}]/u', 'severity' => LintSeverity::Info];
        yield 'one code point under u' => ['pattern' => '/[\x{5C4}-\x{5C4}]/u', 'severity' => LintSeverity::Warning];
    }

    #[Test]
    #[DataProvider('provideRanges')]
    public function test_the_severity_follows_the_width_of_the_range(string $pattern, LintSeverity $severity): void
    {
        $issues = self::rangeIssues($pattern);

        $this->assertCount(1, $issues, $pattern);
        $this->assertSame($severity, $issues[0]->severity, $pattern);
        $this->assertSame('Character range is unnecessary; list the characters explicitly.', $issues[0]->message);
    }

    #[Test]
    public function test_a_range_of_three_characters_is_left_alone(): void
    {
        $this->assertSame([], self::rangeIssues('/[a-c]/'));
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
