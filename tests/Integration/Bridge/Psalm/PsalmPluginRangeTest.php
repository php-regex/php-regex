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

namespace PHPRegex\Tests\Integration\Bridge\Psalm;

use PHPRegex\Linter\Exception\UnsupportedTargetException;
use PHPRegex\Parser\Exception\InvalidRegexOptionException;
use PHPRegex\Tests\Support\PcreRule;
use PHPRegex\Tests\Support\RangeFixtureReadings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\Exception\ConfigException;

/**
 * <phpVersion> may name a Composer constraint (">=8.1"): Psalm has no range
 * of its own, so the range is judged as given, as `regex lint` judges it,
 * from its lowest PHP on PCRE2 at every gate point it allows. A pattern the
 * floor accepts and a later PHP of the range refuses is reported as an
 * invalid pattern for that PHP, as for a single version. Without
 * <phpVersion>, composer.json's require.php is never read as a range: Psalm's
 * own version alone is judged.
 */
final class PsalmPluginRangeTest extends TestCase
{
    use PcreRule;
    use RangeFixtureReadings;

    private const BASE_DIR = PsalmRun::FIXTURES.'/Range';

    private const FILE = 'range.php';

    private const PATTERN = "'/(?<=a\\Kb)c/'";

    /**
     * @param array<string, string> $options
     */
    #[Test]
    #[DataProvider('provideRangesReachingTheRefusal')]
    public function test_a_later_php_of_the_range_refusing_the_pattern_is_reported(array $options, int $refusing): void
    {
        self::assertEngineKeepInLookbehind();

        $reported = $this->invalidPatterns($options, '8.4');

        $this->assertSame([self::PATTERN], array_keys($reported), 'The patterns reported.');
        $this->assertStringStartsWith(\sprintf('Regex pattern is invalid for PHP %d.%d with PCRE2 %s: ', intdiv($refusing, 10000), intdiv($refusing, 100) % 100, self::judgedPcre($refusing)), $reported[self::PATTERN]);
    }

    /**
     * @return iterable<string, array{options: array<string, string>, refusing: int}>
     */
    public static function provideRangesReachingTheRefusal(): iterable
    {
        yield 'an open range' => ['options' => ['phpVersion' => '>=8.1'], 'refusing' => 80500];
        yield 'a hyphen range' => ['options' => ['phpVersion' => '8.1 - 8.6'], 'refusing' => 80500];
        // The floor itself refuses it: reported as for a single version.
        yield 'from the last gate point' => ['options' => ['phpVersion' => '>=8.5.10'], 'refusing' => 80510];
        yield 'above every gate point' => ['options' => ['phpVersion' => '>8.6'], 'refusing' => 80601];
    }

    /**
     * @param array<string, string> $options
     */
    #[Test]
    #[DataProvider('provideRangesStoppingBeforeTheRefusal')]
    public function test_a_range_stopping_before_the_refusal_reports_nothing(array $options): void
    {
        self::assertEngineKeepInLookbehind();

        $this->assertSame([], $this->invalidPatterns($options, '8.4'));
    }

    /**
     * @return iterable<string, array{options: array<string, string>}>
     */
    public static function provideRangesStoppingBeforeTheRefusal(): iterable
    {
        yield 'a ceiling below 8.5' => ['options' => ['phpVersion' => '^8.1 <8.5']];
        yield 'a hyphen range up to 8.4' => ['options' => ['phpVersion' => '8.1 - 8.4']];
    }

    /**
     * The range named is judged as given, whatever Psalm's version: under
     * Psalm on 8.1 the refusal of 8.5 is still reported.
     */
    #[Test]
    public function test_the_range_is_judged_as_given_whatever_psalms_version(): void
    {
        self::assertEngineKeepInLookbehind();

        $reported = $this->invalidPatterns(['phpVersion' => '>=8.1'], '8.1');

        $this->assertSame([self::PATTERN], array_keys($reported));
        $this->assertStringStartsWith('Regex pattern is invalid for PHP 8.5 with PCRE2 '.self::judgedPcre(80500).': ', $reported[self::PATTERN]);
    }

