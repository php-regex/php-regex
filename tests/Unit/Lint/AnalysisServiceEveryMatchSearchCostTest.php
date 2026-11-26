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
use PHPRegex\Linter\DiagnosticType;
use PHPRegex\Linter\LintIssue;
use PHPRegex\Linter\PatternOccurrence;
use PHPRegex\Linter\RedosVerdict;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Redos\RedosAnalysis;
use PHPRegex\Redos\RedosMode;
use PHPRegex\Redos\RedosSearchCost;
use PHPRegex\Redos\SearchCostCause;
use PHPRegex\Tests\Support\PcreAttemptSteps;
use PHPRegex\Tests\Support\PcreInterpreter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The search cost of the functions that look for every match, raised as
 * regex.lint.redos.search at the calls that run their loop: after each
 * empty match PHP retries the same offset with PCRE2_NOTEMPTY_ATSTART |
 * PCRE2_ANCHORED, and on a run of "a" each retry of "(?:a+\d)?" reads what
 * is left of it. PHP 8.4.28, PCRE2 10.49, on 2 000 to 16 000 "a" with
 * pcre.jit=0: preg_match_all() 2.27, 8.61, 34.08, 130.96 ms; preg_replace()
 * 2.24, 8.75, 34.82, 130.75 ms; preg_split() 2.31, 8.91, 33.23, 127.15 ms;
 * preg_match() and preg_grep() 0.01 ms.
 */
final class AnalysisServiceEveryMatchSearchCostTest extends TestCase
{
    private const SEARCH = 'regex.lint.redos.search';

    private const PATTERN = '/(?:a+\d)?/';

    #[Test]
    #[DataProvider('provideEveryMatchFunctions')]
    public function test_every_match_search_cost_is_raised_at_the_calls_that_loop(string $function): void
    {
        $this->assertGreaterThanOrEqual(100, self::matchesMade($function, str_repeat('a', 100).'!'), 'Oracle: '.$function.'() stops before the end of the run.');
        self::assertRetriesGrow();

        $issues = self::searchIssues(self::service('medium'), new PatternOccurrence(self::PATTERN, 'file.php', 1, $function.'()', everyMatch: $function));

        $this->assertCount(1, $issues);
        $this->assertSame('warning', $issues[0]->severity->level());
        $analysis = $issues[0]->analysis;
        $this->assertInstanceOf(RedosAnalysis::class, $analysis);
        $this->assertNull($analysis->searchCost);
        $cost = $analysis->everyMatchSearchCost;
        $this->assertInstanceOf(RedosSearchCost::class, $cost);
        $this->assertSame(RedosVerdict::everyMatchMessage($function, $cost), $issues[0]->message);
        $this->assertStringContainsString($function.'()', (string) $issues[0]->message);
        $this->assertStringStartsWith('Quadratic search after an empty match:', (string) $issues[0]->message);
        $this->assertSame(RedosVerdict::everyMatchEvidence($cost, $analysis->pcreVersion), $issues[0]->hints);
        $this->assertSame(implode(' ', $issues[0]->hints), $issues[0]->hint);
        $this->assertGreaterThanOrEqual(50, self::matchesMade($function, $cost->build(100)), 'The attack stops '.$function.'() before the end of the run.');
    }

    /**
     * @return iterable<string, array{function: string}>
     */
    public static function provideEveryMatchFunctions(): iterable
    {
        foreach (['preg_match_all', 'preg_replace', 'preg_filter', 'preg_replace_callback', 'preg_replace_callback_array', 'preg_split'] as $function) {
            yield $function => ['function' => $function];
        }
    }

    /**
     * A call that stops at the first match, or a pattern read without a
     * call, keeps the verdict of one search: nothing.
     */
    #[Test]
    #[DataProvider('provideCallsStoppingAtTheFirstMatch')]
    public function test_every_match_search_cost_is_not_raised_where_the_call_stops_at_the_first_match(string $source, string $function): void
    {
        $this->assertSame(1, self::matchesMade($function, str_repeat('a', 100).'!'), 'Oracle: '.$function.'() goes on after the first match.');

        $issues = self::service('low')->lint([new PatternOccurrence(self::PATTERN, 'file.php', 1, $source)]);

        $this->assertSame([], self::issuesWithId($issues, self::SEARCH));
        $this->assertSame([], self::issuesWithId($issues, RedosVerdict::ISSUE_ID));
    }

    /**
     * @return iterable<string, array{source: string, function: string}>
     */
    public static function provideCallsStoppingAtTheFirstMatch(): iterable
    {
        yield 'preg_match' => ['source' => 'preg_match()', 'function' => 'preg_match'];
        yield 'preg_grep' => ['source' => 'preg_grep()', 'function' => 'preg_grep'];
        yield 'no call' => ['source' => 'php', 'function' => 'preg_match'];
    }

