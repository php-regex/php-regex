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
use PHPStan\Php\PhpVersion;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * PHPStan's "phpVersion: {min: …, max: …}" names a range: a pattern the
 * floor accepts is also judged at each later PHP of the range where a rule
 * of the library changes, as `regex lint` judges a composer.json range, and
 * the first that refuses it is reported under regex.invalidForTarget.
 *
 * @extends RuleTestCase<RegexPatternRule>
 */
final class RegexParserRuleVersionRangeTest extends RuleTestCase
{
    private const KEEP_IN_LOOKBEHIND = '/(?<=a\Kb)c/';

    private mixed $range = null;

    /**
     * @var array<string, mixed>
     */
    private array $config = [];

    #[Test]
    public function test_a_later_php_of_the_range_refusing_the_pattern_is_reported(): void
    {
        $this->range = ['min' => 80400, 'max' => 80599];
        $refusal = RegexParser::create(['php_version' => '8.5'])->validate(self::KEEP_IN_LOOKBEHIND);
        $this->assertFalse($refusal->isValid);

        $this->analyse(
            [__DIR__.'/Fixtures/VersionRangeFixture.php'],
            self::runningEngineCompiles(self::KEEP_IN_LOOKBEHIND) ? [
                ['Regex pattern is invalid for PHP 8.5 with PCRE2 10.44: '.$refusal->error, 22, $refusal->hint],
            ] : [],
        );
    }

    #[Test]
    public function test_a_range_that_stops_before_the_change_reports_nothing(): void
    {
        $this->range = ['min' => 80400, 'max' => 80499];

        $this->analyse([__DIR__.'/Fixtures/VersionRangeFixture.php'], []);
    }

    #[Test]
    public function test_one_version_is_judged_alone(): void
    {
        $this->range = 80400;

        $this->analyse([__DIR__.'/Fixtures/VersionRangeFixture.php'], []);
    }

    /**
     * "phpRegex.phpVersion" names the one version patterns are judged for:
     * PHPStan's range is then not read.
     */
    #[Test]
    public function test_a_version_named_for_the_rule_overrides_the_range(): void
    {
        $this->range = ['min' => 80400, 'max' => 80599];
        $this->config = ['phpVersion' => '8.4'];

        $this->analyse([__DIR__.'/Fixtures/VersionRangeFixture.php'], []);
    }

    protected function getRule(): Rule
    {
        return new RegexPatternRule(config: $this->config, phpVersion: new PhpVersion(80400), phpVersionRange: $this->range);
    }

    private static function runningEngineCompiles(string $pattern): bool
    {
        return false !== @preg_match($pattern, '');
    }
}
