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

namespace PHPRegex\Tests\Unit\Parser;

use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Each group calls the next twice, so a lookbehind calling the first one
 * would be 2^(levels - 1) characters long, past PHP's integers beyond 63
 * levels. PCRE2 (10.32 to 10.49 alike) stops at the first branch past 65535,
 * two calls of a group of 32768, and so does the measure: the count never
 * grows further, however deep the calls go.
 */
final class LookbehindLengthOverflowTest extends TestCase
{
    #[Test]
    #[DataProvider('provideLevels')]
    public function test_a_lookbehind_length_doubling_past_the_integer_range_stops_past_the_limit(int $levels): void
    {
        $result = Regex::create(['cache' => null])->validate(self::pattern($levels));

        $this->assertFalse($result->isValid);
        $this->assertStringContainsString('(65536 reached)', (string) $result->error);
    }

    /**
     * @return iterable<string, array{levels: int}>
     */
    public static function provideLevels(): iterable
    {
        yield 'just past the limit' => ['levels' => 17];
        yield 'just past the range' => ['levels' => 64];
        yield 'far past the range' => ['levels' => 70];
    }

    #[Test]
    public function test_a_lookbehind_length_doubling_inside_the_integer_range_stops_past_the_limit(): void
    {
        $result = Regex::create(['cache' => null])->validate(self::pattern(63));

        $this->assertStringContainsString('(65536 reached)', (string) $result->error);
    }

    #[Test]
    public function test_a_lookbehind_length_doubling_up_to_the_limit_compiles(): void
    {
        $this->assertTrue(Regex::create(['cache' => null])->validate(self::pattern(16))->isValid);
    }

    private static function pattern(int $levels): string
    {
        $groups = '';
        for ($level = 1; $level < $levels; $level++) {
            $groups .= '((?'.($level + 1).')(?'.($level + 1).'))';
        }

        return '/(?<=(?1))x'.$groups.'(a)/';
    }
}
