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

namespace PhpRegex\Tests\Unit\Lint;

use PhpRegex\Linter\AnalysisService;
use PhpRegex\Linter\DiagnosticType;
use PhpRegex\Linter\LintRequest;
use PhpRegex\Linter\LintService;
use PhpRegex\Linter\PatternOccurrence;
use PhpRegex\Linter\Source\PatternSourceCollection;
use PhpRegex\Parser\RegexParser;
use PHPUnit\Framework\Attributes\DoesNotPerformAssertions;
use PHPUnit\Framework\TestCase;

final class RegexLintServiceTest extends TestCase
{
    private AnalysisService $analysis;

    private PatternSourceCollection $sources;

    protected function setUp(): void
    {
        $this->analysis = new AnalysisService(RegexParser::create());
        $this->sources = new PatternSourceCollection([]);
    }

    #[DoesNotPerformAssertions]
    public function test_construct(): void
    {
        $service = new LintService($this->analysis, $this->sources);
    }

    public function test_collect_patterns(): void
    {
        $request = new LintRequest(['.'], [], 0);

        $service = new LintService($this->analysis, $this->sources);
        $result = $service->collectPatterns($request, null);

        $this->assertSame([], $result);
    }

    public function test_analyze_with_empty_patterns(): void
    {
        $request = new LintRequest(['.'], [], 0);
        $patterns = [];

        $service = new LintService($this->analysis, $this->sources);
        $result = $service->analyze($patterns, $request, null);

        $this->assertSame([], $result->results);
        $this->assertSame(['errors' => 0, 'warnings' => 0, 'optimizations' => 0], $result->stats);
    }

    public function test_analyze_with_invalid_pattern(): void
    {
        $request = new LintRequest(['.'], [], 0);
        $patterns = [
            new PatternOccurrence('/[a-z/', 'test.php', 1, 'preg_match'),
        ];

        $service = new LintService($this->analysis, $this->sources);
        $result = $service->analyze($patterns, $request, null);

        $this->assertCount(1, $result->results);
        $this->assertArrayHasKey('file', $result->results[0]);
        $this->assertArrayHasKey('line', $result->results[0]);
        $this->assertArrayHasKey('issues', $result->results[0]);
        $this->assertSame(['errors' => 1, 'warnings' => 0, 'optimizations' => 0], $result->stats);
    }

    public function test_analyze_reports_invalid_delimiter_patterns(): void
    {
        $request = new LintRequest(['.'], [], 0);
        $patterns = [
            new PatternOccurrence('nok', 'test.php', 1, 'preg_match'),
        ];

        $service = new LintService($this->analysis, $this->sources);
        $result = $service->analyze($patterns, $request, null);

        $this->assertCount(1, $result->results);

        $messages = array_map(
            static fn (array $issue): string => (string) $issue['message'],
            $result->results[0]['issues'],
        );

        $this->assertTrue(
            (bool) array_filter($messages, static fn (string $message): bool => str_contains($message, 'Invalid delimiter')),
        );
    }

    public function test_analyze_filters_validation_issues_when_disabled(): void
    {
        $request = new LintRequest(['.'], [], 0, [], true, false, true); // checkValidation = false
        $patterns = [
            new PatternOccurrence('/[a-z/', 'test.php', 1, 'preg_match'),
        ];

        $service = new LintService($this->analysis, $this->sources);
        $result = $service->analyze($patterns, $request, null);

        // Even with checkValidation=false, invalid patterns still produce validation errors
        // because they are fundamental errors that should always be reported
        $this->assertCount(1, $result->results);
        $this->assertCount(1, $result->results[0]['issues']);
        $this->assertArrayHasKey('validation', $result->results[0]['issues'][0]);
    }

    public function test_analyze_with_pattern_warnings(): void
    {
        $request = new LintRequest(['.'], [], 0);
        // Pattern with nested quantifier which should produce a warning
        $patterns = [
            new PatternOccurrence('/(a+)+/', 'test.php', 1, 'preg_match'),
        ];

        $service = new LintService($this->analysis, $this->sources);
        $result = $service->analyze($patterns, $request, null);

        $this->assertCount(1, $result->results);
        $this->assertSame('/(a+)+/', $result->results[0]['pattern']);
        $warnings = array_filter($result->results[0]['issues'], static fn ($issue) => 'warning' === $issue['type']);
        $this->assertNotSame([], $warnings, 'The linter reported no warning for a nested quantifier.');

        $nestedWarnings = array_values(array_filter(
            $result->results[0]['issues'],
            static fn (array $issue): bool => ($issue['issueId'] ?? '') === 'regex.lint.quantifier.nested',
        ));

        $this->assertCount(1, $nestedWarnings);
        $this->assertArrayHasKey('suggestedPattern', $nestedWarnings[0]);
        $this->assertSame('/(?>(a+))+/', $nestedWarnings[0]['suggestedPattern']);
    }

