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
 * "(*LIMIT_MATCH=n)" takes digits up to its ")". With no digit, PCRE stops
 * on the character after "="; after digits, PCRE2 10.45 and later stop on
 * the first character that is not one, and 10.40 to 10.44 one further, even
 * past the end of an unclosed verb (pcre2test on 10.40, 10.44, 10.45, 10.48;
 * PHP 8.2 to 8.5 bundle 10.40 to 10.44).
 */
final class LimitValueSyntaxTest extends TestCase
{
    #[Test]
    #[DataProvider('provideMalformedLimits')]
    public function test_a_malformed_value_is_refused_where_the_bundled_pcre2_stops(string $pattern, int $bundledOffset, int $newerOffset): void
    {
        unset($newerOffset);

        foreach ([80200, 80400, 80500] as $phpVersion) {
            $result = Regex::create(['cache' => null, 'php_version' => $phpVersion])->validate($pattern);

            $this->assertFalse($result->isValid, $pattern);
            $this->assertSame($bundledOffset, $result->offset, \sprintf('%s on PHP %d', $pattern, $phpVersion));
        }
    }

    #[Test]
    #[DataProvider('provideMalformedLimits')]
    public function test_without_a_target_the_running_pcre2_decides(string $pattern, int $bundledOffset, int $newerOffset): void
    {
        $result = Regex::create(['cache' => null])->validate($pattern);

        $this->assertFalse($result->isValid, $pattern);
        $this->assertSame(version_compare(explode(' ', \PCRE_VERSION)[0], '10.45', '>=') ? $newerOffset : $bundledOffset, $result->offset, $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string, bundledOffset: int, newerOffset: int}>
     */
    public static function provideMalformedLimits(): iterable
    {
        yield 'letters after digits' => ['pattern' => '/(*LIMIT_MATCH=12bc)abc/', 'bundledOffset' => 17, 'newerOffset' => 16];
        yield 'letter after one digit' => ['pattern' => '/(*LIMIT_HEAP=1b)abc/', 'bundledOffset' => 15, 'newerOffset' => 14];
        yield 'space after one digit' => ['pattern' => '/(*LIMIT_DEPTH=1 )abc/', 'bundledOffset' => 16, 'newerOffset' => 15];
        yield 'letter and no digit' => ['pattern' => '/(*LIMIT_MATCH=b)abc/', 'bundledOffset' => 14, 'newerOffset' => 14];
        yield 'no digit' => ['pattern' => '/(*LIMIT_MATCH=)abc/', 'bundledOffset' => 14, 'newerOffset' => 14];
        yield 'unclosed after digits' => ['pattern' => '/(*LIMIT_MATCH=12/', 'bundledOffset' => 17, 'newerOffset' => 16];
        yield 'unclosed after a letter' => ['pattern' => '/(*LIMIT_MATCH=12b/', 'bundledOffset' => 17, 'newerOffset' => 16];
        // Without "=", the name is what PCRE reads, and where it stops.
        yield 'unclosed without a value' => ['pattern' => '/(*LIMIT_MATCH/', 'bundledOffset' => 13, 'newerOffset' => 13];
    }
}
