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
 * "Numbers out of order in {} quantifier" lands past the second number
 * (pcre2test 10.49, error 104), whatever follows the count: a comment, then
 * the "+" or "?" that makes it possessive or lazy.
 */
final class QuantifierRangeOffsetTest extends TestCase
{
    #[Test]
    #[DataProvider('provideReversedCounts')]
    public function test_a_reversed_count_is_refused_past_its_second_number(string $pattern, int $offset): void
    {
        $this->assertFalse(@preg_match($pattern, ''), $pattern);

        $result = Regex::create(['cache' => null])->validate($pattern);

        $this->assertFalse($result->isValid, $pattern);
        $this->assertSame($offset, $result->offset, $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string, offset: int}>
     */
    public static function provideReversedCounts(): iterable
    {
        yield 'comment then plus' => ['pattern' => '/a{3,2}(?#c)+/', 'offset' => 5];
        yield 'comment then question mark' => ['pattern' => '/a{3,2}(?#c)?/', 'offset' => 5];
        yield 'space before the count under x' => ['pattern' => '/a {3,2}(?#c)+/x', 'offset' => 6];
        yield 'plain' => ['pattern' => '/a{3,2}/', 'offset' => 5];
        yield 'possessive' => ['pattern' => '/a{3,2}+/', 'offset' => 5];
    }
}
