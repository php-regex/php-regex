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
 * is 2^(levels - 1) characters long: past 63 levels the count leaves PHP's
 * integers.
 */
final class LookbehindLengthOverflowTest extends TestCase
{
    #[Test]
    #[DataProvider('provideLevels')]
    public function test_a_lookbehind_length_past_the_integer_range_is_said_to_be_past_it(int $levels): void
    {
        $result = Regex::create(['cache' => null])->validate(self::pattern($levels));

        $this->assertFalse($result->isValid);
        $this->assertStringContainsString(\sprintf('(length over %d)', \PHP_INT_MAX), (string) $result->error);
    }

    /**
     * @return iterable<string, array{levels: int}>
     */
    public static function provideLevels(): iterable
    {
        yield 'just past the range' => ['levels' => 64];
        yield 'far past the range' => ['levels' => 70];
    }

    #[Test]
    public function test_a_lookbehind_length_inside_the_integer_range_is_printed(): void
    {
        $result = Regex::create(['cache' => null])->validate(self::pattern(63));

        $this->assertStringContainsString('(length=4611686018427387904)', (string) $result->error);
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
