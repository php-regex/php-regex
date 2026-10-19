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
 * Without UTF mode PCRE reads the pattern byte by byte: a multibyte
 * character ending a range ends it on its first byte, its other bytes are
 * members, and a "-" after it starts a range from its last byte.
 *
 * Every row was run through pcre2test 10.32 to 10.49 (and php:7.3-cli,
 * php:7.4-cli without X): "range out of order in character class at offset
 * 6" for "[a-é-a]" up to 10.46, at offset 7 from 10.47; "invalid range in
 * character class at offset 8" for "[a-é-\d]" on every release.
 */
final class ByteModeRangeEndTest extends TestCase
{
    #[Test]
    #[DataProvider('provideRefusals')]
    public function test_a_hyphen_after_a_multibyte_range_end_starts_a_range_from_its_last_byte(string $pattern, string $php, string $release, ErrorCode $code, int $offset): void
    {
        $result = self::parser($php, $release)->validate($pattern);

        $this->assertFalse($result->isValid, \sprintf('%s on PHP %s with PCRE2 %s', $pattern, $php, $release));
        $this->assertSame($code, $result->errorCode, \sprintf('%s on PHP %s with PCRE2 %s: %s', $pattern, $php, $release, (string) $result->error));
        $this->assertSame($offset, $result->offset, \sprintf('%s on PHP %s with PCRE2 %s', $pattern, $php, $release));
    }

