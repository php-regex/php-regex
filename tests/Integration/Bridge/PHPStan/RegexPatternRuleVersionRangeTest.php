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
use PHPRegex\Tests\Support\PcreRule;
use PHPRegex\Tests\Support\RangeFixtureReadings;
use PHPStan\Php\PhpVersion;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * PHPStan's "phpVersion: {min: …, max: …}" names a range: a pattern the
 * floor accepts is also judged at each later PHP of the range where a rule
 * of the library changes, as `regex lint` judges a composer.json range, and
 * the first that refuses it is reported under regex.invalidForTarget.
 *
 * "phpRegex.phpVersion" may name a range too, a Composer constraint or
 * {min, max}: intersected with PHPStan's range when PHPStan names one,
 * judged as given when PHPStan names one version, and reported alike.
 *
 * @extends RuleTestCase<RegexPatternRule>
 *
 * @phpstan-import-type ConfigArray from RegexPatternRule
 */
final class RegexPatternRuleVersionRangeTest extends RuleTestCase
{
    use PcreRule;
    use RangeFixtureReadings;

    private const KEEP_IN_LOOKBEHIND = '/(?<=a\Kb)c/';

    private const VARIABLE_LENGTH_LOOKBEHIND = '/(?<=ab?)c/';

    /**
     * @var int|array{min: int, max: int}|null
     */
    private int|array|null $range = null;

    /**
     * @var ConfigArray
     */
    private array $config = [];

    /**
     * The PHP version PHPStan analyses for: the min of its range.
     */
    private int $phpVersionId = 80400;

