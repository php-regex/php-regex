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

namespace PHPRegex\Tests\Unit;

use PHPRegex\Parser\Exception\InvalidRegexOptionException;
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Tests\Support\InstalledLocales;
use PHPRegex\Tests\Support\PhpUnitCompat\PhpUnitForwardCompat;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PcreTargetTest extends TestCase
{
    use InstalledLocales;
    use PhpUnitForwardCompat;

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
        // php-src reverted to 10.44 after its 10.45/10.46 upgrades, and GA
        // 8.6 keeps that bundle.
        yield 'PHP 8.6' => ['phpVersionId' => 80600, 'release' => '10.44'];
        yield 'PHP 8.6 RC' => ['phpVersionId' => 80603, 'release' => '10.44'];
        // PHP 8.1.1 to 8.1.34 bundle 10.39; 8.1.0 bundled 10.37, judged as
        // 10.39.
        yield 'PHP 8.1' => ['phpVersionId' => 80100, 'release' => '10.39'];
        yield 'PHP 8.1 patch' => ['phpVersionId' => 80134, 'release' => '10.39'];
        // The release each series bundled at x.y.0, read from pcre2.h at the
        // php-src tags: the whole 7.3 series kept 10.32 and 8.0 kept 10.35;
        // 7.4.6 moved to 10.34 and 7.4.12 to 10.35, judged as 7.4.0's 10.33.
        yield 'PHP 8.0' => ['phpVersionId' => 80000, 'release' => '10.35'];
        yield 'PHP 8.0 patch' => ['phpVersionId' => 80030, 'release' => '10.35'];
        yield 'PHP 7.4' => ['phpVersionId' => 70400, 'release' => '10.33'];
        yield 'PHP 7.4 patch' => ['phpVersionId' => 70433, 'release' => '10.33'];
        yield 'PHP 7.3' => ['phpVersionId' => 70300, 'release' => '10.32'];
        yield 'PHP 7.3 patch' => ['phpVersionId' => 70333, 'release' => '10.32'];
    }

    /**
     * The library knows the rules of PCRE2 10.39 and later in full: an
     * older release is judged with the 10.39 rules but for the few older
     * changes the library names.
     */
    #[Test]
    #[DataProvider('provideJudgedReleases')]
    public function test_a_release_older_than_the_library_knows_in_full_is_judged_with_the_oldest_it_does(string $release, string $judgedWith): void
    {
        $this->assertSame($judgedWith, (new PcreTarget(70300, $release))->judgedWith());
    }

    /**
     * @return iterable<string, array{release: string, judgedWith: string}>
     */
    public static function provideJudgedReleases(): iterable
    {
        yield 'PHP 7.3\'s' => ['release' => '10.32', 'judgedWith' => '10.39'];
        yield 'PHP 8.1.0\'s' => ['release' => '10.37', 'judgedWith' => '10.39'];
        yield 'the oldest known in full' => ['release' => '10.39', 'judgedWith' => '10.39'];
        yield 'a newer one' => ['release' => '10.44', 'judgedWith' => '10.44'];
        yield 'one newer than any rule' => ['release' => '10.49', 'judgedWith' => '10.49'];
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
        yield 'leading zero kept' => ['written' => '10.05', 'release' => '10.05'];
        yield 'tab before the date' => ['written' => "10.44\t2024-06-07", 'release' => '10.44'];
    }

    /**
     * The host's backtrack limit is no reason to refuse a release, nor to
     * blame the option for it.
     */
    #[Test]
    #[DataProvider('provideReleaseSpellings')]
    public function test_a_release_is_read_under_any_backtrack_limit(string $written, string $release): void
    {
        $limit = (string) \ini_get('pcre.backtrack_limit');
        \ini_set('pcre.backtrack_limit', '1');

        try {
            $this->assertSame($release, (new PcreTarget(80400, $written))->pcreVersion);
            $this->assertSame(explode(' ', \PCRE_VERSION)[0], PcreTarget::runtime()->pcreVersion);
        } finally {
            \ini_set('pcre.backtrack_limit', $limit);
        }
    }

    #[Test]
    #[DataProvider('provideUnreadableReleases')]
    public function test_a_release_that_names_none_is_refused(string $written): void
    {
        $this->expectException(InvalidRegexOptionException::class);
        $this->expectExceptionMessageIsOrContains(\sprintf('"pcre_version" must be a PCRE2 release like "10.44", not "%s".', $written));

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
        yield 'no minor' => ['written' => '10.'];
        yield 'no major' => ['written' => '.44'];
        yield 'letter after the minor' => ['written' => '10.44x'];
        yield 'bare hyphen' => ['written' => '10.44-'];
        yield 'symbol in the suffix' => ['written' => '10.44-RC!'];
        yield 'prefixed' => ['written' => 'v10.44'];
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
        $this->assertSame('php8.4/pcre10.44', (new PcreTarget(80400, '10.44', 'C'))->cacheKey());
        $this->assertNotSame((new PcreTarget(80400, '10.42'))->cacheKey(), (new PcreTarget(80400, '10.44'))->cacheKey());
        // No parse rule depends on a PHP patch release, and "10.4" is "10.04".
        $this->assertSame((new PcreTarget(80400, '10.44'))->cacheKey(), (new PcreTarget(80426, '10.44 2024-06-07'))->cacheKey());
        $this->assertSame((new PcreTarget(80400, '10.04'))->cacheKey(), (new PcreTarget(80400, '10.4'))->cacheKey());
    }

    #[Test]
    public function test_php_version_boundaries_list_every_version_a_verdict_may_change_at(): void
    {
        // The floor, 7.3, the first PHP on PCRE2; the PHP versions that
        // bundle a newer PCRE2 (7.4, 8.0, 8.1, 8.2, 8.3, 8.4); and the ones
        // where a rule starts: \C under u refused from 8.4.25 and 8.5.10, \K
        // in a lookaround refused from 8.5.
        $this->assertSame([70300, 70400, 80000, 80100, 80200, 80300, 80400, 80425, 80500, 80510], PcreTarget::phpVersionBoundaries());
    }

    /**
     * The LC_CTYPE locale the patterns run under: the runtime's by default,
     * "C" for PHP's own start, where PCRE2's built-in tables are used.
     */
    #[Test]
    public function test_the_locale_is_the_runtimes_unless_one_is_named(): void
    {
        $this->assertSame(PcreTarget::runtime()->locale, (new PcreTarget(80400, '10.44'))->locale);
        $this->assertSame('C', (new PcreTarget(80400, '10.44', 'C'))->locale);
        $this->assertSame('C', (new PcreTarget(80400, '10.44'))->withLocale('C')->locale);
        $this->assertSame(80400, (new PcreTarget(80400, '10.44'))->withLocale('C')->phpVersionId);
        $this->assertSame('10.44', (new PcreTarget(80400, '10.44'))->withLocale('C')->pcreVersion);
        $this->assertSame(PcreTarget::runtime()->locale, (new PcreTarget(80400, '10.44', 'C'))->withLocale(null)->locale);
    }

    #[Test]
    public function test_a_locale_the_machine_cannot_set_is_refused(): void
    {
        $this->expectException(InvalidRegexOptionException::class);
        $this->expectExceptionMessageIsOrContains('"locale" names "xx_YY.NOWHERE", which this machine cannot set');

        new PcreTarget(80400, '10.44', 'xx_YY.NOWHERE');
    }

    #[Test]
    public function test_two_locales_never_share_a_cache_key(): void
    {
        $this->requiresLocale('tr_TR.ISO8859-9', 'The cache key of a Turkish target');
        $turkish = new PcreTarget(80400, '10.44', 'tr_TR.ISO8859-9');

        $this->assertSame('tr_TR.ISO8859-9', $turkish->locale);
        $this->assertSame('php8.4/pcre10.44/tr_TR.ISO8859-9', $turkish->cacheKey());
        $this->assertNotSame((new PcreTarget(80400, '10.44', 'C'))->cacheKey(), $turkish->cacheKey());
    }

    /**
     * The running engine compiles under the runtime's locale: a target of
     * another locale is not it.
     */
    #[Test]
    public function test_the_running_engine_runs_under_the_runtimes_locale(): void
    {
        $this->requiresLocale('tr_TR.ISO8859-9', 'The running engine under another locale');
        $running = PcreTarget::runtime();

        $this->assertTrue($running->withLocale($running->locale)->isRunningEngine());
        $this->assertFalse($running->withLocale('C' === $running->locale ? 'tr_TR.ISO8859-9' : 'C')->isRunningEngine());
    }

    /**
     * What tells two targets apart, the PHP patch release and the locale
     * included.
     */
    #[Test]
    public function test_the_key_names_the_php_the_release_and_the_locale(): void
    {
        $this->assertSame('80426/10.44/C', (new PcreTarget(80426, '10.44 2024-06-07', 'C'))->key());
        $this->assertNotSame((new PcreTarget(80400, '10.44', 'C'))->key(), (new PcreTarget(80425, '10.44', 'C'))->key());
        $this->requiresLocale('tr_TR.ISO8859-9', 'The key of a Turkish target');
        $this->assertSame('80400/10.44/tr_TR.ISO8859-9', (new PcreTarget(80400, '10.44', 'tr_TR.ISO8859-9'))->key());
    }
}