    /**
     * A literal several calls read is one occurrence, at its first call:
     * each other call that looks for every match gets the cost of its own
     * loop, named in its own issue, and a function named twice is raised
     * once.
     */
    #[Test]
    public function test_every_match_search_cost_is_raised_for_each_loop_the_literal_runs_in(): void
    {
        foreach (['preg_match_all', 'preg_split'] as $function) {
            $this->assertGreaterThanOrEqual(100, self::matchesMade($function, str_repeat('a', 100).'!'), 'Oracle: '.$function.'() stops before the end of the run.');
        }
        $this->assertSame(1, self::matchesMade('preg_match', str_repeat('a', 100).'!'), 'Oracle: preg_match() goes on after the first match.');
        self::assertRetriesGrow();

        $issues = self::searchIssues(self::service('medium'), new PatternOccurrence(self::PATTERN, 'file.php', 1, 'preg_match()', everyMatchElsewhere: ['preg_match_all', 'preg_split']));

        $this->assertSame([RedosVerdict::everyMatchMessage('preg_match_all', new RedosSearchCost(2, SearchCostCause::EmptyMatchRetry, '', 'a', '', false)), RedosVerdict::everyMatchMessage('preg_split', new RedosSearchCost(2, SearchCostCause::EmptyMatchRetry, '', 'a', '', false))], array_map(static fn (LintIssue $issue): string => $issue->message, $issues));
        foreach ($issues as $issue) {
            $this->assertSame('warning', $issue->severity->level());
            $this->assertSame([1, 'file.php'], [$issue->line, $issue->file], 'Raised where the literal is written.');
        }

        $issues = self::searchIssues(self::service('medium'), new PatternOccurrence(self::PATTERN, 'file.php', 1, 'preg_split()', everyMatch: 'preg_split', everyMatchElsewhere: ['preg_split']));
        $this->assertSame([RedosVerdict::everyMatchMessage('preg_split', new RedosSearchCost(2, SearchCostCause::EmptyMatchRetry, '', 'a', '', false))], array_map(static fn (LintIssue $issue): string => $issue->message, $issues));
    }

    #[Test]
    public function test_every_match_search_cost_is_hidden_by_the_default_threshold(): void
    {
        $occurrence = self::looping();
        $this->assertCount(1, self::searchIssues(self::service('medium'), $occurrence), 'control: raised at medium');

        $this->assertSame([], self::searchIssues(new AnalysisService(RegexParser::create(), redosEnabled: true), $occurrence));
        $this->assertSame([], self::searchIssues(self::service('high'), $occurrence));
    }

    #[Test]
    public function test_every_match_search_cost_needs_the_redos_check(): void
    {
        $this->assertCount(1, self::searchIssues(self::service('low'), self::looping()), 'control: raised with the check on');

        $this->assertSame([], self::searchIssues(new AnalysisService(RegexParser::create(), redosThreshold: 'low', redosEnabled: false), self::looping()));
    }

    #[Test]
    public function test_every_match_search_cost_is_turned_off_with_the_search_cost(): void
    {
        $this->assertCount(1, self::searchIssues(self::service('low'), self::looping()), 'control: raised with the rule on');

        $service = new AnalysisService(RegexParser::create(), redosThreshold: 'low', redosEnabled: true, lintRules: [self::SEARCH => false]);

        $this->assertSame([], self::searchIssues($service, self::looping()));
    }

    #[Test]
    public function test_every_match_search_cost_follows_the_redos_ignore_list(): void
    {
        $this->assertCount(1, self::searchIssues(self::service('low'), self::looping()), 'control: raised when not ignored');

        $service = new AnalysisService(RegexParser::create(), redosThreshold: 'low', redosIgnoredPatterns: [self::PATTERN], redosEnabled: true);

        $this->assertSame([], self::searchIssues($service, self::looping()));
    }

    /**
     * Confirmed mode keeps it a warning and says what the step replay of
     * the retry found.
     */
    #[Test]
    public function test_every_match_search_cost_stays_a_warning_in_confirmed_mode(): void
    {
        self::assertRetriesGrow();
        $service = new AnalysisService(RegexParser::create(), redosThreshold: 'low', redosMode: RedosMode::Confirmed, redosEnabled: true);

        $issues = self::searchIssues($service, self::looping());

        $this->assertCount(1, $issues);
        $this->assertSame('warning', $issues[0]->severity->level());
        $analysis = $issues[0]->analysis;
        $this->assertInstanceOf(RedosAnalysis::class, $analysis);
        $this->assertTrue($analysis->everyMatchSearchCost?->replayed);
        $this->assertContains(\sprintf('Replayed on PCRE2 %s without the JIT: the retry at a position further from the end of the run takes more steps.', $analysis->pcreVersion), $issues[0]->hints);
    }

