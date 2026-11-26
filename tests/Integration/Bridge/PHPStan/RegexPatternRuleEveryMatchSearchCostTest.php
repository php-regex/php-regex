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

namespace PHPRegex\Tests\Integration\Bridge\PHPStan;

use PHPRegex\PHPStan\RegexPatternRule;
use PHPRegex\Tests\Support\PcreAttemptSteps;
use PHPRegex\Tests\Support\PcreInterpreter;
use PHPStan\Analyser\Error;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The search cost of the functions that look for every match in PHPStan:
 * the existing regex.redos.search and its frozen message, at the calls that
 * run their loop, the tip naming the retry. preg_match() and preg_grep()
 * stop at the first match, a literal limit bounds the loop, and a constant
 * subject carries no attack.
 *
 * @extends RuleTestCase<RegexPatternRule>
 */
final class RegexPatternRuleEveryMatchSearchCostTest extends RuleTestCase
{
    private const FIXTURE = __DIR__.'/Fixtures/EveryMatchSearchCostFixture.php';

    /**
     * "(?:a+\d)?" matches the empty string at each position of a run of
     * "a", and the retry PHP makes there reads what is left of it.
     */
    #[Test]
    public function test_every_match_search_cost_is_reported_at_the_calls_that_loop(): void
    {
        $pattern = PcreInterpreter::pattern('/(?:a+\d)?/');
        $subject = str_repeat('a', 1000).'!';
        // An empty match at each "a", at the "!" and at the end.
        $this->assertSame(1002, preg_match_all($pattern, $subject), 'Oracle: preg_match_all() stops before the end of the run.');
        $this->assertSame(1, preg_match($pattern, $subject), 'Oracle: preg_match() goes on.');
        $count = 0;
        preg_replace($pattern, '', $subject, 1, $count);
        $this->assertSame(1, $count, 'Oracle: the limit does not bound preg_replace().');
        $retry = PcreAttemptSteps::pinned('/(*NOTEMPTY_ATSTART)(?:a+\d)?/');
        $this->assertGreaterThanOrEqual(250, PcreAttemptSteps::steps($retry, $subject, 250) - PcreAttemptSteps::steps($retry, $subject, 500), 'Oracle: the retry does not read the run.');

        $errors = $this->errorsWithIdentifier(RegexPatternRule::IDENTIFIER_REDOS_SEARCH);

        $this->assertSame(
            array_map(static fn (int $line): array => [$line, 'Quadratic search (ReDoS): /(?:a+\d)?/'], [22, 23, 24, 25, 26, 27, 28, 29, 35]),
            array_map(static fn (Error $error): array => [$error->getLine(), $error->getMessage()], $errors),
        );
    }

    #[Test]
    public function test_every_match_search_cost_tip_names_the_function_and_the_retry(): void
    {
        $errors = $this->errorsWithIdentifier(RegexPatternRule::IDENTIFIER_REDOS_SEARCH);
        $this->assertNotSame([], $errors);

        $tip = (string) $errors[0]->getTip();
        $this->assertStringContainsString('Quadratic search after an empty match', $tip);
        $this->assertStringContainsString('preg_match_all() finds an empty match at each position of the run', $tip);
        $this->assertStringContainsString('whatever pcre.jit', $tip);
        $this->assertStringContainsString('Attack: "a" x n', $tip);

        $split = $errors[5]->getTip();
        $this->assertStringContainsString('preg_split()', (string) $split);
    }

    /**
     * No per-attempt error beside it: one attempt is linear.
     */
    #[Test]
    public function test_every_match_search_cost_comes_without_a_per_attempt_error(): void
    {
        $this->assertNotSame([], $this->errorsWithIdentifier(RegexPatternRule::IDENTIFIER_REDOS_SEARCH), 'control: reported');

        $this->assertSame([], $this->errorsWithIdentifier(RegexPatternRule::IDENTIFIER_REDOS));
    }

