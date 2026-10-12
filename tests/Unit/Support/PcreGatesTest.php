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

namespace PHPRegex\Tests\Unit\Support;

use PHPRegex\Parser\PcreTarget;
use PHPRegex\Tests\Support\PcreGateLimits;
use PHPRegex\Tests\Support\PcreGates;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\SkippedWithMessageException;
use PHPUnit\Framework\TestCase;

/**
 * The self-test of the gate trait.
 *
 * The skip direction is forced with versions deliberately outside every
 * version the suite judges (a release above the ceiling, a PHP floor no
 * PHP meets, a PHP ceiling no PHP running the suite is under), so every
 * row holds on any engine and any PHP the suite runs on; the no-skip
 * direction assumes only the documented floors, never the development
 * machine's versions.
 *
 * The skip is caught by hand rather than expected, so the message itself
 * is asserted verbatim. On PHPUnit 10, 11 and 12 alike a skip thrown by
 * the framework is a SkippedWithMessageException; SkippedTestError was a
 * PHPUnit 9 class and no longer exists.
 */
final class PcreGatesTest extends TestCase
{
    use PcreGates;

    #[Test]
    public function test_the_ceiling_constant_pins_the_newest_known_release(): void
    {
        $this->assertSame('10.49', PcreGateLimits::MAX_KNOWN_RELEASE);
    }

    #[Test]
    public function test_a_release_above_the_ceiling_skips_with_the_verbatim_pcre2_message(): void
    {
        $subject = '/(?-r)k*+\x{212A}/iur/';

        try {
            $this->requiresPcre2('10.99', $subject);
            $this->fail('A release no engine has did not skip.');
        } catch (SkippedWithMessageException $skip) {
            $this->assertSame(
                \sprintf('%s is verified against PCRE2 %s and later; PCRE2 %s reports it differently.', $subject, '10.99', \PCRE_VERSION),
                $skip->getMessage(),
            );
        }
    }

    #[Test]
    public function test_a_php_floor_no_php_meets_skips_with_the_verbatim_message(): void
    {
        $subject = '/(?-r)(?!k)\x{212A}/iur/';

        try {
            $this->requiresPhp('99.0', $subject, 'does not read the r modifier');
            $this->fail('A floor above every PHP version did not skip.');
        } catch (SkippedWithMessageException $skip) {
            $this->assertSame(
                \sprintf('%s is verified against PHP %s and later; PHP %s %s.', $subject, '99.0', \PHP_VERSION, 'does not read the r modifier'),
                $skip->getMessage(),
            );
        }
    }

    #[Test]
    public function test_a_php_ceiling_below_every_running_php_skips_with_the_verbatim_message(): void
    {
        $subject = '/(?<=a\Kb)c/';

        try {
            $this->forbidsPhp('5.0', $subject, 'refuses "\K" in a lookaround');
            $this->fail('A ceiling below every PHP running the suite did not skip.');
        } catch (SkippedWithMessageException $skip) {
            $this->assertSame(
                \sprintf('%s is verified against PHP %s and earlier; PHP %s %s.', $subject, '5.0', \PHP_VERSION, 'refuses "\K" in a lookaround'),
                $skip->getMessage(),
            );
        }
    }

