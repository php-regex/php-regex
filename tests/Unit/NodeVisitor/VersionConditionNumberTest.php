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

namespace PHPRegex\Tests\Unit\NodeVisitor;

use PHPRegex\Parser\ErrorCode;
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Tests\Support\EngineProbe;
use PHPRegex\Tests\TestUtils\PhpErrorOffset;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The numbers of "(?(VERSION>=n.m)...)". Up to PCRE2 10.46, the minor takes
 * two digits at most, refused on the third. The major stops at 1000: up to
 * PCRE2 10.44 it is refused past the digit that goes over, and from 10.45
 * past its last digit. PCRE2 10.47 reads both numbers whole and refuses one
 * over 1000 past its last digit. PHP 8.2 to 8.5 bundle 10.40 to 10.44
 * (every offset below is pcre2test's).
 */
final class VersionConditionNumberTest extends TestCase
{
    /**
     * @param int|null $bundledOffset where PCRE2 10.40 to 10.44 stop, or null when they compile it
     */
    #[Test]
    #[DataProvider('provideVersions')]
    public function test_the_numbers_are_read_as_the_bundled_pcre2_reads_them(string $pattern, ?int $bundledOffset): void
    {
        foreach ([80200, 80300, 80400, 80500] as $phpVersion) {
            $result = Regex::create(['cache' => null, 'php_version' => $phpVersion])->validate($pattern);

            if (null === $bundledOffset) {
                $this->assertTrue($result->isValid, \sprintf('%s compiles on PHP %d: %s', $pattern, $phpVersion, (string) $result->error));
            } else {
                $this->assertFalse($result->isValid, \sprintf('%s is refused on PHP %d.', $pattern, $phpVersion));
                $this->assertSame(ErrorCode::ConditionalVersionSyntax, $result->errorCode);
                $this->assertSame($bundledOffset, $result->offset, $pattern);
            }
        }
    }

    #[Test]
    #[DataProvider('provideVersions')]
    public function test_without_a_target_the_running_pcre2_decides(string $pattern, ?int $bundledOffset): void
    {
        unset($bundledOffset);

        $this->assertSame(false !== @preg_match($pattern, ''), Regex::create(['cache' => null])->validate($pattern)->isValid, $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string, bundledOffset: int|null}>
     */
    public static function provideVersions(): iterable
    {
        yield 'three-digit minor' => ['pattern' => '/(?(VERSION=10.101)yes|no)/', 'bundledOffset' => 16];
        yield 'three-digit minor after >=' => ['pattern' => '/(?(VERSION>=10.100)yes|no)/', 'bundledOffset' => 17];
        yield 'minor of 1001' => ['pattern' => '/(?(VERSION=10.1001)yes|no)/', 'bundledOffset' => 16];
        yield 'major of 1001' => ['pattern' => '/(?(VERSION=1001.1)yes|no)/', 'bundledOffset' => 15];
        yield 'major of five digits' => ['pattern' => '/(?(VERSION=99999.1)yes|no)/', 'bundledOffset' => 15];
        yield 'two-digit minor' => ['pattern' => '/(?(VERSION=10.99)yes|no)/', 'bundledOffset' => null];
        yield 'major of 1000' => ['pattern' => '/(?(VERSION=1000.1)yes|no)/', 'bundledOffset' => null];
    }

    /**
     * PCRE2 10.45 and 10.46 already read a major whole, as every number
     * since 10.45, and still stop a minor at its third digit.
     *
     * PHP 8.4.27 linked to PCRE2 10.45 2025-02-05 and to 10.46 2025-08-27
     * warns "Compilation failed: syntax error or number too big in
     * (?(VERSION condition" at offset 16 for "/(?(VERSION=99999.1)yes|no)/"
     * and "/(?(VERSION=99999)yes|no)/", where 10.44 says 15, at 15 for
     * "1001.1", at 16 for the minors "10.101" and "10.1001", and compiles
     * "1000.1". Where the running PCRE2 is the release of the row, PHP is
     * asked again.
     */
    #[Test]
    #[DataProvider('provideReleasesReadingTheMajorWhole')]
    public function test_pcre2_10_45_reads_the_major_whole(string $release): void
    {
        $regex = Regex::create(['cache' => null, 'pcre_version' => $release]);
        $running = PcreTarget::runtime()->pcreVersion === $release;

        $offsets = [
            '/(?(VERSION=99999.1)yes|no)/' => 16,
            '/(?(VERSION=99999)yes|no)/' => 16,
            '/(?(VERSION=1001.1)yes|no)/' => 15,
            '/(?(VERSION=10.101)yes|no)/' => 16,
            '/(?(VERSION=10.1001)yes|no)/' => 16,
        ];
        foreach ($offsets as $pattern => $offset) {
            $this->assertSame($offset, $regex->validate($pattern)->offset, $pattern.' on '.$release);
            if ($running) {
                $this->assertSame($offset, PhpErrorOffset::of($pattern), \sprintf('Oracle: %s on PCRE2 %s: %s', $pattern, \PCRE_VERSION, EngineProbe::warning($pattern) ?? 'compiles'));
            }
        }

        $this->assertTrue($regex->validate('/(?(VERSION=1000.1)yes|no)/')->isValid);
        if ($running) {
            $this->assertTrue(EngineProbe::compiles('/(?(VERSION=1000.1)yes|no)/'), 'Oracle: /(?(VERSION=1000.1)yes|no)/ on PCRE2 '.\PCRE_VERSION);
        }
    }

    /**
     * @return iterable<string, array{release: string}>
     */
    public static function provideReleasesReadingTheMajorWhole(): iterable
    {
        yield 'PCRE2 10.45' => ['release' => '10.45'];
        yield 'PCRE2 10.46' => ['release' => '10.46'];
    }

    #[Test]
    public function test_pcre2_10_47_reads_the_numbers_whole(): void
    {
        // Before 10.47, the minor takes two digits.
        $this->assertFalse(Regex::create(['cache' => null, 'pcre_version' => '10.46'])->validate('/(?(VERSION=10.101)yes|no)/')->isValid);

        // Past the last digit of a number over 1000; a three-digit minor
        // compiles.
        $regex = Regex::create(['cache' => null, 'pcre_version' => '10.47']);
        $this->assertTrue($regex->validate('/(?(VERSION=10.101)yes|no)/')->isValid);
        $this->assertSame(18, $regex->validate('/(?(VERSION=10.1001)yes|no)/')->offset);
        $this->assertSame(16, $regex->validate('/(?(VERSION=99999.1)yes|no)/')->offset);
    }
}
