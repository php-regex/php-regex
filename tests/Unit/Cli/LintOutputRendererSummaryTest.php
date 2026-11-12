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

namespace PHPRegex\Tests\Unit\Cli;

use PHPRegex\Cli\Command\LintOutputRenderer;
use PHPRegex\Cli\Output;
use PHPRegex\Linter\AnalysisService;
use PHPRegex\Linter\LintReport;
use PHPRegex\Linter\LintRequest;
use PHPRegex\Linter\LintService;
use PHPRegex\Linter\LintStatsCounter;
use PHPRegex\Linter\PatternOccurrence;
use PHPRegex\Linter\Source\PatternSourceCollection;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Tests\Support\ArgumentCarriers;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The CLI renderer's FAIL line names each kind of error apart, as the
 * console report does: a pattern PCRE refuses is an invalid pattern, a
 * failing ReDoS verdict a ReDoS error, a lint rule at Error a lint error.
 *
 * @phpstan-import-type LintStats from LintReport
 */
final class LintOutputRendererSummaryTest extends TestCase
{
    #[Test]
    public function test_a_lint_rule_at_error_is_a_lint_error_not_an_invalid_pattern(): void
    {
        // Oracle: "[é]" compiles; without /u it matches a lone byte of "é"
        // and not "é" itself.
        $this->assertSame(0, preg_match('/^[é]$/', 'é'));
        $this->assertSame(1, preg_match('/^[é]$/u', 'é'));

        $service = new LintService(new AnalysisService(RegexParser::create(['cache' => null])), new PatternSourceCollection([]));
        $stats = $service->analyze(
            [new PatternOccurrence('/[é]/', 'f.php', 1, 'php:preg_match()')],
            new LintRequest(['.'], [], 0, checkOptimizations: false),
        )->stats;
        $this->assertSame(1, $stats['lintErrors'] ?? null);

        $line = self::failLine($stats);

        $this->assertSame('FAIL 1 lint errors, 0 warnings, 0 optimizations.', $line);
    }

    /**
     * @return iterable<string, array{stats: LintStats, line: string}>
     */
    public static function provideStats(): iterable
    {
        yield 'invalid patterns only' => [
            'stats' => ['errors' => 2, 'warnings' => 1, 'optimizations' => 0],
            'line' => 'FAIL 2 invalid patterns, 1 warnings, 0 optimizations.',
        ];
        yield 'a ReDoS error is no invalid pattern' => [
            'stats' => ['errors' => 1, 'warnings' => 0, 'optimizations' => 2, 'redos' => 1],
            'line' => 'FAIL 1 ReDoS errors, 0 warnings, 2 optimizations.',
        ];
        yield 'each kind under its own label' => [
            'stats' => ['errors' => 3, 'warnings' => 2, 'optimizations' => 1, 'redos' => 1, 'lintErrors' => 1],
            'line' => 'FAIL 1 invalid patterns, 1 ReDoS errors, 1 lint errors, 2 warnings, 1 optimizations.',
        ];
    }

    /**
     * @param LintStats $stats
     */
    #[Test]
    #[DataProvider('provideStats')]
    public function test_fail_line_reads_redos_and_lint_errors_from_the_stats(array $stats, string $line): void
    {
        $this->assertSame($line, self::failLine($stats));
    }

    /**
     * The request for a star ends a verbose run only, empty or not.
     */
    #[Test]
    public function test_the_summary_ends_on_the_footer_under_verbose_only(): void
    {
        foreach ([true, false] as $isEmpty) {
            $this->assertStringNotContainsString('GitHub star', self::summary(['errors' => 0, 'warnings' => 0, 'optimizations' => 0], $isEmpty));
            $this->assertStringEndsWith("a GitHub star is appreciated: https://github.com/php-regex/php-regex\n\n", self::summary(['errors' => 0, 'warnings' => 0, 'optimizations' => 0], $isEmpty, true));
        }
    }

    /**
     * @return iterable<string, array{counts: array<string, int>, line: string|null}>
     */
    public static function provideArgumentsOfARunWithoutPattern(): iterable
    {
        yield 'dynamic arguments alone' => ['counts' => ['opaque' => 3], 'line' => 'Not verifiable: 3 pattern arguments (3 dynamic)'];
        yield 'one argument' => ['counts' => ['raw_shell' => 1], 'line' => 'Not verifiable: 1 pattern argument (1 around a dynamic part)'];
        yield 'the three figures' => ['counts' => ArgumentCarriers::COUNTS, 'line' => ArgumentCarriers::LINE];
        yield 'no argument' => ['counts' => [], 'line' => null];
    }

    /**
     * A run that found no pattern still says how many arguments it could
     * not verify, on one line after "No regex patterns found.": a project
     * whose patterns are all built at run time is not an empty one.
     *
     * @param array<string, int> $counts
     */
    #[Test]
    #[DataProvider('provideArgumentsOfARunWithoutPattern')]
    public function test_the_empty_run_says_how_many_arguments_were_not_verified(array $counts, ?string $line): void
    {
        $without = self::summary(LintStatsCounter::count([]), true);
        $this->assertStringContainsString('No regex patterns found.', $without);
        $this->assertStringNotContainsString('Not verifiable', $without);

        $written = self::summary(LintStatsCounter::count([], ArgumentCarriers::of($counts)), true);

        if (null === $line) {
            $this->assertSame($without, $written);

            return;
        }

        $this->assertNotSame('', $without);
        $this->assertStringStartsWith($without, $written);
        $this->assertSame($line, trim(substr($written, \strlen($without))));
        $this->assertStringEndsWith("\n", $written);
    }

    /**
     * @param LintStats $stats
     */
    private static function summary(array $stats, bool $isEmpty, ?bool $verbose = null): string
    {
        $stream = fopen('php://memory', 'w+');
        self::assertIsResource($stream);

        ob_start();

        try {
            $renderer = new LintOutputRenderer();
            $output = new Output(false, false, errorStream: $stream);
            null === $verbose ? $renderer->renderSummary($output, $stats, $isEmpty) : $renderer->renderSummary($output, $stats, $isEmpty, $verbose);
        } finally {
            $written = (string) ob_get_clean();
            fclose($stream);
        }

        return $written;
    }

    /**
     * @param LintStats $stats
     */
    private static function failLine(array $stats): string
    {
        $stream = fopen('php://memory', 'w+');
        self::assertIsResource($stream);

        ob_start();

        try {
            (new LintOutputRenderer())->renderSummary(new Output(false, false, errorStream: $stream), $stats);
        } finally {
            $output = (string) ob_get_clean();
            fclose($stream);
        }

        self::assertSame(1, preg_match('/^\s*(FAIL\b.*)$/m', $output, $match), $output);

        return $match[1];
    }
}
