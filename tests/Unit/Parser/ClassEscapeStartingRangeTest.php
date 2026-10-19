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
 * A type, a POSIX class or a property starting a range in a class, "[\w-a]",
 * is refused as an invalid range: on its hyphen up to PCRE2 10.44, before
 * the end of the range is read; past the hyphen from 10.45, once the end of
 * the range is read. An end PCRE2 refuses as it reads it, as "\p{Foo}" or
 * "\x{100}" without UTF, is then refused first, as itself.
 *
 * Every row was run through pcre2test 10.44 to 10.49, and on PHP with PCRE2
 * 10.49 for the newest: "invalid range in character class at offset 3" for
 * "[\w-\p{Foo}]" on 10.44, "unknown property after \P or \p at offset 11"
 * from 10.45; "malformed \P or \p sequence at offset 7" for "[\w-\p]" from
 * 10.45; "invalid range in character class at offset 4" for "[\w-\pL]" and
 * "[\w-a\x{100}]" from 10.45.
 */
final class ClassEscapeStartingRangeTest extends TestCase
{
    #[Test]
    #[DataProvider('provideRefusals')]
    public function test_an_end_refused_as_it_is_read_comes_before_the_range_from_pcre2_10_45(string $pattern, string $php, string $release, ErrorCode $code, int $offset): void
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
        foreach (['10.45', '10.46', '10.47', '10.48'] as $release) {
            yield \sprintf('unknown property, PCRE2 %s', $release) => ['pattern' => '/[\w-\p{Foo}]/', 'php' => '8.4', 'release' => $release, 'code' => ErrorCode::UnicodePropertyInvalid, 'offset' => 11];
            yield \sprintf('property with no name, PCRE2 %s', $release) => ['pattern' => '/[\w-\p]/', 'php' => '8.4', 'release' => $release, 'code' => ErrorCode::UnicodePropertyMalformed, 'offset' => 7];
        }

        yield 'unknown property, PCRE2 10.49' => ['pattern' => '/[\w-\p{Foo}]/', 'php' => '8.5', 'release' => '10.49', 'code' => ErrorCode::UnicodePropertyInvalid, 'offset' => 11];
        yield 'unknown negated property' => ['pattern' => '/[\w-\P{Foo}]/', 'php' => '8.4', 'release' => '10.47', 'code' => ErrorCode::UnicodePropertyInvalid, 'offset' => 11];
        yield 'unknown property negated by a caret' => ['pattern' => '/[\w-\p{^Foo}]/', 'php' => '8.4', 'release' => '10.45', 'code' => ErrorCode::UnicodePropertyInvalid, 'offset' => 12];
        yield 'property left open' => ['pattern' => '/[\w-\p{L]/', 'php' => '8.4', 'release' => '10.46', 'code' => ErrorCode::UnicodePropertyMalformed, 'offset' => 9];
        yield 'from a digit type' => ['pattern' => '/[\d-\p]/', 'php' => '8.4', 'release' => '10.45', 'code' => ErrorCode::UnicodePropertyMalformed, 'offset' => 7];
        yield 'from a property' => ['pattern' => '/[\pL-\p{Foo}]/', 'php' => '8.4', 'release' => '10.45', 'code' => ErrorCode::UnicodePropertyInvalid, 'offset' => 12];
        yield 'from a POSIX class' => ['pattern' => '/[[:alpha:]-\p{Foo}]/', 'php' => '8.4', 'release' => '10.47', 'code' => ErrorCode::UnicodePropertyInvalid, 'offset' => 18];
        yield 'after an empty quote' => ['pattern' => '/[\w-\E\p{Foo}]/', 'php' => '8.4', 'release' => '10.45', 'code' => ErrorCode::UnicodePropertyInvalid, 'offset' => 13];
        yield 'after an empty quoted run' => ['pattern' => '/[\w-\Q\E\p]/', 'php' => '8.4', 'release' => '10.47', 'code' => ErrorCode::UnicodePropertyMalformed, 'offset' => 11];
        yield 'code point past a byte' => ['pattern' => '/[\w-\x{100}]/', 'php' => '8.4', 'release' => '10.45', 'code' => ErrorCode::OctalOutOfRange, 'offset' => 10];
        yield 'code point past Unicode' => ['pattern' => '/[\w-\x{110000}]/u', 'php' => '8.4', 'release' => '10.48', 'code' => ErrorCode::UnicodeOutOfRange, 'offset' => 13];
        yield 'hex escape with no digits' => ['pattern' => '/[\w-\x]/', 'php' => '8.4', 'release' => '10.45', 'code' => ErrorCode::EscapeDigitsMissing, 'offset' => 6];
        yield 'non-hex character' => ['pattern' => '/[\w-\x{zz}]/', 'php' => '8.4', 'release' => '10.46', 'code' => ErrorCode::UnicodeInvalidDigit, 'offset' => 7];
        yield 'non-hex character, PCRE2 10.47' => ['pattern' => '/[\w-\x{zz}]/', 'php' => '8.4', 'release' => '10.47', 'code' => ErrorCode::UnicodeInvalidDigit, 'offset' => 8];
        yield 'octal past a byte' => ['pattern' => '/[\w-\777]/', 'php' => '8.4', 'release' => '10.45', 'code' => ErrorCode::OctalOutOfRange, 'offset' => 8];