    #[Test]
    #[DataProvider('provideRefusedPatterns')]
    public function test_a_pattern_the_engine_refuses_skips_with_the_verbatim_probe_message(string $pattern): void
    {
        try {
            $this->requiresCompile($pattern);
            $this->fail(\sprintf('The engine accepted %s, so the probe did not skip.', $pattern));
        } catch (SkippedWithMessageException $skip) {
            $this->assertSame(
                \sprintf('%s does not run on PCRE2 %s.', $pattern, \PCRE_VERSION),
                $skip->getMessage(),
            );
        }
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideRefusedPatterns(): iterable
    {
        // The reversed quantifier bounds are refused by every PCRE2 release.
        yield 'reversed quantifier bounds' => ['pattern' => '/a{2,1}/'];
        yield 'unclosed character class' => ['pattern' => '/[a/'];
        // A literal percent sign in the pattern proves the message is built
        // as sprintf('%s does not run on PCRE2 %s.', ...) and never as a
        // format string of its own: an argument swap would mangle it.
        yield 'subject carrying a percent sign' => ['pattern' => '/100%{2,1}/'];
        yield 'unicode subject' => ['pattern' => '/é{2,1}/'];
    }

    #[Test]
    public function test_the_label_appends_to_the_pattern_in_the_probe_message(): void
    {
        try {
            $this->requiresCompile('/a{2,1}/', 'the reversed-bounds probe');
            $this->fail('The engine accepted the probe pattern, so it did not skip.');
        } catch (SkippedWithMessageException $skip) {
            $this->assertSame(
                \sprintf('%s (%s) does not run on PCRE2 %s.', '/a{2,1}/', 'the reversed-bounds probe', \PCRE_VERSION),
                $skip->getMessage(),
            );
        }
    }

    #[Test]
    public function test_a_release_the_engine_has_does_not_skip(): void
    {
        $this->requiresPcre2('10.40', '/(?-r)k*+\x{212A}/iur/');

        // The documented engine floor: the gate may only hold because every
        // engine the suite runs on has this release.
        $this->assertTrue(PcreTarget::runtime()->pcreAtLeast('10.40'));
    }

    #[Test]
    public function test_the_ceiling_itself_gates_or_skips_as_the_engine_reads_it(): void
    {
        $subject = '/(?-r)k*+\x{212A}/iur/';

        if (PcreTarget::runtime()->pcreAtLeast(PcreGateLimits::MAX_KNOWN_RELEASE)) {
            $this->requiresPcre2(PcreGateLimits::MAX_KNOWN_RELEASE, $subject);

            $this->assertTrue(
                PcreTarget::runtime()->pcreAtLeast(PcreGateLimits::MAX_KNOWN_RELEASE),
                'A gate at the ceiling is a run, not a permanent invisible skip.',
            );

            return;
        }

        try {
            $this->requiresPcre2(PcreGateLimits::MAX_KNOWN_RELEASE, $subject);
            $this->fail('A release above the running engine did not skip.');
        } catch (SkippedWithMessageException $skip) {
            $this->assertSame(
                \sprintf('%s is verified against PCRE2 %s and later; PCRE2 %s reports it differently.', $subject, PcreGateLimits::MAX_KNOWN_RELEASE, \PCRE_VERSION),
                $skip->getMessage(),
            );
        }
    }

    #[Test]
    public function test_a_php_floor_the_suite_always_meets_does_not_skip(): void
    {
        $this->requiresPhp('8.2', '/(?-r)(?!k)\x{212A}/iur/', 'does not read the r modifier');

        // The documented PHP floor: the library requires PHP 8.2.
        $this->assertGreaterThanOrEqual(80200, \PHP_VERSION_ID);
    }

    #[Test]
    public function test_a_php_floor_gates_or_skips_as_the_running_php_meets_it(): void
    {
        $subject = '/(?-r)(?!k)\x{212A}/iur/';

        if (\PHP_VERSION_ID >= 80400) {
            $this->requiresPhp('8.4', $subject, 'does not read the r modifier');

            $this->assertGreaterThanOrEqual(80400, \PHP_VERSION_ID);

            return;
        }

        try {
            $this->requiresPhp('8.4', $subject, 'does not read the r modifier');
            $this->fail('A floor above the running PHP did not skip.');
        } catch (SkippedWithMessageException $skip) {
            $this->assertSame(
                \sprintf('%s is verified against PHP %s and later; PHP %s %s.', $subject, '8.4', \PHP_VERSION, 'does not read the r modifier'),
                $skip->getMessage(),
            );
        }
    }

    #[Test]
    public function test_a_php_ceiling_gates_or_skips_as_the_running_php_reaches_it(): void
    {
        $subject = '/(?<=a\Kb)c/';

        if (\PHP_VERSION_ID >= 80500) {
            try {
                $this->forbidsPhp('8.4', $subject, 'refuses "\K" in a lookaround');
                $this->fail('A ceiling the running PHP passed did not skip.');
            } catch (SkippedWithMessageException $skip) {
                $this->assertSame(
                    \sprintf('%s is verified against PHP %s and earlier; PHP %s %s.', $subject, '8.4', \PHP_VERSION, 'refuses "\K" in a lookaround'),
                    $skip->getMessage(),
                );
            }

            return;
        }

        $this->forbidsPhp('8.4', $subject, 'refuses "\K" in a lookaround');

        $this->assertLessThan(80500, \PHP_VERSION_ID);
    }

    #[Test]
    public function test_a_pattern_the_engine_accepts_does_not_skip(): void
    {
        $this->requiresCompile('/a+/');

        // The guard the trait probes: an accepted pattern matches or misses,
        // but never returns false.
        $this->assertNotFalse(@preg_match('/a+/', ''));
    }

    /**
     * @param non-empty-string $version
     */
    #[Test]
    #[DataProvider('providePhpVersions')]
    public function test_a_php_version_reads_as_its_version_id(string $version, int $versionId): void
    {
        $this->assertSame($versionId, self::pcreGatePhpVersionId($version));
    }

    /**
     * @return iterable<string, array{version: non-empty-string, versionId: int}>
     */
    public static function providePhpVersions(): iterable
    {
        yield 'the documented php floor' => ['version' => '8.2', 'versionId' => 80200];
        yield 'a php the suite judges' => ['version' => '8.3', 'versionId' => 80300];
        yield 'the php the r modifier changed in' => ['version' => '8.4', 'versionId' => 80400];
        yield 'the php that refuses a lookaround \K' => ['version' => '8.5', 'versionId' => 80500];
        yield 'a floor no php meets' => ['version' => '99.0', 'versionId' => 990000];
        yield 'a ceiling below every php' => ['version' => '5.0', 'versionId' => 50000];
    }

    #[Test]
    public function test_the_version_id_of_a_php_version_is_the_one_php_itself_computes(): void
    {
        $running = \PHP_MAJOR_VERSION.'.'.\PHP_MINOR_VERSION;

        $this->assertSame(\PHP_VERSION_ID - (int) \PHP_RELEASE_VERSION, self::pcreGatePhpVersionId($running));
    }

    /**
     * @param non-empty-string $version
     */
    #[Test]
    #[DataProvider('provideMalformedPhpVersions')]
    public function test_a_php_version_the_gates_cannot_read_is_refused(string $version): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($version);

        self::pcreGatePhpVersionId($version);
    }

    /**
     * @return iterable<string, array{version: non-empty-string}>
     */
    public static function provideMalformedPhpVersions(): iterable
    {
        // A patch level cannot be read as a version id: truncating it would
        // gate "8.4.25" as plain 8.4 and run rows that only hold from the patch.
        yield 'a patch level' => ['version' => '8.4.25'];
        yield 'one part only' => ['version' => '8'];
    }
}
