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

namespace PHPRegex\Tests\Unit\Bridge\Rector;

use PHPRegex\Parser\PcreTarget;
use PHPRegex\Rector\PregCall;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The PCRE2 the rules judge the PHP the code targets with, as `regex lint`
 * resolves it: the release composer.json pins in config.platform.lib-pcre,
 * else the running PCRE2 when the PHP is the running one's minor version,
 * else the one the PHP bundles.
 */
final class PregCallTargetTest extends TestCase
{
    private ?string $composer = null;

    protected function tearDown(): void
    {
        putenv('COMPOSER');
        if (null !== $this->composer) {
            @unlink($this->composer);
        }
    }

    #[Test]
    public function test_the_running_minor_is_judged_with_the_running_pcre2(): void
    {
        $this->pinPcre2(null);

        $this->assertSame(PcreTarget::runtime()->pcreVersion, PregCall::target(\PHP_VERSION_ID)->pcreVersion);
        $this->assertSame(PcreTarget::runtime()->pcreVersion, PregCall::targetParser(\PHP_VERSION_ID)->target()->pcreVersion);
    }

    #[Test]
    public function test_another_minor_is_judged_with_the_pcre2_it_bundles(): void
    {
        $this->pinPcre2(null);
        $other = self::anotherMinor();

        $this->assertSame(PcreTarget::bundledWith($other)->pcreVersion, PregCall::target($other)->pcreVersion);
        $this->assertSame($other, PregCall::target($other)->phpVersionId);
    }

    #[Test]
    public function test_the_release_composer_json_pins_wins_at_every_php(): void
    {
        $this->pinPcre2('10.45.0');

        $this->assertSame('10.45', PregCall::target(\PHP_VERSION_ID)->pcreVersion);
        $this->assertSame('10.45', PregCall::target(80300)->pcreVersion);
        $this->assertSame('10.45', PregCall::targetParser(80300)->target()->pcreVersion);
    }

    /**
     * Composer spells a platform version with up to four numbers; a PCRE2
     * release has two.
     */
    #[Test]
    public function test_a_pin_of_four_numbers_names_its_release(): void
    {
        $this->pinPcre2('10.42.0.0');

        $this->assertSame('10.42', PregCall::target(80300)->pcreVersion);
    }

    /**
     * @return iterable<string, array{release: string}>
     */
    public static function provideUnreadablePins(): iterable
    {
        yield 'no number' => ['release' => 'latest'];
        yield 'a wildcard' => ['release' => '10.x'];
        yield 'a patch that is no number' => ['release' => '10.42.x'];
        yield 'five numbers' => ['release' => '10.42.0.0.0'];
        yield 'one number' => ['release' => '10'];
    }

    #[Test]
    #[DataProvider('provideUnreadablePins')]
    public function test_a_pin_that_names_no_release_is_passed_over(string $release): void
    {
        $this->pinPcre2($release);

        $this->assertSame(PcreTarget::bundledWith(self::anotherMinor())->pcreVersion, PregCall::target(self::anotherMinor())->pcreVersion);
    }

    #[Test]
    public function test_a_pin_that_is_no_string_is_passed_over(): void
    {
        $this->composer = sys_get_temp_dir().'/regex-rector-composer-'.bin2hex(random_bytes(8)).'.json';
        file_put_contents($this->composer, '{"config": {"platform": {"lib-pcre": 10.44}}}');
        putenv('COMPOSER='.$this->composer);

        $this->assertSame(PcreTarget::bundledWith(self::anotherMinor())->pcreVersion, PregCall::target(self::anotherMinor())->pcreVersion);
    }

    #[Test]
    public function test_a_composer_json_without_a_pin_reads_as_none(): void
    {
        $this->composer = sys_get_temp_dir().'/regex-rector-composer-'.bin2hex(random_bytes(8)).'.json';
        file_put_contents($this->composer, '{"name": "acme/app"}');
        putenv('COMPOSER='.$this->composer);

        $this->assertSame(PcreTarget::bundledWith(self::anotherMinor())->pcreVersion, PregCall::target(self::anotherMinor())->pcreVersion);
    }

    #[Test]
    public function test_a_php_rector_takes_below_the_library_is_judged_from_its_oldest(): void
    {
        $this->pinPcre2(null);

        $this->assertSame(70000, PregCall::target(50300)->phpVersionId);
    }

    /**
     * A PHP other than the running one's minor version, which a project
     * pinning no PCRE2 is judged with the release it bundles at.
     */
    private static function anotherMinor(): int
    {
        return 803 === intdiv(\PHP_VERSION_ID, 100) ? 80200 : 80300;
    }

    /**
     * A composer.json pinning the release, or none at all when null: the
     * file named does not exist.
     */
    private function pinPcre2(?string $release): void
    {
        // The rules keep the release a file pins for the run, by its name:
        // each test names a file of its own.
        $this->composer = sys_get_temp_dir().'/regex-rector-composer-'.bin2hex(random_bytes(8)).'.json';
        if (null !== $release) {
            file_put_contents($this->composer, json_encode(['config' => ['platform' => ['lib-pcre' => $release]]], \JSON_THROW_ON_ERROR));
        }
        putenv('COMPOSER='.$this->composer);
    }
}
