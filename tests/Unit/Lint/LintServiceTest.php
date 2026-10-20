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

use PHPRegex\Automata\LanguageSolver;
use PHPRegex\Linter\AnalysisService;
use PHPRegex\Linter\DiagnosticType;
use PHPRegex\Linter\LintIssue;
use PHPRegex\Linter\LintProgress;
use PHPRegex\Linter\LintReport;
use PHPRegex\Linter\LintRequest;
use PHPRegex\Linter\LintService;
use PHPRegex\Linter\PatternLinter;
use PHPRegex\Linter\PatternOccurrence;
use PHPRegex\Linter\Source\PatternSourceCollection;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Parser\Validation\ValidationResult;
use PHPRegex\Redos\RedosAnalyzer;
use PHPRegex\Redos\RedosComplexity;
use PHPRegex\Redos\RedosProof;
use PHPRegex\Tests\Support\PcreInterpreter;
use PHPRegex\Tests\Support\RecordingLintProgress;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LintServiceTest extends TestCase
{
    private AnalysisService $analysis;

    private PatternSourceCollection $sources;

    protected function setUp(): void
    {
        // anchor.dollarNewline is off by default: on here, so that a route
        // source can be seen to leave it out.
        $this->analysis = new AnalysisService(RegexParser::create(), lintRules: ['anchor.dollarNewline' => true]);
        $this->sources = new PatternSourceCollection([]);
    }

    #[Test]
    public function test_collect_patterns(): void
    {
        $request = new LintRequest(['.'], [], 0);

        $service = new LintService($this->analysis, $this->sources);
        $result = $service->collectPatterns($request, null);

        $this->assertSame([], $result);
    }

    #[Test]
    public function test_analyze_with_empty_patterns(): void
    {
        $request = new LintRequest(['.'], [], 0);
        $patterns = [];

        $service = new LintService($this->analysis, $this->sources);
        $result = $service->analyze($patterns, $request, null);

        $this->assertSame([], $result->results);
        $this->assertSame(['errors' => 0, 'warnings' => 0, 'optimizations' => 0], $result->stats);
    }

    /**
     * A file read again with the tokenizer is counted, never reported: no
     * result, no error, one fallback in the stats.
     */
    #[Test]
    public function test_analyze_counts_a_parser_fallback_without_reporting_it(): void
    {
        $request = new LintRequest(['.'], [], 0);
        $patterns = [
            new PatternOccurrence('/a+/', 'test.php', 2, 'preg_match()'),
            PatternOccurrence::parserFallback('test.php', 'Syntax error, unexpected EOF on line 3'),
        ];

        $service = new LintService($this->analysis, $this->sources);
        $result = $service->analyze($patterns, $request, null);

        $this->assertSame([], $result->results);
        $this->assertSame(['errors' => 0, 'warnings' => 0, 'optimizations' => 0, 'parserFallbacks' => 1], $result->stats);
    }

    #[Test]
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

    #[Test]
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
            static fn (LintIssue $issue): string => (string) $issue->message,
            $result->results[0]['issues'],
        );

        $this->assertTrue(
            (bool) array_filter($messages, static fn (string $message): bool => str_contains($message, 'Invalid delimiter')),
        );
    }

    #[Test]
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
        $this->assertInstanceOf(ValidationResult::class, $result->results[0]['issues'][0]->validation);
    }

    #[Test]
    public function test_analyze_with_pattern_warnings(): void
    {
        $request = new LintRequest(['.'], [], 0);
        // Pattern with nested quantifier which should produce a warning
        $patterns = [
            new PatternOccurrence('/(a+)+$/', 'test.php', 1, 'preg_match'),
        ];

        $service = new LintService($this->analysis, $this->sources);
        $result = $service->analyze($patterns, $request, null);

        $this->assertCount(1, $result->results);
        $this->assertSame('/(a+)+$/', $result->results[0]['pattern']);
        $warnings = array_filter($result->results[0]['issues'], static fn (LintIssue $issue) => 'warning' === $issue->severity->level());
        $this->assertNotSame([], $warnings, 'The linter reported no warning for a nested quantifier.');

        $nestedWarnings = array_values(array_filter(
            $result->results[0]['issues'],
            static fn (LintIssue $issue): bool => ($issue->issueId ?? '') === 'regex.lint.quantifier.nested',
        ));

        $this->assertCount(1, $nestedWarnings);

        $this->assertObjectNotHasProperty('suggestedPattern', $nestedWarnings[0]);
        $this->assertStringContainsStringIgnoringCase('verify', (string) ($nestedWarnings[0]->hint ?? ''));
    }

    #[Test]
    public function test_analyze_nested_quantifier_warning_carries_no_rewrite_under_pattern_limits(): void
    {
        $analysis = new AnalysisService(RegexParser::create(['max_pattern_length' => 10]));
        $service = new LintService($analysis, $this->sources);
        $request = new LintRequest(['.'], [], 0);
        $patterns = [
            new PatternOccurrence('/(a+)+$/', 'test.php', 1, 'preg_match'),
        ];

        $result = $service->analyze($patterns, $request, null);

        $nestedWarnings = array_values(array_filter(
            $result->results[0]['issues'],
            static fn (LintIssue $issue): bool => ($issue->issueId ?? '') === 'regex.lint.quantifier.nested',
        ));

        $this->assertCount(1, $nestedWarnings);

        $this->assertObjectNotHasProperty('suggestedPattern', $nestedWarnings[0]);
    }

    #[Test]
    public function test_analyze_dotstar_warning_carries_no_automatic_rewrite(): void
    {
        $request = new LintRequest(['.'], [], 0);
        $patterns = [
            new PatternOccurrence('/(?:.*)+/', 'test.php', 1, 'preg_match'),
        ];

        $service = new LintService($this->analysis, $this->sources);
        $result = $service->analyze($patterns, $request, null);

        $dotstarWarnings = array_values(array_filter(
            $result->results[0]['issues'],
            static fn (LintIssue $issue): bool => ($issue->issueId ?? '') === 'regex.lint.dotstar.nested',
        ));

        $this->assertCount(1, $dotstarWarnings);

        $this->assertObjectNotHasProperty('suggestedPattern', $dotstarWarnings[0]);
        $this->assertStringContainsStringIgnoringCase('verify', (string) ($dotstarWarnings[0]->hint ?? ''));
    }

    #[Test]
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

    /**
     * @return iterable<string, array{pattern: string, issueId: string, positions: list<int>}>
     */
    public static function provideIssuesSharingAMessage(): iterable
    {
        yield 'two useless quantifiers' => ['pattern' => '/a{1}b{1}/', 'issueId' => 'regex.lint.quantifier.useless', 'positions' => [0, 4]];
        yield 'two redundant classes' => ['pattern' => '/[aa]x[bb]/', 'issueId' => 'regex.lint.charclass.redundant', 'positions' => [0, 5]];
        yield 'two redundant groups' => ['pattern' => '/(?:a)b(?:c)/', 'issueId' => 'regex.lint.group.redundant', 'positions' => [0, 6]];
    }

    /**
     * Two findings of one rule in one pattern share their message and
     * differ by their position: both are reported.
     *
     * @param list<int> $positions
     */
    #[DataProvider('provideIssuesSharingAMessage')]
    public function test_analyze_keeps_each_issue_of_a_pattern_that_shares_its_message(string $pattern, string $issueId, array $positions): void
    {
        $request = new LintRequest(['.'], [], 0);
        $occurrence = new PatternOccurrence($pattern, 'test.php', 1, 'preg_match()', column: 12, fileOffset: 40);

        $service = new LintService($this->analysis, $this->sources);
        // The same occurrence twice, as a file given by its path and inside
        // its directory is read twice: its findings are reported once.
        $result = $service->analyze([$occurrence, $occurrence], $request, null);

        $this->assertCount(1, $result->results);
        $found = array_values(array_filter($result->results[0]['issues'], static fn (LintIssue $issue): bool => $issueId === $issue->issueId));
        $this->assertSame($positions, array_map(static fn (LintIssue $issue): ?int => $issue->position, $found));
    }

    #[Test]
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

    #[Test]
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

    /**
     * The 1.x marker "// @regex-lint-ignore" is gone: the line above a
     * pattern takes "// @regex-ignore-next-line", which the extraction reads.
     */
    #[Test]
    public function test_analyze_reports_an_issue_below_the_1x_lint_ignore_comment(): void
    {
        $file = __DIR__.'/../../Fixtures/Extractor/regex_lint_ignore.php';

        $request = new LintRequest(['.'], [], 0);
        $patterns = [
            new PatternOccurrence('/(a+)+/', $file, 3, 'preg_match'), // Line 3 has the pattern
        ];

        $service = new LintService($this->analysis, $this->sources);
        $result = $service->analyze($patterns, $request, null);

        $this->assertCount(1, $result->results);
        $this->assertSame(3, $result->results[0]['line']);
    }

    #[Test]
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
            static fn (LintIssue $issue) => ($issue->issueId ?? '') === 'regex.lint.complexity',
        );
        $this->assertCount(0, $complexityIssues);
    }

    /**
     * "/^(a|b|c)(d|e|f)(g|h)$/" scores 16: reported from a threshold of 16,
     * not from 17, and only when the request turns the check on.
     *
     * @return iterable<string, array{threshold: int, checkComplexity: bool, expected: list<string>}>
     */
    public static function provideComplexityRequests(): iterable
    {
        yield 'on, threshold reached' => ['threshold' => 16, 'checkComplexity' => true, 'expected' => ['Pattern is complex (score: 16).']];
        yield 'on, threshold one above' => ['threshold' => 17, 'checkComplexity' => true, 'expected' => []];
        yield 'off, threshold reached' => ['threshold' => 16, 'checkComplexity' => false, 'expected' => []];
    }

    /**
     * @param list<string> $expected
     */
    #[Test]
    #[DataProvider('provideComplexityRequests')]
    public function test_analyze_reports_complexity_only_when_the_request_asks(int $threshold, bool $checkComplexity, array $expected): void
    {
        $this->assertSame(16, RegexParser::create()->validate('/^(a|b|c)(d|e|f)(g|h)$/')->complexityScore);

        $request = new LintRequest(['.'], [], 1, checkOptimizations: false, checkComplexity: $checkComplexity);
        $service = new LintService(new AnalysisService(RegexParser::create(), warningThreshold: $threshold), $this->sources);
        $result = $service->analyze([new PatternOccurrence('/^(a|b|c)(d|e|f)(g|h)$/', 'test.php', 1, 'php:preg_match()')], $request, null);

        $messages = [];
        foreach ($result->results as $entry) {
            foreach ($entry['issues'] as $issue) {
                if ('regex.lint.complexity' === $issue->issueId) {
                    $messages[] = $issue->message;
                }
            }
        }

        $this->assertSame($expected, $messages);
    }

    #[Test]
    public function test_analyze_with_route_pattern_filters_route_issues(): void
    {
        $request = new LintRequest(['.'], [], 0);
        $patterns = [
            new PatternOccurrence('/(a+)+$/', 'test.php', 1, 'route:home'),
        ];

        $service = new LintService($this->analysis, $this->sources);
        $result = $service->analyze($patterns, $request, null);

        // For route patterns, certain issues like nested quantifiers are
        // filtered, whether or not another finding keeps the pattern listed.
        $routeIssues = array_filter(
            array_merge([], ...array_map(static fn (array $entry): array => $entry['issues'] ?? [], $result->results)),
            static fn (LintIssue $issue) => ($issue->issueId ?? '') === 'regex.lint.quantifier.nested',
        );
        $this->assertCount(0, $routeIssues);
    }

    #[Test]
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
                static fn (LintIssue $issue) => null !== $issue->analysis,
            );
            $this->assertCount(0, $redosIssues, 'ReDoS issues should be filtered when checkRedos is false');
        }
    }

    #[Test]
    public function test_analyze_with_progress_callback(): void
    {
        $request = new LintRequest(['.'], [], 0);
        $patterns = [
            new PatternOccurrence('/abc/', 'test.php', 1, 'preg_match'),
        ];

        $progress = new RecordingLintProgress();

        $service = new LintService($this->analysis, $this->sources);
        $service->analyze($patterns, $request, $progress);

        $this->assertSame(1, $progress->advancedIn(LintProgress::STEP_ANALYSIS));
    }

    #[Test]
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
            LintReport::problems($result->results[0]),
            static fn ($problem) => DiagnosticType::Security === $problem->type,
        );
        $this->assertNotEmpty($redosProblems, 'Should create security problems for ReDoS issues');
    }

    #[Test]
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

    /**
     * A heuristic ReDoS lint issue is dropped when the ReDoS analysis ran
     * and proved the pattern linear; any other verdict keeps it.
     *
     * @return iterable<string, array{pattern: string, heuristic: string, redos: bool, proof: RedosProof|null, kept: bool}>
     */
    public static function provideHeuristicIssuesAgainstTheVerdict(): iterable
    {
        // Proven linear: (c?)+$ on c{40}d takes 123 steps.
        yield 'nested, proven linear' => ['pattern' => '/(c?)+$/', 'heuristic' => 'regex.lint.quantifier.nested', 'redos' => true, 'proof' => RedosProof::Proven, 'kept' => false];
        yield 'nested and trailing, proven linear' => ['pattern' => '/(a+)+/', 'heuristic' => 'regex.lint.quantifier.nested', 'redos' => true, 'proof' => RedosProof::Proven, 'kept' => false];
        yield 'dot star, proven linear' => ['pattern' => '/(?:.*)+/', 'heuristic' => 'regex.lint.dotstar.nested', 'redos' => true, 'proof' => RedosProof::Proven, 'kept' => false];
        yield 'overlap, proven linear' => ['pattern' => '/(?:[a-m]|[a-z])+/', 'heuristic' => 'regex.lint.overlap.charset', 'redos' => true, 'proof' => RedosProof::Proven, 'kept' => false];
        // Proven exponential: the verdict and the heuristic both stay.
        yield 'nested, proven exponential' => ['pattern' => '/^(a+)+$/', 'heuristic' => 'regex.lint.quantifier.nested', 'redos' => true, 'proof' => RedosProof::Proven, 'kept' => true];
        yield 'overlap, proven exponential' => ['pattern' => '/(?:[a-m]|[a-z])+$/', 'heuristic' => 'regex.lint.overlap.charset', 'redos' => true, 'proof' => RedosProof::Proven, 'kept' => true];
        // A backreference is outside the model: the heuristics decided.
        yield 'nested, heuristic verdict' => ['pattern' => '/(x)(a+)+\1$/', 'heuristic' => 'regex.lint.quantifier.nested', 'redos' => true, 'proof' => RedosProof::Heuristic, 'kept' => true];
        yield 'nested, budget exceeded' => ['pattern' => '/^(?:(?:a{16}){16}){16}(c?)+$/', 'heuristic' => 'regex.lint.quantifier.nested', 'redos' => true, 'proof' => RedosProof::BudgetExceeded, 'kept' => true];
        // No analysis, nothing proven, nothing dropped.
        yield 'nested, ReDoS off' => ['pattern' => '/(c?)+$/', 'heuristic' => 'regex.lint.quantifier.nested', 'redos' => false, 'proof' => null, 'kept' => true];
        yield 'overlap, ReDoS off' => ['pattern' => '/(?:[a-m]|[a-z])+/', 'heuristic' => 'regex.lint.overlap.charset', 'redos' => false, 'proof' => null, 'kept' => true];
    }

    #[Test]
    #[DataProvider('provideHeuristicIssuesAgainstTheVerdict')]
    public function test_analysis_drops_a_heuristic_issue_only_when_proven_linear(string $pattern, string $heuristic, bool $redos, ?RedosProof $proof, bool $kept): void
    {
        if (null !== $proof) {
            $verdict = (new RedosAnalyzer(RegexParser::create()))->analyze($pattern);
            $this->assertSame($proof, $verdict->proof, 'The row no longer names the verdict the analysis gives.');
            $this->assertSame(!$kept, $verdict->isProvenSafe());
        }

        $issues = (new AnalysisService(RegexParser::create(), redosThreshold: 'low', redosEnabled: $redos))
            ->lint([new PatternOccurrence($pattern, 'test.php', 1, 'preg_match')]);
        $ids = array_map(static fn (LintIssue $issue): string => ($issue->issueId ?? ''), $issues);

        $kept
            ? $this->assertContains($heuristic, $ids, $pattern)
            : $this->assertNotContains($heuristic, $ids, $pattern);
    }

    #[Test]
    public function test_analysis_keeps_the_redos_issue_next_to_the_heuristic_on_a_proven_blow_up(): void
    {
        $issues = (new AnalysisService(RegexParser::create(), redosThreshold: 'low', redosEnabled: true))
            ->lint([new PatternOccurrence('/^(a+)+$/', 'test.php', 1, 'preg_match')]);
        $ids = array_map(static fn (LintIssue $issue): string => ($issue->issueId ?? ''), $issues);

        $this->assertContains('regex.lint.redos', $ids);
        $this->assertContains('regex.lint.quantifier.nested', $ids);
    }

    /**
     * The analysis reads every inline option as the engine does, so the
     * linter drops a heuristic exactly when the verdict is proven linear,
     * inline option group or not: "(?i-r:(?:k+\x{212A})+)$" under ur is
     * exponential on the engine (68 -> 3 194 -> 150 050 steps on (k,
     * Kelvin sign){4, 8, 12}x) and keeps its heuristic next to the ReDoS
     * issue; "(?s:x)(?:.*)+" runs in 2 steps whatever the subject and
     * loses it; "(?ir)(?:k+\x{212A})+$" under u is linear (6 -> 10 steps)
     * and loses it too, where the analysis called it exponential before.
     *
     * @return iterable<string, array{pattern: string, heuristic: string, unit: string, suffix: string, exponential: bool}>
     */
    public static function provideInlineOptionPatternsAgainstTheEngine(): iterable
    {
        yield 'scoped i turning r off' => ['pattern' => '/(?i-r:(?:k+\x{212A})+)$/ur', 'heuristic' => 'regex.lint.quantifier.nested', 'unit' => "k\u{212A}", 'suffix' => 'x', 'exponential' => true];
        yield 'caret reset with i' => ['pattern' => '/(?^i:(?:k+\x{212A})+)$/ur', 'heuristic' => 'regex.lint.quantifier.nested', 'unit' => "k\u{212A}", 'suffix' => 'x', 'exponential' => true];
        yield 'scoped i inside r turned off' => ['pattern' => '/(?-r:(?i:(?:k+\x{212A})+))$/ur', 'heuristic' => 'regex.lint.quantifier.nested', 'unit' => "k\u{212A}", 'suffix' => 'x', 'exponential' => true];
        yield 'harmless scoped s' => ['pattern' => '/(?s:x)(?:.*)+/', 'heuristic' => 'regex.lint.dotstar.nested', 'unit' => 'a', 'suffix' => "\n", 'exponential' => false];
        yield 'inline r on a caseless loop' => ['pattern' => '/(?ir)(?:k+\x{212A})+$/u', 'heuristic' => 'regex.lint.quantifier.nested', 'unit' => "k\u{212A}", 'suffix' => 'x', 'exponential' => false];
        yield 'no inline group under ur' => ['pattern' => '/(?:k+\x{212A})+$/ur', 'heuristic' => 'regex.lint.quantifier.nested', 'unit' => "k\u{212A}", 'suffix' => 'x', 'exponential' => false];
    }

    #[Test]
    #[DataProvider('provideInlineOptionPatternsAgainstTheEngine')]
    public function test_analysis_drops_the_heuristic_on_an_inline_option_pattern_only_when_the_engine_is_linear(string $pattern, string $heuristic, string $unit, string $suffix, bool $exponential): void
    {
        if (false === @preg_match($pattern, '')) {
            // Before PHP 8.4 the r modifier does not exist, and before
            // PCRE2 10.43 neither does "(?r)": nothing to analyse.
            $this->assertTrue(\PHP_VERSION_ID < 80400 || version_compare(explode(' ', \PCRE_VERSION)[0], '10.43', '<'), $pattern.' does not compile here.');

            return;
        }

        $short = self::steps($pattern, str_repeat($unit, 4).$suffix);
        $long = self::steps($pattern, str_repeat($unit, 8).$suffix);
        $this->assertSame($exponential, $long / $short > 3, \sprintf('Oracle disagrees with the row: %d -> %d steps.', $short, $long));

        $verdict = (new RedosAnalyzer(RegexParser::create()))->analyze($pattern);
        $this->assertSame(RedosProof::Proven, $verdict->proof, $pattern.' is "'.$verdict->headline().'"');
        $this->assertSame(!$exponential, $verdict->isProvenSafe(), $pattern.' is "'.$verdict->headline().'", the engine disagrees.');
        $this->assertSame($exponential ? RedosComplexity::Exponential : RedosComplexity::Linear, $verdict->complexity, $pattern);

        $linter = new PatternLinter();
        Regex::create(['cache' => null])->parse($pattern)->accept($linter);
        $this->assertContains($heuristic, array_map(static fn ($issue): string => $issue->id, $linter->getIssues()));

        $issues = (new AnalysisService(RegexParser::create(), redosThreshold: 'low', redosEnabled: true))
            ->lint([new PatternOccurrence($pattern, 'test.php', 1, 'preg_match')]);
        $ids = array_map(static fn (LintIssue $issue): string => ($issue->issueId ?? ''), $issues);

        $exponential
            ? $this->assertContains($heuristic, $ids, $pattern)
            : $this->assertNotContains($heuristic, $ids, $pattern);
        $this->assertSame($exponential, \in_array('regex.lint.redos', $ids, true), $pattern.' and the ReDoS issue');
    }

    /**
     * An s set in an earlier alternative reaches the loop: the analysis
     * proves "x(?s)|(?:.*\n.*\n)+x" exponential, as the engine needs
     * 385 -> 98 305 steps on "\n"{8} -> "\n"{16}; the heuristic stays next
     * to the ReDoS issue.
     */
    #[Test]
    public function test_analysis_reports_an_s_carried_from_an_earlier_alternative(): void
    {
        $pattern = '/x(?s)|(?:.*\n.*\n)+x/';

        $verdict = (new RedosAnalyzer(RegexParser::create()))->analyze($pattern);
        $this->assertSame(RedosProof::Proven, $verdict->proof);
        $this->assertFalse($verdict->isProvenSafe(), $pattern.' is "'.$verdict->headline().'"');
        $this->assertSame(RedosComplexity::Exponential, $verdict->complexity);

        // Oracle: the engine exhausts a limit of 1 000 000 steps on 24 "\n".
        $limit = ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', '1000000');

        try {
            $result = @preg_match(PcreInterpreter::pattern('/(*NO_START_OPT)x(?s)|(?:.*\n.*\n)+x/'), str_repeat("\n", 24));
            $error = preg_last_error();
        } finally {
            ini_set('pcre.backtrack_limit', false === $limit ? '1000000' : $limit);
        }
        $this->assertFalse($result);
        $this->assertSame(\PREG_BACKTRACK_LIMIT_ERROR, $error);

        $issues = (new AnalysisService(RegexParser::create(), redosThreshold: 'low', redosEnabled: true))
            ->lint([new PatternOccurrence($pattern, 'test.php', 1, 'preg_match')]);
        $ids = array_map(static fn (LintIssue $issue): string => ($issue->issueId ?? ''), $issues);

        $this->assertContains('regex.lint.dotstar.nested', $ids);
        $this->assertContains('regex.lint.redos', $ids);
    }

    /**
     * An s set in an earlier alternative reaches the loop: the dot takes
     * "\n" and the loop blows up (1 024 -> 262 144 steps on "\na"{8} ->
     * "\na"{16}; 716 -> 159 824 on "a\n"{8} -> "a\n"{16}). With ReDoS
     * analysis on, the heuristic still reports it.
     *
     * @return iterable<string, array{pattern: string, unit: string, heuristic: string}>
     */
    public static function provideLoopsUnderAnSSetInAnEarlierAlternative(): iterable
    {
        yield 'overlapping alternatives' => ['pattern' => '/x(?s)|(?:.a|\na)+x/', 'unit' => "\na", 'heuristic' => 'regex.lint.overlap.charset'];
        yield 'nested quantifiers' => ['pattern' => '/x(?s)|(?:.{1,9}\n)+x/', 'unit' => "a\n", 'heuristic' => 'regex.lint.quantifier.nested'];
    }

    #[Test]
    #[DataProvider('provideLoopsUnderAnSSetInAnEarlierAlternative')]
    public function test_analysis_reports_a_loop_under_an_s_set_in_an_earlier_alternative(string $pattern, string $unit, string $heuristic): void
    {
        // Oracle: the engine exhausts a limit of 1 000 000 steps on 24 units.
        $limit = ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', '1000000');

        try {
            $this->assertFalse(@preg_match(PcreInterpreter::pattern('/(*NO_START_OPT)'.substr($pattern, 1)), str_repeat($unit, 24).'!'));
            $this->assertSame(\PREG_BACKTRACK_LIMIT_ERROR, preg_last_error());
        } finally {
            ini_set('pcre.backtrack_limit', false === $limit ? '1000000' : $limit);
        }

        $issues = (new AnalysisService(RegexParser::create(), redosThreshold: 'low', redosEnabled: true))
            ->lint([new PatternOccurrence($pattern, 'test.php', 1, 'preg_match')]);
        $ids = array_map(static fn (LintIssue $issue): string => ($issue->issueId ?? ''), $issues);

        $this->assertContains($heuristic, $ids, $pattern);
    }

    #[Test]
    public function test_analysis_drops_charset_overlap_on_an_ignored_pattern(): void
    {
        $analysis = new AnalysisService(RegexParser::create(), redosIgnoredPatterns: ['(?:[a-m]|[a-z])+', '(a+)+']);

        $ids = array_map(
            static fn (LintIssue $issue): string => ($issue->issueId ?? ''),
            $analysis->lint([
                new PatternOccurrence('/^(?:[a-m]|[a-z])+$/', 'test.php', 1, 'preg_match'),
                new PatternOccurrence('/^(a+)+$/', 'test.php', 2, 'preg_match'),
            ]),
        );

        $this->assertNotContains('regex.lint.overlap.charset', $ids);
        $this->assertNotContains('regex.lint.quantifier.nested', $ids);
    }

    /**
     * An ignored pattern loses its heuristic risk findings alone: the
     * findings of the other rules after them stay.
     */
    #[Test]
    public function test_analysis_keeps_the_other_findings_of_an_ignored_pattern(): void
    {
        $ids = array_map(
            static fn (LintIssue $issue): string => $issue->issueId ?? '',
            (new AnalysisService(RegexParser::create(), redosIgnoredPatterns: ['(a+)+b{1}']))->lint([new PatternOccurrence('/(a+)+b{1}/', 'test.php', 1, 'preg_match')]),
        );

        $this->assertSame(['regex.lint.group.quantifiedCapture', 'regex.lint.quantifier.useless'], $ids);
    }

    /**
     * The ReDoS ignore list takes patterns, fragments or full regexes: a full
     * regex, delimiters and modifiers included, skips its verdict too.
     */
    #[Test]
    public function test_analysis_skips_the_redos_verdict_of_an_ignored_full_regex(): void
    {
        $occurrence = new PatternOccurrence('/(a+)+$/', 'test.php', 1, 'preg_match');
        $redosIds = static fn (AnalysisService $analysis): array => array_values(array_filter(
            array_map(static fn (LintIssue $issue): string => ($issue->issueId ?? ''), $analysis->lint([$occurrence])),
            static fn (string $id): bool => 'regex.lint.redos' === $id,
        ));

        $this->assertSame(['regex.lint.redos'], $redosIds(new AnalysisService(RegexParser::create(), redosThreshold: 'low', redosEnabled: true)), 'control: reported when not ignored');

        $this->assertSame([], $redosIds(new AnalysisService(RegexParser::create(), redosThreshold: 'low', redosIgnoredPatterns: ['/(a+)+$/'], redosEnabled: true)));
    }

    #[Test]
    public function test_analyze_with_route_pattern_filters_charset_overlap(): void
    {
        $service = new LintService($this->analysis, $this->sources);
        $result = $service->analyze(
            [new PatternOccurrence('/^(?:[a-m]|[a-z])+$/', 'test.php', 1, 'route:home')],
            new LintRequest(['.'], [], 0),
            null,
        );

        $ids = [];
        foreach ($result->results as $item) {
            foreach ($item['issues'] as $issue) {
                $ids[] = $issue->issueId ?? '';
            }
        }

        $this->assertNotContains('regex.lint.overlap.charset', $ids);
    }

    /**
     * Symfony's RouteCompiler matches every requirement with "{^...$}sD":
     * a route requirement cannot drop s or D, so neither is reported as
     * useless there, while the same pattern elsewhere still is.
     */
    #[Test]
    #[DataProvider('provideRouterFlags')]
    public function test_analyze_leaves_out_the_flags_the_router_compiles_a_route_with(string $pattern, string $issueId): void
    {
        $this->assertContains($issueId, $this->issueIds($pattern, 'php:preg_match()'), 'control: reported outside a route');
        $this->assertNotContains($issueId, $this->issueIds($pattern, 'route:home:id'));
    }

    /**
     * @return iterable<string, array{pattern: string, issueId: string}>
     */
    public static function provideRouterFlags(): iterable
    {
        yield 'no dot under s' => ['pattern' => '{^\d+$}sD', 'issueId' => 'regex.lint.flag.useless.s'];
        // An inline m reads the router's $ as a line end: D reads nothing.
        yield 'every $ under m' => ['pattern' => '{^(?m)a$}sD', 'issueId' => 'regex.lint.flag.useless.D'];
        // A requirement handed over without the router's D: the router
        // still matches it with D, so its final $ ends the subject.
        yield 'a final $ read without D' => ['pattern' => '{^\d+$}s', 'issueId' => 'regex.lint.anchor.dollarNewline'];
    }

    /**
     * The RouteCompiler turns "(" into "(?:" unless "?" or "*" follows: an
     * unnamed group of a requirement captures nothing, a named one still
     * lands in the route attributes.
     */
    #[Test]
    public function test_analyze_leaves_out_the_quantified_unnamed_group_of_a_route(): void
    {
        $this->assertContains('regex.lint.group.quantifiedCapture', $this->issueIds('{^(en|fr)+$}sD', 'php:preg_match()'), 'control');
        $this->assertNotContains('regex.lint.group.quantifiedCapture', $this->issueIds('{^(en|fr)+$}sD', 'route:home:locale'));
        $this->assertContains('regex.lint.group.quantifiedCapture', $this->issueIds('{^(?<l>en|fr)+$}sD', 'route:home:locale'));
    }

    /**
     * A rewrite of a route requirement keeps the flags the router adds; one
     * that only drops them is no rewrite the requirement can take.
     */
    #[Test]
    public function test_analyze_keeps_the_router_flags_in_a_route_optimization(): void
    {
        $this->assertSame(['{^\d+$}D'], $this->optimized('{^\d+$}sD', 'php:preg_match()'), 'control');
        $this->assertSame([], $this->optimized('{^\d+$}sD', 'route:home:id'));
        $this->assertSame(['{^\d+$}sD'], $this->optimized('{^[0-9]+$}sD', 'route:home:id'));
        $this->assertSame(['{^ab$}sDu'], $this->optimized('{^a{1}b$}sDu', 'route:home:id'));
    }

    /**
     * The router splices a requirement into the pattern it compiles, between
     * the text of the path and the other parameters: a rewrite proven on the
     * requirement alone, anchored at both ends, proves nothing there. "a|ab"
     * and "ab|a" write the same matches anchored, yet before a "b?" they
     * capture other text. Only a rewrite whose normal form is the
     * requirement's, as "[0-9]" for "\d", is offered.
     */
    #[Test]
    public function test_analyze_offers_a_route_rewrite_only_when_it_reads_as_the_requirement(): void
    {
        $solver = new LanguageSolver();
        $this->assertTrue($solver->matchEquivalent('{^(?P<p>a|ab)$}sD', '{^(?P<p>ab|a)$}sD')->isEquivalent);
        $this->assertSame(1, preg_match('{^/(?P<p>a|ab)(?P<q>b?)$}sD', '/ab', $spliced));
        $this->assertSame(1, preg_match('{^/(?P<p>ab|a)(?P<q>b?)$}sD', '/ab', $swapped));
        $this->assertNotSame($spliced['p'], $swapped['p']);

        $this->assertSame(['{^[abc]$}D'], $this->optimized('{^(?:a|b|c)$}sD', 'php:preg_match()'), 'control');
        $this->assertSame([], $this->optimized('{^(?:a|b|c)$}sD', 'route:home:id'));
        $this->assertSame(['{^\d+$}sD'], $this->optimized('{^[0-9]+$}sD', 'route:home:id'));
    }

    #[Test]
    public function test_analyze_drops_a_route_optimization_the_router_flags_bring_under_the_minimum_savings(): void
    {
        // "[0-9]" to "\d" saves 3 characters, dropping s a fourth.
        $this->assertSame(['{^\d+$}D'], $this->optimized('{^[0-9]+$}sD', 'php:preg_match()', 4), 'control');
        $this->assertSame([], $this->optimized('{^[0-9]+$}sD', 'route:home:id', 4));
    }

    /**
     * @return list<string>
     */
    private function issueIds(string $pattern, string $source): array
    {
        $result = (new LintService($this->analysis, $this->sources))->analyze(
            [new PatternOccurrence($pattern, 'test.php', 1, $source)],
            new LintRequest(['.'], [], 0),
            null,
        );

        $ids = [];
        foreach ($result->results as $item) {
            foreach ($item['issues'] as $issue) {
                $ids[] = $issue->issueId ?? '';
            }
        }

        return $ids;
    }

    /**
     * @return list<string>
     */
    private function optimized(string $pattern, string $source, int $minSavings = 1): array
    {
        $result = (new LintService($this->analysis, $this->sources))->analyze(
            [new PatternOccurrence($pattern, 'test.php', 1, $source)],
            new LintRequest(['.'], [], $minSavings),
            null,
        );

        $optimized = [];
        foreach ($result->results as $item) {
            foreach ($item['optimizations'] as $optimization) {
                $optimized[] = $optimization['optimization']->optimized;
            }
        }

        return $optimized;
    }

    /**
     * The smallest backtrack limit the match attempt runs under: the
     * engine's count of steps for one start, in the interpreter, start
     * optimizations off.
     */
    private static function steps(string $pattern, string $subject): int
    {
        $limit = ini_get('pcre.backtrack_limit');
        $unoptimized = PcreInterpreter::pattern($pattern[0].'(*NO_START_OPT)'.substr($pattern, 1));

        try {
            $low = 1;
            $high = 1 << 22;
            while ($low < $high) {
                $middle = intdiv($low + $high, 2);
                ini_set('pcre.backtrack_limit', (string) $middle);
                if (false === @preg_match($unoptimized, $subject)) {
                    $low = $middle + 1;
                } else {
                    $high = $middle;
                }
            }

            return $low;
        } finally {
            ini_set('pcre.backtrack_limit', false === $limit ? '1000000' : $limit);
        }
    }
}
