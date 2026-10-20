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

namespace PHPRegex\Tests\Unit\Bridge\Symfony\Service;

use PHPRegex\Linter\AnalysisService;
use PHPRegex\Linter\LintIssue;
use PHPRegex\Linter\PatternOccurrence;
use PHPRegex\Linter\RedosVerdict;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Redos\RedosAnalysis;
use PHPRegex\Redos\RedosMode;
use PHPUnit\Framework\TestCase;

final class AnalysisServiceTest extends TestCase
{
    public function test_reports_invalid_pattern(): void
    {
        $service = $this->createService(warningThreshold: 10, redosThreshold: 'high');
        $pattern = new PatternOccurrence('#^($#', 'file.php', 1, 'route:test', '(');

        $issues = $service->lint([$pattern]);

        $this->assertCount(1, $issues);
        $this->assertSame('error', $issues[0]->severity->level());
    }

    public function test_warns_on_complexity_threshold(): void
    {
        $service = $this->createService(warningThreshold: 0, redosThreshold: 'critical');
        $pattern = new PatternOccurrence('#^[a-z]+$#', 'file.php', 1, 'route:test', '[a-z]+');

        $issues = $service->lint([$pattern]);

        $this->assertCount(1, $issues);
        $this->assertSame('warning', $issues[0]->severity->level());
        $this->assertNotNull($issues[0]->issueId);
        $this->assertSame('regex.lint.complexity', $issues[0]->issueId);
    }

    public function test_trivial_alternation_skips_risk_checks(): void
    {
        $service = $this->createService(warningThreshold: 0, redosThreshold: 'critical');
        $pattern = new PatternOccurrence('#^(?:en|fr|de)$#', 'file.php', 1, 'route:test', 'en|fr|de');

        $issues = $service->lint([$pattern]);

        $this->assertSame([], $issues);
    }

    public function test_ignored_patterns_skip_risk_checks(): void
    {
        $service = $this->createService(
            warningThreshold: 0,
            redosThreshold: 'critical',
            ignoredPatterns: ['[A-Za-z0-9]+(?:-[A-Za-z0-9]+)*'],
        );
        $pattern = new PatternOccurrence(
            '#^[A-Za-z0-9]+(?:-[A-Za-z0-9]+)*$#',
            'file.php',
            1,
            'route:test',
            '^[A-Za-z0-9]+(?:-[A-Za-z0-9]+)*$',
        );

        $issues = $service->lint([$pattern]);

        $this->assertSame([], $issues);
    }

    public function test_reports_redos_risk(): void
    {
        $service = $this->createService(warningThreshold: 50, redosThreshold: 'low');
        $pattern = new PatternOccurrence('/(a+)+b/', 'file.php', 1, 'php:preg_match()');

        $issues = $service->lint([$pattern]);

        $redosIssues = array_values(array_filter(
            $issues,
            static fn (LintIssue $issue): bool => 'regex.lint.redos' === $issue->issueId,
        ));

        $this->assertNotEmpty($redosIssues);
        $this->assertSame('warning', $redosIssues[0]->severity->level());
    }

    public function test_scan_method(): void
    {
        $service = $this->createService(warningThreshold: 10, redosThreshold: 'high');
        $patterns = $service->scan(['tests'], ['vendor']);

        $this->assertIsArray($patterns);
        // We can't predict exact results since it depends on test files, but ensure it's an array
    }

    public function test_redos_issues_carry_their_place_and_analysis(): void
    {
        $service = $this->createService(warningThreshold: 50, redosThreshold: 'low');
        $patterns = [
            new PatternOccurrence('/(a+)+b/', 'file.php', 1, 'php:preg_match()'),
        ];

        $issues = self::redosIssuesOf($service, $patterns);

        $this->assertNotEmpty($issues);
        $this->assertSame('file.php', $issues[0]->file);
        $this->assertSame(1, $issues[0]->line);
        $this->assertNotNull($issues[0]->analysis);
    }

    public function test_suggest_optimizations_method(): void
    {
        $service = $this->createService(warningThreshold: 10, redosThreshold: 'high');
        $patterns = [
            new PatternOccurrence('/a*b*c*/', 'file.php', 1, 'php:preg_match()'),
        ];

        $optimizations = $service->suggestOptimizations($patterns, 1);

        $this->assertIsArray($optimizations);
    }

    public function test_highlight_method(): void
    {
        $service = $this->createService(warningThreshold: 10, redosThreshold: 'high');

        $highlighted = $service->highlight('/test/');

        $this->assertIsString($highlighted);
        $this->assertNotEmpty($highlighted);
    }

    public function test_highlight_body_method(): void
    {
        $service = $this->createService(warningThreshold: 10, redosThreshold: 'high');

        $highlighted = $service->highlightBody('test', 'i');

        $this->assertIsString($highlighted);
        $this->assertNotEmpty($highlighted);
    }

