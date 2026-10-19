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
 * Before PCRE2 10.45, an escape that names no character ending a range,
 * "[a-\z]", is refused as an invalid range past its letter, before its
 * name or anything after it is read: parse_regex() checks the range before
 * it looks at the escape. Only "\B", "\R" and "\X", which check_escape()
 * already refuses in a class from 10.33, are refused on their letter.
 * PCRE2 10.32 refuses those three as the range too, under "X" where PHP 7.3
 * does not read them as their letter. From 10.45 the escape itself is
 * refused, on its letter, and from 10.47 past it.
 *
 * Every row was run on php:7.3-cli (PCRE2 10.32), php:7.4.0-cli (10.33),
 * php:7.4-cli and php:8.0-cli (10.35), php:8.1-cli (10.39), php:8.2-cli
 * (10.40), php:8.4-cli (10.44), through pcre2test 10.44, 10.45 and 10.48,
 * and on PHP with PCRE2 10.49: "invalid range in character class at offset
 * 5" for "[a-\z]" up to 10.44, "escape sequence is invalid in character
 * class at offset 4" on 10.45 and at offset 5 on 10.48 and 10.49.
 */
final class ClassEscapeEndingRangeTest extends TestCase
{
    #[Test]
    #[DataProvider('provideRefusals')]
    public function test_the_refusal_follows_the_judged_release(string $pattern, string $php, string $release, ErrorCode $code, int $offset): void
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
        // The escapes a class refuses, ending a range, on every release up
        // to 10.44; with "X" on PHP 7.3 and 7.4, without it where the escape
        // is no bad escape PCRE2 reads as its letter.
        $targets = [['7.4', '10.33'], ['7.4', '10.35'], ['8.0', '10.35'], ['8.1', '10.39'], ['8.2', '10.40'], ['8.3', '10.42'], ['8.4', '10.44']];
        foreach (['A', 'C', 'G', 'K', 'Z', 'z', 'k'] as $letter) {
            foreach ($targets as [$php, $release]) {
                yield \sprintf('\\%s ending a range, PCRE2 %s on PHP %s', $letter, $release, $php) => ['pattern' => '/[a-\\'.$letter.']/', 'php' => $php, 'release' => $release, 'code' => ErrorCode::RangeInvalidBounds, 'offset' => 5];
            }

            yield \sprintf('\\%s ending a range under X, PCRE2 10.32', $letter) => ['pattern' => '/[a-\\'.$letter.']/X', 'php' => '7.3', 'release' => '10.32', 'code' => ErrorCode::RangeInvalidBounds, 'offset' => 5];
            yield \sprintf('\\%s ending a range under X, PCRE2 10.35', $letter) => ['pattern' => '/[a-\\'.$letter.']/X', 'php' => '7.4', 'release' => '10.35', 'code' => ErrorCode::RangeInvalidBounds, 'offset' => 5];
        }

        // PCRE2 10.32 refuses "\B", "\R" and "\X" in a class past the range
        // check; 10.33 refuses them on their letter.
        foreach (['B', 'R', 'X'] as $letter) {
            yield \sprintf('\\%s ending a range under X, PCRE2 10.32', $letter) => ['pattern' => '/[a-\\'.$letter.']/X', 'php' => '7.3', 'release' => '10.32', 'code' => ErrorCode::RangeInvalidBounds, 'offset' => 5];
            yield \sprintf('\\%s ending a range, PCRE2 10.33', $letter) => ['pattern' => '/[a-\\'.$letter.']/', 'php' => '7.4', 'release' => '10.33', 'code' => ErrorCode::CharclassInvalidEscape, 'offset' => 4];
            yield \sprintf('\\%s ending a range, PCRE2 10.44', $letter) => ['pattern' => '/[a-\\'.$letter.']/', 'php' => '8.4', 'release' => '10.44', 'code' => ErrorCode::CharclassInvalidEscape, 'offset' => 4];
        }

