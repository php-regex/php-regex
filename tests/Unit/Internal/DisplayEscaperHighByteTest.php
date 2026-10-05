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

namespace PHPRegex\Tests\Unit\Internal;

use PHPRegex\Parser\Internal\DisplayEscaper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * In text that is no valid UTF-8, every byte above ASCII is printed as
 * "\xHH", the last one included, and a control byte keeps its own escape.
 * A raw byte would read back as itself too, so the spelling is pinned here,
 * not only the read-back.
 */
final class DisplayEscaperHighByteTest extends TestCase
{
    /**
     * The escape map is built once per process; each test builds it again,
     * so what it checks does not depend on a test run before it.
     */
    protected function setUp(): void
    {
        (new \ReflectionProperty(DisplayEscaper::class, 'escapes'))->setValue(null, []);
    }

    /**
     * @return iterable<string, array{text: string, shown: string}>
     */
    public static function provideInvalidUtf8(): iterable
    {
        yield 'first byte above ASCII' => ['text' => "\x80", 'shown' => '\x80'];
        yield 'last byte' => ['text' => "\xFF", 'shown' => '\xFF'];
        yield 'NUL next to the last byte' => ['text' => "\x00\xFF", 'shown' => '\x00\xFF'];
        yield 'letter between high bytes' => ['text' => "\xFEa\xFF", 'shown' => '\xFEa\xFF'];
    }

    #[Test]
    #[DataProvider('provideInvalidUtf8')]
    public function test_every_byte_of_invalid_utf8_is_spelled_in_hex(string $text, string $shown): void
    {
        $this->assertFalse(mb_check_encoding($text, 'UTF-8'));

        $this->assertSame($shown, DisplayEscaper::escape($text));
        // Oracle: the spelling reads back as the same bytes.
        $this->assertSame(1, preg_match('/^'.$shown.'$/', $text));
    }
}
