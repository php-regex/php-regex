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

namespace RegexParser\Tests\Unit\Bridge\Symfony\Service;

use PHPUnit\Framework\TestCase;
use RegexParser\Lint\RegexAnalysisService;
use RegexParser\Lint\RegexPatternOccurrence;
use RegexParser\ReDoS\ReDoSSeverity;
use RegexParser\RegexParser;

final class RegexAnalysisServiceTest extends TestCase
{
    public function test_reports_invalid_pattern(): void
    {
        $service = $this->createService(warningThreshold: 10, redosThreshold: 'high');
        $pattern = new RegexPatternOccurrence('#^($#', 'file.php', 1, 'route:test', '(');

        $issues = $service->lint([$pattern]);

        $this->assertCount(1, $issues);
        $this->assertSame('error', $issues[0]['type']);
    }

    public function test_warns_on_complexity_threshold(): void
    {
        $service = $this->createService(warningThreshold: 0, redosThreshold: 'critical');
        $pattern = new RegexPatternOccurrence('#^[a-z]+$#', 'file.php', 1, 'route:test', '[a-z]+');

        $issues = $service->lint([$pattern]);

        $this->assertCount(1, $issues);
        $this->assertSame('warning', $issues[0]['type']);
        $this->assertArrayHasKey('issueId', $issues[0]);
        $this->assertSame('regex.lint.complexity', $issues[0]['issueId']);
    }

