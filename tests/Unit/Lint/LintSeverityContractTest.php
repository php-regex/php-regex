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

use PHPRegex\Linter\AnalysisService;
use PHPRegex\Linter\Diagnostic;
use PHPRegex\Linter\DiagnosticType;
use PHPRegex\Linter\Internal\LintSummary;
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
 * The lint pipeline honours the severity each rule declares: Critical and
 * Error are errors, Warning a warning, Style, Perf and Info an info.
 */
final class LintSeverityContractTest extends TestCase
{
    /**
     * @return iterable<string, array{pattern: string, issueId: string, type: string, severity: LintSeverity, lintRules: array<string, bool>}>
     */
    public static function provideRuleSeverities(): iterable
    {
        // Error rules: patterns that compile but misbehave without /u.
        yield 'multibyte in a class without u' => ['pattern' => '/[é]/', 'issueId' => 'regex.lint.unicode.multibyteInClassWithoutU', 'type' => 'error', 'severity' => LintSeverity::Error, 'lintRules' => []];
        yield 'unicode property without u' => ['pattern' => '/\p{L}/', 'issueId' => 'regex.lint.unicode.propertyWithoutU', 'type' => 'error', 'severity' => LintSeverity::Error, 'lintRules' => []];
        yield 'quantified multibyte without u' => ['pattern' => '/é+/', 'issueId' => 'regex.lint.unicode.quantifiedMultibyteWithoutU', 'type' => 'error', 'severity' => LintSeverity::Error, 'lintRules' => []];
        // Warning rules stay warnings.
        yield 'lazy quantifier at the end' => ['pattern' => '/a\d+?/', 'issueId' => 'regex.lint.quantifier.lazyEnd', 'type' => 'warning', 'severity' => LintSeverity::Warning, 'lintRules' => []];
        yield 'named quantified capture' => ['pattern' => '/(?<n>a)+/', 'issueId' => 'regex.lint.group.quantifiedCapture', 'type' => 'warning', 'severity' => LintSeverity::Warning, 'lintRules' => []];
        // The bug rules found by SonarPHP: warnings, none fails a run.
        yield 'repeat of a nullable body' => ['pattern' => '/(?:a*)+/', 'issueId' => 'regex.lint.quantifier.emptyRepeat', 'type' => 'warning', 'severity' => LintSeverity::Warning, 'lintRules' => []];
        yield 'anchor that skips a branch' => ['pattern' => '/^a|b/', 'issueId' => 'regex.lint.anchor.alternationPrecedence', 'type' => 'warning', 'severity' => LintSeverity::Warning, 'lintRules' => []];
        yield 'possessive repeat that starves the next atom' => ['pattern' => '/a*+a/', 'issueId' => 'regex.lint.quantifier.possessiveImpossible', 'type' => 'warning', 'severity' => LintSeverity::Warning, 'lintRules' => []];
        yield 'impossible word boundary' => ['pattern' => '/a\bb/', 'issueId' => 'regex.lint.anchor.impossible.boundary', 'type' => 'warning', 'severity' => LintSeverity::Warning, 'lintRules' => []];
        yield 'contradictory lookahead' => ['pattern' => '/(?=a)b/', 'issueId' => 'regex.lint.lookaround.impossible', 'type' => 'warning', 'severity' => LintSeverity::Warning, 'lintRules' => []];
        yield 'empty group' => ['pattern' => '/a(?:)b/', 'issueId' => 'regex.lint.group.empty', 'type' => 'warning', 'severity' => LintSeverity::Warning, 'lintRules' => []];
        // Info and Style rules are infos.
        yield 'unnamed quantified capture' => ['pattern' => '/(a)+/', 'issueId' => 'regex.lint.group.quantifiedCapture', 'type' => 'info', 'severity' => LintSeverity::Info, 'lintRules' => []];
        yield 'shorthand without u, a style rule' => ['pattern' => '/\w/', 'issueId' => 'regex.lint.unicode.shorthandWithoutU', 'type' => 'info', 'severity' => LintSeverity::Info, 'lintRules' => ['unicode.shorthandWithoutU' => true]];
        yield 'single-character class, a style rule' => ['pattern' => '/[a]/', 'issueId' => 'regex.lint.charclass.single', 'type' => 'info', 'severity' => LintSeverity::Info, 'lintRules' => ['charclass.single' => true]];
        yield 'run of spaces, a style rule' => ['pattern' => '/a  b/', 'issueId' => 'regex.lint.literal.multipleSpaces', 'type' => 'info', 'severity' => LintSeverity::Info, 'lintRules' => ['literal.multipleSpaces' => true]];
        yield 'lazy dot before a delimiter, a perf rule' => ['pattern' => '/".*?"/', 'issueId' => 'regex.lint.quantifier.lazyToClass', 'type' => 'info', 'severity' => LintSeverity::Info, 'lintRules' => ['quantifier.lazyToClass' => true]];
    }