    /**
     * The reports that keep the message and its snippet (GitHub,
     * Checkstyle, JUnit) show the attack of the retry.
     */
    #[Test]
    public function test_every_match_search_cost_diagnostic_carries_its_evidence(): void
    {
        $issues = self::searchIssues(self::service('medium'), self::looping());
        $this->assertCount(1, $issues);
        $analysis = $issues[0]->analysis;
        $this->assertInstanceOf(RedosAnalysis::class, $analysis);
        $cost = $analysis->everyMatchSearchCost;
        $this->assertInstanceOf(RedosSearchCost::class, $cost);

        $diagnostic = $issues[0]->diagnostic();

        $this->assertSame(DiagnosticType::Security, $diagnostic->type);
        $this->assertSame(self::SEARCH, $diagnostic->code);
        $this->assertSame(implode("\n", RedosVerdict::everyMatchEvidence($cost, $analysis->pcreVersion)), $diagnostic->snippet);
    }

    /**
     * The words of the finding, frozen in the hints a report shows.
     */
    #[Test]
    public function test_every_match_search_cost_words(): void
    {
        $this->assertSame(
            "Quadratic search after an empty match: one attempt is linear (proven), but preg_split() finds an empty match at each position of the run and, as PHP does after every empty match, tries that position again for a non-empty one, which reads to the end of the run; PHP runs that retry in PCRE2's interpreter, whatever pcre.jit. Severity: MEDIUM.",
            RedosVerdict::everyMatchMessage('preg_split', new RedosSearchCost(2, SearchCostCause::EmptyMatchRetry, '', 'a', '', false)),
        );

        $this->assertSame([
            'Attack: "a" x n.',
            'pcre.backtrack_limit does not stop it: the limit counts each call apart.',
            'preg_match() and preg_grep() stop at the first match and make no such retry.',
            'Make every match read at least one character, or bound the length of the run; ^ or \A before every alternative stops it, \G and the A modifier do not.',
        ], RedosVerdict::everyMatchEvidence(new RedosSearchCost(2, SearchCostCause::EmptyMatchRetry, '', 'a', '', false), '10.49'));

        $this->assertContains('Not confirmed by the step replay on PCRE2 10.49.', RedosVerdict::everyMatchEvidence(new RedosSearchCost(2, SearchCostCause::EmptyMatchRetry, '', 'a', '', false, false), '10.49'));
    }

    /**
     * The words of the finding when each short match pays the run, frozen
     * in the hints a report shows.
     */
    #[Test]
    public function test_every_match_search_cost_words_of_short_matches(): void
    {
        $cost = new RedosSearchCost(2, SearchCostCause::ShortMatch, '', 'a', '', false);

        $this->assertSame(
            "Quadratic search over short matches: one attempt is linear (proven), but preg_split() finds a short match at each position of the run, and each attempt reads to the end of the run before it settles on it; that is quadratic in PCRE2's interpreter (pcre.jit=0, a build without JIT, or (*NO_JIT)), and the JIT may avoid it for some patterns. Severity: MEDIUM.",
            RedosVerdict::everyMatchMessage('preg_split', $cost),
        );

        $this->assertSame([
            'Attack: "a" x n.',
            'pcre.backtrack_limit does not stop it: the limit counts each call apart.',
            'preg_match() and preg_grep() stop at the first match and read the run once.',
            'Bound the length of the run; ^ or \A before every alternative stops it, \G and the A modifier do not.',
        ], RedosVerdict::everyMatchEvidence($cost, '10.49'));

        $this->assertContains('Replayed on PCRE2 10.49 without the JIT: the attempt at a position further from the end of the run takes more steps.', RedosVerdict::everyMatchEvidence(new RedosSearchCost(2, SearchCostCause::ShortMatch, '', 'a', '', false, true), '10.49'));
        $this->assertContains('Not confirmed by the step replay on PCRE2 10.49.', RedosVerdict::everyMatchEvidence(new RedosSearchCost(2, SearchCostCause::ShortMatch, '', 'a', '', false, false), '10.49'));
    }

