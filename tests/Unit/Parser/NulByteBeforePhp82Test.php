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

use PHPRegex\Parser\ErrorCode;
use PHPRegex\Parser\RegexParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Before PHP 8.2, php_pcre.c stops its scan of the pattern at a NUL byte
 * and refuses it ("Null byte in regex"): as the delimiter, in the body, or
 * among the modifiers. From PHP 8.2 a NUL in the body is a character like
 * any other (PHP 8.4.26: preg_match("/a\0b/", "a\0b") is 1).
 */
final class NulByteBeforePhp82Test extends TestCase
{
    #[Test]
    #[DataProvider('providePatternsHoldingANul')]
    public function test_php_8_1_refuses_a_nul_byte_anywhere_in_the_pattern(string $pattern, int $offset): void
    {
        $result = RegexParser::create(['php_version' => '8.1'])->validate($pattern);

        $this->assertFalse($result->isValid);
        $this->assertSame(ErrorCode::PatternNulByte, $result->errorCode);
        $this->assertSame($offset, $result->offset);
    }

    /**
     * @return iterable<string, array{pattern: string, offset: int}>
     */
    public static function providePatternsHoldingANul(): iterable
    {
        yield 'in the body' => ['pattern' => "/a\0b/", 'offset' => 1];
        yield 'after a backslash' => ['pattern' => "/a\\\0b/", 'offset' => 2];
        yield 'in a bracket-delimited body' => ['pattern' => "{a\0b}", 'offset' => 1];
        yield 'among the modifiers' => ['pattern' => "/a/i\0", 'offset' => 3];
        yield 'as the delimiter' => ['pattern' => "\0a\0", 'offset' => 0];
    }

    #[Test]
    public function test_php_8_2_reads_a_nul_byte_in_the_body(): void
    {
        $this->assertSame(1, preg_match("/a\0b/", "a\0b"));

        $this->assertTrue(RegexParser::create(['php_version' => '8.2'])->validate("/a\0b/")->isValid);
    }

    #[Test]
    public function test_php_8_1_still_reads_a_pattern_without_a_nul_byte(): void
    {
        $this->assertTrue(RegexParser::create(['php_version' => '8.1'])->validate('/a\x00b/')->isValid);
    }
}
