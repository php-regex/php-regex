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

namespace PHPRegex\Tests\Unit\Parser\Syntax;

use PHPRegex\Parser\Syntax\PcreNumber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The largest number PCRE takes in a count, a group number or a length,
 * 65535, and whether written digits go past it, leading zeros aside.
 *
 * Oracle (PHP 8.4.26, PCRE2 10.49): "/a{65535}/" compiles, "/a{65536}/"
 * and "/a{0065536}/" are refused, "/a{0000065535}/" compiles.
 */
final class PcreNumberTest extends TestCase
{
    #[Test]
    public function test_the_largest_number_is_65535(): void
    {
        $this->assertNotFalse(@preg_match('/a{'.PcreNumber::MAX.'}/', ''), 'Oracle: PCRE takes the largest number.');
        $this->assertTrue(PcreNumber::exceeds((string) (PcreNumber::MAX + 1)), 'One past it is past it.');
    }

    #[Test]
    #[DataProvider('provideDigits')]
    public function test_digits_exceed_the_largest_number_past_it(string $digits, bool $exceeds): void
    {
        $this->assertSame($exceeds, PcreNumber::exceeds($digits));
        $this->assertSame(!$exceeds, false !== @preg_match('/a{'.$digits.'}/', ''), 'Oracle: PCRE reads the count so.');
    }

    /**
     * @return iterable<string, array{digits: string, exceeds: bool}>
     */
    public static function provideDigits(): iterable
    {
        yield 'zero' => ['digits' => '0', 'exceeds' => false];
        yield 'the largest' => ['digits' => '65535', 'exceeds' => false];
        yield 'one past it' => ['digits' => '65536', 'exceeds' => true];
        yield 'padded, the largest' => ['digits' => '0000065535', 'exceeds' => false];
        yield 'padded, one past it' => ['digits' => '0065536', 'exceeds' => true];
        yield 'six digits' => ['digits' => '100000', 'exceeds' => true];
        yield 'past an integer' => ['digits' => '99999999999999999999999', 'exceeds' => true];
    }
}