    /**
     * Each short match of "a*b|a" on a run of "a" is found after "a*b"
     * read the rest of the run: the cost is raised at the calls that loop,
     * in the words of short matches, and not at preg_match().
     */
    #[Test]
    public function test_every_match_search_cost_of_short_matches_is_raised_at_the_calls_that_loop(): void
    {
        $pattern = '/a*b|a/';
        $subject = str_repeat('a', 1000).'!';
        $this->assertSame(1000, preg_match_all(PcreInterpreter::pattern($pattern), $subject), 'Oracle: preg_match_all() reads the run in one match.');
        $attempt = PcreAttemptSteps::pinned($pattern);
        $this->assertGreaterThanOrEqual(250, PcreAttemptSteps::steps($attempt, $subject, 250) - PcreAttemptSteps::steps($attempt, $subject, 500), 'Oracle: the attempt does not read the run.');

        $issues = self::searchIssues(self::service('medium'), new PatternOccurrence($pattern, 'file.php', 1, 'preg_match_all()', everyMatch: 'preg_match_all'));

        $this->assertCount(1, $issues);
        $this->assertSame('warning', $issues[0]->severity->level());
        $analysis = $issues[0]->analysis;
        $this->assertInstanceOf(RedosAnalysis::class, $analysis);
        $cost = $analysis->everyMatchSearchCost;
        $this->assertInstanceOf(RedosSearchCost::class, $cost);
        $this->assertSame(SearchCostCause::ShortMatch, $cost->cause);
        $this->assertSame(RedosVerdict::everyMatchMessage('preg_match_all', $cost), $issues[0]->message);
        $this->assertStringStartsWith('Quadratic search over short matches:', (string) $issues[0]->message);
        $this->assertSame(RedosVerdict::everyMatchEvidence($cost, $analysis->pcreVersion), $issues[0]->hints);

        $this->assertSame([], self::searchIssues(self::service('low'), new PatternOccurrence($pattern, 'file.php', 1, 'preg_match()')));
    }

    private static function service(string $redosThreshold): AnalysisService
    {
        return new AnalysisService(RegexParser::create(), redosThreshold: $redosThreshold, redosEnabled: true);
    }

    private static function looping(): PatternOccurrence
    {
        return new PatternOccurrence(self::PATTERN, 'file.php', 1, 'preg_match_all()', everyMatch: 'preg_match_all');
    }

    /**
     * The retry PHP makes after the empty match at a quarter, then half-way
     * through 1 000 "a", reads what is left of the run: 752 then 502 steps
     * with auto-possession off.
     */
    private static function assertRetriesGrow(): void
    {
        $subject = str_repeat('a', 1000).'!';
        $retry = PcreAttemptSteps::pinned('/(*NOTEMPTY_ATSTART)(?:a+\d)?/');
        self::assertGreaterThanOrEqual(250, PcreAttemptSteps::steps($retry, $subject, 250) - PcreAttemptSteps::steps($retry, $subject, 500), 'Oracle: the retry does not read the run.');
    }

    /**
     * How many matches the function makes with the pattern on the subject,
     * in PCRE2's interpreter.
     */
    private static function matchesMade(string $function, string $subject): int
    {
        $pattern = PcreInterpreter::pattern(self::PATTERN);
        $count = 0;
        $replace = static fn (array $matches): string => '';
        $made = match ($function) {
            'preg_match' => preg_match($pattern, $subject),
            'preg_grep' => preg_grep($pattern, [$subject]),
            'preg_match_all' => preg_match_all($pattern, $subject),
            'preg_replace' => null === preg_replace($pattern, '', $subject, -1, $count) ? false : $count,
            'preg_filter' => null === preg_filter($pattern, '', $subject, -1, $count) ? false : $count,
            'preg_replace_callback' => null === preg_replace_callback($pattern, $replace, $subject, -1, $count) ? false : $count,
            'preg_replace_callback_array' => null === preg_replace_callback_array([$pattern => $replace], $subject, -1, $count) ? false : $count,
            'preg_split' => preg_split($pattern, $subject),
            default => false,
        };
        // The pieces of preg_split() are one more than its matches.
        if (\is_array($made)) {
            $made = \count($made) - ('preg_split' === $function ? 1 : 0);
        }
        self::assertIsInt($made, 'Oracle: '.$function.' failed.');

        return $made;
    }

    /**
     * @return list<LintIssue>
     */
    private static function searchIssues(AnalysisService $service, PatternOccurrence $occurrence): array
    {
        return self::issuesWithId($service->lint([$occurrence]), self::SEARCH);
    }

    /**
     * @param array<LintIssue> $issues
     *
     * @return list<LintIssue>
     */
    private static function issuesWithId(array $issues, string $id): array
    {
        return array_values(array_filter($issues, static fn (LintIssue $issue): bool => $id === $issue->issueId));
    }
}
