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
use RegexParser\ErrorCode;
use RegexParser\Regex;

/**
 * Before PCRE2 10.43 a lookbehind must have a fixed length, and a group of
 * variable length still breaks it when it is repeated zero times: PCRE2
 * 10.40 and 10.42 refuse "(?<=a(b?c){0}d)" (error 125 at offset 0), which
 * 10.43 compiles. PHP 8.2 and 8.3 bundle 10.40 and 10.42 (pcre2test on each).
 */
final class LookbehindZeroCountTest extends TestCase
{
    #[Test]
    #[DataProvider('provideZeroCounts')]
    public function test_a_variable_group_repeated_zero_times_is_not_fixed_before_php_8_4(string $pattern): void
    {
        foreach ([80200, 80300] as $phpVersion) {
            $result = Regex::create(['cache' => null, 'php_version' => $phpVersion])->validate($pattern);

            $this->assertSame(ErrorCode::LookbehindVariableLengthNotSupported, $result->errorCode, \sprintf('%s on PHP %d', $pattern, $phpVersion));
            $this->assertSame(0, $result->offset);
        }

        $this->assertTrue(Regex::create(['cache' => null, 'php_version' => 80400])->validate($pattern)->isValid, $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideZeroCounts(): iterable
    {
        yield 'capturing group' => ['pattern' => '/(?<=a(b?c){0}d)X/'];
        yield 'non-capturing group' => ['pattern' => '/(?<=a(?:b?c){0}d)X/'];
        yield 'count written as a range' => ['pattern' => '/(?<=a(b?c){0,0}d)X/'];
        yield 'UTF mode' => ['pattern' => '/(?<=á(b?c){0}d)X/u'];
    }

    #[Test]
    public function test_a_fixed_group_repeated_zero_times_is_fine_everywhere(): void
    {
        foreach ([80200, 80300, 80400] as $phpVersion) {
            $this->assertTrue(Regex::create(['cache' => null, 'php_version' => $phpVersion])->validate('/(?<=a(bc){0}d)X/')->isValid);
        }
    }
}
