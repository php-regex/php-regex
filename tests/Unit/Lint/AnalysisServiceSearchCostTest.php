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
use PHPRegex\Redos\RedosSearchCost;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The lint issue of a search cost, regex.lint.redos.search: it runs under
 * the ReDoS check with no switch of its own, is medium like a proven
 * quadratic attempt (so the default high threshold hides it), and is a
 * warning, never an error.
 */
final class AnalysisServiceSearchCostTest extends TestCase
{
    private const SEARCH = 'regex.lint.redos.search';

    #[Test]
    #[DataProvider('provideReportingThresholds')]
    public function test_search_cost_issue_is_reported_at_or_below_medium(string $threshold): void
    {
        $issues = self::searchIssues(self::service(redosThreshold: $threshold), '/\s+$/');

        $this->assertCount(1, $issues);
        $this->assertSame('warning', $issues[0]['type'] ?? null);
        $analysis = $issues[0]['analysis'] ?? null;
        $this->assertInstanceOf(RedosAnalysis::class, $analysis);
        $this->assertInstanceOf(RedosSearchCost::class, $analysis->searchCost);
    }

    /**
     * @return iterable<string, array{threshold: string}>
     */
    public static function provideReportingThresholds(): iterable
    {
        yield 'medium' => ['threshold' => 'medium'];
        yield 'low' => ['threshold' => 'low'];
    }

    #[Test]
    #[DataProvider('provideHidingThresholds')]
    public function test_search_cost_issue_is_hidden_above_medium(string $threshold): void
    {
        $this->assertCount(1, self::searchIssues(self::service(redosThreshold: 'medium'), '/\s+$/'), 'control: reported at medium');

        $this->assertSame([], self::searchIssues(self::service(redosThreshold: $threshold), '/\s+$/'));
    }

    /**
     * @return iterable<string, array{threshold: string}>
     */
    public static function provideHidingThresholds(): iterable
    {
        yield 'high, the default' => ['threshold' => 'high'];
        yield 'critical' => ['threshold' => 'critical'];
    }

    #[Test]
    public function test_search_cost_issue_is_hidden_by_the_default_threshold(): void
    {
        $this->assertCount(1, self::searchIssues(self::service(redosThreshold: 'medium'), '/\s+$/'), 'control: reported at medium');

        $service = new AnalysisService(RegexParser::create(), redosEnabled: true);

        $this->assertSame([], self::searchIssues($service, '/\s+$/'));
    }

    #[Test]
    public function test_search_cost_issue_needs_the_redos_check(): void
    {
        $this->assertCount(1, self::searchIssues(self::service(redosThreshold: 'low'), '/\s+$/'), 'control: reported with the check on');

        $service = new AnalysisService(RegexParser::create(), redosThreshold: 'low', redosEnabled: false);

        $this->assertSame([], self::searchIssues($service, '/\s+$/'));
    }

    #[Test]
    public function test_search_cost_message_says_one_attempt_is_linear_and_the_search_quadratic(): void
    {
        $issues = self::searchIssues(self::service(redosThreshold: 'medium'), '/\s+$/');
        $this->assertCount(1, $issues);
        $message = $issues[0]['message'] ?? null;
        $this->assertIsString($message);
        $hint = $issues[0]['hint'] ?? '';
        $this->assertIsString($hint);

        $this->assertStringContainsString("one attempt is linear (proven); an unanchored search is quadratic in PCRE2's interpreter", (string) $message);
        // No immunity claimed for the JIT; the per-attempt limit does not stop it.
        $this->assertStringContainsString('the JIT may avoid it for some patterns', $message.' '.$hint);
        $this->assertStringContainsString('pcre.backtrack_limit', $message.' '.$hint);
    }

    /**
     * One attempt is proven linear: no regex.lint.redos issue beside it.
     */
    #[Test]
    public function test_search_cost_issue_comes_without_a_per_attempt_issue(): void
    {
        $issues = self::service(redosThreshold: 'low')->lint([new PatternOccurrence('/\s+$/', 'file.php', 1, 'php:preg_match()')]);

        $this->assertSame([], self::issuesWithId($issues, 'regex.lint.redos'));
        $this->assertCount(1, self::issuesWithId($issues, self::SEARCH));
    }

