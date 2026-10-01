<?php

declare(strict_types=1);

/*
 * This file is part of the PhpRegex package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpRegex\Tests\Unit\Internal;

use PhpRegex\Parser\Internal\DisplayEscaper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DisplayEscaperTest extends TestCase
{
    #[Test]
    #[DataProvider('provideEscapedText')]
    public function test_escape_only_rewrites_unprintable_bytes(string $text, string $expected): void
    {
        $this->assertSame($expected, DisplayEscaper::escape($text));
    }

    #[Test]
    public function test_escape_keeps_utf8_patterns_equivalent(): void
    {
        $pattern = '/《붉은별》/iu';

        $this->assertSame($pattern, DisplayEscaper::escape($pattern));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideEscapedText(): iterable
    {
        yield 'ascii is untouched' => ['/[a-z]+/i', '/[a-z]+/i'];
        yield 'control chars are escaped' => ["/a\tb\nc/", '/a\tb\nc/'];
        yield 'del is escaped' => ["/a\177b/", '/a\177b/'];
        yield 'utf8 is preserved' => ['/«»“”/u', '/«»“”/u'];
        yield 'invalid utf8 is escaped' => ["/x\x80\xFEy/", '/x\200\376y/'];
    }

    #[Test]
    public function test_quote_wraps_a_sample_and_escapes_what_would_break_the_layout(): void
    {
        $this->assertSame('"" (empty string)', DisplayEscaper::quote(''));
        $this->assertSame('"a\\nb"', DisplayEscaper::quote("a\nb"));
        $this->assertSame('"\\x00\\x1F"', DisplayEscaper::quote("\x00\x1F"));
        $this->assertSame('"\\\\ \\""', DisplayEscaper::quote('\\ "'));
    }

    #[Test]
    public function test_quote_puts_the_markup_around_the_quotes(): void
    {
        $this->assertSame('<fg=cyan>"ok"</>', DisplayEscaper::quote('ok', '<fg=cyan>', '</>'));
        // An empty sample says so in words, with no markup to colour.
        $this->assertSame('"" (empty string)', DisplayEscaper::quote('', '<fg=cyan>', '</>'));
    }
}
