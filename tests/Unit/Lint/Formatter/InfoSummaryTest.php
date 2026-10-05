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
use PHPRegex\Linter\Formatter\RelativePathHelper;
use PHPRegex\Linter\LintReport;
use PHPRegex\Linter\LintRequest;
use PHPRegex\Linter\LintService;
use PHPRegex\Linter\PatternOccurrence;
use PHPRegex\Linter\Source\PatternSourceCollection;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Symfony\Output\SymfonyConsoleFormatter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The summary line of a run. An info does not fail the run nor warn, but a
 * summary printed under INFO lines does not claim "No issues found": once
 * there are infos it counts them next to the warnings, whether the run
 * passes or fails.
 *
 * "(a)+" is an unnamed quantified capture, an info: PCRE keeps only the last
 * iteration's capture. "a\d+?" ends with a lazy quantifier, a warning: PCRE
 * stops it at its minimum.
 */
final class InfoSummaryTest extends TestCase
{
    /**
     * @return iterable<string, array{formatter: string, patterns: list<string>, summary: string}>
     */
    public static function provideSummaries(): iterable
    {
        $rows = [
            'infos only' => [['/(a)+/', '/(b)*c/'], '0 warnings, 2 infos found, 0 optimizations available.'],
            'warnings and infos' => [['/(a)+/', '/a\d+?/'], '1 warnings, 1 infos found, 0 optimizations available.'],
            'warnings only' => [['/a\d+?/'], '1 warnings found, 0 optimizations available.'],
            'nothing' => [['/a/'], 'No issues found, 0 optimizations available.'],
        ];

        foreach ($rows as $name => [$patterns, $summary]) {
            yield 'console, '.$name => ['formatter' => 'console', 'patterns' => $patterns, 'summary' => 'PASS '.$summary];
            yield 'quiet console, '.$name => ['formatter' => 'quiet', 'patterns' => $patterns, 'summary' => (str_starts_with($summary, '1 warnings') ? 'WARN: ' : 'PASS: ').$summary];
            yield 'symfony, '.$name => ['formatter' => 'symfony', 'patterns' => $patterns, 'summary' => 'PASS '.$summary];
            yield 'laravel, '.$name => ['formatter' => 'laravel', 'patterns' => $patterns, 'summary' => 'PASS '.$summary];
        }
    }

    /**
     * @param list<string> $patterns
     */
    #[Test]
    #[DataProvider('provideSummaries')]
    public function test_summary_counts_the_infos_of_a_passing_run(string $formatter, array $patterns, string $summary): void
    {
        // Oracle: only the last iteration's capture is kept, and a lazy
        // quantifier that ends the pattern stops at its minimum.
        $this->assertSame(1, preg_match('/(a)+/', 'ab', $captures));
        $this->assertSame(1, preg_match('/(a)+/', 'aab', $captures));
        $this->assertSame(['aa', 'a'], $captures);
        $this->assertSame(1, preg_match('/a\d+?/', 'a123', $match));
        $this->assertSame(['a1'], $match);

        $report = self::lint($patterns);
        $this->assertSame(0, $report->stats['errors']);

        $this->assertSame($summary, self::summaryLine(self::format($formatter, $report)));
    }

    /**
     * A failing run counts its infos in the same clause, "I infos found",
     * between the warnings and the optimizations; without infos the FAIL
     * line stays as it was. "[é]" without /u is a lint error.
     *
     * @return iterable<string, array{formatter: string, patterns: list<string>, summary: string}>
     */
    public static function provideFailingSummaries(): iterable
    {
        $rows = [
            'error and info' => [['/[é]/', '/(a)+/'], '1 lint errors, 0 warnings, 1 infos found, 0 optimizations.'],
            'error, warning and info' => [['/[é]/', '/a\d+?/', '/(a)+/'], '1 lint errors, 1 warnings, 1 infos found, 0 optimizations.'],
            'error only' => [['/[é]/'], '1 lint errors, 0 warnings, 0 optimizations.'],
        ];

        foreach ($rows as $name => [$patterns, $summary]) {
            yield 'console, '.$name => ['formatter' => 'console', 'patterns' => $patterns, 'summary' => 'FAIL '.$summary];
            yield 'quiet console, '.$name => ['formatter' => 'quiet', 'patterns' => $patterns, 'summary' => 'FAIL: '.$summary];
            yield 'symfony, '.$name => ['formatter' => 'symfony', 'patterns' => $patterns, 'summary' => 'FAIL '.$summary];
            yield 'laravel, '.$name => ['formatter' => 'laravel', 'patterns' => $patterns, 'summary' => 'FAIL '.$summary];
        }
    }

    /**
     * @param list<string> $patterns
     */
    #[Test]
    #[DataProvider('provideFailingSummaries')]
    public function test_summary_counts_the_infos_of_a_failing_run(string $formatter, array $patterns, string $summary): void
    {
        // Oracle: without /u the class holds the two bytes of "é", so it
        // does not match "é" itself; only the last capture of "(a)+" is kept.
        $this->assertSame(0, preg_match('/^[é]$/', 'é'));
        $this->assertSame(1, preg_match('/^[é]$/u', 'é'));
        $this->assertSame(1, preg_match('/(a)+/', 'aab', $captures));
        $this->assertSame(['aa', 'a'], $captures);

        $report = self::lint($patterns);
        $this->assertSame(1, $report->stats['errors']);
        $this->assertSame(1, $report->stats['lintErrors'] ?? null);

        $this->assertSame($summary, self::summaryLine(self::format($formatter, $report)));
    }

    private static function format(string $name, LintReport $report): string
    {
        $service = new AnalysisService(RegexParser::create(['cache' => null]));
        $links = new LinkFormatter(null, new RelativePathHelper('/project'));

        return match ($name) {
            'console' => (new ConsoleFormatter($service, new OutputConfiguration(ansi: false)))->getSummary($report->stats),
            'quiet' => (new ConsoleFormatter(null, OutputConfiguration::quiet()))->format($report),
            'symfony' => (new SymfonyConsoleFormatter($service, $links, false))->format($report),
            default => (new LaravelConsoleFormatter($service, $links, false))->format($report),
        };
    }

    /**
     * @param list<string> $patterns
     */
    private static function lint(array $patterns): LintReport
    {
        $service = new LintService(new AnalysisService(RegexParser::create(['cache' => null])), new PatternSourceCollection([]));
        $occurrences = [];
        foreach ($patterns as $line => $pattern) {
            $occurrences[] = new PatternOccurrence($pattern, 'f.php', $line + 1, 'php:preg_match()');
        }

        return $service->analyze($occurrences, new LintRequest(['.'], [], 0, checkOptimizations: false));
    }

    /**
     * The last PASS, WARN or FAIL line, its console tags removed, its runs
     * of spaces (the padding of a badge) made one: the summary comes last.
     */
    private static function summaryLine(string $output): string
    {
        $plain = preg_replace('/<(?:[a-z]+=[^<>]*|\/)>/', '', $output);
        self::assertIsString($plain);
        self::assertNotFalse(preg_match_all('/^\s*(?:PASS|WARN|FAIL)\b.*$/m', $plain, $lines));
        self::assertNotSame([], $lines[0], $output);

        return (string) preg_replace('/ {2,}/', ' ', trim($lines[0][\count($lines[0]) - 1]));
    }
}