    public function test_lint_with_linter_warnings(): void
    {
        $service = $this->createService(warningThreshold: 50, redosThreshold: 'high');
        // Nested quantifiers something must follow: a trailing loop cannot
        // fail after it, so the anchor keeps the warning meaningful
        $pattern = new PatternOccurrence('#(a*)*$#', 'file.php', 1, 'route:test', '(a*)*$');

        $issues = $service->lint([$pattern]);

        // Should have warnings from the linter
        $warnings = array_filter($issues, static fn (LintIssue $issue) => 'warning' === $issue->severity->level());
        $this->assertNotEmpty($warnings);
    }

    public function test_lint_with_complex_pattern_above_threshold(): void
    {
        $service = $this->createService(warningThreshold: 0, redosThreshold: 'critical');
        // Create a complex pattern that exceeds the threshold
        $complexPattern = '#^'.str_repeat('(a|b|c|d|e|f|g|h|i|j|k|l|m|n|o|p|q|r|s|t|u|v|w|x|y|z)*', 10).'$#';
        $pattern = new PatternOccurrence($complexPattern, 'file.php', 1, 'route:test', substr($complexPattern, 1, -1));

        $issues = $service->lint([$pattern]);

        $complexityIssues = array_filter($issues, static fn (LintIssue $issue) => 'regex.lint.complexity' === $issue->issueId);
        $this->assertNotEmpty($complexityIssues);
    }

    public function test_lint_with_redos_pattern(): void
    {
        $service = $this->createService(warningThreshold: 50, redosThreshold: 'low');
        $pattern = new PatternOccurrence('/(x+)+y/', 'file.php', 1, 'php:preg_match()');

        $issues = $service->lint([$pattern]);

        $redosIssues = array_filter($issues, static fn (LintIssue $issue) => 'regex.lint.redos' === $issue->issueId);
        $this->assertNotEmpty($redosIssues);
        $this->assertInstanceOf(RedosAnalysis::class, $redosIssues[array_key_first($redosIssues)]->analysis);
    }

    public function test_suggest_optimizations_with_min_savings(): void
    {
        $service = $this->createService(warningThreshold: 10, redosThreshold: 'high');
        $patterns = [
            new PatternOccurrence('/a*a*a*/', 'file.php', 1, 'php:preg_match()'), // This should be optimizable
        ];

        $optimizations = $service->suggestOptimizations($patterns, 10); // High min savings

        // May be empty if savings don't meet threshold
        $this->assertIsArray($optimizations);
    }

    public function test_suggest_optimizations_with_extended_mode(): void
    {
        $service = $this->createService(warningThreshold: 10, redosThreshold: 'high');
        $patterns = [
            new PatternOccurrence("/a+\n# comment\nb+/x", 'file.php', 1, 'php:preg_match()'),
        ];

        $optimizations = $service->suggestOptimizations($patterns, 1);

        $this->assertIsArray($optimizations);
    }

    public function test_validation_error_tips_delimiter_fix(): void
    {
        $service = $this->createService(warningThreshold: 50, redosThreshold: 'high');
        $pattern = new PatternOccurrence('/test', 'file.php', 1, 'php:preg_match()', 'test');

        $issues = $service->lint([$pattern]);

        $this->assertNotEmpty($issues);
        $errorIssue = array_filter($issues, static fn (LintIssue $issue) => 'error' === $issue->severity->level())[0] ?? null;
        $this->assertInstanceOf(LintIssue::class, $errorIssue);
        $this->assertNotNull($errorIssue->tip);
        /** @var string $tip */
        $tip = $errorIssue->tip;
        $this->assertStringContainsString('delimiter', $tip);
    }

    public function test_validation_error_tips_character_class_fix(): void
    {
        $service = $this->createService(warningThreshold: 50, redosThreshold: 'high');
        $pattern = new PatternOccurrence('/[a-z/', 'file.php', 1, 'php:preg_match()', '[a-z');

        $issues = $service->lint([$pattern]);

        $this->assertNotEmpty($issues);
        $errorIssue = array_filter($issues, static fn (LintIssue $issue) => 'error' === $issue->severity->level())[0] ?? null;
        $this->assertInstanceOf(LintIssue::class, $errorIssue);
        $this->assertNotNull($errorIssue->tip);
    }

    public function test_validation_error_tips_quantifier_range_fix(): void
    {
        $service = $this->createService(warningThreshold: 50, redosThreshold: 'high');
        $pattern = new PatternOccurrence('/a{3,2}/', 'file.php', 1, 'php:preg_match()', 'a{3,2}');

        $issues = $service->lint([$pattern]);

        $this->assertNotEmpty($issues);
        $errorIssue = array_filter($issues, static fn (LintIssue $issue) => 'error' === $issue->severity->level())[0] ?? null;
        $this->assertInstanceOf(LintIssue::class, $errorIssue);
        $this->assertNotNull($errorIssue->tip);
    }

    public function test_validation_error_tips_backreference_fix(): void
    {
        $service = $this->createService(warningThreshold: 50, redosThreshold: 'high');
        $pattern = new PatternOccurrence('/\\2/', 'file.php', 1, 'php:preg_match()', '\\2');

        $issues = $service->lint([$pattern]);

        $this->assertNotEmpty($issues);
        $errorIssue = array_filter($issues, static fn (LintIssue $issue) => 'error' === $issue->severity->level())[0] ?? null;
        $this->assertInstanceOf(LintIssue::class, $errorIssue);
        $this->assertNotNull($errorIssue->tip);
    }

