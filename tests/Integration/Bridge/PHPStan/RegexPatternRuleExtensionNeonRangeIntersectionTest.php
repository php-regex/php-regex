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
use PHPRegex\Tests\Support\PcreRule;
use PHPRegex\Tests\Support\RangeFixtureReadings;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The shipped extension.neon with "phpRegex.phpVersion: {min: 80100, max:
 * 80499}" inside PHPStan's "phpVersion: {min: 80100, max: 80599}": the
 * versions judged are those both allow, 8.1 to 8.4, and the pattern PHP
 * 8.5 refuses is not reported. Read alone, PHPStan's range would report it.
 *
 * @extends RuleTestCase<RegexPatternRule>
 */
final class RegexPatternRuleExtensionNeonRangeIntersectionTest extends RuleTestCase
{
    use PcreRule;
    use RangeFixtureReadings;

    public static function getAdditionalConfigFiles(): array
    {
        return [
            ...parent::getAdditionalConfigFiles(),
            __DIR__.'/Fixtures/target-range-intersection.neon',
        ];
    }

    #[Test]
    public function test_the_range_named_for_the_rule_narrows_phpstans_range(): void
    {
        self::assertEngineKeepInLookbehind();

        $this->analyse([__DIR__.'/Fixtures/VersionRangeFixture.php'], []);
    }

    protected function getRule(): Rule
    {
        return self::getContainer()->getByType(RegexPatternRule::class);
    }
}