    /**
     * @return iterable<string, array{pattern: string, php: string, release: string, code: ErrorCode, offset: int}>
     */
    public static function provideRefusals(): iterable
    {
        foreach ([['7.3', '10.32', 6], ['7.4', '10.35', 6], ['8.2', '10.40', 6], ['8.4', '10.44', 6], ['8.4', '10.45', 6], ['8.4', '10.47', 7], ['8.5', '10.49', 7]] as [$php, $release, $offset]) {
            yield \sprintf('two-byte character ending a range before a range to ASCII, PCRE2 %s', $release) => ['pattern' => '/[a-é-a]/'.($php < '8' ? 'X' : ''), 'php' => $php, 'release' => $release, 'code' => ErrorCode::RangeReversed, 'offset' => $offset];
        }

        yield 'three-byte character ending a range before a range to ASCII' => ['pattern' => '/[a-€-a]/', 'php' => '8.4', 'release' => '10.44', 'code' => ErrorCode::RangeReversed, 'offset' => 7];
        yield 'three-byte character ending a range before a range to a lower byte' => ['pattern' => '/[a-€-\x{ab}]/', 'php' => '8.4', 'release' => '10.44', 'code' => ErrorCode::RangeReversed, 'offset' => 12];
        yield 'two-byte character ending a range from an escape' => ['pattern' => '/[\x{41}-é-a]/', 'php' => '8.4', 'release' => '10.47', 'code' => ErrorCode::RangeReversed, 'offset' => 12];
        yield 'two-byte character ending a range, x on' => ['pattern' => '/[a-é-a]/x', 'php' => '8.4', 'release' => '10.44', 'code' => ErrorCode::RangeReversed, 'offset' => 6];
        yield 'quoted two-byte character ending a range' => ['pattern' => '/[a-\Qé\E-a]/', 'php' => '8.4', 'release' => '10.44', 'code' => ErrorCode::RangeReversed, 'offset' => 10];
        yield 'two-byte character ending a range, a quote end before the hyphen' => ['pattern' => '/[a-é\E-a]/', 'php' => '8.4', 'release' => '10.47', 'code' => ErrorCode::RangeReversed, 'offset' => 9];
        yield 'two-byte character ending a range before a range to a quoted hyphen' => ['pattern' => '/[a-é-\Q-\E]/', 'php' => '8.4', 'release' => '10.44', 'code' => ErrorCode::RangeReversed, 'offset' => 8];
        yield 'two-byte character ending a range before a range to a control character' => ['pattern' => '/[a-é-\cA]/', 'php' => '8.4', 'release' => '10.44', 'code' => ErrorCode::RangeReversed, 'offset' => 8];

        foreach ([['7.3', '10.32'], ['8.4', '10.44'], ['8.4', '10.45'], ['8.5', '10.49']] as [$php, $release]) {
            yield \sprintf('two-byte character ending a range before a range to a type, PCRE2 %s', $release) => ['pattern' => '/[a-é-\d]/'.($php < '8' ? 'X' : ''), 'php' => $php, 'release' => $release, 'code' => ErrorCode::RangeInvalidBounds, 'offset' => 8];
        }

        // A reversed range to a multibyte character ends on its first byte,
        // where PCRE refuses it: "range out of order in character class at
        // offset 6" for "[\xff-ɐ]" up to 10.46, at offset 7 from 10.47.
        foreach ([['7.3', '10.32', 6], ['8.4', '10.44', 6], ['8.4', '10.47', 7]] as [$php, $release, $offset]) {
            yield \sprintf('reversed range to a two-byte character, PCRE2 %s', $release) => ['pattern' => '/[\xff-ɐ]/'.($php < '8' ? 'X' : ''), 'php' => $php, 'release' => $release, 'code' => ErrorCode::RangeReversed, 'offset' => $offset];
        }

        yield 'reversed range to a three-byte character' => ['pattern' => '/[\xff-€]/', 'php' => '8.4', 'release' => '10.44', 'code' => ErrorCode::RangeReversed, 'offset' => 6];
        yield 'reversed range to a four-byte character' => ['pattern' => '/[\xff-𐀀]/', 'php' => '8.4', 'release' => '10.47', 'code' => ErrorCode::RangeReversed, 'offset' => 7];
        yield 'reversed range to an escaped two-byte character' => ['pattern' => '/[\xff-\é]/', 'php' => '8.4', 'release' => '10.44', 'code' => ErrorCode::RangeReversed, 'offset' => 7];
        yield 'reversed range to a quoted two-byte character' => ['pattern' => '/[\xff-\Qé\E]/', 'php' => '8.4', 'release' => '10.44', 'code' => ErrorCode::RangeReversed, 'offset' => 8];
        yield 'reversed range to a two-byte character, class left open' => ['pattern' => '/[\xff-ɐ/', 'php' => '8.4', 'release' => '10.44', 'code' => ErrorCode::RangeReversed, 'offset' => 6];
        yield 'reversed range to a two-byte character, class left open, PCRE2 10.47' => ['pattern' => '/[\xff-ɐ/', 'php' => '8.4', 'release' => '10.47', 'code' => ErrorCode::RangeReversed, 'offset' => 7];
        yield 'two-byte character ending a range before a range to ASCII, class left open' => ['pattern' => '/[a-é-a/', 'php' => '8.4', 'release' => '10.44', 'code' => ErrorCode::RangeReversed, 'offset' => 6];
        yield 'two-byte character ending a range before a range to ASCII, class left open, PCRE2 10.47' => ['pattern' => '/[a-é-a/', 'php' => '8.4', 'release' => '10.47', 'code' => ErrorCode::RangeReversed, 'offset' => 7];
        yield 'reversed range to a two-byte character after a four-byte one' => ['pattern' => '/[𐀀\xff-ê]/i', 'php' => '8.4', 'release' => '10.44', 'code' => ErrorCode::RangeReversed, 'offset' => 10];

        // PHP 7.3 without X reads a class escape ending a range as its
        // letter on PCRE2 10.32 (php:7.3-cli: "range out of order in
        // character class at offset 7").
        yield 'two-byte character ending a range before a range to a type, PHP 7.3' => ['pattern' => '/[a-é-\d]/', 'php' => '7.3', 'release' => '10.32', 'code' => ErrorCode::RangeReversed, 'offset' => 7];

        yield 'two-byte character ending a range before a range to a POSIX class' => ['pattern' => '/[a-é-[:alpha:]]/', 'php' => '8.4', 'release' => '10.44', 'code' => ErrorCode::RangeInvalidBounds, 'offset' => 7];
        yield 'two-byte character ending a range before a range to a POSIX class, PCRE2 10.45' => ['pattern' => '/[a-é-[:alpha:]]/', 'php' => '8.4', 'release' => '10.45', 'code' => ErrorCode::RangeInvalidBounds, 'offset' => 15];
        yield 'two-byte character ending a range before a range to a property' => ['pattern' => '/[a-é-\pL]/', 'php' => '8.4', 'release' => '10.44', 'code' => ErrorCode::RangeInvalidBounds, 'offset' => 8];
        yield 'two-byte character ending a range before a range to a property, PCRE2 10.45' => ['pattern' => '/[a-é-\pL]/', 'php' => '8.4', 'release' => '10.45', 'code' => ErrorCode::RangeInvalidBounds, 'offset' => 9];
    }

