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
    private function errorsWithIdentifier(string $identifier): array
    {
        $errors = array_values(array_filter(
            $this->gatherAnalyserErrors([self::FIXTURE]),
            static fn (Error $error): bool => $identifier === $error->getIdentifier(),
        ));
        usort($errors, static fn (Error $a, Error $b): int => ($a->getLine() ?? 0) <=> ($b->getLine() ?? 0));

        return $errors;
    }
}
