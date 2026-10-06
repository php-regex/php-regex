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

use PHPRegex\Linter\Diagnostic;
use PHPRegex\Linter\DiagnosticType;
use PHPRegex\Linter\Formatter\JsonFormatter;
use PHPRegex\Linter\LintException;
use PHPRegex\Linter\LintReport;
use PHPRegex\Linter\LintSeverity;
use PHPRegex\Optimizer\OptimizationResult;
use PHPRegex\Parser\Internal\JsonEncodingFailure;
use PHPRegex\Redos\Confirmation;
use PHPRegex\Redos\RedosAnalysis;
use PHPRegex\Redos\RedosSeverity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\DoesNotPerformAssertions;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class JsonFormatterTest extends TestCase
{
    private JsonFormatter $formatter;

    protected function setUp(): void
    {
        $this->formatter = new JsonFormatter();
    }

    #[DoesNotPerformAssertions]
    public function test_construct(): void
    {
        $formatter = new JsonFormatter();
    }

    public function test_format_empty_report(): void
    {
        $report = new LintReport([], ['errors' => 0, 'warnings' => 0, 'optimizations' => 0]);

        $output = $this->formatter->format($report);

        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded);
        $this->assertSame(['errors' => 0, 'warnings' => 0, 'optimizations' => 0, 'redos_errors' => 0, 'infos' => 0, 'lint_errors' => 0], $decoded['stats']);
        $this->assertSame([], $decoded['results']);
    }

    /**
     * A value of the report JSON has no form for, a non-finite float, is
     * reported as the lint's own exception, the encoding failure kept as
     * its cause.
     */
    #[Test]
    #[DataProvider('provideNonFiniteTimeouts')]
    public function test_format_reports_a_value_with_no_json_form_as_a_lint_exception(float $timeoutMs): void
    {
        $analysis = new RedosAnalysis(RedosSeverity::Safe, 0, confirmation: new Confirmation(false, [], null, null, null, 1, $timeoutMs));
        $report = new LintReport([[
            'file' => 'a.php',
            'line' => 1,
            'pattern' => '/a/',
            'issues' => [['type' => 'error', 'message' => 'm', 'file' => 'a.php', 'line' => 1, 'analysis' => $analysis]],
            'optimizations' => [],
            'problems' => [],
        ]], ['errors' => 1, 'warnings' => 0, 'optimizations' => 0]);

        try {
            $this->formatter->format($report);
            $this->fail('No exception for a value with no JSON form.');
        } catch (LintException $e) {
            $this->assertSame('Failed to encode JSON: Inf and NaN cannot be JSON encoded', $e->getMessage());
            $this->assertInstanceOf(JsonEncodingFailure::class, $e->getPrevious());
        }
    }

    /**
     * @return iterable<string, array{timeoutMs: float}>
     */
    public static function provideNonFiniteTimeouts(): iterable
    {
        yield 'infinity' => ['timeoutMs' => \INF];
        yield 'not a number' => ['timeoutMs' => \NAN];
    }

    /**
     * The issues and optimizations of a result are JSON arrays whatever
     * their keys: a list with a gap would otherwise come out as an object.
     */
    #[Test]
    public function test_format_writes_issues_and_optimizations_as_arrays_whatever_their_keys(): void
    {
        $report = new LintReport([[
            'file' => 'a.php',
            'line' => 1,
            'pattern' => '/[0-9]/',
            'issues' => [3 => ['type' => 'warning', 'message' => 'm', 'file' => 'a.php', 'line' => 1]],
            'optimizations' => [5 => ['file' => 'a.php', 'line' => 1, 'optimization' => new OptimizationResult('/[0-9]/', '/\d/', ['digit class']), 'savings' => 2]],
            'problems' => [],
        ]], ['errors' => 0, 'warnings' => 1, 'optimizations' => 1]);

        $decoded = json_decode($this->formatter->format($report), true);

        $this->assertIsArray($decoded);
        $this->assertIsArray($decoded['results'] ?? null);
        $result = $decoded['results'][0] ?? null;
        $this->assertIsArray($result);
        $this->assertIsArray($result['issues'] ?? null);
        $this->assertIsArray($result['optimizations'] ?? null);
        $this->assertSame([0], array_keys($result['issues']));
        $this->assertSame([0], array_keys($result['optimizations']));
    }

    public function test_format_leads_with_the_target_when_given(): void
    {
        $target = ['php' => '8.2', 'pcre' => '10.40', 'source' => 'composer.json require.php'];
        $report = new LintReport([], ['errors' => 0, 'warnings' => 0, 'optimizations' => 0]);

        $decoded = json_decode((new JsonFormatter(target: $target))->format($report), true);

        $this->assertIsArray($decoded);
        $this->assertSame(['target', 'stats', 'results'], array_keys($decoded));
        $this->assertSame($target, $decoded['target']);
    }

    public function test_format_has_no_target_by_default(): void
    {
        $report = new LintReport([], ['errors' => 0, 'warnings' => 0, 'optimizations' => 0]);

        $decoded = json_decode($this->formatter->format($report), true);

        $this->assertIsArray($decoded);
        $this->assertArrayNotHasKey('target', $decoded);
    }

    public function test_format_with_results(): void
    {
        $result1 = [
            'file' => 'file1.php',
            'line' => 10,
            'pattern' => '/test1/',
            'source' => 'preg_match',
            'location' => 'function call',
            'issues' => [['type' => 'error', 'message' => 'Error 1', 'file' => 'file1.php', 'line' => 10]],
            'optimizations' => [['file' => 'file1.php', 'line' => 10, 'optimization' => new OptimizationResult('/test1/', '/optimized/', ['test']), 'savings' => 5]],
            'problems' => [
                new Diagnostic(DiagnosticType::Syntax, LintSeverity::Error, 'Problem 1', null, null, null, null),
            ],
        ];

        $result2 = [
            'file' => 'file2.php',
            'line' => 20,
            'pattern' => '/test2/',
            'issues' => [],
            'optimizations' => [],
            'problems' => [],
        ];

        $report = new LintReport([$result1, $result2], ['errors' => 1, 'warnings' => 0, 'optimizations' => 1]);

        $output = $this->formatter->format($report);

        /** @var array{stats: array<string, int>, results: array<array<string, mixed>>} $decoded */
        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded);
        $this->assertSame(['errors' => 1, 'warnings' => 0, 'optimizations' => 1, 'redos_errors' => 0, 'infos' => 0, 'lint_errors' => 0], $decoded['stats']);
        $this->assertIsArray($decoded['results']);
        $this->assertCount(2, $decoded['results']);

        // Check that problems are removed from results
        $this->assertIsArray($decoded['results'][0]);
        $this->assertArrayNotHasKey('problems', $decoded['results'][0]);
        $this->assertIsArray($decoded['results'][1]);
        $this->assertArrayNotHasKey('problems', $decoded['results'][1]);

        // Check that other keys are preserved
        $this->assertSame('file1.php', $decoded['results'][0]['file']);
        $this->assertSame(10, $decoded['results'][0]['line']);
        $this->assertSame('/test1/', $decoded['results'][0]['pattern']);
        $this->assertSame('preg_match', $decoded['results'][0]['source']);
        $this->assertSame('function call', $decoded['results'][0]['location']);
        // Every documented key, null when the issue does not have it.
        $expectedIssue = [
            'severity' => 'error',
            'file' => 'file1.php',
            'line' => 10,
            'column' => null,
            'file_offset' => null,
            'position' => null,
            'issue_id' => null,
            'message' => 'Error 1',
            'hint' => null,
            'tip' => null,
            'source' => null,
            'validation' => null,
            'analysis' => null,
            'target' => null,
        ];
        $this->assertIsArray($decoded['results'][0]['issues']);
        $this->assertCount(1, $decoded['results'][0]['issues']);
        $issue = $decoded['results'][0]['issues'][0];
        $this->assertIsArray($issue);
        ksort($expectedIssue);
        ksort($issue);
        $this->assertSame($expectedIssue, $issue);
        /** @var array<array{savings: int}> $optimizations */
        $optimizations = $decoded['results'][0]['optimizations'];
        $this->assertIsArray($optimizations);
        $this->assertArrayHasKey(0, $optimizations);
        $this->assertSame(5, $optimizations[0]['savings']);
    }

    public function test_format_with_invalid_result(): void
    {
        $results = [
            ['file' => 'valid.php', 'line' => 1, 'pattern' => '/test/', 'issues' => [], 'optimizations' => [], 'problems' => []],
        ];

        $report = new LintReport($results, ['errors' => 0, 'warnings' => 0, 'optimizations' => 0]);

        $output = $this->formatter->format($report);

        /** @var array{results: array<array<string, mixed>>} $decoded */
        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded);
        $this->assertIsArray($decoded['results']);
        $this->assertCount(1, $decoded['results']);
        $this->assertIsArray($decoded['results'][0]);
        $this->assertSame('valid.php', $decoded['results'][0]['file']);
    }

    public function test_format_skips_non_array_results(): void
    {
        $results = [
            ['file' => 'valid.php', 'line' => 1, 'pattern' => '/test/', 'issues' => [], 'optimizations' => [], 'problems' => []],
            'invalid',
        ];

        /** @phpstan-ignore-next-line intentionally mixing invalid results for coverage */
        $report = new LintReport($results, ['errors' => 0, 'warnings' => 0, 'optimizations' => 0]);

        $output = $this->formatter->format($report);

        /** @var array{results: array<array<string, mixed>>} $decoded */
        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded);
        $this->assertCount(1, $decoded['results']);
        $this->assertSame('valid.php', $decoded['results'][0]['file']);
    }

    public function test_format_escapes_invalid_utf8(): void
    {
        $results = [
            [
                'file' => "invalid-\xB1\x31",
                'line' => 1,
                'pattern' => "/x\xFE\x80y/is",
                'issues' => [],
                'optimizations' => [],
                'problems' => [],
            ],
        ];

        $report = new LintReport($results, ['errors' => 0, 'warnings' => 0, 'optimizations' => 0]);

        $decoded = json_decode($this->formatter->format($report), true, 512, \JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);
        $this->assertIsArray($decoded['results']);
        $entry = $decoded['results'][0];
        $this->assertIsArray($entry);

        $this->assertSame('invalid-\xB11', $entry['file']);
        $this->assertSame('/x\xFE\x80y/is', $entry['pattern']);
    }

    public function test_format_error(): void
    {
        $message = 'Test error message';

        $output = $this->formatter->formatError($message);

        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded);
        $this->assertSame(['error', 'stage'], array_keys($decoded));
        $this->assertSame('Test error message', $decoded['error']);
        $this->assertIsString($decoded['stage']);
    }

    public function test_format_error_with_special_chars(): void
    {
        $message = "Error with quotes \" and slashes / and newlines\n";

        $output = $this->formatter->formatError($message);

        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded);
        $this->assertSame(['error', 'stage'], array_keys($decoded));
        $this->assertSame($message, $decoded['error']);
    }

    public function test_format_uses_pretty_print(): void
    {
        $report = new LintReport([], ['errors' => 0, 'warnings' => 0, 'optimizations' => 0]);

        $output = $this->formatter->format($report);

        $this->assertStringContainsString("\n", $output);
        $this->assertStringContainsString('  ', $output);
    }

    public function test_format_uses_unescaped_slashes(): void
    {
        $result = [
            'file' => 'test.php',
            'line' => 1,
            'pattern' => '/test/path/',
            'issues' => [],
            'optimizations' => [],
            'problems' => [],
        ];

        $report = new LintReport([$result], ['errors' => 0, 'warnings' => 0, 'optimizations' => 0]);

        $output = $this->formatter->format($report);

        $this->assertStringContainsString('/test/path/', $output);
        // JSON_UNESCAPED_SLASHES should prevent escaping forward slashes
        $this->assertStringNotContainsString('\\/', $output);
    }
}
