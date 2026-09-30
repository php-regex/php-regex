<?php

declare(strict_types=1);

/*
 * This file is part of the RegexParser package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace RegexParser\Tests\Unit\Lint\Formatter;

use PHPUnit\Framework\Attributes\DoesNotPerformAssertions;
use PHPUnit\Framework\TestCase;
use RegexParser\Lint\Formatter\JsonFormatter;
use RegexParser\Lint\RegexLintReport;
use RegexParser\OptimizationResult;
use RegexParser\ProblemType;
use RegexParser\RegexProblem;
use RegexParser\Severity;

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
        $report = new RegexLintReport([], ['errors' => 0, 'warnings' => 0, 'optimizations' => 0]);

        $output = $this->formatter->format($report);

        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded);
        $this->assertSame(['errors' => 0, 'warnings' => 0, 'optimizations' => 0], $decoded['stats']);
        $this->assertSame([], $decoded['results']);
    }

    public function test_format_leads_with_the_target_when_given(): void
    {
        $target = ['php' => '8.2', 'pcre' => '10.40', 'source' => 'composer.json require.php'];
        $report = new RegexLintReport([], ['errors' => 0, 'warnings' => 0, 'optimizations' => 0]);

        $decoded = json_decode((new JsonFormatter(target: $target))->format($report), true);

        $this->assertIsArray($decoded);
        $this->assertSame(['target', 'stats', 'results'], array_keys($decoded));
        $this->assertSame($target, $decoded['target']);
    }

    public function test_format_has_no_target_by_default(): void
    {
        $report = new RegexLintReport([], ['errors' => 0, 'warnings' => 0, 'optimizations' => 0]);

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
                new RegexProblem(ProblemType::Syntax, Severity::Error, 'Problem 1', null, null, null, null),
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

        $report = new RegexLintReport([$result1, $result2], ['errors' => 1, 'warnings' => 0, 'optimizations' => 1]);

        $output = $this->formatter->format($report);

        /** @var array{stats: array<string, int>, results: array<array<string, mixed>>} $decoded */
        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded);
        $this->assertSame(['errors' => 1, 'warnings' => 0, 'optimizations' => 1], $decoded['stats']);
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
        $this->assertSame([['type' => 'error', 'message' => 'Error 1', 'file' => 'file1.php', 'line' => 10]], $decoded['results'][0]['issues']);
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

        $report = new RegexLintReport($results, ['errors' => 0, 'warnings' => 0, 'optimizations' => 0]);

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
        $report = new RegexLintReport($results, ['errors' => 0, 'warnings' => 0, 'optimizations' => 0]);

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

        $report = new RegexLintReport($results, ['errors' => 0, 'warnings' => 0, 'optimizations' => 0]);

        $decoded = json_decode($this->formatter->format($report), true, 512, \JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);
        $this->assertIsArray($decoded['results']);
        $entry = $decoded['results'][0];
        $this->assertIsArray($entry);

        $this->assertSame('invalid-\2611', $entry['file']);
        $this->assertSame('/x\376\200y/is', $entry['pattern']);
    }

    public function test_format_error(): void
    {
        $message = 'Test error message';

        $output = $this->formatter->formatError($message);

        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded);
        $this->assertSame(['error' => 'Test error message'], $decoded);
    }

    public function test_format_error_with_special_chars(): void
    {
        $message = "Error with quotes \" and slashes / and newlines\n";

        $output = $this->formatter->formatError($message);

        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded);
        $this->assertSame(['error' => $message], $decoded);
    }

    public function test_format_uses_pretty_print(): void
    {
        $report = new RegexLintReport([], ['errors' => 0, 'warnings' => 0, 'optimizations' => 0]);

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

        $report = new RegexLintReport([$result], ['errors' => 0, 'warnings' => 0, 'optimizations' => 0]);

        $output = $this->formatter->format($report);

        $this->assertStringContainsString('/test/path/', $output);
        // JSON_UNESCAPED_SLASHES should prevent escaping forward slashes
        $this->assertStringNotContainsString('\\/', $output);
    }
}
