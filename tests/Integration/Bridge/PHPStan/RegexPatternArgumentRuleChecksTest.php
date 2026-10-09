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

use PHPRegex\PHPStan\RegexPatternArgumentRule;
use PHPStan\Analyser\Error;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * A pattern passed to a parameter marked #[RegexPattern], with the opt-in
 * rules.neon: linted and checked for ReDoS as in a preg_*() call.
 *
 * @extends RuleTestCase<RegexPatternArgumentRule>
 */
final class RegexPatternArgumentRuleChecksTest extends RuleTestCase
{
    public static function getAdditionalConfigFiles(): array
    {
        return [
            ...parent::getAdditionalConfigFiles(),
            __DIR__.'/../../../../src/PHPStan/extension.neon',
            __DIR__.'/../../../../src/PHPStan/rules.neon',
        ];
    }

    #[Test]
    public function test_rules_neon_lints_a_marked_argument_and_checks_it_for_redos(): void
    {
        $identifiers = array_map(
            static fn (Error $error): string => $error->getLine().' '.$error->getIdentifier(),
            $this->gatherAnalyserErrors([__DIR__.'/Fixtures/RegexPatternArgumentChecksFixture.php']),
        );
        sort($identifiers);

        $this->assertSame([
            '30 regex.lint.group.quantifiedCapture',
            '30 regex.lint.quantifier.nested',
            '30 regex.redos',
            '31 regex.lint.group.redundant',
        ], $identifiers);
    }

    #[Test]
    public function test_a_redos_error_reads_as_in_a_preg_call(): void
    {
        $messages = array_map(
            static fn (Error $error): string => $error->getMessage(),
            array_filter(
                $this->gatherAnalyserErrors([__DIR__.'/Fixtures/RegexPatternArgumentChecksFixture.php']),
                static fn (Error $error): bool => 'regex.redos' === $error->getIdentifier(),
            ),
        );

        $this->assertSame(['Exponential backtracking (ReDoS): /(a+)+$/'], array_values($messages));
    }

    protected function getRule(): Rule
    {
        // The fixture declares its own functions and classes, out of the
        // autoloader's reach: loaded, PHPStan reflects them as it runs.
        require_once __DIR__.'/Fixtures/RegexPatternArgumentChecksFixture.php';

        return self::getContainer()->getByType(RegexPatternArgumentRule::class);
    }
}