    /**
     * @param array<string, bool> $lintRules
     */
    #[Test]
    #[DataProvider('provideRuleSeverities')]
    public function test_issue_type_follows_the_rule_severity(string $pattern, string $issueId, string $type, LintSeverity $severity, array $lintRules): void
    {
        $this->assertNotFalse(@preg_match($pattern, ''), 'Every row compiles: the issue is a lint issue, not an invalid pattern.');

        $issues = (new AnalysisService(RegexParser::create(), lintRules: $lintRules))
            ->lint([new PatternOccurrence($pattern, 'test.php', 1, 'preg_match')]);

        $types = [];
        foreach ($issues as $issue) {
            if ($issueId === ($issue['issueId'] ?? null)) {
                $types[] = $issue['type'];
            }
        }

        $this->assertSame([$type], $types, $pattern);
    }

    /**
     * @param array<string, bool> $lintRules
     */
    #[Test]
    #[DataProvider('provideRuleSeverities')]
    public function test_problem_severity_follows_the_rule_severity(string $pattern, string $issueId, string $type, LintSeverity $severity, array $lintRules): void
    {
        $service = new LintService(new AnalysisService(RegexParser::create(), lintRules: $lintRules), new PatternSourceCollection([]));
        $report = $service->analyze([new PatternOccurrence($pattern, 'test.php', 1, 'preg_match')], new LintRequest(['.'], [], 0, lintRules: $lintRules), null);

        $severities = [];
        foreach ($report->results as $result) {
            foreach ($result['problems'] as $problem) {
                if (DiagnosticType::Lint === $problem->type && $issueId === $problem->code) {
                    $severities[] = $problem->severity;
                }
            }
        }

        $this->assertSame([$severity], $severities, $pattern);
    }

    #[Test]
    public function test_the_error_rules_name_patterns_the_engine_reads_differently_without_u(): void
    {
        // The class matches one byte of "é" on its own.
        $this->assertSame(1, preg_match('/^[é]$/', "\xC3"));
        $this->assertSame(1, preg_match('/^[é]$/u', 'é'));
        // The quantifier repeats the last byte only.
        $this->assertSame(1, preg_match('/^é+$/', "\xC3\xA9\xA9"));
        $this->assertSame(0, preg_match('/^é+$/', 'éé'));
        $this->assertSame(1, preg_match('/^é+$/u', 'éé'));
        // The property stops at the first 256 code points.
        $this->assertSame(0, preg_match('/^\p{L}$/', 'ā'));
        $this->assertSame(1, preg_match('/^\p{L}$/u', 'ā'));
    }