    /**
     * A worse per-attempt verdict covers the search: one issue, the
     * per-attempt one.
     */
    #[Test]
    #[DataProvider('provideWorsePerAttemptVerdicts')]
    public function test_search_cost_issue_is_not_raised_beside_a_worse_per_attempt_verdict(string $pattern): void
    {
        $issues = self::service(redosThreshold: 'low')->lint([new PatternOccurrence($pattern, 'file.php', 1, 'php:preg_match()')]);

        $perAttempt = self::issuesWithId($issues, 'regex.lint.redos');
        $this->assertCount(1, $perAttempt, $pattern);
        $analysis = $perAttempt[0]['analysis'] ?? null;
        $this->assertInstanceOf(RedosAnalysis::class, $analysis);
        $this->assertNull(self::searchCostOf($analysis), $pattern);
        $this->assertSame([], self::issuesWithId($issues, self::SEARCH), $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideWorsePerAttemptVerdicts(): iterable
    {
        yield 'quadratic attempt' => ['pattern' => '/a*a*$/'];
        yield 'exponential attempt' => ['pattern' => '/(a+)+$/'];
    }

    #[Test]
    public function test_anchored_pattern_raises_no_search_cost_issue(): void
    {
        $this->assertCount(1, self::searchIssues(self::service(redosThreshold: 'low'), '/\s+$/'), 'control: the unanchored pattern is reported');

        $this->assertSame([], self::searchIssues(self::service(redosThreshold: 'low'), '/^\s+$/'));
    }

    /**
     * Replayed or not, a search cost is a warning: the confirmed mode never
     * turns it into an error.
     */
    #[Test]
    public function test_search_cost_issue_stays_a_warning_in_confirmed_mode(): void
    {
        $service = new AnalysisService(RegexParser::create(), redosThreshold: 'low', redosMode: RedosMode::Confirmed, redosEnabled: true);

        $issues = self::searchIssues($service, '/\s+$/');

        $this->assertCount(1, $issues);
        $this->assertSame('warning', $issues[0]['type'] ?? null);
    }

    /**
     * The id is a rule id like the others: --disable-rule and regex.json
     * "rules" pass it in $lintRules.
     */
    #[Test]
    public function test_search_cost_issue_is_turned_off_by_its_rule_id(): void
    {
        $this->assertCount(1, self::searchIssues(self::service(redosThreshold: 'low'), '/\s+$/'), 'control: reported with the rule on');

        $service = new AnalysisService(RegexParser::create(), redosThreshold: 'low', redosEnabled: true, lintRules: [self::SEARCH => false]);

        $this->assertSame([], self::searchIssues($service, '/\s+$/'));
    }

    #[Test]
    #[DataProvider('provideIgnoredForms')]
    public function test_search_cost_issue_follows_the_redos_ignore_list(string $pattern, string $ignored): void
    {
        $this->assertCount(1, self::searchIssues(self::service(redosThreshold: 'low'), $pattern), 'control: reported when not ignored');

        $service = new AnalysisService(RegexParser::create(), redosThreshold: 'low', redosIgnoredPatterns: [$ignored], redosEnabled: true);

        $this->assertSame([], self::searchIssues($service, $pattern));
    }

    /**
     * The ignore list holds full regexes or their bodies, anchors left out,
     * whatever delimiter the regex uses.
     *
     * @return iterable<string, array{pattern: string, ignored: string}>
     */
    public static function provideIgnoredForms(): iterable
    {
        yield 'full regex' => ['pattern' => '/\s+$/', 'ignored' => '/\s+$/'];
        yield 'body of a regex delimited by "!"' => ['pattern' => '!\s+$!', 'ignored' => '\s+'];
    }

    /**
     * The issue points where the pattern was found, like every other one.
     */
    #[Test]
    public function test_search_cost_issue_carries_the_location_of_the_pattern(): void
    {
        $issues = self::service(redosThreshold: 'low')->lint([new PatternOccurrence('/\s+$/', 'file.php', 3, 'php:preg_match()', column: 7, fileOffset: 42)]);

        $search = self::issuesWithId($issues, self::SEARCH);
        $this->assertCount(1, $search);
        $this->assertSame('file.php', $search[0]['file'] ?? null);
        $this->assertSame(3, $search[0]['line'] ?? null);
        $this->assertSame(7, $search[0]['column'] ?? null);
        $this->assertSame(42, $search[0]['fileOffset'] ?? null);
    }

    #[Test]
    public function test_search_cost_message_names_its_severity(): void
    {
        $issues = self::searchIssues(self::service(redosThreshold: 'medium'), '/\s+$/');
        $this->assertCount(1, $issues);

        $message = $issues[0]['message'] ?? null;
        $this->assertIsString($message);
        $this->assertStringEndsWith('Severity: MEDIUM.', $message);
    }

    /**
     * The hint says what the step replay found: nothing when none ran,
     * "replayed" when it counted the attempts growing, "not confirmed" when
     * it could not count them (a possessive loop of one character, which the
     * counter does not see inside).
     */
    #[Test]
    #[DataProvider('provideReplayOutcomes')]
    public function test_search_cost_hint_says_what_the_replay_found(string $pattern, RedosMode $mode, ?bool $replayed, ?string $line): void
    {
        $service = new AnalysisService(RegexParser::create(), redosThreshold: 'low', redosMode: $mode, redosEnabled: true);

        $issues = self::searchIssues($service, $pattern);
        $this->assertCount(1, $issues, $pattern);
        $analysis = $issues[0]['analysis'] ?? null;
        $this->assertInstanceOf(RedosAnalysis::class, $analysis);
        $this->assertSame($replayed, self::searchCostOf($analysis)?->replayed, $pattern);

        $hint = $issues[0]['hint'] ?? null;
        $this->assertIsString($hint, $pattern);
        foreach (['Replayed on PCRE2', 'Not confirmed by the step replay'] as $candidate) {
            if ($candidate === $line) {
                $this->assertStringContainsString($candidate, $hint, $pattern);
            } else {
                $this->assertStringNotContainsString($candidate, $hint, $pattern);
            }
        }
    }

    /**
     * @return iterable<string, array{pattern: string, mode: RedosMode, replayed: bool|null, line: string|null}>
     */
    public static function provideReplayOutcomes(): iterable
    {
        yield 'no replay' => ['pattern' => '/\s+$/', 'mode' => RedosMode::Theoretical, 'replayed' => null, 'line' => null];
        yield 'replayed' => ['pattern' => '/\s+$/', 'mode' => RedosMode::Confirmed, 'replayed' => true, 'line' => 'Replayed on PCRE2'];
        yield 'not confirmed' => ['pattern' => '/a++b/', 'mode' => RedosMode::Confirmed, 'replayed' => false, 'line' => 'Not confirmed by the step replay'];
    }

    /**
     * The attack in the hint is the whole witness: the prefix the first
     * attempt fails on, the run repeated, the breaker.
     */
    #[Test]
    public function test_search_cost_attack_opens_with_the_prefix(): void
    {
        $cost = (new RedosAnalyzer())->analyze('/^\s+|\s+$/')->searchCost;
        $this->assertInstanceOf(RedosSearchCost::class, $cost);
        $this->assertNotSame('', $cost->prefix);

        $issues = self::searchIssues(self::service(redosThreshold: 'medium'), '/^\s+|\s+$/');
        $this->assertCount(1, $issues);
        $hint = $issues[0]['hint'] ?? null;
        $this->assertIsString($hint);
        $this->assertStringContainsString('Attack: '.$cost->render().'.', (string) $hint);
        $this->assertStringStartsWith('"', $cost->render());
        $this->assertStringContainsString('" x n', $cost->render());
    }

    private static function service(string $redosThreshold): AnalysisService
    {
        return new AnalysisService(RegexParser::create(), redosThreshold: $redosThreshold, redosEnabled: true);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function searchIssues(AnalysisService $service, string $pattern): array
    {
        return self::issuesWithId($service->lint([new PatternOccurrence($pattern, 'file.php', 1, 'php:preg_match()')]), self::SEARCH);
    }

    /**
     * @param array<array<string, mixed>> $issues
     *
     * @return list<array<string, mixed>>
     */
    private static function issuesWithId(array $issues, string $issueId): array
    {
        return array_values(array_filter($issues, static fn (array $issue): bool => $issueId === ($issue['issueId'] ?? null)));
    }

    /**
     * The search cost of an analysis, failing when RedosAnalysis has no such
     * property: reading a missing one gives null with a warning, and a null
     * is what the negative rows expect.
     */
    private static function searchCostOf(RedosAnalysis $analysis): ?RedosSearchCost
    {
        self::assertObjectHasProperty('searchCost', $analysis, 'RedosAnalysis has no searchCost property.');

        return $analysis->searchCost;
    }
}
