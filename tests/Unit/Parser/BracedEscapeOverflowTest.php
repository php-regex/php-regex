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
 * PCRE reads the digits of "\o{" and "\x{" before it looks for the "}": a
 * value past 0xFF without UTF, past U+10FFFF with it, is refused where the
 * digits end, whether a "}" closes them or not; from PCRE2 10.43 past the
 * spaces and tabs after them.
 *
 * Every row was run through pcre2test 10.32 to 10.49: "character code
 * point value in \x{} or \o{} is too large at offset 6" for "\o{400" and
 * "\o{400]" on every release; "at offset 8" for "\o{400  x" from 10.43, 6
 * before.
 */
final class BracedEscapeOverflowTest extends TestCase
{
    #[Test]
    #[DataProvider('provideRefusals')]
    public function test_a_value_too_large_is_refused_before_the_brace(string $pattern, string $php, string $release, ErrorCode $code, int $offset): void
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
        foreach ([['7.3', '10.32'], ['8.2', '10.40'], ['8.4', '10.44'], ['8.4', '10.47'], ['8.5', '10.49']] as [$php, $release]) {
            yield \sprintf('octal past a byte, no brace, PCRE2 %s', $release) => ['pattern' => '/\o{400/'.($php < '8' ? 'X' : ''), 'php' => $php, 'release' => $release, 'code' => ErrorCode::OctalOutOfRange, 'offset' => 6];
            yield \sprintf('octal past a byte in a class, no brace, PCRE2 %s', $release) => ['pattern' => '/[\o{400/'.($php < '8' ? 'X' : ''), 'php' => $php, 'release' => $release, 'code' => ErrorCode::OctalOutOfRange, 'offset' => 7];
        }

        // A value past what a PHP integer holds is still too large
        // (pcre2test 10.32 to 10.49: "too large at offset 19" for
        // "\x{8000000000000000}").
        yield 'hex past a PHP integer, closed' => ['pattern' => '/\x{8000000000000000}/', 'php' => '8.4', 'release' => '10.44', 'code' => ErrorCode::UnicodeOutOfRange, 'offset' => 19];
        yield 'hex past a PHP integer under UTF, closed' => ['pattern' => '/\x{ffffffffffffffff}/u', 'php' => '8.5', 'release' => '10.49', 'code' => ErrorCode::UnicodeOutOfRange, 'offset' => 19];
        yield 'octal past a PHP integer, closed' => ['pattern' => '/\o{1000000000000000000000}/', 'php' => '8.4', 'release' => '10.47', 'code' => ErrorCode::OctalOutOfRange, 'offset' => 25];
        yield 'hex past a PHP integer ending a range' => ['pattern' => '/[a-\x{8000000000000000}]/', 'php' => '8.2', 'release' => '10.40', 'code' => ErrorCode::UnicodeOutOfRange, 'offset' => 22];
        yield 'hex past a PHP integer before an unclosed property' => ['pattern' => '/[\x{8000000000000000}\p{L/', 'php' => '8.4', 'release' => '10.44', 'code' => ErrorCode::UnicodeOutOfRange, 'offset' => 20];
        yield 'octal past a byte before a bracket' => ['pattern' => '/\o{400]/', 'php' => '8.4', 'release' => '10.47', 'code' => ErrorCode::OctalOutOfRange, 'offset' => 6];
        yield 'octal past a byte in a class before its bracket' => ['pattern' => '/[\o{400]/', 'php' => '8.4', 'release' => '10.44', 'code' => ErrorCode::OctalOutOfRange, 'offset' => 7];
        yield 'octal past a byte before a class escape' => ['pattern' => '/\o{400\d/', 'php' => '8.4', 'release' => '10.44', 'code' => ErrorCode::OctalOutOfRange, 'offset' => 6];
        yield 'octal past a byte, the digits after it read' => ['pattern' => '/\o{4007/', 'php' => '8.4', 'release' => '10.48', 'code' => ErrorCode::OctalOutOfRange, 'offset' => 7];
        yield 'octal past a byte before a digit that is no octal one' => ['pattern' => '/\o{4008/', 'php' => '8.4', 'release' => '10.44', 'code' => ErrorCode::OctalOutOfRange, 'offset' => 6];
        yield 'octal past a byte after leading zeros' => ['pattern' => '/\o{0000400/', 'php' => '8.4', 'release' => '10.47', 'code' => ErrorCode::OctalOutOfRange, 'offset' => 10];
        yield 'octal past Unicode' => ['pattern' => '/\o{4000000000/u', 'php' => '8.4', 'release' => '10.44', 'code' => ErrorCode::OctalOutOfRange, 'offset' => 13];
        yield 'hex past a byte, no brace' => ['pattern' => '/\x{FFFF/', 'php' => '8.4', 'release' => '10.44', 'code' => ErrorCode::OctalOutOfRange, 'offset' => 7];
        yield 'hex past a byte in a class, no brace' => ['pattern' => '/[\x{FFFF/', 'php' => '8.4', 'release' => '10.47', 'code' => ErrorCode::OctalOutOfRange, 'offset' => 8];
        yield 'hex past Unicode, no brace' => ['pattern' => '/\x{110000/u', 'php' => '8.4', 'release' => '10.48', 'code' => ErrorCode::UnicodeOutOfRange, 'offset' => 9];

        // Spaces and tabs after the digits are read from PCRE2 10.43.
        yield 'octal past a byte before spaces' => ['pattern' => '/\o{400  x/', 'php' => '8.2', 'release' => '10.42', 'code' => ErrorCode::OctalOutOfRange, 'offset' => 6];
        yield 'octal past a byte before spaces, PCRE2 10.43' => ['pattern' => '/\o{400  x/', 'php' => '8.4', 'release' => '10.43', 'code' => ErrorCode::OctalOutOfRange, 'offset' => 8];
        // Under UTF, from PCRE2 10.47 a surrogate is refused before the "}"
        // is looked for, past the digits and the spaces after them
        // (pcre2test: "disallowed Unicode code point (>= 0xd800 && <=
        // 0xdfff) at offset 7" for "\x{d800"; before 10.47 the brace is
        // missing, "non-hex character in \x{} (closing brace missing?) at
        // offset 6").
        yield 'surrogate, no brace, PCRE2 10.47' => ['pattern' => '/\x{d800/u', 'php' => '8.4', 'release' => '10.47', 'code' => ErrorCode::UnicodeSurrogate, 'offset' => 7];
        yield 'surrogate, no brace, PCRE2 10.49' => ['pattern' => '/\x{dfff/u', 'php' => '8.5', 'release' => '10.49', 'code' => ErrorCode::UnicodeSurrogate, 'offset' => 7];
        yield 'surrogate in a class, no brace' => ['pattern' => '/[\x{d800/u', 'php' => '8.4', 'release' => '10.48', 'code' => ErrorCode::UnicodeSurrogate, 'offset' => 8];
        yield 'surrogate before a bracket' => ['pattern' => '/\x{d800]/u', 'php' => '8.4', 'release' => '10.47', 'code' => ErrorCode::UnicodeSurrogate, 'offset' => 7];
        yield 'octal surrogate, no brace' => ['pattern' => '/\o{154000/u', 'php' => '8.4', 'release' => '10.47', 'code' => ErrorCode::UnicodeSurrogate, 'offset' => 9];
        yield 'surrogate, UTF from the start of the pattern' => ['pattern' => '/(*UTF)\x{d800/', 'php' => '8.4', 'release' => '10.49', 'code' => ErrorCode::UnicodeSurrogate, 'offset' => 13];
        // "\N{U+" too (pcre2test 10.47 and 10.49: error 173 at offset 9 for
        // "\N{U+D800", 10 once a space follows; 10.46: error 167 at 8 and 9).
        yield 'named surrogate, no brace' => ['pattern' => '/\N{U+D800/u', 'php' => '8.4', 'release' => '10.47', 'code' => ErrorCode::UnicodeSurrogate, 'offset' => 9];
        yield 'named surrogate and a space, no brace' => ['pattern' => '/\N{U+D800 /u', 'php' => '8.5', 'release' => '10.49', 'code' => ErrorCode::UnicodeSurrogate, 'offset' => 10];
        yield 'named surrogate, no brace, PCRE2 10.46' => ['pattern' => '/\N{U+D800/u', 'php' => '8.4', 'release' => '10.46', 'code' => ErrorCode::UnicodeInvalidDigit, 'offset' => 8];
        yield 'surrogate, no brace, PCRE2 10.46' => ['pattern' => '/\x{d800/u', 'php' => '8.4', 'release' => '10.46', 'code' => ErrorCode::UnicodeInvalidDigit, 'offset' => 6];

        // Before PCRE2 10.43 no space is read in the braces: the first one is
        // refused, after a value too large is (pcre2test 10.32 to 10.42:
        // "non-hex character in \x{} (closing brace missing?) at offset 3"
        // for "\x{ d800  x" under UTF; "character code point value in \x{}
        // or \o{} is too large at offset 9" for "[\d\x{777 }").
        yield 'space first in the braces, before PCRE2 10.43' => ['pattern' => '/\x{ d800  x/u', 'php' => '8.2', 'release' => '10.40', 'code' => ErrorCode::UnicodeInvalidDigit, 'offset' => 3];
        yield 'space first in octal braces in a class, before PCRE2 10.43' => ['pattern' => '/[\o{ 41\d/u', 'php' => '8.3', 'release' => '10.42', 'code' => ErrorCode::OctalInvalidDigit, 'offset' => 4];
        yield 'space after the octal digits, before PCRE2 10.43' => ['pattern' => '/\o{400  x/u', 'php' => '8.0', 'release' => '10.35', 'code' => ErrorCode::OctalInvalidDigit, 'offset' => 6];
        yield 'space after the digits ending a range, before PCRE2 10.43' => ['pattern' => '/[a-\x{7  x/u', 'php' => '8.2', 'release' => '10.40', 'code' => ErrorCode::UnicodeInvalidDigit, 'offset' => 7];
        yield 'hex past a byte before a space and a brace, before PCRE2 10.43' => ['pattern' => '/[\d\x{777 }/', 'php' => '8.2', 'release' => '10.40', 'code' => ErrorCode::OctalOutOfRange, 'offset' => 9];
        yield 'hex past a byte before its last digit, a space and a brace, before PCRE2 10.43' => ['pattern' => '/a\x{4007 }/', 'php' => '8.3', 'release' => '10.42', 'code' => ErrorCode::OctalOutOfRange, 'offset' => 8];
        yield 'octal past a byte before a space and a brace, before PCRE2 10.43' => ['pattern' => '/\o{400 }/', 'php' => '8.2', 'release' => '10.40', 'code' => ErrorCode::OctalOutOfRange, 'offset' => 6];

        // Closed, padded: the spaces are read only after the last digit
        // (pcre2test 10.43 to 10.49: "too large at offset 7" for
        // "\o{4007 }").
        yield 'octal past a byte before its last digit, padded and closed' => ['pattern' => '/\o{4007 }/', 'php' => '8.4', 'release' => '10.44', 'code' => ErrorCode::OctalOutOfRange, 'offset' => 7];
        yield 'hex past a byte before its last digit, padded and closed' => ['pattern' => '/\x{110000 }/', 'php' => '8.4', 'release' => '10.47', 'code' => ErrorCode::UnicodeOutOfRange, 'offset' => 9];
        yield 'hex past Unicode before its last digit, padded and closed' => ['pattern' => '/\x{ 4000000000 }/u', 'php' => '8.5', 'release' => '10.49', 'code' => ErrorCode::UnicodeOutOfRange, 'offset' => 14];
        yield 'hex past a byte, padded and closed' => ['pattern' => '/\x{FFFF }/', 'php' => '8.4', 'release' => '10.44', 'code' => ErrorCode::OctalOutOfRange, 'offset' => 7];
        yield 'octal past Unicode, padded and closed' => ['pattern' => '/\o{ 4000000000 }/u', 'php' => '8.4', 'release' => '10.44', 'code' => ErrorCode::OctalOutOfRange, 'offset' => 14];
        yield 'octal past a byte on its last digit, padded and closed' => ['pattern' => '/\o{400 }/', 'php' => '8.4', 'release' => '10.44', 'code' => ErrorCode::OctalOutOfRange, 'offset' => 7];
        yield 'octal past a byte before a space ending the pattern, PCRE2 10.43' => ['pattern' => '/\o{400 /', 'php' => '8.4', 'release' => '10.43', 'code' => ErrorCode::OctalOutOfRange, 'offset' => 7];
    }
}