        // The class left open.
        yield 'unknown property, class left open' => ['pattern' => '/[\w-\p{Foo}/', 'php' => '8.4', 'release' => '10.45', 'code' => ErrorCode::UnicodePropertyInvalid, 'offset' => 11];
        yield 'property with no name, class left open' => ['pattern' => '/[\w-\p/', 'php' => '8.4', 'release' => '10.47', 'code' => ErrorCode::UnicodePropertyMalformed, 'offset' => 6];

        // An end PCRE2 reads leaves the range refused, past its hyphen; a
        // property ending a range is refused past it.
        yield 'property ending a range' => ['pattern' => '/[!-\p{L}]/', 'php' => '8.4', 'release' => '10.45', 'code' => ErrorCode::RangeInvalidBounds, 'offset' => 8];
        yield 'property' => ['pattern' => '/[\w-\pL]/', 'php' => '8.4', 'release' => '10.45', 'code' => ErrorCode::RangeInvalidBounds, 'offset' => 4];
        yield 'letter before a code point past a byte' => ['pattern' => '/[\w-a\x{100}]/', 'php' => '8.4', 'release' => '10.47', 'code' => ErrorCode::RangeInvalidBounds, 'offset' => 4];
        yield 'property before a code point past a byte' => ['pattern' => '/[\w-\pL\x{100}]/', 'php' => '8.4', 'release' => '10.45', 'code' => ErrorCode::RangeInvalidBounds, 'offset' => 4];
        yield 'quoted end' => ['pattern' => '/[\w-\Q\x{100}]/', 'php' => '8.4', 'release' => '10.45', 'code' => ErrorCode::RangeInvalidBounds, 'offset' => 4];

