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

namespace PHPRegex\Tests\Unit\Lint\Formatter;

use PHPRegex\Laravel\Output\LaravelConsoleFormatter;
use PHPRegex\Linter\AnalysisService;
use PHPRegex\Linter\Formatter\ConsoleFormatter;
use PHPRegex\Linter\Formatter\LinkFormatter;
use PHPRegex\Linter\Formatter\OutputConfiguration;
use PHPRegex\Linter\Formatter\OutputFormatterInterface;
use PHPRegex\Linter\Formatter\RelativePathHelper;
use PHPRegex\Linter\LintReport;
use PHPRegex\Linter\LintRequest;
use PHPRegex\Linter\LintService;
use PHPRegex\Linter\PatternOccurrence;
use PHPRegex\Linter\Source\PatternSourceCollection;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Redos\RedosMode;
use PHPRegex\Symfony\Output\SymfonyConsoleFormatter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The summary line of the console reports other than the CLI's own: the
 * quiet console report, and the Symfony and Laravel lint commands. A ReDoS
 * error is not an invalid pattern: the pattern compiles.
 *
 * (a+)+$ on a…a! exhausts the backtrack limit at 19 pumps, JIT on and off
 * (PCRE2 10.49): in confirmed mode it is a replayed critical verdict, an
 * error. PCRE refuses "(unclosed" ("missing closing parenthesis at offset 9").
 */
final class RedosSummaryLabelTest extends TestCase
{
    #[Test]
    #[DataProvider('provideFormatters')]
    public function test_summary_does_not_count_a_redos_error_as_an_invalid_pattern(string $formatter): void
    {
        $report = self::report('/(a+)+$/');
        $this->assertSame(1, $report->stats['errors']);

        $summary = self::summaryLine(self::formatter($formatter)->format($report));

        $this->assertDoesNotMatchRegularExpression('/[1-9]\d* invalid patterns?/', $summary);
        $this->assertMatchesRegularExpression('/\b1\b[^,.]*\bReDoS\b/i', $summary);
    }

    #[Test]
    #[DataProvider('provideFormatters')]
    public function test_summary_counts_a_pattern_pcre_rejects_as_invalid(string $formatter): void
    {
        $this->assertFalse(@preg_match(self::invalidPattern(), ''));

        $summary = self::summaryLine(self::formatter($formatter)->format(self::report(self::invalidPattern())));

        $this->assertStringContainsString('1 invalid patterns', $summary);
    }

    /**
     * A lint rule at Error fails the run, but the pattern compiles: it is a
     * lint error, not an invalid pattern. Without /u, \p{L} stops at the
     * first 256 code points.
     */
    #[Test]
    #[DataProvider('provideFormatters')]
    public function test_summary_does_not_count_a_lint_error_as_an_invalid_pattern(string $formatter): void
    {
        $this->assertSame(0, preg_match('/^\p{L}$/', 'ā'));
        $report = self::report('/\p{L}/');
        $this->assertSame(1, $report->stats['errors']);

        $summary = self::summaryLine(self::formatter($formatter)->format($report));

        $this->assertDoesNotMatchRegularExpression('/[1-9]\d* invalid patterns?/', $summary);
        $this->assertStringContainsString('1 lint error', $summary);
    }

    /**
     * @return iterable<string, array{formatter: string}>
     */
    public static function provideFormatters(): iterable
    {
        yield 'quiet console' => ['formatter' => 'quiet'];
        yield 'symfony' => ['formatter' => 'symfony'];
        yield 'laravel' => ['formatter' => 'laravel'];
    }

    private static function formatter(string $name): OutputFormatterInterface
    {
        $service = new AnalysisService(RegexParser::create(['cache' => null]));
        $links = new LinkFormatter(null, new RelativePathHelper('/project'));

        return match ($name) {
            'quiet' => new ConsoleFormatter(null, OutputConfiguration::quiet()),
            'symfony' => new SymfonyConsoleFormatter($service, $links, false),
            default => new LaravelConsoleFormatter($service, $links, false),
        };
    }

    private static function report(string $pattern): LintReport
    {
        $analysis = new AnalysisService(RegexParser::create(['cache' => null]), redosThreshold: 'high', redosMode: RedosMode::Confirmed, redosEnabled: true);
        $lint = new LintService($analysis, new PatternSourceCollection([]));

        return $lint->analyze(
            [new PatternOccurrence($pattern, 'file.php', 1, 'php:preg_match()')],
            new LintRequest(['.'], [], 0, checkRedos: true, checkOptimizations: false),
        );
    }

    /**
     * The last FAIL line, its console tags removed.
     */
    private static function summaryLine(string $output): string
    {
        $plain = preg_replace('/<(?:[a-z]+=[^<>]*|\/)>/', '', $output);
        self::assertIsString($plain);
        self::assertNotFalse(preg_match_all('/^.*\bFAIL\b.*$/m', $plain, $lines));
        self::assertNotSame([], $lines[0], $output);

        return $lines[0][\count($lines[0]) - 1];
    }

    /**
     * Through a call, so that static analysis does not compile it.
     */
    private static function invalidPattern(): string
    {
        return '/(unclosed/';
    }
}
