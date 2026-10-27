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

use PHPRegex\Linter\Config\TargetConstraint;
use PHPRegex\Parser\Exception\InvalidRegexOptionException;
use PHPRegex\Parser\PcreTarget;
use PHPRegex\PHPStan\TargetScope;
use PHPStan\Php\PhpVersion;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The PCRE2 the extension judges a pattern with: a release set in
 * "phpRegex.pcreVersion", else
 * in composer.json's config.platform.lib-pcre, is used as is, older or
 * newer than the one the analysed PHP bundles; else the running PCRE2 when
 * the analysed PHP is the running PHP's minor version; else the PCRE2 the
 * analysed PHP bundles.
 */
final class TargetScopePcreTest extends TestCase
{
    private ?string $directory = null;

    private ?string $cwd = null;

    protected function tearDown(): void
    {
        if (null !== $this->cwd) {
            chdir($this->cwd);
        }
        if (null !== $this->directory) {
            @unlink($this->directory.'/composer.json');
            @rmdir($this->directory);
        }
    }

    /**
     * The running PHP's minor at another patch release, as PHPStan names it
     * from its phpVersion or composer.json: the running engine speaks for it.
     */
    #[Test]
    public function test_the_running_pcre2_judges_the_analysed_php_of_the_running_minor(): void
    {
        $scope = new TargetScope([], new PhpVersion(self::runningMinor(), PhpVersion::SOURCE_CONFIG));

        $this->assertSame(self::runningMinor(), $scope->parser()->target()->phpVersionId);
        $this->assertSame(PcreTarget::runtime()->pcreVersion, $scope->parser()->target()->pcreVersion);
        $this->assertFalse($scope->judgesBeyondTheRunningEngine());
    }

    #[Test]
    public function test_the_running_pcre2_judges_the_running_minor_named_by_the_extension(): void
    {
        $named = \sprintf('%d.%d', intdiv(\PHP_VERSION_ID, 10000), intdiv(\PHP_VERSION_ID, 100) % 100);
        $scope = new TargetScope(['phpVersion' => $named]);

        $this->assertSame(PcreTarget::runtime()->pcreVersion, $scope->parser()->target()->pcreVersion);
    }

    /**
     * Another minor version gets the PCRE2 its sources bundle, whatever the
     * running engine links.
     */
    #[Test]
    public function test_another_minor_gets_the_pcre2_it_bundles(): void
    {
        $other = self::otherMinor();

        $this->assertSame(PcreTarget::bundledWith($other)->pcreVersion, (new TargetScope([], new PhpVersion($other, PhpVersion::SOURCE_CONFIG)))->parser()->target()->pcreVersion);
        $this->assertSame(PcreTarget::bundledWith($other)->pcreVersion, (new TargetScope(['phpVersion' => $other]))->parser()->target()->pcreVersion);
    }

    /**
     * @param array{phpVersion?: int, pcreVersion?: string} $config
     */
    #[Test]
    #[DataProvider('providePins')]
    public function test_a_pin_is_used_as_is_in_both_directions(array $config, ?int $phpstan, string $expected): void
    {
        $scope = new TargetScope($config, null === $phpstan ? null : new PhpVersion($phpstan, PhpVersion::SOURCE_CONFIG));

        $this->assertSame($expected, $scope->parser()->target()->pcreVersion);
    }

    /**
     * @return iterable<string, array{config: array{phpVersion?: int, pcreVersion?: string}, phpstan: int|null, expected: string}>
     */
    public static function providePins(): iterable
    {
        yield 'older than the bundled one' => ['config' => ['pcreVersion' => '10.39'], 'phpstan' => 80400, 'expected' => '10.39'];
        yield 'newer than the bundled one' => ['config' => ['pcreVersion' => '10.49'], 'phpstan' => 80200, 'expected' => '10.49'];
        yield 'beside the running minor' => ['config' => ['pcreVersion' => '10.40'], 'phpstan' => self::runningMinor(), 'expected' => '10.40'];
        yield 'beside a PHP the extension names' => ['config' => ['phpVersion' => 80300, 'pcreVersion' => '10.45'], 'phpstan' => null, 'expected' => '10.45'];
    }

