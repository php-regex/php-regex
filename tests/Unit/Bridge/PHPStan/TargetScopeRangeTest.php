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

namespace PHPRegex\Tests\Unit\Bridge\PHPStan;

use PHPRegex\Linter\Exception\UnsupportedTargetException;
use PHPRegex\Parser\Exception\InvalidRegexOptionException;
use PHPRegex\Parser\PcreTarget;
use PHPRegex\PHPStan\TargetScope;
use PHPRegex\Tests\Support\PcreRule;
use PHPStan\Php\PhpVersion;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * "phpRegex.phpVersion" names a range, as a Composer constraint (">=8.1")
 * or as {min, max}: it is judged as `regex lint` judges a range, from its
 * lowest PHP on PCRE2 at every gate point it allows. When PHPStan's own
 * "phpVersion" is a {min, max} range too, the two are intersected, so that
 * the versions judged stay within those PHPStan types the code for; two
 * ranges that share no version stop the analysis, naming both. When
 * PHPStan names one version, or none, the extension's range is judged as
 * given. composer.json's require.php is never read for it.
 */
final class TargetScopeRangeTest extends TestCase
{
    use PcreRule;

    private const PCRE1_REFUSAL = 'PHP 7.2 and earlier ship PCRE1, which this library does not judge. Name a PHP version of 7.3 or later to judge for.';

    private const EVERY_POINT_FROM_8_1 = [80100, 80200, 80300, 80400, 80425, 80500, 80510];

    /**
     * @param array{phpVersion: string|array{min: string|int, max: string|int}} $config
     * @param int|array{min: int, max: int}|null                                $phpStanRange
     * @param list<int>                                                         $points
     */
    #[Test]
    #[DataProvider('provideRanges')]
    public function test_a_range_named_for_the_extension_judges_the_points_it_allows(array $config, ?PhpVersion $phpVersion, int|array|null $phpStanRange, array $points): void
    {
        $scope = new TargetScope($config, $phpVersion, $phpStanRange);

        $this->assertSame($points, array_map(static fn (PcreTarget $target): int => $target->phpVersionId, $scope->judged()->points()));
        $this->assertSame($points[0], $scope->parser()->target()->phpVersionId);
        $this->assertSame(self::judgedPcre($points[0]), $scope->parser()->target()->pcreVersion);
        $this->assertTrue($scope->judgesBeyondTheRunningEngine());
    }

    /**
     * @return iterable<string, array{config: array{phpVersion: string|array{min: string|int, max: string|int}}, phpVersion: PhpVersion|null, phpStanRange: int|array{min: int, max: int}|null, points: list<int>}>
     */
    public static function provideRanges(): iterable
    {
        yield 'a constraint, PHPStan naming one version' => [
            'config' => ['phpVersion' => '>=8.1'],
            'phpVersion' => new PhpVersion(80100, PhpVersion::SOURCE_CONFIG),
            'phpStanRange' => 80100,
            'points' => self::EVERY_POINT_FROM_8_1,
        ];
        yield 'a constraint, PHPStan naming none' => [
            'config' => ['phpVersion' => '>=8.1'],
            'phpVersion' => null,
            'phpStanRange' => null,
            'points' => self::EVERY_POINT_FROM_8_1,
        ];
        yield 'a constraint with a ceiling' => [
            'config' => ['phpVersion' => '^8.1 <8.5'],
            'phpVersion' => null,
            'phpStanRange' => null,
            'points' => [80100, 80200, 80300, 80400, 80425],
        ];
        yield 'min and max as PHP_VERSION_IDs' => [
            'config' => ['phpVersion' => ['min' => 80100, 'max' => 80699]],
            'phpVersion' => null,
            'phpStanRange' => null,
            'points' => self::EVERY_POINT_FROM_8_1,
        ];
        yield 'min and max as versions, a two-part max covering its series' => [
            'config' => ['phpVersion' => ['min' => '8.1', 'max' => '8.4']],
            'phpVersion' => null,
            'phpStanRange' => null,
            'points' => [80100, 80200, 80300, 80400, 80425],
        ];
        yield 'intersected with PHPStan\'s range below it' => [
            'config' => ['phpVersion' => '>=8.1'],
            'phpVersion' => new PhpVersion(80100, PhpVersion::SOURCE_CONFIG),
            'phpStanRange' => ['min' => 80100, 'max' => 80399],
            'points' => [80100, 80200, 80300],
        ];
        yield 'intersected with PHPStan\'s range above its floor' => [
            'config' => ['phpVersion' => '>=8.1'],
            'phpVersion' => new PhpVersion(80300, PhpVersion::SOURCE_CONFIG),
            'phpStanRange' => ['min' => 80300, 'max' => 80599],
            'points' => [80300, 80400, 80425, 80500, 80510],
        ];
        yield 'an object intersected with PHPStan\'s range' => [
            'config' => ['phpVersion' => ['min' => 80300, 'max' => 80699]],
            'phpVersion' => new PhpVersion(80100, PhpVersion::SOURCE_CONFIG),
            'phpStanRange' => ['min' => 80100, 'max' => 80499],
            'points' => [80300, 80400, 80425],
        ];
        yield 'from the last gate point' => [
            'config' => ['phpVersion' => '>=8.5.10'],
            'phpVersion' => null,
            'phpStanRange' => null,
            'points' => [80510],
        ];
        yield 'above every gate point' => [
            'config' => ['phpVersion' => '>8.6'],
            'phpVersion' => null,
            'phpStanRange' => null,
            'points' => [80601],
        ];
    }

