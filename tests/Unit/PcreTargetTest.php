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

namespace RegexParser\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RegexParser\Exception\InvalidRegexOptionException;
use RegexParser\PcreTarget;

final class PcreTargetTest extends TestCase
{
    #[Test]
    public function test_the_runtime_target_is_the_running_php_and_the_pcre2_it_links(): void
    {
        $target = PcreTarget::runtime();

        $this->assertSame(\PHP_VERSION_ID, $target->phpVersionId);
        $this->assertSame(explode(' ', \PCRE_VERSION)[0], $target->pcreVersion);
        $this->assertTrue($target->isRunningEngine());
    }

    #[Test]
    #[DataProvider('provideBundledReleases')]
    public function test_a_php_version_alone_gets_the_pcre2_it_bundles(int $phpVersionId, string $release): void
    {
        $target = PcreTarget::bundledWith($phpVersionId);

        $this->assertSame($phpVersionId, $target->phpVersionId);
        $this->assertSame($release, $target->pcreVersion);
    }

    /**
     * @return iterable<string, array{phpVersionId: int, release: string}>
     */
    public static function provideBundledReleases(): iterable
    {
        yield 'PHP 8.2' => ['phpVersionId' => 80200, 'release' => '10.40'];
        yield 'PHP 8.2 patch' => ['phpVersionId' => 80227, 'release' => '10.40'];
        yield 'PHP 8.3' => ['phpVersionId' => 80300, 'release' => '10.42'];
        yield 'PHP 8.4' => ['phpVersionId' => 80400, 'release' => '10.44'];
        yield 'PHP 8.5' => ['phpVersionId' => 80500, 'release' => '10.44'];
        // Older PHP versions are judged with the oldest rules the library knows.
        yield 'PHP 8.1' => ['phpVersionId' => 80100, 'release' => '10.40'];
        yield 'PHP 7.4' => ['phpVersionId' => 70400, 'release' => '10.40'];
    }

    #[Test]
    #[DataProvider('provideReleaseSpellings')]
    public function test_a_release_is_read_as_pcre_version_spells_it(string $written, string $release): void
    {
        $this->assertSame($release, (new PcreTarget(80400, $written))->pcreVersion);
    }

    /**
     * @return iterable<string, array{written: string, release: string}>
     */
    public static function provideReleaseSpellings(): iterable
    {
        yield 'plain' => ['written' => '10.42', 'release' => '10.42'];
        yield 'with its date' => ['written' => '10.44 2024-06-07', 'release' => '10.44'];
        yield 'padded' => ['written' => ' 10.48 ', 'release' => '10.48'];
        yield 'three-digit minor' => ['written' => '10.100', 'release' => '10.100'];
        // PCRE_VERSION spells a pre-release build "10.45-RC1 2024-12-01".
        yield 'release candidate' => ['written' => '10.45-RC1 2024-12-01', 'release' => '10.45'];
        yield 'development build' => ['written' => '10.48-DEV 2025-10-01', 'release' => '10.48'];
    }

    #[Test]
    #[DataProvider('provideUnreadableReleases')]
    public function test_a_release_that_names_none_is_refused(string $written): void
    {
        $this->expectException(InvalidRegexOptionException::class);
        $this->expectExceptionMessage(\sprintf('"pcre_version" must be a PCRE2 release like "10.44", not "%s".', $written));

        new PcreTarget(80400, $written);
    }

    /**
     * @return iterable<string, array{written: string}>
     */
    public static function provideUnreadableReleases(): iterable
    {
        yield 'empty' => ['written' => ''];
        yield 'word' => ['written' => 'latest'];
        yield 'major only' => ['written' => '10'];
    }

    #[Test]
    public function test_releases_compare_as_numbers(): void
    {
        $target = new PcreTarget(80400, '10.44');

        $this->assertTrue($target->pcreAtLeast('10.43'));
        $this->assertTrue($target->pcreAtLeast('10.44'));
        $this->assertFalse($target->pcreAtLeast('10.45'));
        $this->assertTrue((new PcreTarget(80400, '10.100'))->pcreAtLeast('10.48'));
        $this->assertFalse((new PcreTarget(80400, '10.32'))->pcreAtLeast('10.40'));
    }

    #[Test]
    public function test_the_running_engine_is_the_running_php_minor_and_pcre2_release(): void
    {
        $running = explode(' ', \PCRE_VERSION)[0];
        $minor = intdiv(\PHP_VERSION_ID, 100) * 100;

        $this->assertTrue((new PcreTarget($minor, $running))->isRunningEngine());
        $this->assertFalse((new PcreTarget($minor, '10.100'))->isRunningEngine());
        $this->assertFalse((new PcreTarget($minor - 100, $running))->isRunningEngine());
    }

    #[Test]
    public function test_the_cache_key_names_both_versions(): void
    {
        $this->assertSame('php80400/pcre10.44', (new PcreTarget(80400, '10.44'))->cacheKey());
        $this->assertNotSame((new PcreTarget(80400, '10.42'))->cacheKey(), (new PcreTarget(80400, '10.44'))->cacheKey());
    }
}
