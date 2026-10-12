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
 * preg_replace(), preg_replace_callback() and preg_filter() take an array
 * of patterns, and preg_replace_callback_array() a map whose keys are
 * patterns: each one is judged as the same pattern passed alone, written
 * in the call, held in a variable or in a constant.
 *
 * @extends RuleTestCase<RegexPatternRule>
 */
final class RegexPatternRulePatternArrayTest extends RuleTestCase
{
    private const FIXTURE = __DIR__.'/Fixtures/PatternArrayFixture.php';

    #[Test]
    public function test_every_pattern_of_an_array_is_judged(): void
    {
        $this->assertSame([24, 25, 26, 28, 29, 30, 31, 32], $this->linesOf(RegexPatternRule::IDENTIFIER_REDOS));
    }

    #[Test]
    public function test_the_patterns_of_an_array_are_linted(): void
    {
        $this->assertSame([24, 25, 26, 28, 29, 30, 31, 32, 33], $this->linesOf('regex.lint.quantifier.nested'));
    }

    protected function getRule(): Rule
    {
        return new RegexPatternRule(config: [
            'checks' => [
                'lint' => ['enabled' => true],
                'redos' => ['enabled' => true, 'threshold' => 'low'],
                'optimizations' => ['enabled' => false],
            ],
        ]);
    }

    /**
     * @return list<int>
     */
    private function linesOf(string $identifier): array
    {
        $lines = array_map(
            static fn (Error $error): int => (int) $error->getLine(),
            array_values(array_filter(
                $this->gatherAnalyserErrors([self::FIXTURE]),
                static fn (Error $error): bool => $identifier === $error->getIdentifier(),
            )),
        );
        sort($lines);

        return $lines;
    }
}
