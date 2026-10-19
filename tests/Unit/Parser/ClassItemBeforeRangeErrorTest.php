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
 * PCRE reads a class item by item: an item it refuses as it reads it, a
 * code point too large or an unknown POSIX class, is refused before a range
 * after it that PCRE refuses, whether a "]" closes the class or not.
 *
 * Every row was run through pcre2test 10.32 to 10.49: "unknown POSIX class
 * name at offset 3" for "[[:foo:]-\w]" up to 10.44, at offset 8 from 10.45;
 * "character code point value in \x{} or \o{} is too large at offset 7" for
 * "[\x{100}z-[:foo:]]"; "invalid range in character class at offset 4" for
 * "[\d-a\x{100}]" from 10.45, the code point read after the range.
 */
final class ClassItemBeforeRangeErrorTest extends TestCase
{
    #[Test]
    #[DataProvider('provideRefusals')]
    public function test_an_item_refused_before_a_range_comes_first(string $pattern, string $php, string $release, ErrorCode $code, int $offset): void
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
        foreach ([['7.4', '10.35'], ['8.2', '10.40'], ['8.4', '10.44']] as [$php, $release]) {
            yield \sprintf('unknown POSIX class starting a range from a type, PCRE2 %s', $release) => ['pattern' => '/[[:foo:]-\w]/'.('7.4' === $php ? 'X' : ''), 'php' => $php, 'release' => $release, 'code' => ErrorCode::PosixInvalid, 'offset' => 3];
        }

        yield 'unknown POSIX class starting a range from a type, PCRE2 10.45' => ['pattern' => '/[[:foo:]-\w]/', 'php' => '8.4', 'release' => '10.45', 'code' => ErrorCode::PosixInvalid, 'offset' => 8];
        yield 'unknown POSIX class starting a range from a type, PCRE2 10.49' => ['pattern' => '/[[:foo:]-\w]/', 'php' => '8.5', 'release' => '10.49', 'code' => ErrorCode::PosixInvalid, 'offset' => 8];
        yield 'unknown POSIX class before a range to a type' => ['pattern' => '/[[:foo:]a-\h]/', 'php' => '8.4', 'release' => '10.44', 'code' => ErrorCode::PosixInvalid, 'offset' => 3];
        yield 'unknown POSIX class before a range to a type, PCRE2 10.47' => ['pattern' => '/[[:foo:]a-\h]/', 'php' => '8.4', 'release' => '10.47', 'code' => ErrorCode::PosixInvalid, 'offset' => 8];
        yield 'unknown POSIX class before a range from a POSIX class' => ['pattern' => '/[[:foo:][:alpha:]-a]/', 'php' => '8.2', 'release' => '10.40', 'code' => ErrorCode::PosixInvalid, 'offset' => 3];
        yield 'code point past a byte before a range to a POSIX class' => ['pattern' => '/[\x{100}z-[:foo:]]/', 'php' => '8.4', 'release' => '10.44', 'code' => ErrorCode::OctalOutOfRange, 'offset' => 7];
        yield 'code point past a byte before a range to a POSIX class, PCRE2 10.45' => ['pattern' => '/[\x{100}z-[:foo:]]/', 'php' => '8.4', 'release' => '10.45', 'code' => ErrorCode::OctalOutOfRange, 'offset' => 7];
        yield 'code point past a byte before a range from a type' => ['pattern' => '/[\x{100}\d-a]/', 'php' => '8.4', 'release' => '10.47', 'code' => ErrorCode::OctalOutOfRange, 'offset' => 7];
        yield 'code point past a byte before a range from a POSIX class' => ['pattern' => '/[\x{100}[:alpha:]-a]/', 'php' => '8.0', 'release' => '10.35', 'code' => ErrorCode::OctalOutOfRange, 'offset' => 7];
        yield 'braced octal past a byte before a property with no name' => ['pattern' => '/[\o{400}\p-\w]/', 'php' => '8.4', 'release' => '10.44', 'code' => ErrorCode::OctalOutOfRange, 'offset' => 7];
        yield 'braced octal past a byte before an unknown property' => ['pattern' => '/[\o{400}\p{Foo}-\N]/', 'php' => '8.4', 'release' => '10.44', 'code' => ErrorCode::OctalOutOfRange, 'offset' => 7];
        yield 'surrogate before a range from a type' => ['pattern' => '/[\x{d800}\w-a]/u', 'php' => '8.4', 'release' => '10.44', 'code' => ErrorCode::UnicodeSurrogate, 'offset' => 8];
        yield 'named code point past Unicode before a range from a type' => ['pattern' => '/[\N{U+110000}\w-a]/u', 'php' => '8.4', 'release' => '10.45', 'code' => ErrorCode::UnicodeOutOfRange, 'offset' => 12];

