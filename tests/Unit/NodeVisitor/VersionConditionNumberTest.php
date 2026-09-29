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

namespace RegexParser\Tests\Unit\NodeVisitor;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RegexParser\Regex;

/**
 * The numbers of "(?(VERSION>=n.m)...)". Up to PCRE2 10.45, the minor takes
 * two digits at most, refused on the third, and the major stops at 1000,
 * refused past the digit that goes over. PCRE2 10.48 reads both numbers
 * whole and refuses one over 1000 past its last digit. PHP 8.2 to 8.5
 * bundle 10.40 to 10.44 (every offset below is pcre2test's).
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
                $this->assertSame('regex.condition.version_syntax', $result->errorCode);
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

    #[Test]
    public function test_pcre2_10_48_reads_the_numbers_whole(): void
    {
        if (version_compare(explode(' ', \PCRE_VERSION)[0], '10.48', '<')) {
            $this->assertFalse(@preg_match('/(?(VERSION=10.101)yes|no)/', ''), 'Before 10.48, the minor takes two digits.');

            return;
        }

        // Past the last digit of a number over 1000; a three-digit minor
        // compiles.
        $regex = Regex::create(['cache' => null]);
        $this->assertTrue($regex->validate('/(?(VERSION=10.101)yes|no)/')->isValid);
        $this->assertSame(18, $regex->validate('/(?(VERSION=10.1001)yes|no)/')->offset);
        $this->assertSame(16, $regex->validate('/(?(VERSION=99999.1)yes|no)/')->offset);
    }
}
