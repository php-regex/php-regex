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
use PHPRegex\Linter\PatternOccurrence;
use PHPRegex\Linter\Source\PatternSourceCollection;
use PHPRegex\Parser\RegexParser;
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