    /**
     * composer.json's config.platform.lib-pcre pins the PCRE2 as the
     * setting does, which wins over it.
     */
    #[Test]
    public function test_composer_json_lib_pcre_is_used_as_is(): void
    {
        $this->inProject('{"require": {"php": "^8.2"}, "config": {"platform": {"lib-pcre": "10.43"}}}');

        $this->assertSame('10.43', (new TargetScope([], new PhpVersion(80200, PhpVersion::SOURCE_CONFIG)))->parser()->target()->pcreVersion);
        $this->assertSame('10.43', (new TargetScope([], new PhpVersion(self::runningMinor(), PhpVersion::SOURCE_CONFIG)))->parser()->target()->pcreVersion);
        $this->assertSame('10.43', (new TargetScope(['phpVersion' => 'runtime']))->parser()->target()->pcreVersion);
        $this->assertSame('10.39', (new TargetScope(['pcreVersion' => '10.39'], new PhpVersion(80200, PhpVersion::SOURCE_CONFIG)))->parser()->target()->pcreVersion);
    }

    /**
     * Composer spells a platform version with up to four numbers: a PCRE2
     * release has two.
     */
    #[Test]
    public function test_composer_json_lib_pcre_reads_as_composer_spells_it(): void
    {
        $this->inProject('{"config": {"platform": {"lib-pcre": "10.42.0"}}}');

        $this->assertSame('10.42', (new TargetScope([], new PhpVersion(80400, PhpVersion::SOURCE_CONFIG)))->parser()->target()->pcreVersion);
    }

    #[Test]
    public function test_composer_json_lib_pcre_that_names_no_release_stops_the_analysis(): void
    {
        $this->inProject('{"config": {"platform": {"lib-pcre": "ten"}}}');

        try {
            new TargetScope([], new PhpVersion(80400, PhpVersion::SOURCE_CONFIG));
            $this->fail('A lib-pcre that names no release was read.');
        } catch (InvalidRegexOptionException $e) {
            $this->assertSame('composer.json config.platform.lib-pcre "ten" is not a PCRE2 release like "10.44": fix it, or remove it to judge with the PCRE2 the analysed PHP bundles.', $e->getMessage());
        }
    }

    /**
     * A later PHP of PHPStan's range, and a version a guard or a marker
     * keeps, follow the same rule: the running minor with the running
     * PCRE2, every other minor with the one it bundles, a pin as is.
     */
    #[Test]
    public function test_every_version_of_the_range_follows_the_rule(): void
    {
        $other = self::otherMinor();
        [$min, $max] = [min($other, self::runningMinor()), (intdiv(max($other, self::runningMinor()), 100) * 100) + 99];
        $expected = static fn (int $php): string => intdiv($php, 100) === intdiv(\PHP_VERSION_ID, 100) ? PcreTarget::runtime()->pcreVersion : PcreTarget::bundledWith($php)->pcreVersion;

        $scope = new TargetScope([], new PhpVersion($min, PhpVersion::SOURCE_CONFIG), ['min' => $min, 'max' => $max]);
        $judged = $scope->judged();
        $this->assertGreaterThan(1, \count($judged->points()));
        foreach ($judged->points() as $point) {
            $this->assertSame($expected($point->phpVersionId), $point->pcreVersion, (string) $point->phpVersionId);
        }

        $patch = self::runningMinor() + 5;
        $narrowed = $judged->narrow(TargetConstraint::phpFrom($patch, self::runningMinor() + 100));
        $this->assertSame($patch, $narrowed[0]->phpVersionId);
        $this->assertSame(PcreTarget::runtime()->pcreVersion, $narrowed[0]->pcreVersion);

        $pinned = (new TargetScope(['pcreVersion' => '10.40'], new PhpVersion($min, PhpVersion::SOURCE_CONFIG), ['min' => $min, 'max' => $max]))->judged();
        foreach ($pinned->points() as $point) {
            $this->assertSame('10.40', $point->pcreVersion);
        }
        $this->assertSame('10.40', $pinned->narrow(TargetConstraint::phpFrom($patch, self::runningMinor() + 100))[0]->pcreVersion);
    }

    /**
     * The x.y.0 release of the running PHP's minor, or x.y.1 when the
     * running PHP is x.y.0: a version of its minor that is not itself.
     */
    private static function runningMinor(): int
    {
        $minor = intdiv(\PHP_VERSION_ID, 100) * 100;

        return \PHP_VERSION_ID === $minor ? $minor + 1 : $minor;
    }

    /**
     * A PHP on PCRE2 of another minor than the running PHP's.
     */
    private static function otherMinor(): int
    {
        return 802 === intdiv(\PHP_VERSION_ID, 100) ? 80300 : 80200;
    }

    private function inProject(string $composerJson): void
    {
        $this->directory = sys_get_temp_dir().'/phpregex-phpstan-pcre-'.bin2hex(random_bytes(4));
        mkdir($this->directory);
        file_put_contents($this->directory.'/composer.json', $composerJson);
        $this->cwd = (string) getcwd();
        chdir($this->directory);
    }
}