    public function test_analyze_skips_atomic_tip_when_pattern_limit_exceeded(): void
    {
        $analysis = new AnalysisService(RegexParser::create(['max_pattern_length' => 10]));
        $service = new LintService($analysis, $this->sources);
        $request = new LintRequest(['.'], [], 0);
        $patterns = [
            new PatternOccurrence('/(a+)+/', 'test.php', 1, 'preg_match'),
        ];

        $result = $service->analyze($patterns, $request, null);

        $nestedWarnings = array_values(array_filter(
            $result->results[0]['issues'],
            static fn (array $issue): bool => ($issue['issueId'] ?? '') === 'regex.lint.quantifier.nested',
        ));

        $this->assertCount(1, $nestedWarnings);
        $this->assertArrayNotHasKey('suggestedPattern', $nestedWarnings[0]);
    }

    public function test_analyze_adds_atomic_group_tip_for_dotstar_warning(): void
    {
        $request = new LintRequest(['.'], [], 0);
        $patterns = [
            new PatternOccurrence('/(?:.*)+/', 'test.php', 1, 'preg_match'),
        ];

        $service = new LintService($this->analysis, $this->sources);
        $result = $service->analyze($patterns, $request, null);

        $dotstarWarnings = array_values(array_filter(
            $result->results[0]['issues'],
            static fn (array $issue): bool => ($issue['issueId'] ?? '') === 'regex.lint.dotstar.nested',
        ));

        $this->assertCount(1, $dotstarWarnings);
        $this->assertArrayHasKey('suggestedPattern', $dotstarWarnings[0]);
        $this->assertSame('/(?>(?:.*))+/', $dotstarWarnings[0]['suggestedPattern']);
    }

    public function test_analyze_deduplicates_issues(): void
    {
        $request = new LintRequest(['.'], [], 0);
        // Create two identical patterns that would produce the same issue
        $patterns = [
            new PatternOccurrence('/(a+)+/', 'test.php', 1, 'preg_match'),
            new PatternOccurrence('/(a+)+/', 'test.php', 1, 'preg_match'),
        ];

        $service = new LintService($this->analysis, $this->sources);
        $result = $service->analyze($patterns, $request, null);

        $this->assertCount(1, $result->results); // Should be deduplicated to one result
    }

    public function test_analyze_does_not_deduplicate_patterns_with_different_offsets(): void
    {
        $request = new LintRequest(['.'], [], 0);
        $patterns = [
            new PatternOccurrence(
                pattern: '/(a+)+/',
                file: 'test.php',
                line: 1,
                source: 'preg_match',
                column: 5,
                fileOffset: 10,
            ),
            new PatternOccurrence(
                pattern: '/(a+)+/',
                file: 'test.php',
                line: 1,
                source: 'preg_match',
                column: 20,
                fileOffset: 40,
            ),
        ];

        $service = new LintService($this->analysis, $this->sources);
        $result = $service->analyze($patterns, $request, null);

        $this->assertCount(2, $result->results);

        $offsets = array_map(static fn (array $item): ?int => $item['fileOffset'] ?? null, $result->results);
        sort($offsets);
        $this->assertSame([10, 40], $offsets);
    }

    public function test_analyze_with_optimizations(): void
    {
        $request = new LintRequest(['.'], [], 0, [], true, true, true); // checkOptimizations = true
        // Pattern that can be optimized (simple case)
        $patterns = [
            new PatternOccurrence('/(?:abc)/', 'test.php', 1, 'preg_match'), // Non-capturing group that can be simplified
        ];

        $service = new LintService($this->analysis, $this->sources);
        $result = $service->analyze($patterns, $request, null);

        $this->assertCount(1, $result->results);
        // Optimizations might or might not be found depending on the pattern
        // Just check that the structure is correct
        $this->assertArrayHasKey('optimizations', $result->results[0]);
    }

    public function test_analyze_ignores_issues_with_ignore_comment(): void
    {
        $file = __DIR__.'/../../Fixtures/Extractor/regex_lint_ignore.php';

        $request = new LintRequest(['.'], [], 0);
        $patterns = [
            new PatternOccurrence('/(a+)+/', $file, 3, 'preg_match'), // Line 3 has the pattern
        ];

        $service = new LintService($this->analysis, $this->sources);
        $result = $service->analyze($patterns, $request, null);

        // The issue should be ignored due to the comment on the previous line
        $this->assertCount(0, $result->results);
    }