    #[Test]
    public function test_stats_count_lint_errors_among_errors_and_infos_apart(): void
    {
        $service = new LintService(new AnalysisService(RegexParser::create()), new PatternSourceCollection([]));
        $report = $service->analyze([
            new PatternOccurrence('/[é]/', 'test.php', 1, 'preg_match'),
            new PatternOccurrence('/(a)+/', 'test.php', 2, 'preg_match'),
            new PatternOccurrence('/a\d+?/', 'test.php', 3, 'preg_match'),
        ], new LintRequest(['.'], [], 0, checkOptimizations: false), null);

        $this->assertSame(1, $report->stats['errors']);
        $this->assertSame(1, $report->stats['lintErrors'] ?? null);
        $this->assertSame(1, $report->stats['warnings']);
        $this->assertSame(1, $report->stats['infos'] ?? null);
        $this->assertArrayNotHasKey('redos', $report->stats);
    }

    #[Test]
    public function test_stats_carry_no_lint_error_or_info_count_when_there_are_none(): void
    {
        // Like "redos": the optional counts are left out at zero.
        $service = new LintService(new AnalysisService(RegexParser::create()), new PatternSourceCollection([]));
        $report = $service->analyze([new PatternOccurrence(self::invalidPattern(), 'test.php', 1, 'preg_match')], new LintRequest(['.'], [], 0), null);

        $this->assertFalse(@preg_match(self::invalidPattern(), ''));
        $this->assertSame(['errors' => 1, 'warnings' => 0, 'optimizations' => 0], $report->stats);
    }

    /**
     * @param array{errors: int, warnings: int, optimizations: int, redos?: int, lintErrors?: int, infos?: int} $stats
     */
    #[Test]
    #[DataProvider('provideSummaries')]
    public function test_summary_names_lint_errors_apart_from_invalid_patterns(array $stats, string $summary): void
    {
        $this->assertSame($summary, LintSummary::errors($stats));
    }

    /**
     * @return iterable<string, array{stats: array{errors: int, warnings: int, optimizations: int, redos?: int, lintErrors?: int, infos?: int}, summary: string}>
     */
    public static function provideSummaries(): iterable
    {
        yield 'one lint error' => ['stats' => ['errors' => 1, 'warnings' => 0, 'optimizations' => 0, 'lintErrors' => 1], 'summary' => '1 lint errors'];
        yield 'one invalid pattern' => ['stats' => ['errors' => 1, 'warnings' => 0, 'optimizations' => 0], 'summary' => '1 invalid patterns'];
        yield 'one of each' => ['stats' => ['errors' => 3, 'warnings' => 0, 'optimizations' => 0, 'redos' => 1, 'lintErrors' => 1], 'summary' => '1 invalid patterns, 1 ReDoS errors, 1 lint errors'];
        yield 'ReDoS and lint errors, nothing invalid' => ['stats' => ['errors' => 2, 'warnings' => 0, 'optimizations' => 0, 'redos' => 1, 'lintErrors' => 1], 'summary' => '1 ReDoS errors, 1 lint errors'];
    }

    #[Test]
    public function test_a_style_rule_becomes_an_info_problem(): void
    {
        // The Diagnostic carries the one mapping: no Style or Perf survives
        // the pipeline, they are infos like Info.
        $service = new LintService(new AnalysisService(RegexParser::create(), lintRules: ['unicode.shorthandWithoutU' => true]), new PatternSourceCollection([]));
        $report = $service->analyze([new PatternOccurrence('/\w/', 'test.php', 1, 'preg_match')], new LintRequest(['.'], [], 0, lintRules: ['unicode.shorthandWithoutU' => true]), null);

        $severities = [];
        foreach ($report->results as $result) {
            foreach ($result['problems'] as $problem) {
                $this->assertInstanceOf(Diagnostic::class, $problem);
                $severities[] = $problem->severity;
            }
        }

        $this->assertNotContains(LintSeverity::Style, $severities);
        $this->assertNotContains(LintSeverity::Warning, $severities);
    }

    /**
     * Through a call, so that static analysis does not compile it.
     */
    private static function invalidPattern(): string
    {
        return '/[a-z/';
    }
}
