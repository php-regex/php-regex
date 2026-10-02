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

namespace PHPRegex\Tests\Unit\Automata;

use PHPRegex\Automata\Unicode\CodePointHelper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The ends of the code point space: NUL is a character a witness may name,
 * the space above U+10FFFF is not, and a negative number is no code point
 * at all.
 */
final class CodePointHelperBoundaryTest extends TestCase
{
    #[Test]
    public function test_the_null_byte_is_a_character(): void
    {
        $this->assertSame("\x00", CodePointHelper::toString(0));
    }

    #[Test]
    public function test_the_last_code_point_is_a_character(): void
    {
        $this->assertSame("\u{10FFFF}", CodePointHelper::toString(0x10FFFF));
    }

    #[Test]
    #[DataProvider('provideNonCharacters')]
    public function test_a_non_character_has_no_string(int $codePoint): void
    {
        $this->assertNull(CodePointHelper::toString($codePoint));
    }

    /**
     * @return iterable<string, array{codePoint: int}>
     */
    public static function provideNonCharacters(): iterable
    {
        yield 'below the space' => ['codePoint' => -1];
        yield 'one past the end' => ['codePoint' => 0x110000];
    }
}