        // A reversed range, refused where it ends (pcre2test 10.32 to 10.49:
        // "range out of order in character class at offset 3" for
        // "[z-a\d-b]" up to 10.46, at offset 4 from 10.47).
        foreach ([['7.3', '10.32', 3], ['8.2', '10.40', 3], ['8.4', '10.44', 3], ['8.4', '10.45', 3], ['8.4', '10.47', 4], ['8.5', '10.49', 4]] as [$php, $release, $offset]) {
            yield \sprintf('reversed range before a range from a type, PCRE2 %s', $release) => ['pattern' => '/[z-a\d-b]/'.('7.3' === $php ? 'X' : ''), 'php' => $php, 'release' => $release, 'code' => ErrorCode::RangeReversed, 'offset' => $offset];
        }

        yield 'reversed range before a range to a type' => ['pattern' => '/[z-aa-\d]/', 'php' => '8.4', 'release' => '10.44', 'code' => ErrorCode::RangeReversed, 'offset' => 3];
        yield 'reversed range before a range from a property' => ['pattern' => '/[b-a\pL-a]/', 'php' => '8.4', 'release' => '10.47', 'code' => ErrorCode::RangeReversed, 'offset' => 4];
        yield 'reversed range before a range from a POSIX class' => ['pattern' => '/[z-a[:alpha:]-a]/', 'php' => '8.2', 'release' => '10.40', 'code' => ErrorCode::RangeReversed, 'offset' => 3];
        yield 'reversed range of escapes before a range from a type' => ['pattern' => '/[\x{7a}-a\w-[:alpha:]]/', 'php' => '8.4', 'release' => '10.45', 'code' => ErrorCode::RangeReversed, 'offset' => 8];
        yield 'reversed range, an empty quote, then a range from a type' => ['pattern' => '/[z-a\Q\E\d-b]/', 'php' => '8.4', 'release' => '10.44', 'code' => ErrorCode::RangeReversed, 'offset' => 3];
        yield 'reversed range ending on a hyphen before a quoted run' => ['pattern' => '/[a--\Qb\E-\d]/', 'php' => '8.4', 'release' => '10.47', 'code' => ErrorCode::RangeReversed, 'offset' => 4];
        yield 'reversed range after an empty quote' => ['pattern' => '/[\Q\Ez-a\pL-a]/', 'php' => '8.4', 'release' => '10.44', 'code' => ErrorCode::RangeReversed, 'offset' => 7];
        yield 'reversed range before a range from a type, class left open' => ['pattern' => '/[z-a\d-b/', 'php' => '8.4', 'release' => '10.45', 'code' => ErrorCode::RangeReversed, 'offset' => 3];