    /**
     * No range from composer.json by default: Psalm read 8.1 from
     * require.php ">=8.1", and 8.1 alone is judged.
     */
    #[Test]
    public function test_composer_json_is_not_read_as_a_range_without_php_version(): void
    {
        self::assertEngineKeepInLookbehind();

        $this->assertSame([], $this->invalidPatterns([], '8.1', 'composer'));
    }

    #[Test]
    #[DataProvider('provideConstraintsLintRefuses')]
    public function test_a_constraint_lint_refuses_stops_psalm_in_lints_words(string $constraint): void
    {
        try {
            PsalmRun::issues([self::FILE], '8.4', ['phpVersion' => $constraint], baseDir: self::BASE_DIR);
            $this->fail(\sprintf('<phpVersion>%s</phpVersion> was accepted.', $constraint));
        } catch (ConfigException $e) {
            $previous = $e->getPrevious();
            $this->assertInstanceOf(InvalidRegexOptionException::class, $previous, $e->getMessage());
            $this->assertStringContainsString('a Composer constraint like ">=8.1" that names a lowest version', $previous->getMessage());
            $this->assertStringContainsString('<phpVersion>', $previous->getMessage());
        }
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

    /**
     * A range that allows no PHP on PCRE2 is refused as lint refuses it.
     */
    #[Test]
    public function test_a_range_on_pcre1_only_is_refused_as_lint_refuses_it(): void
    {
        try {
            PsalmRun::issues([self::FILE], '8.4', ['phpVersion' => '~7.1.0'], baseDir: self::BASE_DIR);
            $this->fail('A range on PCRE1 only was judged.');
        } catch (ConfigException $e) {
            $this->assertInstanceOf(UnsupportedTargetException::class, $e->getPrevious(), $e->getMessage());
            $this->assertSame('<phpVersion> allows PHP 7.1: PHP 7.2 and earlier ship PCRE1, which this library does not judge. Name a PHP version of 7.3 or later to judge for.', $e->getPrevious()->getMessage());
        }
    }

    /**
     * A guard on PHP_VERSION_ID and a "@regex-target" marker narrow the
     * range named as they narrow the one regex lint judges: the calls they
     * keep off PHP 8.5 are not reported, the call written outside them is,
     * and a marker that keeps no version of the range is reported, its
     * call left unjudged.
     */
    #[Test]
    public function test_a_guard_and_a_marker_narrow_the_range_named(): void
    {
        self::assertEngineKeepInLookbehind();
        $file = 'narrowed.php';

        $reported = PsalmRun::reported(PsalmRun::inFile(PsalmRun::issues([$file], '8.4', ['phpVersion' => '>=8.1'], baseDir: self::BASE_DIR), $file, self::BASE_DIR));

        $this->assertSame([[27, 'InvalidRegexPattern'], [28, 'InvalidRegexTarget']], array_map(static fn (array $issue): array => [$issue[0], $issue[1]], $reported));
        $this->assertStringStartsWith('Regex pattern is invalid for PHP 8.5 with PCRE2 '.self::judgedPcre(80500).': ', $reported[0][2]);
        $this->assertSame('The @regex-target marker "php<8.0" keeps no version the project is judged for (PHP >=8.1): the patterns it covers are not judged.', $reported[1][2]);
    }

    /**
     * The InvalidRegexPattern issues of the fixture, by the pattern they
     * select.
     *
     * @param array<string, string>             $options
     * @param 'cli'|'config'|'composer'|'tests' $phpVersionSource
     *
     * @return array<string, string>
     */
    private function invalidPatterns(array $options, string $phpVersion, string $phpVersionSource = 'tests'): array
    {
        return PsalmRun::invalidPatterns(PsalmRun::inFile(PsalmRun::issues([self::FILE], $phpVersion, $options, baseDir: self::BASE_DIR, phpVersionSource: $phpVersionSource), self::FILE, self::BASE_DIR));
    }
}
