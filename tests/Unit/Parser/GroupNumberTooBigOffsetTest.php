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
 * "Subpattern number is too big" lands where PCRE2 10.49 puts it
 * (pcre2test, error 161): on the bracket that opens a braced, angled or
 * quoted number, past the digits otherwise.
 */
final class GroupNumberTooBigOffsetTest extends TestCase
{
    #[Test]
    #[DataProvider('provideTooBigNumbers')]
    public function test_a_group_number_past_65535_is_refused_where_pcre_refuses_it(string $pattern, int $offset): void
    {
        $this->assertFalse(@preg_match($pattern, ''), $pattern);

        $result = Regex::create(['cache' => null])->validate($pattern);

        $this->assertFalse($result->isValid);
        $this->assertSame($offset, $result->offset, $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string, offset: int}>
     */
    public static function provideTooBigNumbers(): iterable
    {
        // The forward relative number fits; its sum with the groups before
        // it does not.
        yield 'braced forward reference' => ['pattern' => '/(a)(b)\g{+65534}/', 'offset' => 8];
        yield 'braced forward reference, one group' => ['pattern' => '/(a)\g{+65535}/', 'offset' => 5];
        yield 'angled forward call' => ['pattern' => '/(a)(b)\g<+65534>/', 'offset' => 8];
        yield 'quoted forward call' => ['pattern' => "/(a)(b)\\g'+65534'/", 'offset' => 8];
        // Already where PCRE puts them.
        yield 'braced number' => ['pattern' => '/(a)(b)\g{65536}/', 'offset' => 8];
        yield 'bare number' => ['pattern' => '/(a)(b)\g65536/', 'offset' => 13];
        yield 'bare forward reference' => ['pattern' => '/(a)(b)\g+65534/', 'offset' => 14];
        yield 'numbered call' => ['pattern' => '/(a)(b)(?65536)/', 'offset' => 13];
        yield 'forward numbered call' => ['pattern' => '/(a)(b)(?+65534)/', 'offset' => 14];
    }
}
