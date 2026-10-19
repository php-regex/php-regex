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
 * PCRE reads a class item by item before it meets the end of a pattern that
 * leaves the class open: an item it refuses, a code point too large or an
 * unknown POSIX class, is refused where it is in a closed class, before the
 * missing "]".
 *
 * Every row was run through pcre2test 10.32 to 10.49, on php:8.4-cli, and
 * on php:7.3-cli and php:7.4.0-cli under "X": "character code point value
 * in \x{} or \o{} is too large at offset 7" for "[\x{100}"; "unknown POSIX
 * class name at offset 3" for "[[:foo:]" up to 10.44, at offset 8 from
 * 10.45. Without "X", php:7.3-cli and php:7.4.0-cli read "\x{100}" as text
 * and miss the "]" at offset 8.
 */
final class ClassLeftOpenItemErrorTest extends TestCase
{
    #[Test]
    #[DataProvider('provideRefusals')]
    public function test_an_item_refused_in_a_class_left_open_comes_before_the_missing_bracket(string $pattern, string $php, string $release, ErrorCode $code, int $offset): void
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
        foreach ([['8.0', '10.35'], ['8.2', '10.40'], ['8.4', '10.44'], ['8.4', '10.45'], ['8.4', '10.47'], ['8.5', '10.49']] as [$php, $release]) {
            yield \sprintf('code point past a byte, PCRE2 %s', $release) => ['pattern' => '/[\x{100}/', 'php' => $php, 'release' => $release, 'code' => ErrorCode::OctalOutOfRange, 'offset' => 7];
        }

        yield 'code point past a byte under X, PCRE2 10.32' => ['pattern' => '/[\x{100}/X', 'php' => '7.3', 'release' => '10.32', 'code' => ErrorCode::OctalOutOfRange, 'offset' => 7];
        yield 'code point past a byte after a member' => ['pattern' => '/[a\x{100}/', 'php' => '8.4', 'release' => '10.44', 'code' => ErrorCode::OctalOutOfRange, 'offset' => 8];
        yield 'code point past a byte before a member' => ['pattern' => '/[\x{100}a/', 'php' => '8.4', 'release' => '10.44', 'code' => ErrorCode::OctalOutOfRange, 'offset' => 7];
        yield 'code point past a byte ending a range' => ['pattern' => '/[a-\x{100}/', 'php' => '8.2', 'release' => '10.40', 'code' => ErrorCode::OctalOutOfRange, 'offset' => 9];
        yield 'code point past a byte starting a range' => ['pattern' => '/[\x{100}-a/', 'php' => '8.4', 'release' => '10.47', 'code' => ErrorCode::OctalOutOfRange, 'offset' => 7];
        yield 'code point past a byte ending a range from a type, PCRE2 10.45' => ['pattern' => '/[\w-\x{100}/', 'php' => '8.4', 'release' => '10.45', 'code' => ErrorCode::OctalOutOfRange, 'offset' => 10];
        yield 'braced octal past a byte' => ['pattern' => '/[\o{400}/', 'php' => '8.4', 'release' => '10.44', 'code' => ErrorCode::OctalOutOfRange, 'offset' => 7];
        yield 'braced octal past a byte ending a range' => ['pattern' => '/[a-\o{400}/', 'php' => '8.4', 'release' => '10.44', 'code' => ErrorCode::OctalOutOfRange, 'offset' => 9];
        yield 'code point past Unicode' => ['pattern' => '/[\x{110000}/u', 'php' => '8.4', 'release' => '10.44', 'code' => ErrorCode::UnicodeOutOfRange, 'offset' => 10];
        yield 'code point past Unicode ending a range' => ['pattern' => '/[a-\x{110000}/u', 'php' => '8.4', 'release' => '10.44', 'code' => ErrorCode::UnicodeOutOfRange, 'offset' => 12];
        yield 'named code point past Unicode' => ['pattern' => '/[\N{U+110000}/u', 'php' => '8.4', 'release' => '10.44', 'code' => ErrorCode::UnicodeOutOfRange, 'offset' => 12];
        yield 'surrogate' => ['pattern' => '/[\x{d800}/u', 'php' => '8.4', 'release' => '10.44', 'code' => ErrorCode::UnicodeSurrogate, 'offset' => 8];
        yield 'unknown POSIX class, PCRE2 10.44' => ['pattern' => '/[[:foo:]/', 'php' => '8.4', 'release' => '10.44', 'code' => ErrorCode::PosixInvalid, 'offset' => 3];
        yield 'unknown POSIX class, PCRE2 10.32' => ['pattern' => '/[[:foo:]/', 'php' => '7.3', 'release' => '10.32', 'code' => ErrorCode::PosixInvalid, 'offset' => 3];
        yield 'unknown POSIX class, PCRE2 10.45' => ['pattern' => '/[[:foo:]/', 'php' => '8.4', 'release' => '10.45', 'code' => ErrorCode::PosixInvalid, 'offset' => 8];
        yield 'unknown POSIX class before a member' => ['pattern' => '/[[:foo:]a/', 'php' => '8.4', 'release' => '10.47', 'code' => ErrorCode::PosixInvalid, 'offset' => 8];
        yield 'unknown POSIX class after a member' => ['pattern' => '/[a[:foo:]/', 'php' => '8.2', 'release' => '10.40', 'code' => ErrorCode::PosixInvalid, 'offset' => 4];
        yield 'code point past a byte after a closed class' => ['pattern' => '/[a][\x{100}/', 'php' => '8.4', 'release' => '10.44', 'code' => ErrorCode::OctalOutOfRange, 'offset' => 10];
        yield 'unknown negated POSIX class' => ['pattern' => '/[[:^foo:]/', 'php' => '8.4', 'release' => '10.44', 'code' => ErrorCode::PosixInvalid, 'offset' => 4];

        // A "\c" PCRE refuses after the item, at the end of the pattern or
        // before a character past ASCII, comes later (pcre2test 10.32 to
        // 10.49: "unknown POSIX class name at offset 3" for "[[:foo:]\c" up
        // to 10.44, at offset 8 from 10.45; "range out of order in
        // character class at offset 3" for "[z-a\c" up to 10.46, at offset 4
        // from 10.47; php:7.3-cli and php:7.4-cli under "X" give offset 3).
        yield 'unknown POSIX class before a \c ending the pattern' => ['pattern' => '/[[:foo:]\c/', 'php' => '8.4', 'release' => '10.44', 'code' => ErrorCode::PosixInvalid, 'offset' => 3];
        yield 'unknown POSIX class before a \c ending the pattern, PCRE2 10.45' => ['pattern' => '/[[:foo:]\c/', 'php' => '8.4', 'release' => '10.45', 'code' => ErrorCode::PosixInvalid, 'offset' => 8];
        yield 'unknown POSIX class and a hyphen before a \c ending the pattern' => ['pattern' => '/[[:foo:]-\c/', 'php' => '8.2', 'release' => '10.40', 'code' => ErrorCode::PosixInvalid, 'offset' => 3];
        yield 'unknown POSIX class and a hyphen before a \c ending the pattern, PCRE2 10.47' => ['pattern' => '/[[:foo:]-\c/', 'php' => '8.4', 'release' => '10.47', 'code' => ErrorCode::PosixInvalid, 'offset' => 8];
        yield 'unknown POSIX class after a quote end, before a \c ending the pattern' => ['pattern' => '/[\E[:foo:]\c/', 'php' => '8.4', 'release' => '10.44', 'code' => ErrorCode::PosixInvalid, 'offset' => 5];
        yield 'reversed range before a \c ending the pattern' => ['pattern' => '/[z-a\c/', 'php' => '8.4', 'release' => '10.44', 'code' => ErrorCode::RangeReversed, 'offset' => 3];
        yield 'reversed range before a \c ending the pattern, PCRE2 10.47' => ['pattern' => '/[z-a\c/', 'php' => '8.5', 'release' => '10.49', 'code' => ErrorCode::RangeReversed, 'offset' => 4];
        yield 'reversed range before a \c ending the pattern under X, PCRE2 10.32' => ['pattern' => '/[z-a\c/X', 'php' => '7.3', 'release' => '10.32', 'code' => ErrorCode::RangeReversed, 'offset' => 3];
        yield 'reversed range before a \c and a character past ASCII' => ['pattern' => '/[z-a\cé]/', 'php' => '8.4', 'release' => '10.47', 'code' => ErrorCode::RangeReversed, 'offset' => 4];
        yield 'code point past a byte before a \c ending the pattern' => ['pattern' => '/[\x{100}\c/', 'php' => '8.4', 'release' => '10.44', 'code' => ErrorCode::OctalOutOfRange, 'offset' => 7];
        yield 'unknown POSIX class in a class closed before a \c ending the pattern' => ['pattern' => '/[[:foo:]]\c/', 'php' => '8.4', 'release' => '10.45', 'code' => ErrorCode::PosixInvalid, 'offset' => 8];
        yield 'a \c ending the pattern after a class closed' => ['pattern' => '/[a]\c/', 'php' => '8.4', 'release' => '10.44', 'code' => ErrorCode::ControlCharInvalid, 'offset' => 5];
    }

    /**
     * PHP 7.3 and 7.4 without "X" read a code point PCRE2 refuses as text:
     * the "]" is missing (php:7.3-cli, php:7.4.0-cli: offset 8 for
     * "[\x{100}", 10 for "[a-\x{100}"). A class with nothing in it misses
     * its "]" on every release (pcre2test 10.32 to 10.49: offset 1 for "[").
     */
    #[Test]
    #[DataProvider('provideClassesMissingTheirBracket')]
    public function test_the_bracket_is_missing_after_what_the_release_reads(string $pattern, string $php, string $release, int $offset): void
    {
        $result = self::parser($php, $release)->validate($pattern);

        $this->assertSame(ErrorCode::CharclassUnclosed, $result->errorCode, \sprintf('%s on PHP %s with PCRE2 %s: %s', $pattern, $php, $release, (string) $result->error));
        $this->assertSame($offset, $result->offset, \sprintf('%s on PHP %s with PCRE2 %s', $pattern, $php, $release));
    }

    /**
     * @return iterable<string, array{pattern: string, php: string, release: string, offset: int}>
     */
    public static function provideClassesMissingTheirBracket(): iterable
    {
        yield 'code point past a byte, PCRE2 10.32' => ['pattern' => '/[\x{100}/', 'php' => '7.3', 'release' => '10.32', 'offset' => 8];
        yield 'nothing after the bracket' => ['pattern' => '/[/', 'php' => '8.4', 'release' => '10.44', 'offset' => 1];
        yield 'nothing after the caret' => ['pattern' => '/[^/', 'php' => '8.4', 'release' => '10.47', 'offset' => 2];
        yield 'code point past a byte ending a range, PCRE2 10.33' => ['pattern' => '/[a-\x{100}/', 'php' => '7.4', 'release' => '10.33', 'offset' => 10];
    }

    private static function parser(string $php, string $release): RegexParser
    {
        return RegexParser::create(['cache' => null, 'php_version' => $php, 'pcre_version' => $release, 'runtime_pcre_validation' => false]);
    }
}
