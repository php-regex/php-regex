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
 * A pattern passed to a parameter marked #[RegexPattern], judged for PHP 8.2
 * and the PCRE2 10.40 it bundles, as a preg_*() pattern is.
 *
 * @extends RuleTestCase<RegexPatternArgumentRule>
 */
final class RegexPatternArgumentRuleTargetTest extends RuleTestCase
{
    public static function getAdditionalConfigFiles(): array
    {
        return [
            ...parent::getAdditionalConfigFiles(),
            __DIR__.'/Fixtures/target-php-8.2.neon',
        ];
    }

    #[Test]
    public function test_a_pattern_the_target_refuses_is_invalid_for_target(): void
    {
        // "(?aD)" needs PCRE2 10.43: an engine that refuses it has the
        // running-engine error reported instead, in its own words.
        $refusal = self::runningEngineRefusal('/(?aD)x/');

        $this->analyse([__DIR__.'/Fixtures/RegexPatternArgumentChecksFixture.php'], [
            null === $refusal
                ? ['Regex pattern is invalid for PHP 8.2 with PCRE2 10.40: Invalid group modifier syntax at position 2.', 33]
                : ['Regex pattern is invalid: '.$refusal.'.', 33],
        ]);
    }

    #[Test]
    public function test_a_pattern_the_target_refuses_carries_the_target_identifier(): void
    {
        $identifiers = array_map(
            static fn (Error $error): array => [$error->getLine(), $error->getIdentifier()],
            $this->gatherAnalyserErrors([__DIR__.'/Fixtures/RegexPatternArgumentChecksFixture.php']),
        );

        $this->assertSame(
            [[33, null === self::runningEngineRefusal('/(?aD)x/') ? 'regex.invalidForTarget' : 'regexp.pattern']],
            $identifiers,
        );
    }

    protected function getRule(): Rule
    {
        // The fixture declares its own functions and classes, out of the
        // autoloader's reach: loaded, PHPStan reflects them as it runs.
        require_once __DIR__.'/Fixtures/RegexPatternArgumentChecksFixture.php';

        return self::getContainer()->getByType(RegexPatternArgumentRule::class);
    }

    /**
     * The oracle: why the PCRE2 running this test refuses the pattern, null
     * when it compiles it.
     */
    private static function runningEngineRefusal(string $pattern): ?string
    {
        if (false !== @preg_match($pattern, '')) {
            return null;
        }

        return preg_replace('/^preg_match\(\): Compilation failed: /', '', error_get_last()['message'] ?? '');
    }
}