    /**
     * The range runs from the middle of a character: its text is spelled a
     * byte at a time, so the message stays valid UTF-8.
     */
    #[Test]
    #[DataProvider('provideRangesFromTheLastByte')]
    public function test_a_range_from_the_last_byte_is_described_in_valid_utf8(string $pattern, string $range): void
    {
        foreach ([['8.4', '10.44'], ['8.5', '10.49']] as [$php, $release]) {
            $error = (string) self::parser($php, $release)->validate($pattern)->error;

            $this->assertTrue(mb_check_encoding($error, 'UTF-8'), \sprintf('%s on PCRE2 %s: %s', $pattern, $release, bin2hex($error)));
            $this->assertStringContainsString('"'.$range.'"', $error, $pattern);
        }
    }

    /**
     * @return iterable<string, array{pattern: string, range: string}>
     */
    public static function provideRangesFromTheLastByte(): iterable
    {
        yield 'two-byte character' => ['pattern' => '/[a-é-a]/', 'range' => '\xA9-a'];
        yield 'two-byte character after another range to it' => ['pattern' => '/[!-é-é-a]/', 'range' => '\xA9-a'];
        yield 'three-byte character' => ['pattern' => '/[a-€-\x01]/', 'range' => '\xAC-\x01'];
    }

    #[Test]
    #[DataProvider('provideAccepted')]
    public function test_a_range_from_the_last_byte_in_order_compiles(string $pattern): void
    {
        foreach ([['7.3', '10.32'], ['8.4', '10.44'], ['8.4', '10.47']] as [$php, $release]) {
            $result = self::parser($php, $release)->validate($pattern.($php < '8' ? 'X' : ''));

            $this->assertTrue($result->isValid, \sprintf('%s on PHP %s with PCRE2 %s: %s', $pattern, $php, $release, (string) $result->error));
        }
    }

    /**
     * pcre2test 10.32 to 10.49 compile each.
     *
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideAccepted(): iterable
    {
        yield 'range from the last byte to a higher character' => ['pattern' => '/[a-é-ê]/'];
        yield 'range from the last byte of a three-byte character to a higher byte' => ['pattern' => '/[a-€-\x{ad}]/'];
        yield 'range from the last byte to the last byte' => ['pattern' => '/[a-é-\xff]/'];
        yield 'hyphen before the end of the class' => ['pattern' => '/[a-é-]/'];
        yield 'quoted hyphen and its end' => ['pattern' => '/[a-\Qé-a\E]/'];
        yield 'UTF mode' => ['pattern' => '/[a-é-a]/u'];
        yield 'UTF mode from the start of the pattern' => ['pattern' => '/(*UTF)[a-é-a]/'];
        yield 'three ranges in a row' => ['pattern' => '/[a-é-ê-\xff]/'];
    }

    private static function parser(string $php, string $release): RegexParser
    {
        return RegexParser::create(['cache' => null, 'php_version' => $php, 'pcre_version' => $release, 'runtime_pcre_validation' => false]);
    }
}