    #[Test]
    public function test_a_later_php_of_the_range_refusing_the_pattern_is_reported(): void
    {
        $this->range = ['min' => 80400, 'max' => 80599];
        $refusal = RegexParser::create(['php_version' => '8.5'])->validate(self::KEEP_IN_LOOKBEHIND);
        $this->assertFalse($refusal->isValid);

        $this->analyse(
            [__DIR__.'/Fixtures/VersionRangeFixture.php'],
            self::runningEngineCompiles(self::KEEP_IN_LOOKBEHIND) ? [
                ['Regex pattern is invalid for PHP 8.5 with PCRE2 10.44: '.($refusal->error ?? 'Invalid regex.'), 22, $refusal->hint],
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

    /**
     * phpRegex ">=8.1" within PHPStan's 8.1 to 8.3: the versions judged
     * stay those PHPStan types the code for, and 8.5 is not one of them.
     */
    #[Test]
    public function test_a_range_named_for_the_rule_is_intersected_with_phpstans_range(): void
    {
        self::assertEngineKeepInLookbehind();
        $this->phpVersionId = 80100;
        $this->range = ['min' => 80100, 'max' => 80399];
        $this->config = ['phpVersion' => '>=8.1'];

        $this->analyse([__DIR__.'/Fixtures/VersionRangeFixture.php'], []);
    }

    /**
     * phpRegex ">=8.1" within PHPStan's 8.4 to 8.5: 8.5 is judged, and its
     * refusal reported as for PHPStan's own range.
     */
    #[Test]
    public function test_the_intersection_reports_a_later_php_refusing_the_pattern(): void
    {
        self::assertEngineKeepInLookbehind();
        $this->range = ['min' => 80400, 'max' => 80599];
        $this->config = ['phpVersion' => '>=8.1'];

        $this->analyse([__DIR__.'/Fixtures/VersionRangeFixture.php'], self::refusalFrom85());
    }

    /**
     * @return iterable<string, array{config: ConfigArray, range: int|null}>
     */
    public static function provideRangesJudgedAsGiven(): iterable
    {
        yield 'a constraint, PHPStan naming one version' => ['config' => ['phpVersion' => '>=8.1'], 'range' => 80100];
        yield 'a constraint, PHPStan naming none' => ['config' => ['phpVersion' => '>=8.1'], 'range' => null];
        yield 'min and max, PHPStan naming one version' => ['config' => ['phpVersion' => ['min' => 80100, 'max' => 80699]], 'range' => 80100];
    }

    /**
     * PHPStan names one version, or none: the range named for the rule is
     * judged as given, and 8.5 refuses the pattern.
     *
     * @param ConfigArray $config
     */
    #[Test]
    #[DataProvider('provideRangesJudgedAsGiven')]
    public function test_a_range_named_for_the_rule_is_judged_as_given_without_a_range_of_phpstan(array $config, ?int $range): void
    {
        self::assertEngineKeepInLookbehind();
        $this->phpVersionId = 80100;
        $this->range = $range;
        $this->config = $config;

        $this->analyse([__DIR__.'/Fixtures/VersionRangeFixture.php'], self::refusalFrom85());
    }

    #[Test]
    public function test_a_range_named_for_the_rule_stops_at_its_ceiling(): void
    {
        self::assertEngineKeepInLookbehind();
        $this->phpVersionId = 80100;
        $this->range = 80100;
        $this->config = ['phpVersion' => ['min' => '8.1', 'max' => '8.4']];

        $this->analyse([__DIR__.'/Fixtures/VersionRangeFixture.php'], []);
    }

    /**
     * @return iterable<string, array{range: int|null}>
     */
    public static function provideOneVersionOfPhpstanOrNone(): iterable
    {
        yield 'PHPStan naming one version' => ['range' => 80100];
        yield 'PHPStan naming none' => ['range' => null];
    }

    /**
     * A guard on PHP_VERSION_ID narrows the range named for the rule as it
     * narrows the range regex lint judges, whatever PHPStan names: the
     * guarded calls are judged only for the versions they run on, the
     * calls written outside are still reported.
     */
    #[Test]
    #[DataProvider('provideOneVersionOfPhpstanOrNone')]
    public function test_a_guard_narrows_the_range_named_for_the_rule(?int $range): void
    {
        self::assertEngineKeepInLookbehind();
        self::assertEngineVariableLengthLookbehind();
        $this->phpVersionId = 80100;
        $this->range = $range;
        $this->config = ['phpVersion' => '>=8.1'];

        $this->analyse([__DIR__.'/Fixtures/NamedRangeGuardFixture.php'], [
            ...self::refusalFrom85(26),
            ...self::refusalAt81(self::VARIABLE_LENGTH_LOOKBEHIND, 37),
        ]);
    }

    /**
     * phpRegex ">=8.1" within PHPStan's 8.1 to 8.3: a guard keeping 8.1 to
     * 8.3 judges them alone, never 8.4 or later, and one keeping 8.4 and
     * later keeps none of them.
     */
    #[Test]
    public function test_a_guard_narrows_the_intersection(): void
    {
        self::assertEngineKeepInLookbehind();
        self::assertEngineVariableLengthLookbehind();
        $this->phpVersionId = 80100;
        $this->range = ['min' => 80100, 'max' => 80399];
        $this->config = ['phpVersion' => '>=8.1'];

        $this->analyse([__DIR__.'/Fixtures/NamedRangeGuardFixture.php'], self::refusalAt81(self::VARIABLE_LENGTH_LOOKBEHIND, 37));
    }

    protected function getRule(): Rule
    {
        return new RegexPatternRule(config: $this->config, phpVersion: new PhpVersion($this->phpVersionId), phpVersionRange: $this->range);
    }

    /**
     * The error PHP 8.5 gives the pattern, as for PHPStan's own range, when
     * the running engine compiles it (PHPStan reports it otherwise).
     *
     * @return list<array{string, int, string|null}>
     */
    private static function refusalFrom85(int $line = 22): array
    {
        $refusal = RegexParser::create(['php_version' => '8.5'])->validate(self::KEEP_IN_LOOKBEHIND);
        self::assertFalse($refusal->isValid);

        return self::runningEngineCompiles(self::KEEP_IN_LOOKBEHIND)
            ? [['Regex pattern is invalid for PHP 8.5 with PCRE2 '.self::judgedPcre(80500).': '.($refusal->error ?? 'Invalid regex.'), $line, $refusal->hint]]
            : [];
    }

    /**
     * The error PHP 8.1, the floor, gives a pattern, when the running engine
     * compiles it.
     *
     * @return list<array{string, int, string|null}>
     */
    private static function refusalAt81(string $pattern, int $line): array
    {
        $refusal = RegexParser::create(['php_version' => '8.1'])->validate($pattern);
        self::assertFalse($refusal->isValid);

        return self::runningEngineCompiles($pattern)
            ? [['Regex pattern is invalid for PHP 8.1 with PCRE2 '.self::judgedPcre(80100).': '.($refusal->error ?? 'Invalid regex.'), $line, $refusal->hint]]
            : [];
    }

    private static function runningEngineCompiles(string $pattern): bool
    {
        return false !== @preg_match($pattern, '');
    }
}