    /**
     * @param array{phpVersion: string|array{min: string|int, max: string|int}} $config
     * @param array{min: int, max: int}                                         $phpStanRange
     */
    #[Test]
    #[DataProvider('provideDisjointRanges')]
    public function test_a_range_sharing_no_version_with_phpstans_is_refused_naming_both(array $config, array $phpStanRange, string $named): void
    {
        try {
            new TargetScope($config, new PhpVersion($phpStanRange['min'], PhpVersion::SOURCE_CONFIG), $phpStanRange);
            $this->fail('Two ranges sharing no version were judged.');
        } catch (InvalidRegexOptionException $e) {
            $this->assertStringContainsString('phpRegex.phpVersion '.$named.' shares no version', $e->getMessage());
            // PHPStan's own setting, named apart from the extension's.
            $this->assertMatchesRegularExpression('/(?<!phpRegex\.)\bphpVersion\b/', $e->getMessage());
        }
    }

    /**
     * The extension's range as the neon spells it: a string quoted, a
     * PHP_VERSION_ID as is.
     *
     * @return iterable<string, array{config: array{phpVersion: string|array{min: string|int, max: string|int}}, phpStanRange: array{min: int, max: int}, named: string}>
     */
    public static function provideDisjointRanges(): iterable
    {
        yield 'a constraint above PHPStan\'s range' => ['config' => ['phpVersion' => '>=8.5'], 'phpStanRange' => ['min' => 80100, 'max' => 80399], 'named' => '">=8.5"'];
        yield 'an object below PHPStan\'s range' => ['config' => ['phpVersion' => ['min' => 80100, 'max' => 80299]], 'phpStanRange' => ['min' => 80400, 'max' => 80599], 'named' => '{min: 80100, max: 80299}'];
        yield 'an object of versions below PHPStan\'s range' => ['config' => ['phpVersion' => ['min' => '8.1', 'max' => '8.2']], 'phpStanRange' => ['min' => 80400, 'max' => 80599], 'named' => '{min: "8.1", max: "8.2"}'];
    }

    /**
     * Refused as `regex lint --php-version` refuses it, in its words.
     */
    #[Test]
    #[DataProvider('provideConstraintsLintRefuses')]
    public function test_a_constraint_lint_refuses_stops_the_analysis(string $constraint): void
    {
        $this->expectException(InvalidRegexOptionException::class);
        $this->expectExceptionMessageMatches('/a Composer constraint like ">=8\.1" that names a lowest version/');

        new TargetScope(['phpVersion' => $constraint]);
    }

    /**
     * @return iterable<string, array{constraint: string}>
     */
    public static function provideConstraintsLintRefuses(): iterable
    {
        yield 'no lowest version' => ['constraint' => '<8.4'];
        yield 'a PHP_VERSION_ID inside a constraint' => ['constraint' => '>=80100'];
        yield 'any version' => ['constraint' => '*'];
    }

    #[Test]
    public function test_min_above_max_is_refused(): void
    {
        $this->expectException(InvalidRegexOptionException::class);
        $this->expectExceptionMessageMatches('/min not above max/');

        new TargetScope(['phpVersion' => ['min' => 80600, 'max' => 80100]]);
    }

    /**
     * A range that allows no PHP on PCRE2 is refused as lint refuses it.
     */
    #[Test]
    public function test_a_range_on_pcre1_only_is_refused_as_lint_refuses_it(): void
    {
        try {
            new TargetScope(['phpVersion' => '~7.1.0']);
            $this->fail('A range on PCRE1 only was judged.');
        } catch (UnsupportedTargetException $e) {
            $this->assertSame('phpRegex.phpVersion allows PHP 7.1: '.self::PCRE1_REFUSAL, $e->getMessage());
        }
    }

    /**
     * A range reaching below 7.3 is judged from 7.3, whether the extension
     * or PHPStan names it. PHPStan shows no note of it.
     *
     * @param array{phpVersion?: string}         $config
     * @param int|array{min: int, max: int}|null $phpStanRange
     */
    #[Test]
    #[DataProvider('provideRangesFromBelowPcre2')]
    public function test_a_range_from_below_7_3_is_judged_from_7_3(array $config, ?PhpVersion $phpVersion, int|array|null $phpStanRange): void
    {
        $scope = new TargetScope($config, $phpVersion, $phpStanRange);

        $this->assertSame(70300, $scope->parser()->target()->phpVersionId);
    }

    /**
     * @return iterable<string, array{config: array{phpVersion?: string}, phpVersion: PhpVersion|null, phpStanRange: int|array{min: int, max: int}|null}>
     */
    public static function provideRangesFromBelowPcre2(): iterable
    {
        yield 'the extension\'s range' => [
            'config' => ['phpVersion' => '>=7.1'],
            'phpVersion' => null,
            'phpStanRange' => null,
        ];
        yield 'PHPStan\'s range' => [
            'config' => [],
            'phpVersion' => new PhpVersion(70100, PhpVersion::SOURCE_CONFIG),
            'phpStanRange' => ['min' => 70100, 'max' => 80499],
        ];
    }
}
