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
 * The search cost of the functions that look for every match when each
 * match is short and not empty: the attempt that finds it first reads to
 * the end of the run, and the loop starts a call where it ends. Reported
 * as regex.redos.search at the calls that run the loop, the tip in the
 * words of short matches.
 *
 * @extends RuleTestCase<RegexPatternRule>
 */
final class RegexPatternRuleEveryMatchShortMatchTest extends RuleTestCase
{
    private const FIXTURE = __DIR__.'/Fixtures/EveryMatchShortMatchFixture.php';

    #[Test]
    public function test_every_match_search_cost_of_short_matches_is_reported_at_the_calls_that_loop(): void
    {
        $subject = str_repeat('a', 1000).'!';
        foreach (['/a*b|a/', '/a(?:a+\d)?/'] as $pattern) {
            $this->assertSame(1000, preg_match_all(PcreInterpreter::pattern($pattern), $subject), 'Oracle: preg_match_all() reads the run in one match with '.$pattern.'.');
            $attempt = PcreAttemptSteps::pinned($pattern);
            $this->assertGreaterThanOrEqual(250, PcreAttemptSteps::steps($attempt, $subject, 250) - PcreAttemptSteps::steps($attempt, $subject, 500), 'Oracle: the attempt of '.$pattern.' does not read the run.');
        }

        $lazy = PcreAttemptSteps::pinned('/a(?:a+\d)??/');
        $this->assertLessThan(250, PcreAttemptSteps::steps($lazy, $subject, 250) - PcreAttemptSteps::steps($lazy, $subject, 500), 'Oracle: the attempt of the lazy group reads the run.');

        $errors = $this->errorsWithIdentifier(RegexPatternRule::IDENTIFIER_REDOS_SEARCH);

        $this->assertSame(
            [[20, 'Quadratic search (ReDoS): /a*b|a/'], [21, 'Quadratic search (ReDoS): /a(?:a+\d)?/']],
            array_map(static fn (Error $error): array => [$error->getLine(), $error->getMessage()], $errors),
        );

        $tip = (string) $errors[0]->getTip();
        $this->assertStringContainsString('Quadratic search over short matches', $tip);
        $this->assertStringContainsString('preg_match_all() finds a short match at each position of the run', $tip);
        $this->assertStringContainsString('Attack: "a" x n', $tip);
        $this->assertStringContainsString('preg_split()', (string) $errors[1]->getTip());
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
