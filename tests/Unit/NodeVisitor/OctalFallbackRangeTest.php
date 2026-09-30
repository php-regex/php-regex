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
 * "\NN" of two digits or more that names no group is an octal escape of up
 * to three octal digits. Without UTF mode, one past "\377" is refused where
 * those digits end, whatever follows (testinput9 of the PCRE2 suite; every
 * offset below is PHP's, on 10.42 and 10.48 alike).
 */
final class OctalFallbackRangeTest extends TestCase
{
    #[Test]
    #[DataProvider('provideOutOfRange')]
    public function test_an_octal_escape_past_377_is_refused_where_its_digits_end(string $pattern, int $offset): void
    {
        $this->assertFalse(@preg_match($pattern, ''), \sprintf('%s should not compile.', $pattern));

        $result = Regex::create(['cache' => null])->validate($pattern);

        $this->assertSame(ErrorCode::OctalOutOfRange, $result->errorCode, $pattern);
        $this->assertSame($offset, $result->offset, $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string, offset: int}>
     */
    public static function provideOutOfRange(): iterable
    {
        yield 'three octal digits' => ['pattern' => '/\\666/', 'offset' => 4];
        yield 'digits past the third' => ['pattern' => '/\\6666666666/', 'offset' => 4];
        yield 'fourth digit not octal' => ['pattern' => '/\\4009/', 'offset' => 4];
        yield 'after text' => ['pattern' => '/a\\477b/', 'offset' => 5];
        yield 'in a group, after a count' => ['pattern' => '/(?i:A{1,}\\6666666666)/', 'offset' => 13];
    }

    #[Test]
    public function test_in_utf_mode_it_is_a_code_point(): void
    {
        $this->assertSame(0, @preg_match('/\\666/u', ''));
        $this->assertTrue(Regex::create(['cache' => null])->validate('/\\666/u')->isValid);
    }
}
