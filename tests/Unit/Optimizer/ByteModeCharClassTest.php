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

namespace PHPRegex\Tests\Unit\Optimizer;

use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Without /u a class reads bytes: a byte above 0x7F is no code point, and
 * the optimized class must match the bytes the pattern matches.
 */
final class ByteModeCharClassTest extends TestCase
{
    #[Test]
    #[DataProvider('provideByteClasses')]
    public function test_a_byte_class_keeps_its_bytes_once_optimized(string $pattern, string $subject, int $matches): void
    {
        // Oracle, PHP 8.4.26 / PCRE2 10.49.
        $this->assertSame($matches, preg_match($pattern, $subject), $pattern);

        $optimized = Regex::create()->optimize($pattern)->optimized;

        $this->assertSame($matches, preg_match($optimized, $subject), $pattern.' optimized as '.$optimized);
    }

    /**
     * @return iterable<string, array{pattern: string, subject: string, matches: int}>
     */
    public static function provideByteClasses(): iterable
    {
        // mb_ord() reads a lone high byte as false, so two of them met as
        // the code point 0: "[\xE9\xEA]" came out "[\x00]".
        yield 'two high bytes, first' => ['pattern' => '/[\xE9\xEA]/', 'subject' => "\xE9", 'matches' => 1];
        yield 'two high bytes, second' => ['pattern' => '/[\xE9\xEA]/', 'subject' => "\xEA", 'matches' => 1];
        yield 'two high bytes, not NUL' => ['pattern' => '/[\xE9\xEA]/', 'subject' => "\x00", 'matches' => 0];
        yield 'raw high bytes' => ['pattern' => "/[\xE9\xEA]/", 'subject' => "\xEA", 'matches' => 1];
        yield 'high byte beside ASCII' => ['pattern' => '/[a\xE9b]/', 'subject' => "\xE9", 'matches' => 1];
        yield 'duplicate high byte' => ['pattern' => '/[\xE9\xE9]/', 'subject' => "\xE9", 'matches' => 1];
        // The range loop counted up from false and never ended.
        yield 'range of high bytes, low end' => ['pattern' => '/[\x80-\xBF]/', 'subject' => "\x80", 'matches' => 1];
        yield 'range of high bytes, high end' => ['pattern' => '/[\x80-\xBF]/', 'subject' => "\xBF", 'matches' => 1];
        yield 'range of high bytes, outside' => ['pattern' => '/[\x80-\xBF]/', 'subject' => "\xC0", 'matches' => 0];
        yield 'range from ASCII to a high byte' => ['pattern' => '/[a-\xE9]/', 'subject' => "\xC3", 'matches' => 1];
        // The same classes written with raw bytes, which the parser reads as
        // one-byte literals.
        yield 'raw duplicate high byte' => ['pattern' => "/[\xE9\xE9]/", 'subject' => "\xE9", 'matches' => 1];
        yield 'raw high byte beside ASCII' => ['pattern' => "/[a\xE9b]/", 'subject' => "\xE9", 'matches' => 1];
        yield 'raw range of high bytes' => ['pattern' => "/[\x80-\xBF]/", 'subject' => "\xBF", 'matches' => 1];
        yield 'raw range of high bytes, outside' => ['pattern' => "/[\x80-\xBF]/", 'subject' => "\xC0", 'matches' => 0];
        yield 'raw range from ASCII to a high byte' => ['pattern' => "/[a-\xE9]/", 'subject' => "\xC3", 'matches' => 1];
    }

    #[Test]
    public function test_an_ascii_class_is_still_canonicalized(): void
    {
        $this->assertSame('/[a-d]/', Regex::create()->optimize('/[abcd]/')->optimized);
    }

    /**
     * Without /u "é" is two bytes: a group around it repeats both, and
     * dropping the group would repeat the last byte alone.
     */
    #[Test]
    public function test_a_group_around_a_multibyte_letter_stays_without_u(): void
    {
        $optimized = Regex::create()->optimize('/^(?:é)*x$/')->optimized;

        foreach (['ééx', 'éx', 'x', "\xC3\xA9\xA9x"] as $subject) {
            $this->assertSame(preg_match('/^(?:é)*x$/', $subject), preg_match($optimized, $subject), $optimized.' on '.bin2hex($subject));
        }
    }
}
