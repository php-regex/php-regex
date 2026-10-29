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

use PHPRegex\Linter\Advice\RedosHints;
use PHPRegex\Linter\AnalysisService;
use PHPRegex\Linter\Formatter\ConsoleFormatter;
use PHPRegex\Linter\Formatter\JsonFormatter;
use PHPRegex\Linter\Formatter\OutputConfiguration;
use PHPRegex\Linter\LintIssue;
use PHPRegex\Linter\LintReport;
use PHPRegex\Linter\LintRequest;
use PHPRegex\Linter\LintService;
use PHPRegex\Linter\PatternOccurrence;
use PHPRegex\Linter\RedosVerdict;
use PHPRegex\Linter\Source\PatternSourceCollection;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Redos\RedosAnalysis;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A ReDoS issue the service builds carries the hint lines and their join:
 * the console lists them, the JSON report keeps the one string.
 */
final class RedosHintsIssueTest extends TestCase
{
    #[Test]
    public function test_a_redos_issue_carries_the_lines_and_their_join(): void
    {
        $issue = self::redosIssue(self::lint('/(a+)+$/'));
        $analysis = $issue->analysis;
        $this->assertInstanceOf(RedosAnalysis::class, $analysis);

        $this->assertSame(RedosHints::forAnalysis($analysis, '/(a+)+$/'), $issue->hints);
        $this->assertSame(implode(' ', $issue->hints), $issue->hint);
        $this->assertGreaterThan(1, \count(array_filter($issue->hints, static fn (string $line): bool => str_starts_with($line, RedosHints::SUGGESTION))));
    }

    #[Test]
    public function test_the_console_groups_the_suggestions_and_the_json_keeps_one_string(): void
    {
        $report = self::lint('/(a+)+$/');
        $issue = self::redosIssue($report);

        $console = (new ConsoleFormatter(config: new OutputConfiguration(ansi: false, width: 120)))->format($report);
        $this->assertSame(1, substr_count($console, 'Suggested (verify behavior)'));
        $this->assertStringContainsString("\n             - ", $console);

        $json = json_decode((new JsonFormatter())->format($report), true, 512, \JSON_THROW_ON_ERROR);
        $this->assertIsArray($json);
        $hints = [];
        array_walk_recursive($json, static function (mixed $value, string|int $key) use (&$hints): void {
            if ('hint' === $key && \is_string($value)) {
                $hints[] = $value;
            }
        });
        $this->assertContains($issue->hint, $hints);
        $this->assertStringNotContainsString("\n", (string) $issue->hint);
    }

    private static function lint(string $pattern): LintReport
    {
        $analysis = new AnalysisService(RegexParser::create(['cache' => null]), redosThreshold: 'low', redosEnabled: true);
        $service = new LintService($analysis, new PatternSourceCollection([]));

        return $service->analyze(
            [new PatternOccurrence($pattern, 'f.php', 1, 'php:preg_match()')],
            new LintRequest(['.'], [], 0, checkRedos: true, checkOptimizations: false),
        );
    }

    private static function redosIssue(LintReport $report): LintIssue
    {
        foreach ($report->results as $result) {
            foreach ($result['issues'] as $issue) {
                if (RedosVerdict::ISSUE_ID === $issue->issueId) {
                    return $issue;
                }
            }
        }

        self::fail('No ReDoS issue.');
    }
}