        // Up to 10.44 the range is refused on its hyphen, before its end.
        yield 'unknown property, PCRE2 10.44' => ['pattern' => '/[\w-\p{Foo}]/', 'php' => '8.4', 'release' => '10.44', 'code' => ErrorCode::RangeInvalidBounds, 'offset' => 3];
        yield 'code point past a byte, PCRE2 10.44' => ['pattern' => '/[\w-\x{100}]/', 'php' => '8.4', 'release' => '10.44', 'code' => ErrorCode::RangeInvalidBounds, 'offset' => 3];
    }

    /**
     * Up to PCRE2 10.44 a hyphen after a type, a POSIX class or a property
     * starts a range unless the pattern ends there or a "]" follows it, as
     * PCRE2 reads the characters: "[\w-\Q\E]" is refused on its hyphen. From
     * 10.45 the empty quotes are skipped first, and a hyphen the pattern ends
     * after is a member: the "]" is missing.
     *
     * pcre2test 10.32 to 10.49, php:7.3-cli with and without "X",
     * php:7.4.0-cli, php:8.4-cli: "missing terminating ] for character class
     * at offset 4" for "[\w-"; "invalid range in character class at offset
     * 3" for "[\w-\Q\E]" up to 10.44, which 10.45 compiles; "missing
     * terminating ] for character class at offset 6" for "[\w-\E" from
     * 10.45.
     */
    #[Test]
    #[DataProvider('provideHyphensAfterAClassEscape')]
    public function test_the_hyphen_after_a_class_escape_is_read_as_the_release_reads_it(string $pattern, string $php, string $release, ?ErrorCode $code, ?int $offset): void
    {
        $result = self::parser($php, $release)->validate($pattern);

        $this->assertSame($code, $result->errorCode, \sprintf('%s on PHP %s with PCRE2 %s: %s', $pattern, $php, $release, (string) $result->error));
        $this->assertSame($offset, $result->offset, \sprintf('%s on PHP %s with PCRE2 %s', $pattern, $php, $release));
    }

    /**
     * @return iterable<string, array{pattern: string, php: string, release: string, code: ErrorCode|null, offset: int|null}>
     */
    public static function provideHyphensAfterAClassEscape(): iterable
    {
        foreach ([['7.3', '10.32'], ['7.4', '10.33'], ['8.2', '10.40'], ['8.4', '10.44']] as [$php, $release]) {
            yield \sprintf('type, then the end of the pattern, PCRE2 %s', $release) => ['pattern' => '/[\w-/', 'php' => $php, 'release' => $release, 'code' => ErrorCode::CharclassUnclosed, 'offset' => 4];
            yield \sprintf('type, then an empty quoted run, PCRE2 %s', $release) => ['pattern' => '/[\w-\Q\E]/', 'php' => $php, 'release' => $release, 'code' => ErrorCode::RangeInvalidBounds, 'offset' => 3];
        }

        yield 'type under X, then the end of the pattern, PCRE2 10.32' => ['pattern' => '/[\w-/X', 'php' => '7.3', 'release' => '10.32', 'code' => ErrorCode::CharclassUnclosed, 'offset' => 4];
        yield 'POSIX class, then the end of the pattern' => ['pattern' => '/[[:alpha:]-/', 'php' => '8.4', 'release' => '10.44', 'code' => ErrorCode::CharclassUnclosed, 'offset' => 11];
        yield 'property, then the end of the pattern' => ['pattern' => '/[\pL-/', 'php' => '8.2', 'release' => '10.40', 'code' => ErrorCode::CharclassUnclosed, 'offset' => 5];
        yield 'type, then a quote end' => ['pattern' => '/[\w-\E]/', 'php' => '8.4', 'release' => '10.44', 'code' => ErrorCode::RangeInvalidBounds, 'offset' => 3];
        yield 'POSIX class, then an empty quoted run' => ['pattern' => '/[[:alpha:]-\Q\E]/', 'php' => '8.4', 'release' => '10.44', 'code' => ErrorCode::RangeInvalidBounds, 'offset' => 10];
        yield 'property, then a quote end' => ['pattern' => '/[\pL-\E]/', 'php' => '8.0', 'release' => '10.35', 'code' => ErrorCode::RangeInvalidBounds, 'offset' => 4];
        yield 'type, then quotes' => ['pattern' => '/[\d-\E\Q\E]/', 'php' => '8.4', 'release' => '10.44', 'code' => ErrorCode::RangeInvalidBounds, 'offset' => 3];
        yield 'type, then a quote end, class left open, PCRE2 10.44' => ['pattern' => '/[\w-\E/', 'php' => '8.4', 'release' => '10.44', 'code' => ErrorCode::RangeInvalidBounds, 'offset' => 3];

        // From 10.45.
        yield 'type, then an empty quoted run, PCRE2 10.45' => ['pattern' => '/[\w-\Q\E]/', 'php' => '8.4', 'release' => '10.45', 'code' => null, 'offset' => null];
        yield 'type, then the end of the pattern, PCRE2 10.45' => ['pattern' => '/[\w-/', 'php' => '8.4', 'release' => '10.45', 'code' => ErrorCode::CharclassUnclosed, 'offset' => 4];
        yield 'type, then a quote end and the end of the pattern, PCRE2 10.45' => ['pattern' => '/[\w-\E/', 'php' => '8.4', 'release' => '10.45', 'code' => ErrorCode::CharclassUnclosed, 'offset' => 6];
        yield 'type, then an empty quoted run and the end of the pattern, PCRE2 10.47' => ['pattern' => '/[\w-\Q\E/', 'php' => '8.4', 'release' => '10.47', 'code' => ErrorCode::CharclassUnclosed, 'offset' => 8];
        yield 'type, then quotes and the end of the pattern, PCRE2 10.49' => ['pattern' => '/[\w-\Q\E\E/', 'php' => '8.5', 'release' => '10.49', 'code' => ErrorCode::CharclassUnclosed, 'offset' => 10];
        yield 'POSIX class, then a quote end and the end of the pattern, PCRE2 10.45' => ['pattern' => '/[[:alpha:]-\E/', 'php' => '8.4', 'release' => '10.45', 'code' => ErrorCode::CharclassUnclosed, 'offset' => 13];
    }

    private static function parser(string $php, string $release): RegexParser
    {
        return RegexParser::create(['cache' => null, 'php_version' => $php, 'pcre_version' => $release, 'runtime_pcre_validation' => false]);
    }
}