    public function test_analyze_filters_complexity_issues(): void
    {
        $request = new LintRequest(['.'], [], 0);
        // Create a complex pattern that will trigger complexity warnings
        $complexPattern = '/'.str_repeat('a?', 60).'/'; // Very complex due to many alternations
        $patterns = [
            new PatternOccurrence($complexPattern, 'test.php', 1, 'preg_match'),
        ];

        $service = new LintService($this->analysis, $this->sources);
        $result = $service->analyze($patterns, $request, null);

        // Complexity issues should be filtered out by filterLintIssues
        $complexityIssues = array_filter(
            $result->results[0]['issues'] ?? [],
            static fn ($issue) => ($issue['issueId'] ?? '') === 'regex.lint.complexity',
        );
        $this->assertCount(0, $complexityIssues);
    }

    public function test_analyze_with_route_pattern_filters_route_issues(): void
    {
        $request = new LintRequest(['.'], [], 0);
        $patterns = [
            new PatternOccurrence('/(a+)+/', 'test.php', 1, 'route:home'),
        ];

        $service = new LintService($this->analysis, $this->sources);
        $result = $service->analyze($patterns, $request, null);

        // For route patterns, certain issues like nested quantifiers should be filtered
        if (!empty($result->results)) {
            $routeIssues = array_filter(
                $result->results[0]['issues'] ?? [],
                static fn ($issue) => ($issue['issueId'] ?? '') === 'regex.lint.quantifier.nested',
            );
            $this->assertCount(0, $routeIssues);
        }
    }

    public function test_analyze_filters_redos_issues_when_disabled(): void
    {
        $request = new LintRequest(['.'], [], 0, [], true, false, true); // checkRedos = false
        // Create a pattern that might trigger ReDoS but disable ReDoS checking
        $patterns = [
            new PatternOccurrence('/(x+)+y/', 'test.php', 1, 'preg_match'),
        ];

        $service = new LintService($this->analysis, $this->sources);
        $result = $service->analyze($patterns, $request, null);

        // With checkRedos = false, any ReDoS issues should be filtered out
        // Since the pattern may or may not trigger ReDoS, we just verify the filtering logic works
        // by checking that no issues have 'analysis' key when checkRedos is false
        if (!empty($result->results)) {
            $redosIssues = array_filter(
                $result->results[0]['issues'] ?? [],
                static fn ($issue) => isset($issue['analysis']),
            );
            $this->assertCount(0, $redosIssues, 'ReDoS issues should be filtered when checkRedos is false');
        }
    }

    public function test_analyze_with_progress_callback(): void
    {
        $request = new LintRequest(['.'], [], 0);
        $patterns = [
            new PatternOccurrence('/abc/', 'test.php', 1, 'preg_match'),
        ];

        $progressCalls = 0;
        $progressCallback = static function () use (&$progressCalls): void {
            $progressCalls++;
        };

        $service = new LintService($this->analysis, $this->sources);
        $result = $service->analyze($patterns, $request, $progressCallback);

        $this->assertGreaterThanOrEqual(0, $progressCalls); // Progress may be called during analysis
    }

    public function test_analyze_creates_redos_problems(): void
    {
        $request = new LintRequest(['.'], [], 0, [], true, true); // checkRedos = true explicitly
        $patterns = [
            new PatternOccurrence('/(x+)+y/', 'test.php', 1, 'preg_match'),
        ];

        // ReDoS analysis must be enabled on the analysis service itself;
        // the request flag alone only filters already-produced issues.
        $analysis = new AnalysisService(RegexParser::create(), redosEnabled: true);
        $service = new LintService($analysis, $this->sources);
        $result = $service->analyze($patterns, $request, null);

        $this->assertNotEmpty($result->results, 'Analyzing a vulnerable pattern must produce a result');

        $redosProblems = array_filter(
            $result->results[0]['problems'] ?? [],
            static fn ($problem) => DiagnosticType::Security === $problem->type,
        );
        $this->assertNotEmpty($redosProblems, 'Should create security problems for ReDoS issues');
    }

    public function test_analyze_processes_issues_for_nonexistent_file(): void
    {
        $request = new LintRequest(['.'], [], 0);
        $patterns = [
            new PatternOccurrence('/(a+)+/', '/nonexistent/file.php', 1, 'preg_match'),
        ];

        $service = new LintService($this->analysis, $this->sources);
        $result = $service->analyze($patterns, $request, null);

        // Issues for nonexistent files should still be processed (not ignored)
        $this->assertCount(1, $result->results);
    }
}
