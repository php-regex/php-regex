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

use PHPRegex\Linter\Config\UnsupportedTargetException;
use PHPRegex\PHPStan\TargetScope;
use PHPStan\Php\PhpVersion;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The versions the extension judges a pattern for. It judges the target `regex lint` judges: a PHP named
 * below 7.3, which ships PCRE1, is refused with the same error, and a floor
 * read from composer.json that also allows 7.3 or later is judged from the
 * lowest such PHP.
 */
final class TargetScopeTest extends TestCase
{
    private const REFUSAL = 'PHP 7.2 and earlier ship PCRE1, which this library does not judge. Name a PHP version of 7.3 or later to judge for.';

    /**
     * @param array{phpVersion?: string}         $config
     * @param int|array{min: int, max: int}|null $range
     */
    #[Test]
    #[DataProvider('provideNamedVersionsOnPcre1')]
    public function test_a_version_named_below_7_3_is_refused_as_lint_refuses_it(array $config, ?PhpVersion $phpVersion, int|array|null $range, string $message): void
    {
        try {
            new TargetScope($config, $phpVersion, $range);
            $this->fail('A PHP on PCRE1 was judged.');
        } catch (UnsupportedTargetException $e) {
            $this->assertSame($message, $e->getMessage());
        }
    }

    /**
     * @return iterable<string, array{config: array{phpVersion?: string}, phpVersion: PhpVersion|null, range: int|array{min: int, max: int}|null, message: string}>
     */
    public static function provideNamedVersionsOnPcre1(): iterable
    {
        yield 'the extension\'s phpVersion' => ['config' => ['phpVersion' => '7.2'], 'phpVersion' => null, 'range' => null, 'message' => 'phpRegex.phpVersion names PHP 7.2: '.self::REFUSAL];
        yield 'PHPStan\'s phpVersion' => ['config' => [], 'phpVersion' => new PhpVersion(70100, PhpVersion::SOURCE_CONFIG), 'range' => 70100, 'message' => 'phpVersion names PHP 7.1: '.self::REFUSAL];
        yield 'a range of PHPStan below 7.3' => ['config' => [], 'phpVersion' => new PhpVersion(70000, PhpVersion::SOURCE_CONFIG), 'range' => ['min' => 70000, 'max' => 70299], 'message' => 'phpVersion allows PHP 7.0: '.self::REFUSAL];
    }

    #[Test]
    public function test_a_range_of_phpstan_that_reaches_7_3_is_judged_from_7_3(): void
    {
        $scope = new TargetScope([], new PhpVersion(70100, PhpVersion::SOURCE_CONFIG), ['min' => 70100, 'max' => 80499]);

        $this->assertSame(70300, $scope->parser()->target()->phpVersionId);
        $this->assertSame('10.32', $scope->parser()->target()->pcreVersion);
    }

    /**
     * PHPStan reads the PHP of composer.json; read the way lint reads it, a
     * range that also allows 7.3 or later is judged from the lowest such
     * PHP, and one that allows none is refused.
     */
    #[Test]
    public function test_a_floor_phpstan_read_from_composer_json_is_judged_as_lint_judges_it(): void
    {
        $directory = sys_get_temp_dir().'/phpregex-phpstan-target-'.bin2hex(random_bytes(4));
        mkdir($directory);
        $cwd = (string) getcwd();
        chdir($directory);

        try {
            file_put_contents('composer.json', '{"require": {"php": "^7.1 || ^8.0"}}');
            $scope = new TargetScope([], new PhpVersion(70100, PhpVersion::SOURCE_COMPOSER_PLATFORM_PHP));
            $this->assertSame(70300, $scope->parser()->target()->phpVersionId);

            file_put_contents('composer.json', '{"require": {"php": ">=7.0 <7.3"}}');

            try {
                new TargetScope([], new PhpVersion(70000, PhpVersion::SOURCE_COMPOSER_PLATFORM_PHP));
                $this->fail('A composer.json allowing PHP on PCRE1 only was judged.');
            } catch (UnsupportedTargetException $e) {
                $this->assertSame('composer.json require.php allows PHP 7.0: '.self::REFUSAL, $e->getMessage());
            }
        } finally {
            chdir($cwd);
            @unlink($directory.'/composer.json');
            @rmdir($directory);
        }
    }

    /**
     * The running engine alone is PHPStan's own regexp.pattern: nothing is
     * left for the extension to judge a pattern for.
     */
    #[Test]
    public function test_a_scope_tells_whether_it_judges_beyond_the_running_engine(): void
    {
        $this->assertFalse((new TargetScope(['phpVersion' => 'runtime']))->judgesBeyondTheRunningEngine());
        $this->assertTrue((new TargetScope(['phpVersion' => '7.3', 'pcreVersion' => '10.32']))->judgesBeyondTheRunningEngine());
        $this->assertTrue((new TargetScope([], new PhpVersion(\PHP_VERSION_ID, PhpVersion::SOURCE_RUNTIME), ['min' => \PHP_VERSION_ID, 'max' => \PHP_VERSION_ID + 299]))->judgesBeyondTheRunningEngine());
    }

    /**
     * The "@regex-target" markers of a file are read again once it changes,
     * and a file that cannot be read has none.
     */
    #[Test]
    public function test_the_markers_of_a_file_follow_its_changes(): void
    {
        $file = sys_get_temp_dir().'/phpregex-phpstan-markers-'.bin2hex(random_bytes(4)).'.php';
        $scope = new TargetScope(['phpVersion' => '8.2']);

        try {
            file_put_contents($file, "<?php\n// @regex-target php>=8.4\npreg_match('/a/', \$s);\n");
            $markers = $scope->markersOf($file);
            $this->assertCount(1, $markers);
            $this->assertSame('php>=8.4', $markers[0]->text);

            file_put_contents($file, "<?php\npreg_match('/a/', \$s);\n");
            touch($file, time() + 10);
            clearstatcache();
            $this->assertSame([], $scope->markersOf($file));
        } finally {
            @unlink($file);
        }

        $this->assertSame([], $scope->markersOf($file));
    }

    #[Test]
    public function test_a_version_on_pcre2_is_judged_as_before(): void
    {
        $this->assertSame(70300, (new TargetScope(['phpVersion' => '7.3']))->parser()->target()->phpVersionId);
        $this->assertSame(70400, (new TargetScope([], new PhpVersion(70400, PhpVersion::SOURCE_CONFIG)))->parser()->target()->phpVersionId);
    }
}
