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

namespace PHPRegex\Tests\Unit\Lint;

use PHPRegex\Linter\LintIssue;
use PHPRegex\Linter\LintSeverity;
use PHPRegex\Linter\LintStatsCounter;
use PHPRegex\Linter\PatternOccurrence;
use PHPRegex\Parser\Validation\ValidationErrorCategory;
use PHPRegex\Tests\Support\ArgumentCarriers;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LintStatsCounterTest extends TestCase
{
    /**
     * @return iterable<string, array{counts: array<string, int>}>
     */
    public static function provideArgumentsByState(): iterable
    {
        yield 'every state' => ['counts' => ['constant' => 4, 'finite_set' => 2, 'quoted_shell' => 3, 'raw_shell' => 5, 'opaque' => 1]];
        yield 'one state' => ['counts' => ['constant' => 0, 'finite_set' => 0, 'quoted_shell' => 0, 'raw_shell' => 0, 'opaque' => 6]];
        yield 'one argument' => ['counts' => ['constant' => 1, 'finite_set' => 0, 'quoted_shell' => 0, 'raw_shell' => 0, 'opaque' => 0]];
        yield 'no argument' => ['counts' => ['constant' => 0, 'finite_set' => 0, 'quoted_shell' => 0, 'raw_shell' => 0, 'opaque' => 0]];
    }

    /**
     * The pattern arguments the run anchored are counted by the state each
     * ends in, the five states always, and the five counts sum to the
     * arguments: every argument is in one state.
     *
     * @param array<string, int> $counts
     */
    #[Test]
    #[DataProvider('provideArgumentsByState')]
    public function test_count_gives_each_state_its_arguments_and_the_counts_sum_to_them(array $counts): void
    {
        $carriers = ArgumentCarriers::of($counts);
        shuffle($carriers);

        $stats = LintStatsCounter::count([], $carriers);

        $this->assertSame($counts, ArgumentCarriers::counts($stats));
        $this->assertSame(\count($carriers), array_sum(ArgumentCarriers::counts($stats)));
        // An argument is no issue: it fails nothing and warns of nothing.
        $this->assertSame(0, $stats['errors']);
        $this->assertSame(0, $stats['warnings']);
        $this->assertSame(0, $stats['optimizations']);
        $this->assertSame(0, $stats['infos'] ?? 0);
    }

    /**
     * What is no argument is not counted: a pattern, the file read, the
     * file the tokenizer read again, the file nothing could read. The
     * unit is the argument, not the pattern: a constant two calls read is
     * one pattern and two arguments.
     */
    #[Test]
    public function test_count_reads_the_arguments_among_the_other_occurrences(): void
    {
        $occurrences = [
            new PatternOccurrence('/shared/', 'src/Patterns.php', 7, 'preg_match()', column: 25, fileOffset: 90),
            PatternOccurrence::argument('constant', 'src/a.php', 3, 12, 40, 'preg_match()', 'Patterns::SHARED'),
            PatternOccurrence::argument('constant', 'src/b.php', 3, 12, 40, 'preg_match()', 'Patterns::SHARED'),
            PatternOccurrence::argument('opaque', 'src/b.php', 4, 12, 80, 'preg_match()', '$re'),
            PatternOccurrence::scanned('src/a.php', []),
            PatternOccurrence::scanned('src/b.php', []),
            PatternOccurrence::parserFallback('src/c.php', 'Syntax error'),
            PatternOccurrence::unread('src/d.php', 'Permission denied'),
        ];

        $stats = LintStatsCounter::count([], $occurrences);

        $this->assertSame(['constant' => 2, 'finite_set' => 0, 'quoted_shell' => 0, 'raw_shell' => 0, 'opaque' => 1], ArgumentCarriers::counts($stats));
        $this->assertSame(1, $stats['parserFallbacks'] ?? null);
    }

    #[Test]
    public function test_count_names_each_kind_of_error_apart(): void
    {
        $regex = Regex::create();
        $invalid = $regex->validate('/(/');
        $redos = $regex->redos('/^(a+)+$/');

        $results = [
            [
                'file' => 'a.php',
                'line' => 1,
                'pattern' => '/(/',
                'issues' => [
                    new LintIssue(LintSeverity::Error, 'invalid', 'a.php', 1, validation: $invalid),
                    new LintIssue(LintSeverity::Error, 'redos', 'a.php', 1, analysis: $redos),
                    new LintIssue(LintSeverity::Error, 'lint rule', 'a.php', 1),
                    new LintIssue(LintSeverity::Warning, 'warning', 'a.php', 1),
                    new LintIssue(LintSeverity::Info, 'info', 'a.php', 1),
                    new LintIssue(LintSeverity::Info, 'info', 'a.php', 1),
                ],
                'optimizations' => [],
            ],
        ];

        $this->assertSame(
            ['errors' => 3, 'warnings' => 1, 'optimizations' => 0, 'redos' => 1, 'infos' => 2, 'lintErrors' => 1],
            LintStatsCounter::count($results),
        );
    }

    #[Test]
    public function test_count_names_the_warnings_of_the_patterns_not_analyzed(): void
    {
        $regex = Regex::create(['cache' => null, 'max_pattern_length' => 3]);
        $limit = $regex->validate('/abcd/');
        $this->assertSame(ValidationErrorCategory::Limit, $limit->category);

        $results = [
            [
                'file' => 'a.php',
                'line' => 1,
                'pattern' => '/abcd/',
                'issues' => [
                    new LintIssue(LintSeverity::Warning, 'Not analyzed: too long', 'a.php', 1, validation: $limit),
                    // A warning of a validation that is no limit is not one.
                    new LintIssue(LintSeverity::Warning, 'warning', 'a.php', 1, validation: $regex->validate('/a/')),
                    new LintIssue(LintSeverity::Warning, 'warning', 'a.php', 1),
                ],
                'optimizations' => [],
            ],
        ];

        $this->assertSame(['errors' => 0, 'warnings' => 3, 'optimizations' => 0, 'notAnalyzed' => 1], LintStatsCounter::count($results));
    }

    #[Test]
    public function test_count_leaves_out_the_kinds_at_zero(): void
    {
        $results = [
            [
                'file' => 'a.php',
                'line' => 1,
                'pattern' => '/a/',
                'issues' => [
                    new LintIssue(LintSeverity::Warning, 'warning', 'a.php', 1),
                ],
                'optimizations' => [],
            ],
        ];

        $this->assertSame(['errors' => 0, 'warnings' => 1, 'optimizations' => 0], LintStatsCounter::count($results));
        $this->assertSame(['errors' => 0, 'warnings' => 0, 'optimizations' => 0], LintStatsCounter::count([]));
    }
}