    /**
     * The wrappers of nette/utils, spatie/regex and Laravel's Str whose
     * methods run a function looking for every match: matchAll(), split(),
     * replace() and replaceMatches(), each naming the function it calls,
     * preg_replace_callback() for a closure. Nette's lazy matchAll() calls
     * preg_match() from the end of each match, a loop of its own that
     * makes no retry and pays the attempt before each empty match; a lazy
     * flag no type settles is not known to loop, and a literal limit
     * bounds the loop.
     */
    #[Test]
    public function test_every_match_search_cost_is_reported_at_the_wrapper_calls_that_loop(): void
    {
        $pattern = PcreInterpreter::pattern('/(?:a+\d)?/');
        $subject = str_repeat('a', 1000).'!';
        $this->assertSame(1002, preg_match_all($pattern, $subject), 'Oracle: preg_match_all() stops before the end of the run.');
        $this->assertCount(1003, preg_split($pattern, $subject) ?: [], 'Oracle: preg_split() stops before the end of the run.');
        $count = 0;
        $this->assertIsString(preg_replace_callback($pattern, static fn (array $m): string => '', $subject, -1, $count));
        $this->assertSame(1002, $count, 'Oracle: preg_replace_callback() stops before the end of the run.');
        $this->assertSame(1, preg_match($pattern, $subject, $match, 0, 1), 'Oracle: preg_match() from a later offset goes on.');
        $attempt = PcreAttemptSteps::pinned('/(?:a+\d)?/');
        $this->assertGreaterThanOrEqual(250, PcreAttemptSteps::steps($attempt, $subject, 250) - PcreAttemptSteps::steps($attempt, $subject, 500), 'Oracle: the attempt a loop of preg_match() calls makes does not read the run.');
        $lazy = PcreAttemptSteps::pinned('/(?:a+\d)??/');
        $this->assertSame(PcreAttemptSteps::steps($lazy, $subject, 250), PcreAttemptSteps::steps($lazy, $subject, 500), 'Oracle: the attempt of the lazy group reads the run.');
        $this->assertCount(2, preg_split($pattern, $subject, 2) ?: [], 'Oracle: the limit does not bound preg_split().');

        $errors = $this->errorsWithIdentifier(RegexPatternRule::IDENTIFIER_REDOS_SEARCH, __DIR__.'/Fixtures/EveryMatchWrapperFixture.php');

        $this->assertSame(
            [
                [27, 'preg_match_all'],
                [28, 'preg_match_all'],
                [29, 'preg_split'],
                [30, 'preg_replace'],
                [31, 'preg_replace_callback'],
                [32, 'preg_match_all'],
                [33, 'preg_replace'],
                [34, 'preg_match_all'],
                [35, 'preg_replace_callback'],
                [36, 'the loop that calls preg_match'],
            ],
            array_map(static function (Error $error): array {
                preg_match('~but (\w+)\(\) finds an empty match|but (the loop that calls preg_match)\(\) again where each match ends finds a short or an empty match~', (string) $error->getTip(), $function);

                return [$error->getLine(), $function[2] ?? $function[1] ?? $error->getMessage()];
            }, $errors),
        );
        $this->assertSame(['Quadratic search (ReDoS): /(?:a+\d)?/'], array_values(array_unique(array_map(static fn (Error $error): string => $error->getMessage(), $errors))));
    }

    protected function getRule(): Rule
    {
        return new RegexPatternRule(config: [
            'checks' => [
                'lint' => ['enabled' => false],
                'redos' => ['enabled' => true, 'threshold' => 'medium'],
                'optimizations' => ['enabled' => false],
            ],
        ]);
    }

    /**
     * @return list<Error>
     */
    private function errorsWithIdentifier(string $identifier, string $fixture = self::FIXTURE): array
    {
        $errors = array_values(array_filter(
            $this->gatherAnalyserErrors([$fixture]),
            static fn (Error $error): bool => $identifier === $error->getIdentifier(),
        ));
        usort($errors, static fn (Error $a, Error $b): int => ($a->getLine() ?? 0) <=> ($b->getLine() ?? 0));

        return $errors;
    }
}
