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
 * After "(?(", PCRE reads an assertion when "?" or "*" follows, else a
 * number, a version or a group name: an escape or a group there is no name,
 * and is refused on its first character ("subpattern name expected", the
 * same offset on PCRE2 10.40 to 10.48).
 */
final class ConditionNameExpectedTest extends TestCase
{
    #[Test]
    #[DataProvider('provideRefused')]
    public function test_what_is_no_name_is_refused_where_it_starts(string $pattern, int $offset): void
    {
        $this->assertFalse(@preg_match($pattern, ''), $pattern);

        foreach ([['cache' => null], ['cache' => null, 'php_version' => 80200], ['cache' => null, 'php_version' => 80500]] as $options) {
            $result = Regex::create($options)->validate($pattern);

            $this->assertFalse($result->isValid, $pattern);
            $this->assertSame($offset, $result->offset, $pattern);
        }
    }

    #[Test]
    #[DataProvider('provideAccepted')]
    public function test_an_alphabetic_assertion_is_a_condition(string $pattern): void
    {
        $this->assertNotFalse(preg_match($pattern, ''), $pattern);
        $this->assertTrue(Regex::create(['cache' => null])->validate($pattern)->isValid, $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string, offset: int}>
     */
    public static function provideRefused(): iterable
    {
        yield 'backreference' => ['pattern' => '/(a)(?(\\1)b)/', 'offset' => 6];
        yield 'backreference by g' => ['pattern' => '/(a)(?(\\g1)b)/', 'offset' => 6];
        yield 'braced backreference by g' => ['pattern' => '/(a)(?(\\g{1})b)/', 'offset' => 6];
        yield 'lookahead in a group' => ['pattern' => '/(?((?=a))b)/', 'offset' => 3];
        yield 'call in a group' => ['pattern' => '/(a)(?((?1))b)/', 'offset' => 6];
        yield 'callout in a group' => ['pattern' => '/(?((?C1))b)/', 'offset' => 3];
        yield 'word boundary' => ['pattern' => '/(?(\\b)b)/', 'offset' => 3];
        yield 'colon' => ['pattern' => '/(?(:)a)/', 'offset' => 3];
        yield 'brace' => ['pattern' => '/(?({)a)/', 'offset' => 3];
        yield 'colon never closed' => ['pattern' => '/(?(:)/', 'offset' => 3];
        yield 'backreference never closed' => ['pattern' => '/b(?(\\8/', 'offset' => 4];
        yield 'group never closed' => ['pattern' => '/(?((?1)/', 'offset' => 3];
        // "syntax error in subpattern name (missing terminator?)", where the
        // characters a name holds end.
        yield 'name holding a hyphen' => ['pattern' => '/(?(a-b)x)/', 'offset' => 4];
        yield 'name holding a space' => ['pattern' => '/(?(a b)x)/', 'offset' => 4];
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideAccepted(): iterable
    {
        yield 'short name' => ['pattern' => '/(?(*pla:a)b)/'];
        yield 'long name' => ['pattern' => '/(?(*positive_lookahead:a)b)/'];
    }
}
