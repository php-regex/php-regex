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
use PHPRegex\Linter\PatternOccurrence;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Redos\RedosAnalysis;
use PHPRegex\Redos\RedosAnalyzer;
use PHPRegex\Redos\RedosMode;
use PHPRegex\Redos\RedosWitness;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The lint issue of a ReDoS verdict: its headline says the class and
 * whether it was proven, its hint carries the attack, and only a replayed
 * verdict at or above high is an error.
 */
final class AnalysisServiceProvenRedosTest extends TestCase
{
    #[Test]
    #[DataProvider('provideHeadlines')]
    public function test_redos_issue_message_carries_the_headline(string $pattern, string $headline): void
    {
        $issue = $this->redosIssue(new AnalysisService(RegexParser::create(), redosThreshold: 'low', redosEnabled: true), $pattern);

        $this->assertStringContainsString($headline, $issue['message']);
    }

    /**
     * @return iterable<string, array{pattern: string, headline: string}>
     */
    public static function provideHeadlines(): iterable
    {
        yield 'proven exponential' => ['pattern' => '/(a+)+$/', 'headline' => 'Exponential backtracking (proven)'];
        yield 'proven polynomial of degree 3' => ['pattern' => '/a*a*a*$/', 'headline' => 'Polynomial backtracking, degree 3 (proven)'];
        // Out of the model: the heuristics judge it medium today.
        yield 'heuristic' => ['pattern' => '/(a)\1+/', 'headline' => 'Potential backtracking (heuristic)'];
        // Over the budget, the heuristics judge it critical: their headline wins.
        yield 'over the budget with a heuristic finding' => ['pattern' => '/^(?:(?:a{16}){16}){16}(a+)+$/', 'headline' => 'Potential backtracking (heuristic)'];
    }

    #[Test]
    public function test_redos_issue_over_the_budget_names_the_budget(): void
    {
        $issue = $this->redosIssue(new AnalysisService(RegexParser::create(), redosThreshold: 'low', redosEnabled: true), '/^(?:(?:a{16}){16}){16}(a+)+$/');

        $text = $issue['message'].' '.$issue['hint'];
        $this->assertStringContainsString('budget exceeded', $text);
        $this->assertStringNotContainsString('not analyzed (budget exceeded)', $text);
    }

    #[Test]
    public function test_redos_issue_hint_carries_the_attack(): void
    {
        $issue = $this->redosIssue(new AnalysisService(RegexParser::create(), redosThreshold: 'low', redosEnabled: true), '/(a+)+$/');

        $witness = (new RedosAnalyzer())->analyze('/(a+)+$/')->witness;
        $this->assertInstanceOf(RedosWitness::class, $witness);
        $this->assertStringContainsString('Attack: '.$witness->render(), $issue['hint']);
    }

    #[Test]
    public function test_theoretical_exponential_verdict_is_a_warning(): void
    {
        $issue = $this->redosIssue(new AnalysisService(RegexParser::create(), redosThreshold: 'high', redosEnabled: true), '/(a+)+$/');

        $this->assertSame('warning', $issue['type']);
        $analysis = $issue['analysis'];
        $this->assertInstanceOf(RedosAnalysis::class, $analysis);
        $this->assertObjectHasProperty('replayed', $analysis);
        $this->assertNull($analysis->replayed);
    }

    #[Test]
    public function test_replayed_exponential_verdict_is_an_error(): void
    {
        $service = new AnalysisService(RegexParser::create(), redosThreshold: 'high', redosMode: RedosMode::Confirmed, redosEnabled: true);

        $issue = $this->redosIssue($service, '/(a+)+$/');

        $this->assertSame('error', $issue['type']);
        $this->assertStringContainsString('Replayed on PCRE2 '.self::pcreRelease().': preg_match fails from length ', $issue['message'].' '.$issue['hint']);
    }

    /**
     * A polynomial verdict is never replayed; in confirmed mode it is still
     * reported, as a warning, with medium confidence.
     */
    #[Test]
    #[DataProvider('providePolynomialPatterns')]
    public function test_confirmed_mode_still_reports_an_unreplayed_polynomial_verdict(string $pattern, string $threshold): void
    {
        $service = new AnalysisService(RegexParser::create(), redosThreshold: $threshold, redosMode: RedosMode::Confirmed, redosEnabled: true);

        $issue = $this->redosIssue($service, $pattern);

        $this->assertSame('warning', $issue['type']);
        $analysis = $issue['analysis'];
        $this->assertInstanceOf(RedosAnalysis::class, $analysis);
        $this->assertObjectHasProperty('replayed', $analysis);
        $this->assertNull($analysis->replayed);
        $this->assertSame('medium', $analysis->confidenceLevel()->value);
    }

    /**
     * @return iterable<string, array{pattern: string, threshold: string}>
     */
    public static function providePolynomialPatterns(): iterable
    {
        yield 'degree 3 at the high threshold' => ['pattern' => '/a*a*a*$/', 'threshold' => 'high'];
        yield 'degree 2 at the medium threshold' => ['pattern' => '/a*a*$/', 'threshold' => 'medium'];
    }

    /**
     * A proven quadratic is medium: below the default threshold, unreported.
     */
    #[Test]
    public function test_proven_quadratic_stays_below_the_default_threshold(): void
    {
        $issues = (new AnalysisService(RegexParser::create(), redosEnabled: true))
            ->lint([new PatternOccurrence('/a*a*$/', 'file.php', 1, 'php:preg_match()')]);

        $this->assertSame([], self::redosIssues($issues));
    }

    /**
     * The issue's texts, the hint empty when there is none.
     *
     * @return array{message: string, hint: string, type: string, analysis: mixed}
     */
    private function redosIssue(AnalysisService $service, string $pattern): array
    {
        $issues = self::redosIssues($service->lint([new PatternOccurrence($pattern, 'file.php', 1, 'php:preg_match()')]));
        $this->assertCount(1, $issues, $pattern.' raised no ReDoS issue');

        $issue = $issues[0];
        $message = $issue['message'] ?? null;
        $type = $issue['type'] ?? null;
        $hint = $issue['hint'] ?? null;
        $this->assertIsString($message);
        $this->assertIsString($type);
        if (null !== $hint) {
            $this->assertIsString($hint);
        }

        return ['message' => $message, 'hint' => $hint ?? '', 'type' => $type, 'analysis' => $issue['analysis'] ?? null];
    }

    /**
     * @param array<array<string, mixed>> $issues
     *
     * @return list<array<string, mixed>>
     */
    private static function redosIssues(array $issues): array
    {
        return array_values(array_filter($issues, static fn (array $issue): bool => 'regex.lint.redos' === ($issue['issueId'] ?? null)));
    }

    private static function pcreRelease(): string
    {
        return explode(' ', \PCRE_VERSION)[0];
    }
}
