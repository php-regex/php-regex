<?php

declare(strict_types=1);

/*
 * This file is part of the RegexParser package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace RegexParser\Tests\Integration\Bridge\PHPStan;

use PHPStan\Analyser\Error;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\Test;
use RegexParser\Bridge\PHPStan\RegexParserRule;

/**
 * The shipped extension.neon with "regexParser.phpVersion: '8.2'": a project
 * judged for PHP 8.2 and the PCRE2 10.40 it bundles.
 *
 * @extends RuleTestCase<RegexParserRule>
 */
final class RegexParserRuleExtensionNeonTargetTest extends RuleTestCase
{
    public static function getAdditionalConfigFiles(): array
    {
        return [
            ...parent::getAdditionalConfigFiles(),
            __DIR__.'/Fixtures/target-php-8.2.neon',
        ];
    }

    #[Test]
    public function test_a_target_specific_refusal_is_the_only_error(): void
    {
        // Line 25 ("(?aD)") needs PCRE2 10.43: reported only where the running engine compiles it;
        // on an engine that refuses it, PHPStan core reports it and this rule stays silent.
        $this->analyse(
            [__DIR__.'/Fixtures/NeonConfigFixture.php'],
            self::runningEngineCompiles('/(?aD)x/') ? [
                ['Regex pattern is invalid for PHP 8.2 with PCRE2 10.40: Invalid group modifier syntax at position 2.', 25],
            ] : [],
        );
    }

    #[Test]
    public function test_a_target_specific_refusal_is_identified_as_invalid_for_target(): void
    {
        $identifiers = array_map(
            static fn (Error $error): array => [$error->getLine(), $error->getIdentifier()],
            $this->gatherAnalyserErrors([__DIR__.'/Fixtures/NeonConfigFixture.php']),
        );

        $this->assertSame(
            self::runningEngineCompiles('/(?aD)x/') ? [[25, 'regex.invalidForTarget']] : [],
            $identifiers,
        );
    }

    protected function getRule(): Rule
    {
        return self::getContainer()->getByType(RegexParserRule::class);
    }

    /**
     * The oracle: whether the PCRE2 running this test compiles the pattern.
     */
    private static function runningEngineCompiles(string $pattern): bool
    {
        return false !== @preg_match($pattern, '');
    }
}
