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

namespace PHPRegex\Tests\Unit\NodeVisitor;

use PHPRegex\Linter\PatternLinter;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LinterNodeVisitorCorpusBugsTest extends TestCase
{
    /**
     * Test cases from corpus analysis that revealed library bugs.
     * These are patterns from real-world projects that triggered false positives
     * or were incorrectly parsed.
     *
     * @param array<int, string> $expectedIssueIds
     * @param array<int, string> $unexpectedIssueIds
     */
    #[DataProvider('provideCorpusBugCases')]
    public function test_corpus_bug_patterns_are_handled_correctly(
        string $pattern,
        array $expectedIssueIds,
        array $unexpectedIssueIds,
    ): void {
        $regex = Regex::create()->parse($pattern);
        $linter = new PatternLinter();
        $regex->accept($linter);

        $actualIssueIds = array_map(
            static fn ($issue): string => $issue->id,
            $linter->getIssues(),
        );
        $actualIssueIds = array_unique($actualIssueIds);

        foreach ($expectedIssueIds as $expectedId) {
            $this->assertContains(
                $expectedId,
                $actualIssueIds,
                sprintf(
                    'Expected issue ID %s to be reported for pattern: %s',
                    $expectedId,
                    $pattern,
                ),
            );
        }

        foreach ($unexpectedIssueIds as $unexpectedId) {
            $this->assertNotContains(
                $unexpectedId,
                $actualIssueIds,
                sprintf(
                    'Issue ID %s should NOT be reported for pattern: %s',
                    $unexpectedId,
                    $pattern,
                ),
            );
        }
    }

    /**
     * @return iterable<string, array{string, array<int, string>, array<int, string>}>
     */
    public static function provideCorpusBugCases(): iterable
    {
        yield 'suspicious_A_z_range_in_laravel_env' => [
            '/^[a-zA-z0-9]+$/',
            [
                'regex.lint.charclass.suspiciousRange',
            ],
            [],
        ];

        yield 'suspicious_A_z_range_in_php_codesniffer' => [
            '/[a-zA-z0-9_]/',
            [
                'regex.lint.charclass.suspiciousRange',
            ],
            [],
        ];

    }

    /**
     * Test that suspicious ASCII range A-z is properly detected.
     * The range A-z includes [\ \ ] ^ _ ` between Z and a in ASCII order.
     */
    public function test_suspicious_a_z_range_is_detected(): void
    {
        $pattern = '/^[a-zA-z0-9_]+$/';
        $regex = Regex::create()->parse($pattern);
        $linter = new PatternLinter();
        $regex->accept($linter);

        $issueIds = array_map(
            static fn ($issue): string => $issue->id,
            $linter->getIssues(),
        );

        $this->assertContains(
            'regex.lint.charclass.suspiciousRange',
            $issueIds,
        );
    }

    /**
     * Test that proper A-Za-z range does NOT trigger suspicious range warning.
     */
    public function test_proper_a_za_z_range_is_not_flagged(): void
    {
        $pattern = '/^[A-Za-z0-9_]+$/';
        $regex = Regex::create()->parse($pattern);
        $linter = new PatternLinter();
        $regex->accept($linter);

        $issueIds = array_map(
            static fn ($issue): string => $issue->id,
            $linter->getIssues(),
        );

        $this->assertNotContains(
            'regex.lint.charclass.suspiciousRange',
            $issueIds,
        );
    }
}
