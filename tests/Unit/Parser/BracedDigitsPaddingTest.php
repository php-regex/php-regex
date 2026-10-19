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
 * Spaces and tabs inside "\x{...}", "\o{...}" and "\N{U+...}", which PCRE2
 * reads from 10.43. Before, a space is a character no digit is: it is
 * refused on it, wherever it stands ("non-hex character in \x{} (closing
 * brace missing?)"), and "\N{" then a space is no code point, but a
 * character name PCRE2 does not support, refused past the "\N". From 10.47,
 * "\N{U+" without UTF is refused where PCRE2 stops reading it: past the hex
 * digits right after "U+", the spaces and tabs after them, and a "}" if one
 * follows ("\N{U+dddd} is supported only in Unicode (UTF) mode").
 *
 * Every row was run through pcre2test 10.32 to 10.49, under "X" for PHP 7.3.
 */
final class BracedDigitsPaddingTest extends TestCase
{
    #[Test]
    #[DataProvider('provideRefusals')]
    public function test_a_braced_escape_is_refused_where_pcre2_stops_reading_it(string $pattern, string $php, string $release, ErrorCode $code, int $offset): void
    {
        $result = RegexParser::create(['cache' => null, 'php_version' => $php, 'pcre_version' => $release, 'runtime_pcre_validation' => false])->validate($pattern);

        $this->assertFalse($result->isValid, \sprintf('%s on PHP %s with PCRE2 %s', $pattern, $php, $release));
        $this->assertSame($code, $result->errorCode, \sprintf('%s on PHP %s with PCRE2 %s: %s', $pattern, $php, $release, (string) $result->error));
        $this->assertSame($offset, $result->offset, \sprintf('%s on PHP %s with PCRE2 %s', $pattern, $php, $release));
    }

    /**
     * @return iterable<string, array{pattern: string, php: string, release: string, code: ErrorCode, offset: int}>
     */
    public static function provideRefusals(): iterable
    {
        // Before 10.43, on the space.
        yield 'space between hex digits' => ['pattern' => '/\x{4 1}/', 'php' => '8.2', 'release' => '10.40', 'code' => ErrorCode::UnicodeInvalidDigit, 'offset' => 4];
        yield 'space between hex digits under X' => ['pattern' => '/\x{4 1}/X', 'php' => '7.3', 'release' => '10.32', 'code' => ErrorCode::UnicodeInvalidDigit, 'offset' => 4];
        yield 'space before a letter after hex digits' => ['pattern' => '/a\x{41 x}/', 'php' => '8.3', 'release' => '10.42', 'code' => ErrorCode::UnicodeInvalidDigit, 'offset' => 6];
        yield 'space alone in hex braces' => ['pattern' => '/\x{ }/', 'php' => '8.2', 'release' => '10.40', 'code' => ErrorCode::UnicodeInvalidDigit, 'offset' => 3];
        yield 'space before a bad hex digit' => ['pattern' => '/\x{ 4g}/', 'php' => '8.0', 'release' => '10.35', 'code' => ErrorCode::UnicodeInvalidDigit, 'offset' => 3];
        yield 'space between octal digits' => ['pattern' => '/\o{4 1}/', 'php' => '8.3', 'release' => '10.42', 'code' => ErrorCode::OctalInvalidDigit, 'offset' => 4];
        yield 'space alone in octal braces' => ['pattern' => '/a\o{ }/', 'php' => '8.2', 'release' => '10.40', 'code' => ErrorCode::OctalInvalidDigit, 'offset' => 4];
        yield 'space between code point digits' => ['pattern' => '/\N{U+4 1}/u', 'php' => '8.3', 'release' => '10.42', 'code' => ErrorCode::UnicodeInvalidDigit, 'offset' => 6];
        yield 'space before a letter after code point digits' => ['pattern' => '/\N{U+41 x}/u', 'php' => '8.2', 'release' => '10.40', 'code' => ErrorCode::UnicodeInvalidDigit, 'offset' => 7];
        yield 'space in hex braces ending a range' => ['pattern' => '/[a-\x{41 x}]/', 'php' => '8.2', 'release' => '10.40', 'code' => ErrorCode::UnicodeInvalidDigit, 'offset' => 8];

        // Before 10.43 "\N{" then a space is a character name.
        yield 'space before U+ and its digits' => ['pattern' => '/\N{ U+ 41}/', 'php' => '8.3', 'release' => '10.42', 'code' => ErrorCode::EscapeUnsupported, 'offset' => 2];
        yield 'space before U+ and its digits under UTF' => ['pattern' => '/\N{ U+ 41}/u', 'php' => '8.2', 'release' => '10.40', 'code' => ErrorCode::EscapeUnsupported, 'offset' => 2];
        yield 'space before U+ and a bad digit under UTF' => ['pattern' => '/a\N{ U+41 x}/u', 'php' => '8.3', 'release' => '10.42', 'code' => ErrorCode::EscapeUnsupported, 'offset' => 3];
        yield 'space before U+ ending a range' => ['pattern' => '/[a-\N{ U+4 1}]/', 'php' => '8.2', 'release' => '10.40', 'code' => ErrorCode::EscapeUnsupported, 'offset' => 5];
        yield 'padded code point without UTF' => ['pattern' => '/\N{ U+41 }/', 'php' => '8.2', 'release' => '10.40', 'code' => ErrorCode::EscapeUnsupported, 'offset' => 2];
        yield 'padded code point without UTF ending a range' => ['pattern' => '/[a-\N{ U+41 }]/', 'php' => '8.3', 'release' => '10.42', 'code' => ErrorCode::EscapeUnsupported, 'offset' => 5];

        // From 10.47 without UTF: past the digits, the padding, the "}".
        yield 'space after U+' => ['pattern' => '/\N{U+ 41}/', 'php' => '8.4', 'release' => '10.47', 'code' => ErrorCode::UnicodeNamedRequiresUtf, 'offset' => 6];
        yield 'space after U+, after a letter' => ['pattern' => '/a\N{U+ 41}/', 'php' => '8.5', 'release' => '10.49', 'code' => ErrorCode::UnicodeNamedRequiresUtf, 'offset' => 7];
        yield 'space between code point digits without UTF' => ['pattern' => '/\N{U+4 1}/', 'php' => '8.4', 'release' => '10.47', 'code' => ErrorCode::UnicodeNamedRequiresUtf, 'offset' => 7];
        yield 'spaces around code point digits without UTF' => ['pattern' => '/\N{U+ 41 }/', 'php' => '8.4', 'release' => '10.48', 'code' => ErrorCode::UnicodeNamedRequiresUtf, 'offset' => 6];
        yield 'space and a bad digit after U+' => ['pattern' => '/\N{U+ 4g}/', 'php' => '8.4', 'release' => '10.47', 'code' => ErrorCode::UnicodeNamedRequiresUtf, 'offset' => 6];
        yield 'spaces before and after U+' => ['pattern' => '/\N{ U+ 41}/', 'php' => '8.4', 'release' => '10.47', 'code' => ErrorCode::UnicodeNamedRequiresUtf, 'offset' => 7];
        yield 'space after U+ in a class' => ['pattern' => '/[\N{U+ 41}]/', 'php' => '8.4', 'release' => '10.47', 'code' => ErrorCode::UnicodeNamedRequiresUtf, 'offset' => 7];
        yield 'space between code point digits ending a range' => ['pattern' => '/[a-\N{U+4 1}]/', 'php' => '8.4', 'release' => '10.47', 'code' => ErrorCode::UnicodeNamedRequiresUtf, 'offset' => 10];

        // A code point too large: PCRE2 stops on the digit that takes it
        // past U+10FFFF under UTF, past 0xFF otherwise, skips the spaces
        // after it from 10.43, then the digits left, and refuses it there,
        // whatever follows ("character code point value in \x{} or \o{} is
        // too large").
        yield 'hex too large before a space' => ['pattern' => '/\x{110000 }/', 'php' => '8.4', 'release' => '10.44', 'code' => ErrorCode::UnicodeOutOfRange, 'offset' => 9];
        yield 'hex too large before a space under UTF, PCRE2 10.40' => ['pattern' => '/\x{110000 }/u', 'php' => '8.2', 'release' => '10.40', 'code' => ErrorCode::UnicodeOutOfRange, 'offset' => 9];
        yield 'hex too large on its last digit before a space under UTF' => ['pattern' => '/\x{110000 }/u', 'php' => '8.4', 'release' => '10.44', 'code' => ErrorCode::UnicodeOutOfRange, 'offset' => 10];
        yield 'padded hex too large' => ['pattern' => '/\x{ 10FFFF }/', 'php' => '8.4', 'release' => '10.47', 'code' => ErrorCode::OctalOutOfRange, 'offset' => 10];
        yield 'many hex digits before a space under UTF' => ['pattern' => '/\x{FFFFFFFFF }/u', 'php' => '8.4', 'release' => '10.44', 'code' => ErrorCode::UnicodeOutOfRange, 'offset' => 12];
        yield 'leading zeros and hex too large before a space' => ['pattern' => '/\x{0000100 }/', 'php' => '8.2', 'release' => '10.40', 'code' => ErrorCode::OctalOutOfRange, 'offset' => 10];
        yield 'code point too large before a space' => ['pattern' => '/\N{U+110000 }/u', 'php' => '8.3', 'release' => '10.42', 'code' => ErrorCode::UnicodeOutOfRange, 'offset' => 11];
        yield 'hex too large before a space and a letter' => ['pattern' => '/\x{65536 x}/', 'php' => '8.2', 'release' => '10.40', 'code' => ErrorCode::OctalOutOfRange, 'offset' => 8];
        yield 'hex too large before a comma' => ['pattern' => '/\x{65536,}/', 'php' => '8.4', 'release' => '10.47', 'code' => ErrorCode::OctalOutOfRange, 'offset' => 8];
        yield 'hex too large on its last digit before a space and a letter' => ['pattern' => '/\x{100 x}/', 'php' => '8.4', 'release' => '10.44', 'code' => ErrorCode::OctalOutOfRange, 'offset' => 7];
        yield 'hex too large on its last digit before a space and a letter, PCRE2 10.40' => ['pattern' => '/\x{100 x}/', 'php' => '8.2', 'release' => '10.40', 'code' => ErrorCode::OctalOutOfRange, 'offset' => 6];
        yield 'octal too large before a space and a letter' => ['pattern' => '/\o{400 x}/', 'php' => '8.4', 'release' => '10.47', 'code' => ErrorCode::OctalOutOfRange, 'offset' => 7];
        yield 'octal too large before a space under UTF, PCRE2 10.40' => ['pattern' => '/\o{7777777 }/u', 'php' => '8.2', 'release' => '10.40', 'code' => ErrorCode::OctalOutOfRange, 'offset' => 10];
        yield 'hex too large before a letter under UTF' => ['pattern' => '/\x{110000x}/u', 'php' => '8.5', 'release' => '10.49', 'code' => ErrorCode::UnicodeOutOfRange, 'offset' => 9];
        yield 'code point too large before a letter' => ['pattern' => '/\N{U+110000x}/u', 'php' => '8.4', 'release' => '10.47', 'code' => ErrorCode::UnicodeOutOfRange, 'offset' => 11];
    }
}