        // A quote before the item is no item: the reading goes on past it
        // (pcre2test: "unknown POSIX class name at offset 7" for
        // "[\Q\E[:foo:]\d-z]" up to 10.44, at offset 12 from 10.45).
        yield 'unknown POSIX class after an empty quote' => ['pattern' => '/[\Q\E[:foo:]\d-z]/', 'php' => '8.4', 'release' => '10.44', 'code' => ErrorCode::PosixInvalid, 'offset' => 7];
        yield 'unknown POSIX class after an empty quote, PCRE2 10.45' => ['pattern' => '/[\Q\E[:foo:]\d-z]/', 'php' => '8.4', 'release' => '10.45', 'code' => ErrorCode::PosixInvalid, 'offset' => 12];
        yield 'unknown POSIX class after an empty quote, PCRE2 10.32' => ['pattern' => '/[\Q\E[:foo:]\d-z]/X', 'php' => '7.3', 'release' => '10.32', 'code' => ErrorCode::PosixInvalid, 'offset' => 7];
        yield 'code point past a byte after a quoted run' => ['pattern' => '/[\Qa\E\x{100}\w-[:alpha:]]/', 'php' => '8.4', 'release' => '10.47', 'code' => ErrorCode::OctalOutOfRange, 'offset' => 12];
        yield 'braced octal past a byte after an empty quote, class left open' => ['pattern' => '/[\Q\E\o{400}a-\d/', 'php' => '8.4', 'release' => '10.44', 'code' => ErrorCode::OctalOutOfRange, 'offset' => 11];

        // The class left open.
        yield 'unknown POSIX class starting a range from a type, class left open' => ['pattern' => '/[[:foo:]-\w/', 'php' => '8.4', 'release' => '10.44', 'code' => ErrorCode::PosixInvalid, 'offset' => 3];
        yield 'octal past a byte before a reversed range, class left open' => ['pattern' => '/[\777a-\x41/', 'php' => '8.4', 'release' => '10.44', 'code' => ErrorCode::OctalOutOfRange, 'offset' => 5];
        yield 'octal past a byte before a reversed range, class left open, PCRE2 10.47' => ['pattern' => '/[\777a-\x41/', 'php' => '8.4', 'release' => '10.47', 'code' => ErrorCode::OctalOutOfRange, 'offset' => 5];

        // A POSIX item ending a range is refused as the range before its
        // name is read, a name holding a backslash too (pcre2test 10.32 to
        // 10.49, php:7.3-cli and php:7.4-cli: "invalid range in character
        // class at offset 4" for "[a-[:\d:]]" up to 10.44, at offset 9 from
        // 10.45).
        yield 'POSIX item named with a backslash ending a range' => ['pattern' => '/[a-[:\d:]]/', 'php' => '8.4', 'release' => '10.44', 'code' => ErrorCode::RangeInvalidBounds, 'offset' => 4];
        yield 'POSIX item named with a backslash ending a range, PCRE2 10.45' => ['pattern' => '/[a-[:\d:]]/', 'php' => '8.4', 'release' => '10.45', 'code' => ErrorCode::RangeInvalidBounds, 'offset' => 9];
        yield 'POSIX item named with a backslash ending a range, PCRE2 10.32' => ['pattern' => '/[a-[:\d:]]/', 'php' => '7.3', 'release' => '10.32', 'code' => ErrorCode::RangeInvalidBounds, 'offset' => 4];
        yield 'POSIX item named with a backslash ending a range past a quote end' => ['pattern' => '/[a-\E[:\d:]]/', 'php' => '8.5', 'release' => '10.49', 'code' => ErrorCode::RangeInvalidBounds, 'offset' => 11];
        yield 'POSIX item holding a range from a type, ending a range' => ['pattern' => '/[a-[:\d-z:]]/', 'php' => '8.2', 'release' => '10.40', 'code' => ErrorCode::RangeInvalidBounds, 'offset' => 4];

        // What PCRE reads after the range is not read.
        yield 'code point past a byte after a range from a type' => ['pattern' => '/[\d-a\x{100}]/', 'php' => '8.4', 'release' => '10.45', 'code' => ErrorCode::RangeInvalidBounds, 'offset' => 4];
        yield 'unknown POSIX class ending a range from a type' => ['pattern' => '/[\w-[:foo:]]/', 'php' => '8.4', 'release' => '10.47', 'code' => ErrorCode::RangeInvalidBounds, 'offset' => 4];
    }

    private static function parser(string $php, string $release): RegexParser
    {
        return RegexParser::create(['cache' => null, 'php_version' => $php, 'pcre_version' => $release, 'runtime_pcre_validation' => false]);
    }
}