        // What stands around the range changes nothing.
        yield 'from a punctuation character' => ['pattern' => '/[!-\Z]/', 'php' => '8.0', 'release' => '10.35', 'code' => ErrorCode::RangeInvalidBounds, 'offset' => 5];
        yield 'under u' => ['pattern' => '/[a-\zb]/u', 'php' => '7.4', 'release' => '10.35', 'code' => ErrorCode::RangeInvalidBounds, 'offset' => 5];
        yield 'under x and X' => ['pattern' => '/[a-\K]/xX', 'php' => '7.3', 'release' => '10.32', 'code' => ErrorCode::RangeInvalidBounds, 'offset' => 5];
        yield 'before a hyphen' => ['pattern' => '/[a-\z-b]/', 'php' => '7.4', 'release' => '10.35', 'code' => ErrorCode::RangeInvalidBounds, 'offset' => 5];
        yield 'after an empty quote' => ['pattern' => '/[a-\E\z]/', 'php' => '8.0', 'release' => '10.35', 'code' => ErrorCode::RangeInvalidBounds, 'offset' => 7];
        yield 'after two empty quotes' => ['pattern' => '/[a-\E\E\z]/', 'php' => '7.4', 'release' => '10.35', 'code' => ErrorCode::RangeInvalidBounds, 'offset' => 9];
        yield 'before a group left open' => ['pattern' => '/[a-\z](/', 'php' => '7.4', 'release' => '10.35', 'code' => ErrorCode::RangeInvalidBounds, 'offset' => 5];
        yield 'before a bad escape' => ['pattern' => '/[a-\z]\y/', 'php' => '7.4', 'release' => '10.35', 'code' => ErrorCode::RangeInvalidBounds, 'offset' => 5];
        yield 'before a bad escape under X' => ['pattern' => '/[a-\z]\y/X', 'php' => '7.3', 'release' => '10.32', 'code' => ErrorCode::RangeInvalidBounds, 'offset' => 5];

        // "\N" too, refused on itself from 10.45.
        yield 'N ending a range' => ['pattern' => '/[a-\N]/', 'php' => '7.4', 'release' => '10.35', 'code' => ErrorCode::RangeInvalidBounds, 'offset' => 5];
        yield 'N ending a range under X, PCRE2 10.32' => ['pattern' => '/[a-\N]/X', 'php' => '7.3', 'release' => '10.32', 'code' => ErrorCode::RangeInvalidBounds, 'offset' => 5];
        yield 'N ending a range, PCRE2 10.45' => ['pattern' => '/[a-\N]/', 'php' => '8.4', 'release' => '10.45', 'code' => ErrorCode::CharclassInvalidEscape, 'offset' => 5];

        // A property ending a range is refused before its name is read.
        yield 'p and no name' => ['pattern' => '/[a-\p]/', 'php' => '7.4', 'release' => '10.35', 'code' => ErrorCode::RangeInvalidBounds, 'offset' => 5];
        yield 'p and no name under X' => ['pattern' => '/[a-\p]/X', 'php' => '7.3', 'release' => '10.32', 'code' => ErrorCode::RangeInvalidBounds, 'offset' => 5];
        yield 'p and an unknown name' => ['pattern' => '/[a-\p{Bogus}]/', 'php' => '8.4', 'release' => '10.44', 'code' => ErrorCode::RangeInvalidBounds, 'offset' => 5];
        yield 'P and an unknown name before a group left open' => ['pattern' => '/[a-\P{Bogus}](/', 'php' => '8.0', 'release' => '10.35', 'code' => ErrorCode::RangeInvalidBounds, 'offset' => 5];