    public function test_trivial_alternation_skips_risk_checks(): void
    {
        $service = $this->createService(warningThreshold: 0, redosThreshold: 'critical');
        $pattern = new RegexPatternOccurrence('#^en|fr|de$#', 'file.php', 1, 'route:test', 'en|fr|de');

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
        $pattern = new RegexPatternOccurrence(
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
        $pattern = new RegexPatternOccurrence('/(a+)+b/', 'file.php', 1, 'php:preg_match()');

        $issues = $service->lint([$pattern]);

        $redosIssues = array_values(array_filter(
            $issues,
            static fn (array $issue): bool => 'regex.lint.redos' === ($issue['issueId'] ?? null),
        ));

        $this->assertNotEmpty($redosIssues);
        $this->assertSame('warning', $redosIssues[0]['type']);
    }

    public function test_scan_method(): void
    {
        $service = $this->createService(warningThreshold: 10, redosThreshold: 'high');
        $patterns = $service->scan(['tests'], ['vendor']);

        $this->assertIsArray($patterns);
        // We can't predict exact results since it depends on test files, but ensure it's an array
    }

    public function test_analyze_redos_method(): void
    {
        $service = $this->createService(warningThreshold: 50, redosThreshold: 'low');
        $patterns = [
            new RegexPatternOccurrence('/(a+)+b/', 'file.php', 1, 'php:preg_match()'),
        ];

        $issues = $service->analyzeRedos($patterns, ReDoSSeverity::LOW);

        $this->assertIsArray($issues);
        $this->assertNotEmpty($issues);
        $this->assertArrayHasKey('file', $issues[0]);
        $this->assertArrayHasKey('line', $issues[0]);
        $this->assertArrayHasKey('analysis', $issues[0]);
    }

    public function test_suggest_optimizations_method(): void
    {
        $service = $this->createService(warningThreshold: 10, redosThreshold: 'high');
        $patterns = [
            new RegexPatternOccurrence('/a*b*c*/', 'file.php', 1, 'php:preg_match()'),
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

    public function test_lint_with_invalid_pattern_and_ignore_parse_errors(): void
    {
        $service = new RegexAnalysisService(
            RegexParser::create(),
            null,
            10,
            'high',
            [],
            [],
            true, // ignoreParseErrors = true
        );

        // Use a pattern that would trigger "No closing delimiter" which should be ignored
        $pattern = new RegexPatternOccurrence('/^test', 'file.php', 1, 'route:test', '^test');

        $issues = $service->lint([$pattern]);

        // Should be empty because parse errors are ignored
        $this->assertSame([], $issues);
    }

    public function test_lint_with_linter_warnings(): void
    {
        $service = $this->createService(warningThreshold: 50, redosThreshold: 'high');
        // Use a pattern with nested quantifiers that should trigger linter warnings
        $pattern = new RegexPatternOccurrence('#(a*)*#', 'file.php', 1, 'route:test', '(a*)*');

        $issues = $service->lint([$pattern]);

        // Should have warnings from the linter
        $warnings = array_filter($issues, static fn ($issue) => 'warning' === $issue['type']);
        $this->assertNotEmpty($warnings);
    }

    public function test_lint_with_complex_pattern_above_threshold(): void
    {
        $service = $this->createService(warningThreshold: 0, redosThreshold: 'critical');
        // Create a complex pattern that exceeds the threshold
        $complexPattern = '#^'.str_repeat('(a|b|c|d|e|f|g|h|i|j|k|l|m|n|o|p|q|r|s|t|u|v|w|x|y|z)*', 10).'$#';
        $pattern = new RegexPatternOccurrence($complexPattern, 'file.php', 1, 'route:test', substr($complexPattern, 1, -1));

        $issues = $service->lint([$pattern]);

        $complexityIssues = array_filter($issues, static fn ($issue) => ($issue['issueId'] ?? null) === 'regex.lint.complexity');
        $this->assertNotEmpty($complexityIssues);
    }

    public function test_lint_with_redos_pattern(): void
    {
        $service = $this->createService(warningThreshold: 50, redosThreshold: 'low');
        $pattern = new RegexPatternOccurrence('/(x+)+y/', 'file.php', 1, 'php:preg_match()');

        $issues = $service->lint([$pattern]);

        $redosIssues = array_filter($issues, static fn ($issue) => ($issue['issueId'] ?? null) === 'regex.lint.redos');
        $this->assertNotEmpty($redosIssues);
        $this->assertArrayHasKey('analysis', $redosIssues[array_key_first($redosIssues)]);
    }

    public function test_suggest_optimizations_with_min_savings(): void
    {
        $service = $this->createService(warningThreshold: 10, redosThreshold: 'high');
        $patterns = [
            new RegexPatternOccurrence('/a*a*a*/', 'file.php', 1, 'php:preg_match()'), // This should be optimizable
        ];

        $optimizations = $service->suggestOptimizations($patterns, 10); // High min savings

        // May be empty if savings don't meet threshold
        $this->assertIsArray($optimizations);
    }

    public function test_suggest_optimizations_with_extended_mode(): void
    {
        $service = $this->createService(warningThreshold: 10, redosThreshold: 'high');
        $patterns = [
            new RegexPatternOccurrence("/a+\n# comment\nb+/x", 'file.php', 1, 'php:preg_match()'),
        ];

        $optimizations = $service->suggestOptimizations($patterns, 1);

        $this->assertIsArray($optimizations);
    }

    public function test_validation_error_tips_delimiter_fix(): void
    {
        $service = $this->createService(warningThreshold: 50, redosThreshold: 'high');
        $pattern = new RegexPatternOccurrence('/test', 'file.php', 1, 'php:preg_match()', 'test');

        $issues = $service->lint([$pattern]);

        $this->assertNotEmpty($issues);
        $errorIssue = array_filter($issues, static fn ($issue) => 'error' === $issue['type'])[0] ?? null;
        $this->assertNotNull($errorIssue);
        $this->assertArrayHasKey('tip', $errorIssue);
        /** @var string $tip */
        $tip = $errorIssue['tip'];
        $this->assertStringContainsString('delimiter', $tip);
    }

    public function test_validation_error_tips_character_class_fix(): void
    {
        $service = $this->createService(warningThreshold: 50, redosThreshold: 'high');
        $pattern = new RegexPatternOccurrence('/[a-z/', 'file.php', 1, 'php:preg_match()', '[a-z');

        $issues = $service->lint([$pattern]);

        $this->assertNotEmpty($issues);
        $errorIssue = array_filter($issues, static fn ($issue) => 'error' === $issue['type'])[0] ?? null;
        $this->assertNotNull($errorIssue);
        $this->assertArrayHasKey('tip', $errorIssue);
    }

    public function test_validation_error_tips_quantifier_range_fix(): void
    {
        $service = $this->createService(warningThreshold: 50, redosThreshold: 'high');
        $pattern = new RegexPatternOccurrence('/a{3,2}/', 'file.php', 1, 'php:preg_match()', 'a{3,2}');

        $issues = $service->lint([$pattern]);

        $this->assertNotEmpty($issues);
        $errorIssue = array_filter($issues, static fn ($issue) => 'error' === $issue['type'])[0] ?? null;
        $this->assertNotNull($errorIssue);
        $this->assertArrayHasKey('tip', $errorIssue);
    }

    public function test_validation_error_tips_backreference_fix(): void
    {
        $service = $this->createService(warningThreshold: 50, redosThreshold: 'high');
        $pattern = new RegexPatternOccurrence('/\\2/', 'file.php', 1, 'php:preg_match()', '\\2');

        $issues = $service->lint([$pattern]);

        $this->assertNotEmpty($issues);
        $errorIssue = array_filter($issues, static fn ($issue) => 'error' === $issue['type'])[0] ?? null;
        $this->assertNotNull($errorIssue);
        $this->assertArrayHasKey('tip', $errorIssue);
    }

    public function test_validation_error_tips_lookbehind_fix(): void
    {
        $service = $this->createService(warningThreshold: 50, redosThreshold: 'high');
        $pattern = new RegexPatternOccurrence('/(?<=a*)/', 'file.php', 1, 'php:preg_match()', '(?<=a*)');

        $issues = $service->lint([$pattern]);

        $this->assertNotEmpty($issues);
        $errorIssue = array_filter($issues, static fn ($issue) => 'error' === $issue['type'])[0] ?? null;
        $this->assertNotNull($errorIssue);
        $this->assertArrayHasKey('tip', $errorIssue);
    }

    public function test_redos_hints_with_nested_quantifiers(): void
    {
        $service = $this->createService(warningThreshold: 50, redosThreshold: 'low');
        $pattern = new RegexPatternOccurrence('/(a+)+/', 'file.php', 1, 'php:preg_match()');

        $issues = $service->lint([$pattern]);

        $redosIssues = array_filter($issues, static fn ($issue) => ($issue['issueId'] ?? null) === 'regex.lint.redos');
        $this->assertNotEmpty($redosIssues);
        $this->assertArrayHasKey('hint', $redosIssues[array_key_first($redosIssues)]);
        $hint = $redosIssues[array_key_first($redosIssues)]['hint'];
        $this->assertStringContainsString('atomic groups', (string) $hint);
    }

    public function test_redos_hints_with_dot_star(): void
    {
        $service = $this->createService(warningThreshold: 50, redosThreshold: 'low');
        $pattern = new RegexPatternOccurrence('/.*a+/', 'file.php', 1, 'php:preg_match()');

        $issues = $service->lint([$pattern]);

        $redosIssues = array_filter($issues, static fn ($issue) => ($issue['issueId'] ?? null) === 'regex.lint.redos');
        $this->assertNotEmpty($redosIssues);
        $this->assertArrayHasKey('hint', $redosIssues[array_key_first($redosIssues)]);
    }

    public function test_trivially_safe_patterns_skip_analysis(): void
    {
        $service = $this->createService(warningThreshold: 0, redosThreshold: 'low');
        $pattern = new RegexPatternOccurrence('/^simple|word|list$/', 'file.php', 1, 'route:test', 'simple|word|list');

        $issues = $service->lint([$pattern]);

        // Should skip complexity and ReDoS checks for trivially safe patterns
        $complexityIssues = array_filter($issues, static fn ($issue) => ($issue['issueId'] ?? null) === 'regex.lint.complexity');
        $redosIssues = array_filter($issues, static fn ($issue) => ($issue['issueId'] ?? null) === 'regex.lint.redos');
        $this->assertEmpty($complexityIssues);
        $this->assertEmpty($redosIssues);
    }

    public function test_extended_mode_detection(): void
    {
        $service = $this->createService(warningThreshold: 10, redosThreshold: 'high');
        $patterns = [
            new RegexPatternOccurrence('/test/x', 'file.php', 1, 'php:preg_match()'),
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
        $pattern = new RegexPatternOccurrence('/(a+)+b/', 'file.php', 1, 'php:preg_match()');

        $issues = $service->lint([$pattern]);

        // Should skip ReDoS check for ignored patterns
        $redosIssues = array_filter($issues, static fn ($issue) => ($issue['issueId'] ?? null) === 'regex.lint.redos');
        $this->assertEmpty($redosIssues);
    }

    /**
     * @param array<string> $ignoredPatterns
     */
    private function createService(int $warningThreshold, string $redosThreshold, array $ignoredPatterns = []): RegexAnalysisService
    {
        return new RegexAnalysisService(
            RegexParser::create(),
            null,
            $warningThreshold,
            $redosThreshold,
            $ignoredPatterns,
            [],
            false,
            'theoretical',
            null,
            true, // redosEnabled
        );
    }
}
