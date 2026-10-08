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

use PHPRegex\Parser\RegexParser;
use PHPRegex\PHPStan\RegexPatternRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The shipped extension.neon with PHPStan's "phpVersion: {min: 80400,
 * max: 80599}": a pattern PHP 8.4 accepts and PHP 8.5 refuses is reported.
 *
 * @extends RuleTestCase<RegexPatternRule>
 */
final class RegexParserRuleExtensionNeonRangeTest extends RuleTestCase
{
    public static function getAdditionalConfigFiles(): array
    {
        return [
            ...parent::getAdditionalConfigFiles(),
            __DIR__.'/Fixtures/target-range.neon',
        ];
    }

    #[Test]
    public function test_the_range_reaches_the_rule(): void
    {
        $refusal = RegexParser::create(['php_version' => '8.5'])->validate('/(?<=a\Kb)c/');

        $this->analyse(
            [__DIR__.'/Fixtures/VersionRangeFixture.php'],
            false !== @preg_match('/(?<=a\Kb)c/', '') ? [
                ['Regex pattern is invalid for PHP 8.5 with PCRE2 10.44: '.$refusal->error, 22, $refusal->hint],
            ] : [],
        );
    }

    protected function getRule(): Rule
    {
        return self::getContainer()->getByType(RegexPatternRule::class);
    }
}