        // From 10.45 the escape is refused, on its letter, then past it.
        yield 'z ending a range, PCRE2 10.45' => ['pattern' => '/[a-\z]/', 'php' => '8.4', 'release' => '10.45', 'code' => ErrorCode::CharclassInvalidEscape, 'offset' => 4];
        yield 'z ending a range before a group left open, PCRE2 10.45' => ['pattern' => '/[a-\z](/', 'php' => '8.4', 'release' => '10.45', 'code' => ErrorCode::CharclassInvalidEscape, 'offset' => 4];
        yield 'z ending a range, PCRE2 10.48' => ['pattern' => '/[a-\z]/', 'php' => '8.4', 'release' => '10.48', 'code' => ErrorCode::CharclassInvalidEscape, 'offset' => 5];
        yield 'p and no name, PCRE2 10.45' => ['pattern' => '/[a-\p]/', 'php' => '8.4', 'release' => '10.45', 'code' => ErrorCode::UnicodePropertyMalformed, 'offset' => 6];
        yield 'p and an unknown name, PCRE2 10.48' => ['pattern' => '/[a-\p{Bogus}]/', 'php' => '8.4', 'release' => '10.48', 'code' => ErrorCode::UnicodePropertyInvalid, 'offset' => 12];

        // PHP 7.3 reads the escape as its letter without "X": the range ends
        // there.
        yield 'Z read as its letter, PCRE2 10.32' => ['pattern' => '/[a-\Z]/', 'php' => '7.3', 'release' => '10.32', 'code' => ErrorCode::RangeReversed, 'offset' => 4];
        yield 'B read as its letter, PCRE2 10.32' => ['pattern' => '/[a-\B]/', 'php' => '7.3', 'release' => '10.32', 'code' => ErrorCode::RangeReversed, 'offset' => 4];
        yield 'P read as its letter, PCRE2 10.32' => ['pattern' => '/[a-\P{Lu}]/', 'php' => '7.3', 'release' => '10.32', 'code' => ErrorCode::RangeReversed, 'offset' => 4];
    }

    /**
     * PHP 7.3 reads the escape as its letter without "X": "[a-\z]" is the
     * range a-z (php:7.3-cli compiles each).
     */
    #[Test]
    #[DataProvider('provideRangesPhp73Reads')]
    public function test_php_7_3_reads_the_escape_ending_a_range_as_its_letter(string $pattern): void
    {
        $result = self::parser('7.3', '10.32')->validate($pattern);

        $this->assertTrue($result->isValid, $pattern.': '.(string) $result->error);
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideRangesPhp73Reads(): iterable
    {
        yield 'z' => ['pattern' => '/[a-\z]/'];
        yield 'k' => ['pattern' => '/[a-\k]/'];
        yield 'z under u' => ['pattern' => '/[a-\zb]/u'];
        yield 'p and an unknown name' => ['pattern' => '/[a-\p{Bogus}]/'];
        yield 'z after an empty quote' => ['pattern' => '/[a-\E\z]/'];
    }

    /**
     * PCRE2 reads the start of a range before the hyphen: a code point it
     * refuses there is reported first, whatever ends the range, on every
     * release (php:7.3-cli under "X", php:8.4-cli, pcre2test 10.45:
     * "character code point value in \x{} or \o{} is too large at offset 7"
     * for "[\x{100}-\z]"). A hyphen after a range that ended is a member,
     * so what follows it starts no range: "[a-c-\p{L}](" is refused for its
     * "(" (php:7.3-cli under "X", php:7.4-cli, php:8.4-cli, pcre2test 10.45:
     * "missing closing parenthesis at offset 12").
     */
    #[Test]
    #[DataProvider('provideEarlierErrors')]
    public function test_an_error_before_the_end_of_the_range_comes_first(string $pattern, string $php, string $release, int $offset): void
    {
        $result = self::parser($php, $release)->validate($pattern);

        $this->assertFalse($result->isValid, \sprintf('%s on PHP %s with PCRE2 %s', $pattern, $php, $release));
        $this->assertNotSame(ErrorCode::RangeInvalidBounds, $result->errorCode, \sprintf('%s on PHP %s with PCRE2 %s: %s', $pattern, $php, $release, (string) $result->error));
        $this->assertSame($offset, $result->offset, \sprintf('%s on PHP %s with PCRE2 %s: %s', $pattern, $php, $release, (string) $result->error));
    }

    /**
     * @return iterable<string, array{pattern: string, php: string, release: string, offset: int}>
     */
    public static function provideEarlierErrors(): iterable
    {
        yield 'code point past a byte, then z' => ['pattern' => '/[\x{100}-\z]/', 'php' => '8.4', 'release' => '10.44', 'offset' => 7];
        yield 'code point past a byte, then z, under X' => ['pattern' => '/[\x{100}-\z]/X', 'php' => '7.4', 'release' => '10.35', 'offset' => 7];
        yield 'code point past a byte, then B, under X, PCRE2 10.32' => ['pattern' => '/[\x{100}-\B]/X', 'php' => '7.3', 'release' => '10.32', 'offset' => 7];
        yield 'octal past a byte, then z' => ['pattern' => '/[\777-\z]/', 'php' => '8.4', 'release' => '10.44', 'offset' => 5];
        yield 'braced octal past a byte, then z' => ['pattern' => '/[\o{400}-\z]/', 'php' => '8.4', 'release' => '10.44', 'offset' => 7];
        yield 'code point past Unicode, then z' => ['pattern' => '/[\x{110000}-\z]/u', 'php' => '8.4', 'release' => '10.44', 'offset' => 10];
        yield 'surrogate, then z' => ['pattern' => '/[\x{d800}-\z]/u', 'php' => '8.4', 'release' => '10.44', 'offset' => 8];
        yield 'code point past a byte, then A' => ['pattern' => '/[\x{100}-\A]/', 'php' => '8.4', 'release' => '10.44', 'offset' => 7];
        yield 'code point past a byte, then N' => ['pattern' => '/[\x{100}-\N]/', 'php' => '8.4', 'release' => '10.44', 'offset' => 7];
        yield 'code point past a byte, then a property' => ['pattern' => '/[\x{100}-\p{L}]/', 'php' => '8.4', 'release' => '10.44', 'offset' => 7];
        yield 'code point past a byte, then a type' => ['pattern' => '/[\x{100}-\w]/', 'php' => '8.4', 'release' => '10.44', 'offset' => 7];
        yield 'code point past a byte, then a type, PCRE2 10.45' => ['pattern' => '/[\x{100}-\w]/', 'php' => '8.4', 'release' => '10.45', 'offset' => 7];
        yield 'code point past a byte, then z, before a group left open' => ['pattern' => '/[\x{100}-\z](/', 'php' => '8.4', 'release' => '10.44', 'offset' => 7];
        yield 'code point past a byte, then a property, before a group left open' => ['pattern' => '/[\x{100}-\pL](/', 'php' => '8.4', 'release' => '10.44', 'offset' => 7];
        yield 'property after a range that ended, before a group left open' => ['pattern' => '/[a-c-\p{L}](/', 'php' => '8.4', 'release' => '10.44', 'offset' => 12];
        yield 'property after a range that ended, under X' => ['pattern' => '/[a-c-\p{L}](/X', 'php' => '7.3', 'release' => '10.32', 'offset' => 12];
        yield 'property after a range that ended, class left open' => ['pattern' => '/[a-c-\p{L}/', 'php' => '7.4', 'release' => '10.35', 'offset' => 10];
        yield 'property letter after a range that ended, class left open' => ['pattern' => '/[a-c-\pL/', 'php' => '8.4', 'release' => '10.44', 'offset' => 8];
        yield 'negated property after a range of one character that ended' => ['pattern' => '/[!-!-\P{L}](/', 'php' => '8.4', 'release' => '10.44', 'offset' => 12];
        yield 'property after a range that ended and an empty quote' => ['pattern' => '/[!-!-\Q\E\p{L}](/', 'php' => '8.4', 'release' => '10.44', 'offset' => 16];
    }

    private static function parser(string $php, string $release): RegexParser
    {
        return RegexParser::create(['cache' => null, 'php_version' => $php, 'pcre_version' => $release, 'runtime_pcre_validation' => false]);
    }
}
