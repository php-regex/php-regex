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
use PHPStan\Analyser\Error;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * A subject PHPStan knows to be constant cannot carry an attack: the call
 * either backtracks the same way on every run or never does, so ReDoS is
 * reported only where the subject may come from outside.
 *
 * @extends RuleTestCase<RegexPatternRule>
 */
final class RegexParserRuleConstantSubjectTest extends RuleTestCase
{
    private const FIXTURE = __DIR__.'/Fixtures/ConstantSubjectFixture.php';

    #[Test]
    public function test_redos_is_reported_only_where_the_subject_may_vary(): void
    {
        $lines = array_map(
            static fn (Error $error): int => (int) $error->getLine(),
            array_values(array_filter(
                $this->gatherAnalyserErrors([self::FIXTURE]),
                static fn (Error $error): bool => RegexPatternRule::IDENTIFIER_REDOS === $error->getIdentifier(),
            )),
        );
        sort($lines);

        $this->assertSame([24, 28, 32, 39, 40], $lines);
    }

    #[Test]
    public function test_the_fixture_backtracks_on_none_of_its_constants(): void
    {
        foreach (['aaaa', 'aaaab', 'bbbb'] as $subject) {
            $this->assertNotFalse(preg_match('/(a+)+$/', $subject), $subject);
        }
    }

    protected function getRule(): Rule
    {
        return new RegexPatternRule(config: [
            'checks' => [
                'lint' => ['enabled' => false],
                'redos' => ['enabled' => true, 'threshold' => 'low'],
                'optimizations' => ['enabled' => false],
            ],
        ]);
    }
}
