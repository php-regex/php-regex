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

use PHPRegex\Linter\AnalysisService;
use PHPRegex\Linter\Diagnostic;
use PHPRegex\Linter\DiagnosticType;
use PHPRegex\Linter\Formatter\CheckstyleFormatter;
use PHPRegex\Linter\Formatter\GithubFormatter;
use PHPRegex\Linter\Formatter\JsonFormatter;
use PHPRegex\Linter\Formatter\JunitFormatter;
use PHPRegex\Linter\LintReport;
use PHPRegex\Linter\LintRequest;
use PHPRegex\Linter\LintService;
use PHPRegex\Linter\LintSeverity;
use PHPRegex\Linter\PatternOccurrence;
use PHPRegex\Linter\Source\PatternSourceCollection;
use PHPRegex\Parser\RegexParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * One mapping for every report: Critical and Error are errors, Warning a
 * warning, Style, Perf and Info an info (GitHub calls it a notice).
 */
final class SeverityMappingTest extends TestCase
{
    /**
     * @return iterable<string, array{severity: LintSeverity, github: string, checkstyle: string, junit: string}>
     */
    public static function provideSeverities(): iterable
    {
        yield 'critical' => ['severity' => LintSeverity::Critical, 'github' => 'error', 'checkstyle' => 'error', 'junit' => 'error'];
        yield 'error' => ['severity' => LintSeverity::Error, 'github' => 'error', 'checkstyle' => 'error', 'junit' => 'failure'];
        yield 'warning' => ['severity' => LintSeverity::Warning, 'github' => 'warning', 'checkstyle' => 'warning', 'junit' => 'system-out'];
        yield 'style' => ['severity' => LintSeverity::Style, 'github' => 'notice', 'checkstyle' => 'info', 'junit' => 'system-out'];
        yield 'perf' => ['severity' => LintSeverity::Perf, 'github' => 'notice', 'checkstyle' => 'info', 'junit' => 'system-out'];
        yield 'info' => ['severity' => LintSeverity::Info, 'github' => 'notice', 'checkstyle' => 'info', 'junit' => 'system-out'];
    }

    #[Test]
    #[DataProvider('provideSeverities')]
    public function test_github_maps_the_severity(LintSeverity $severity, string $github, string $checkstyle, string $junit): void
    {
        $output = (new GithubFormatter())->format(self::report($severity));

        $this->assertStringStartsWith('::'.$github.' file=test.php,line=1,', $output);
    }

    #[Test]
    #[DataProvider('provideSeverities')]
    public function test_checkstyle_maps_the_severity(LintSeverity $severity, string $github, string $checkstyle, string $junit): void
    {
        $output = (new CheckstyleFormatter())->format(self::report($severity));

        $this->assertStringContainsString('severity="'.$checkstyle.'"', $output);
    }

    #[Test]
    #[DataProvider('provideSeverities')]
    public function test_junit_maps_the_severity(LintSeverity $severity, string $github, string $checkstyle, string $junit): void
    {
        $output = (new JunitFormatter())->format(self::report($severity));

        $this->assertStringContainsString('<'.$junit.'>', str_replace(' message="Message"', '', $output));
    }

    /**
     * End to end: a lint pass over one pattern per severity, read back from
     * each report.
     */
    #[Test]
    public function test_every_report_reads_the_rule_severity_of_a_lint_pass(): void
    {
        $report = self::lintPass();

        $github = (new GithubFormatter())->format($report);
        $this->assertStringContainsString('::error file=test.php,line=1,', $github);
        $this->assertMatchesRegularExpression('/^::error [^\n]*\(regex\.lint\.unicode\.multibyteInClassWithoutU\)::/m', $github);
        $this->assertMatchesRegularExpression('/^::warning [^\n]*\(regex\.lint\.quantifier\.lazyEnd\)::/m', $github);
        $this->assertMatchesRegularExpression('/^::notice [^\n]*\(regex\.lint\.group\.quantifiedCapture\)::/m', $github);

        $checkstyle = (new CheckstyleFormatter())->format($report);
        $this->assertMatchesRegularExpression('/severity="error"[^>]*source="php-regex\.regex\.lint\.unicode\.multibyteInClassWithoutU"/', $checkstyle);
        $this->assertMatchesRegularExpression('/severity="warning"[^>]*source="php-regex\.regex\.lint\.quantifier\.lazyEnd"/', $checkstyle);
        $this->assertMatchesRegularExpression('/severity="info"[^>]*source="php-regex\.regex\.lint\.group\.quantifiedCapture"/', $checkstyle);

        $junit = (new JunitFormatter())->format($report);
        $this->assertStringContainsString('failures="1" errors="0"', $junit);
        $this->assertSame(1, substr_count($junit, '<failure '));

        $json = json_decode((new JsonFormatter())->format($report), true, 512, \JSON_THROW_ON_ERROR);
        $this->assertIsArray($json);
        $this->assertIsArray($json['results'] ?? null);
        $types = [];
        foreach ($json['results'] as $result) {
            $this->assertIsArray($result);
            $this->assertIsArray($result['issues'] ?? null);
            foreach ($result['issues'] as $issue) {
                $this->assertIsArray($issue);
                $this->assertIsString($issue['issueId'] ?? null);
                $types[$issue['issueId']] = $issue['type'] ?? null;
            }
        }
        $this->assertSame('error', $types['regex.lint.unicode.multibyteInClassWithoutU'] ?? null);
        $this->assertSame('warning', $types['regex.lint.quantifier.lazyEnd'] ?? null);
        $this->assertSame('info', $types['regex.lint.group.quantifiedCapture'] ?? null);

        $stats = $json['stats'] ?? null;
        $this->assertIsArray($stats);
        $this->assertSame(1, $stats['errors'] ?? null);
        $this->assertSame(1, $stats['lintErrors'] ?? null);
        $this->assertSame(1, $stats['warnings'] ?? null);
        $this->assertSame(1, $stats['infos'] ?? null);
        $this->assertSame(0, $stats['redos'] ?? null);
    }

    private static function report(LintSeverity $severity): LintReport
    {
        $problem = new Diagnostic(DiagnosticType::Lint, $severity, 'Message', 'regex.lint.test');

        return new LintReport([[
            'file' => 'test.php',
            'line' => 1,
            'pattern' => '/a/',
            'issues' => [],
            'optimizations' => [],
            'problems' => [$problem],
        ]], ['errors' => 0, 'warnings' => 0, 'optimizations' => 0]);
    }

    private static function lintPass(): LintReport
    {
        $service = new LintService(new AnalysisService(RegexParser::create()), new PatternSourceCollection([]));

        return $service->analyze([
            new PatternOccurrence('/[é]/', 'test.php', 1, 'preg_match'),
            new PatternOccurrence('/a\d+?/', 'test.php', 2, 'preg_match'),
            new PatternOccurrence('/(a)+/', 'test.php', 3, 'preg_match'),
        ], new LintRequest(['.'], [], 0, checkOptimizations: false), null);
    }
}
