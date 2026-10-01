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
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * Tests the safeguard in RegexPatternRule that prevents suggesting invalid optimizations.
 *
 * @extends RuleTestCase<RegexPatternRule>
 */
final class RegexParserRuleSafeguardTest extends RuleTestCase
{
    public function test_no_optimization_suggested_for_invalid_optimized_patterns(): void
    {
        $this->analyse([__DIR__.'/Fixtures/SafeguardFixture.php'], [
            // No errors expected, because optimizations are invalid and should not be suggested
        ]);
    }

    protected function getRule(): Rule
    {
        return new RegexPatternRule(config: [
            'checks' => [
                'redos' => ['enabled' => false, 'threshold' => 'high'],
                'optimizations' => ['enabled' => true], // Enable optimizations to test the safeguard
            ],
        ]);
    }
}
