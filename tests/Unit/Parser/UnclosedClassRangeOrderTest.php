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
 * In a class no "]" closes, PCRE2 reads the ranges first: a reversed one
 * is refused past its end ("range out of order in character class"),
 * before the missing "]".
 */
final class UnclosedClassRangeOrderTest extends TestCase
{
    #[Test]
    #[DataProvider('provideUnclosedClasses')]
    public function test_a_reversed_range_is_refused_before_the_missing_bracket(string $pattern, int $offset): void
    {
        $this->assertFalse(@preg_match($pattern, ''));

        $result = Regex::create(['cache' => null])->validate($pattern);

        $this->assertFalse($result->isValid, $pattern);
        $this->assertSame($offset, $result->offset, $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string, offset: int}>
     */
    public static function provideUnclosedClasses(): iterable
    {
        yield 'first range reversed' => ['pattern' => '/[z-abcd/', 'offset' => 4];
        yield 'reversed range after other characters' => ['pattern' => '/[a(?-1)/', 'offset' => 6];
        yield 'second range reversed' => ['pattern' => '/[a-zz-a/', 'offset' => 7];
        yield 'negated class' => ['pattern' => '/[^z-a/', 'offset' => 5];
        yield 'bracket first' => ['pattern' => '/[]z-a/', 'offset' => 5];
        yield 'after an escape' => ['pattern' => '/[\\dz-a/', 'offset' => 6];
        yield 'after a POSIX class' => ['pattern' => '/[[:alpha:]z-a/', 'offset' => 13];
        yield 'after a POSIX class never closed' => ['pattern' => '/[[:z-a/', 'offset' => 6];
        // An ordered range leaves the missing "]" to be reported.
        yield 'ordered range' => ['pattern' => '/[a-z/', 'offset' => 4];
    }
}
