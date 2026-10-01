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

namespace PhpRegex\Tests\Unit\Parser;

use PhpRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * PCRE2 10.44 raised the longest group name from 32 code units to 128. PHP
 * 8.2 and 8.3 bundle 10.40 and 10.42, which refuse a longer name where it
 * ends, in a group or in a reference to one; PHP 8.4 and 8.5 bundle 10.44
 * (every offset below is pcre2test's on those releases).
 */
final class GroupNameLengthReleaseTest extends TestCase
{
    #[Test]
    #[DataProvider('provideLongNames')]
    public function test_a_name_past_32_code_units_is_refused_before_php_8_4(string $pattern, int $offset): void
    {
        foreach ([80200, 80300] as $phpVersion) {
            $result = Regex::create(['cache' => null, 'php_version' => $phpVersion])->validate($pattern);

            $this->assertFalse($result->isValid, \sprintf('%s is refused on PHP %d.', $pattern, $phpVersion));
            $this->assertSame($offset, $result->offset, $pattern);
        }
    }

    /**
     * @return iterable<string, array{pattern: string, offset: int}>
     */
    public static function provideLongNames(): iterable
    {
        $name = str_repeat('a', 33);

        yield 'named group' => ['pattern' => '/(?<'.$name.'>x)/', 'offset' => 36];
        yield 'quoted named group' => ['pattern' => "/(?'".$name."'x)/", 'offset' => 36];
        yield 'Python reference' => ['pattern' => '/(?P='.$name.')/', 'offset' => 37];
        yield 'call by name' => ['pattern' => '/(?&'.$name.')/', 'offset' => 36];
    }

    #[Test]
    public function test_names_up_to_128_code_units_compile_from_php_8_4(): void
    {
        foreach ([80400, 80500] as $phpVersion) {
            $regex = Regex::create(['cache' => null, 'php_version' => $phpVersion]);

            $this->assertTrue($regex->validate('/(?<'.str_repeat('a', 33).'>x)/')->isValid);
            $this->assertTrue($regex->validate('/(?<'.str_repeat('a', 128).'>x)/')->isValid);

            $tooLong = $regex->validate('/(?<'.str_repeat('a', 129).'>x)/');
            $this->assertFalse($tooLong->isValid);
            $this->assertSame(132, $tooLong->offset);

            // A call by name too: PHP reports the length, not the group.
            $tooLongCall = $regex->validate('/(?&'.str_repeat('a', 129).')/');
            $this->assertFalse($tooLongCall->isValid);
            $this->assertSame(132, $tooLongCall->offset);
        }
    }

    #[Test]
    #[DataProvider('provideReferencesToLongNames')]
    public function test_a_reference_to_a_name_past_the_limit_reports_the_length(string $pattern, int $offset): void
    {
        // PHP on PCRE2 10.48 stops where the name ends, before it looks the
        // group up.
        $this->assertFalse(@preg_match($pattern, ''));

        $result = Regex::create(['cache' => null, 'php_version' => 80400])->validate($pattern);

        $this->assertFalse($result->isValid);
        $this->assertSame($offset, $result->offset, $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string, offset: int}>
     */
    public static function provideReferencesToLongNames(): iterable
    {
        $name = str_repeat('a', 129);

        yield 'k with angle brackets' => ['pattern' => '/\\k<'.$name.'>/', 'offset' => 132];
        yield 'k with braces' => ['pattern' => '/\\k{'.$name.'}/', 'offset' => 132];
        yield 'k with quotes' => ['pattern' => "/\\k'".$name."'/", 'offset' => 132];
        yield 'g with braces' => ['pattern' => '/\\g{'.$name.'}/', 'offset' => 132];
        yield 'g with angle brackets' => ['pattern' => '/\\g<'.$name.'>/', 'offset' => 132];
        yield 'g with quotes' => ['pattern' => "/\\g'".$name."'/", 'offset' => 132];
        yield 'bare name condition' => ['pattern' => '/(?('.$name.')a)/', 'offset' => 132];
    }

    #[Test]
    public function test_names_up_to_32_code_units_compile_everywhere(): void
    {
        foreach ([80200, 80300, 80400] as $phpVersion) {
            $this->assertTrue(Regex::create(['cache' => null, 'php_version' => $phpVersion])->validate('/(?<'.str_repeat('a', 32).'>x)\\k<'.str_repeat('a', 32).'>/')->isValid);
        }
    }

    #[Test]
    public function test_without_a_target_the_running_pcre2_decides(): void
    {
        $pattern = '/(?<'.str_repeat('a', 33).'>x)/';

        $this->assertSame(false !== @preg_match($pattern, ''), Regex::create(['cache' => null])->validate($pattern)->isValid);
    }
}