    public function test_validation_error_tips_lookbehind_fix(): void
    {
        $service = $this->createService(warningThreshold: 50, redosThreshold: 'high');
        $pattern = new PatternOccurrence('/(?<=a*)/', 'file.php', 1, 'php:preg_match()', '(?<=a*)');

        $issues = $service->lint([$pattern]);

        $this->assertNotEmpty($issues);
        $errorIssue = array_filter($issues, static fn (LintIssue $issue) => 'error' === $issue->severity->level())[0] ?? null;
        $this->assertInstanceOf(LintIssue::class, $errorIssue);
        $this->assertNotNull($errorIssue->tip);
    }

    public function test_redos_hints_with_nested_quantifiers(): void
    {
        $service = $this->createService(warningThreshold: 50, redosThreshold: 'low');
        // (a+)+ alone matches a…a! at once; before the end it fails at n=19 (PCRE2 10.49).
        $pattern = new PatternOccurrence('/(a+)+$/', 'file.php', 1, 'php:preg_match()');

        $issues = $service->lint([$pattern]);

        $redosIssues = array_filter($issues, static fn (LintIssue $issue) => 'regex.lint.redos' === $issue->issueId);
        $this->assertNotEmpty($redosIssues);
        $this->assertNotNull($redosIssues[array_key_first($redosIssues)]->hint);
        $hint = $redosIssues[array_key_first($redosIssues)]->hint;
        $this->assertStringContainsString('atomic groups', (string) $hint);
    }

    public function test_redos_hints_with_dot_star(): void
    {
        $service = $this->createService(warningThreshold: 50, redosThreshold: 'low');
        // .*a+ alone matches at once; repeated before the end, 20 "a" then "!" fail (PCRE2 10.49).
        $pattern = new PatternOccurrence('/(.*a)+$/', 'file.php', 1, 'php:preg_match()');

        $issues = $service->lint([$pattern]);

        $redosIssues = array_filter($issues, static fn (LintIssue $issue) => 'regex.lint.redos' === $issue->issueId);
        $this->assertNotEmpty($redosIssues);
        $this->assertNotNull($redosIssues[array_key_first($redosIssues)]->hint);
    }

    public function test_trivially_safe_patterns_skip_analysis(): void
    {
        $service = $this->createService(warningThreshold: 0, redosThreshold: 'low');
        $pattern = new PatternOccurrence('/^simple|word|list$/', 'file.php', 1, 'route:test', 'simple|word|list');

        $issues = $service->lint([$pattern]);

        // Should skip complexity and ReDoS checks for trivially safe patterns
        $complexityIssues = array_filter($issues, static fn (LintIssue $issue) => 'regex.lint.complexity' === $issue->issueId);
        $redosIssues = array_filter($issues, static fn (LintIssue $issue) => 'regex.lint.redos' === $issue->issueId);
        $this->assertEmpty($complexityIssues);
        $this->assertEmpty($redosIssues);
    }

    public function test_extended_mode_detection(): void
    {
        $service = $this->createService(warningThreshold: 10, redosThreshold: 'high');
        $patterns = [
            new PatternOccurrence('/test/x', 'file.php', 1, 'php:preg_match()'),
        ];

        $optimizations = $service->suggestOptimizations($patterns, 1);

        // Extended mode patterns should be handled differently
        $this->assertIsArray($optimizations);
    }

    public function test_ignore_patterns_with_redos(): void
    {
        $service = $this->createService(
            warningThreshold: 50,
            redosThreshold: 'low',
            ignoredPatterns: ['(a+)+b'],
        );
        $pattern = new PatternOccurrence('/(a+)+b/', 'file.php', 1, 'php:preg_match()');

        $issues = $service->lint([$pattern]);

        // Should skip ReDoS check for ignored patterns
        $redosIssues = array_filter($issues, static fn (LintIssue $issue) => 'regex.lint.redos' === $issue->issueId);
        $this->assertEmpty($redosIssues);
    }

    /**
     * @param array<string> $ignoredPatterns
     */
    private function createService(int $warningThreshold, string $redosThreshold, array $ignoredPatterns = []): AnalysisService
    {
        return new AnalysisService(
            RegexParser::create(),
            null,
            $warningThreshold,
            $redosThreshold,
            $ignoredPatterns,
            RedosMode::Theoretical,
            null,
            true, // redosEnabled
        );
    }

    /**
     * The ReDoS issues the lint reports for the patterns.
     *
     * @param array<PatternOccurrence> $patterns
     *
     * @return list<LintIssue>
     */
    private static function redosIssuesOf(AnalysisService $analysis, array $patterns, int $workers = 1): array
    {
        return array_values(array_filter($analysis->lint($patterns, null, $workers), static fn (LintIssue $issue): bool => RedosVerdict::ISSUE_ID === $issue->issueId));
    }
}
