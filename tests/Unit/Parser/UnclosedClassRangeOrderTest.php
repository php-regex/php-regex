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
use PHPRegex\Tests\Support\PcreGates;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * In a class no "]" closes, PCRE2 reads the ranges first: a reversed one
 * is refused past its end ("range out of order in character class"),
 * before the missing "]".
 */
final class UnclosedClassRangeOrderTest extends TestCase
{
    use PcreGates;

    #[Test]
    #[DataProvider('provideUnclosedClasses')]
    public function test_a_reversed_range_is_refused_before_the_missing_bracket(string $pattern, int $offset): void
    {
        $this->assertFalse(@preg_match($pattern, ''));

        $result = Regex::create(['cache' => null])->validate($pattern);

        $this->assertFalse($result->isValid, $pattern);
        $this->assertSame($offset, $result->offset, $pattern);
        $this->assertSame(ErrorCode::RangeReversed, $result->errorCode, $pattern);
    }

    #[Test]
    #[DataProvider('provideUnclosedClassesWithoutAReversedRange')]
    public function test_the_missing_bracket_is_reported_without_a_reversed_range(string $pattern, int $offset): void
    {
        $this->assertFalse(@preg_match($pattern, ''));

        $result = Regex::create(['cache' => null])->validate($pattern);

        $this->assertFalse($result->isValid, $pattern);
        $this->assertSame($offset, $result->offset, $pattern);
        $this->assertSame(ErrorCode::CharclassUnclosed, $result->errorCode, $pattern);
    }

    /**
     * A class escape makes no bound: the range is invalid, not reversed.
     */
    #[Test]
    #[DataProvider('provideClassEscapesAsBounds')]
    public function test_a_class_escape_as_a_bound_is_an_invalid_range(string $pattern, int $offset): void
    {
        // PCRE2 10.45 reads a range from a type to its end before refusing it.
        if ('/[\d-abc/' === $pattern) {
            $this->requiresPcre2('10.45', $pattern);
        }

        $this->assertFalse(@preg_match($pattern, ''));

        $result = Regex::create(['cache' => null])->validate($pattern);

        $this->assertSame(ErrorCode::RangeInvalidBounds, $result->errorCode, $pattern);
        $this->assertSame($offset, $result->offset, $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string, offset: int}>
     */
    public static function provideClassEscapesAsBounds(): iterable
    {
        yield 'digit escape before the hyphen' => ['pattern' => '/[\\d-abc/', 'offset' => 4];
    }

    /**
     * Up to PCRE2 10.44 a bare "\\x" is NUL, a bound below every other.
     */
    #[Test]
    #[DataProvider('provideBareHexEscapesAsBounds')]
    public function test_a_bare_hex_escape_is_nul_as_a_bound_up_to_pcre2_10_44(string $pattern, ErrorCode $code, int $offset): void
    {
        $result = RegexParser::create(['pcre_version' => '10.44', 'php_version' => '8.4'])->validate($pattern);

        $this->assertSame($code, $result->errorCode, $pattern);
        $this->assertSame($offset, $result->offset, $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string, code: ErrorCode, offset: int}>
     */
    public static function provideBareHexEscapesAsBounds(): iterable
    {
        yield 'bare hex escape as the start' => ['pattern' => '/[\\x-abc/', 'code' => ErrorCode::CharclassUnclosed, 'offset' => 7];
        // php:7.3-cli to php:8.4-cli, pcre2test 10.32 to 10.44: "range out
        // of order in character class at offset 4".
        yield 'bare hex escape as the end' => ['pattern' => '/[a-\\x/', 'code' => ErrorCode::RangeReversed, 'offset' => 4];
    }

    /**
     * A class the pattern leaves open is still read range by range before PCRE
     * meets the end of the pattern: a reversed range in it is refused where it
     * is in a closed class, one byte before the end of the range up to PCRE2
     * 10.46, past it from 10.47.
     *
     * Every row was run on php:7.3-cli (PCRE2 10.32), php:7.4-cli and
     * php:8.0-cli (10.35), php:8.1-cli (10.39), php:8.2-cli (10.40), php:8.4-cli
     * (10.44), through pcre2test 10.44 to 10.49, and on PHP with PCRE2 10.49:
     * "range out of order in character class at offset 3" for "[z-a" up to
     * 10.46, at offset 4 from 10.47; "at offset 5" for "[z-\cA" up to 10.46, 6
     * from 10.47; "at offset 4" for "[!-\a{" up to 10.46, 5 from 10.47.
     */
    #[Test]
    #[DataProvider('provideReversedRanges')]
    public function test_a_reversed_range_in_a_class_left_open_is_refused_where_the_release_reports_it(string $pattern, string $php, string $release, int $offset): void
    {
        $result = self::parser($php, $release)->validate($pattern);

        $this->assertFalse($result->isValid, \sprintf('%s on PHP %s with PCRE2 %s', $pattern, $php, $release));
        $this->assertSame(ErrorCode::RangeReversed, $result->errorCode, \sprintf('%s on PHP %s with PCRE2 %s: %s', $pattern, $php, $release, (string) $result->error));
        $this->assertSame($offset, $result->offset, \sprintf('%s on PHP %s with PCRE2 %s', $pattern, $php, $release));
    }

    /**
     * @return iterable<string, array{pattern: string, php: string, release: string, offset: int}>
     */
    public static function provideReversedRanges(): iterable
    {
        foreach ([['7.3', '10.32'], ['7.4', '10.35'], ['8.0', '10.35'], ['8.1', '10.39'], ['8.2', '10.40'], ['8.4', '10.44'], ['8.4', '10.45'], ['8.4', '10.46']] as [$php, $release]) {
            yield \sprintf('z-a, PCRE2 %s on PHP %s', $release, $php) => ['pattern' => '/[z-a/', 'php' => $php, 'release' => $release, 'offset' => 3];
        }

        yield 'z-a, PCRE2 10.47' => ['pattern' => '/[z-a/', 'php' => '8.4', 'release' => '10.47', 'offset' => 4];
        yield 'z-a, PCRE2 10.49' => ['pattern' => '/[z-a/', 'php' => '8.5', 'release' => '10.49', 'offset' => 4];
        yield 'z-a under X, PCRE2 10.32' => ['pattern' => '/[z-a/X', 'php' => '7.3', 'release' => '10.32', 'offset' => 3];
        yield 'z-a under u' => ['pattern' => '/[z-a/u', 'php' => '8.4', 'release' => '10.44', 'offset' => 3];
        yield 'z-a under x' => ['pattern' => '/[z-a/x', 'php' => '8.2', 'release' => '10.40', 'offset' => 3];
        yield 'z-a and more' => ['pattern' => '/[z-ab/', 'php' => '8.4', 'release' => '10.44', 'offset' => 3];
        yield 'z-a negated' => ['pattern' => '/[^z-a/', 'php' => '8.4', 'release' => '10.44', 'offset' => 4];
        yield 'z-a negated, PCRE2 10.47' => ['pattern' => '/[^z-a/', 'php' => '8.4', 'release' => '10.47', 'offset' => 5];
        yield 'control escape' => ['pattern' => '/[z-\cA/', 'php' => '8.4', 'release' => '10.44', 'offset' => 5];
        yield 'control escape, PCRE2 10.32' => ['pattern' => '/[z-\cA/', 'php' => '7.3', 'release' => '10.32', 'offset' => 5];
        yield 'control escape, PCRE2 10.48' => ['pattern' => '/[z-\cA/', 'php' => '8.4', 'release' => '10.48', 'offset' => 6];
        yield 'bell escape before a brace' => ['pattern' => '/[!-\a{/', 'php' => '8.0', 'release' => '10.35', 'offset' => 4];
        yield 'bell escape before a brace, PCRE2 10.49' => ['pattern' => '/[!-\a{/', 'php' => '8.5', 'release' => '10.49', 'offset' => 5];
        yield 'hex escape' => ['pattern' => '/[z-\x41/', 'php' => '8.4', 'release' => '10.44', 'offset' => 6];
        yield 'braced hex escape' => ['pattern' => '/[z-\x{41}/', 'php' => '8.4', 'release' => '10.46', 'offset' => 8];
        yield 'braced hex escape, PCRE2 10.47' => ['pattern' => '/[z-\x{41}/', 'php' => '8.4', 'release' => '10.47', 'offset' => 9];
        yield 'octal escape' => ['pattern' => '/[z-\101/', 'php' => '8.4', 'release' => '10.44', 'offset' => 6];
        yield 'quoted end' => ['pattern' => '/[z-\Qa/', 'php' => '8.4', 'release' => '10.44', 'offset' => 5];
        yield 'quoted end and its close' => ['pattern' => '/[z-\Qa\E/', 'php' => '8.4', 'release' => '10.44', 'offset' => 5];
        yield 'quoted end, PCRE2 10.47' => ['pattern' => '/[z-\Qab/', 'php' => '8.4', 'release' => '10.47', 'offset' => 6];
        yield 'multibyte start under u' => ['pattern' => '/[é-a/u', 'php' => '8.4', 'release' => '10.44', 'offset' => 4];
        yield 'multibyte end under u' => ['pattern' => '/[ü-é/u', 'php' => '8.4', 'release' => '10.44', 'offset' => 5];
        yield 'multibyte end under u, PCRE2 10.47' => ['pattern' => '/[ü-é/u', 'php' => '8.4', 'release' => '10.47', 'offset' => 6];
        yield 'named code point under u' => ['pattern' => '/[z-\N{U+41}/u', 'php' => '8.4', 'release' => '10.44', 'offset' => 10];
    }

    /**
     * A type, a POSIX class or a property ending a range, the last item of a
     * class left open, is refused as the range as PCRE reads it, before the
     * end of the pattern: past the escape's letter up to PCRE2 10.44, past
     * the item from 10.45 (past "\N" there, refused as itself). PHP 7.3
     * without "X" reads "\w" there as the letter w, and misses the "]".
     *
     * php:7.3-cli under "X", php:7.4.0-cli and php:7.4-cli (10.33, 10.35),
     * php:8.0-cli, php:8.4-cli, pcre2test 10.32 to 10.49: "invalid range in
     * character class at offset 5" for "[!-\w"; 6 for "[!-\pL" and 12 for
     * "[!-[:alpha:]" from 10.45; php:7.3-cli: "missing terminating ] for
     * character class at offset 5" for "[!-\w".
     */
    #[Test]
    #[DataProvider('provideClassEscapesEndingARange')]
    public function test_a_class_escape_ending_a_range_is_refused_before_the_missing_bracket(string $pattern, string $php, string $release, ErrorCode $code, int $offset): void
    {
        $result = self::parser($php, $release)->validate($pattern);

        $this->assertFalse($result->isValid, \sprintf('%s on PHP %s with PCRE2 %s', $pattern, $php, $release));
        $this->assertSame($code, $result->errorCode, \sprintf('%s on PHP %s with PCRE2 %s: %s', $pattern, $php, $release, (string) $result->error));
        $this->assertSame($offset, $result->offset, \sprintf('%s on PHP %s with PCRE2 %s', $pattern, $php, $release));
    }

    /**
     * @return iterable<string, array{pattern: string, php: string, release: string, code: ErrorCode, offset: int}>
     */
    public static function provideClassEscapesEndingARange(): iterable
    {
        foreach ([['7.4', '10.33'], ['7.4', '10.35'], ['8.0', '10.35'], ['8.1', '10.39'], ['8.2', '10.40'], ['8.4', '10.44'], ['8.4', '10.45'], ['8.4', '10.47'], ['8.5', '10.49']] as [$php, $release]) {
            yield \sprintf('w, PCRE2 %s on PHP %s', $release, $php) => ['pattern' => '/[!-\w/', 'php' => $php, 'release' => $release, 'code' => ErrorCode::RangeInvalidBounds, 'offset' => 5];
        }

        yield 'w under X, PCRE2 10.32' => ['pattern' => '/[!-\w/X', 'php' => '7.3', 'release' => '10.32', 'code' => ErrorCode::RangeInvalidBounds, 'offset' => 5];
        yield 'w read as its letter, PCRE2 10.32' => ['pattern' => '/[!-\w/', 'php' => '7.3', 'release' => '10.32', 'code' => ErrorCode::CharclassUnclosed, 'offset' => 5];
        yield 'd' => ['pattern' => '/[!-\d/', 'php' => '8.4', 'release' => '10.44', 'code' => ErrorCode::RangeInvalidBounds, 'offset' => 5];
        yield 'D' => ['pattern' => '/[!-\D/', 'php' => '8.2', 'release' => '10.40', 'code' => ErrorCode::RangeInvalidBounds, 'offset' => 5];
        yield 's' => ['pattern' => '/[!-\s/', 'php' => '8.4', 'release' => '10.46', 'code' => ErrorCode::RangeInvalidBounds, 'offset' => 5];
        yield 'h' => ['pattern' => '/[!-\h/', 'php' => '8.0', 'release' => '10.35', 'code' => ErrorCode::RangeInvalidBounds, 'offset' => 5];
        yield 'v' => ['pattern' => '/[!-\v/', 'php' => '8.4', 'release' => '10.48', 'code' => ErrorCode::RangeInvalidBounds, 'offset' => 5];
        yield 'w under u' => ['pattern' => '/[!-\w/u', 'php' => '8.4', 'release' => '10.44', 'code' => ErrorCode::RangeInvalidBounds, 'offset' => 5];
        yield 'w under x' => ['pattern' => '/[!-\w/x', 'php' => '8.2', 'release' => '10.40', 'code' => ErrorCode::RangeInvalidBounds, 'offset' => 5];
        yield 'w, negated' => ['pattern' => '/[^!-\w/', 'php' => '8.4', 'release' => '10.44', 'code' => ErrorCode::RangeInvalidBounds, 'offset' => 6];
        yield 'w after a member' => ['pattern' => '/[a!-\w/', 'php' => '8.0', 'release' => '10.35', 'code' => ErrorCode::RangeInvalidBounds, 'offset' => 6];
        yield 'w after an empty quote' => ['pattern' => '/[!-\E\w/', 'php' => '8.4', 'release' => '10.44', 'code' => ErrorCode::RangeInvalidBounds, 'offset' => 7];
        yield 'N, PCRE2 10.33' => ['pattern' => '/[!-\N/', 'php' => '7.4', 'release' => '10.33', 'code' => ErrorCode::RangeInvalidBounds, 'offset' => 5];
        yield 'N, PCRE2 10.44' => ['pattern' => '/[!-\N/', 'php' => '8.4', 'release' => '10.44', 'code' => ErrorCode::RangeInvalidBounds, 'offset' => 5];
        yield 'N under X, PCRE2 10.32' => ['pattern' => '/[!-\N/X', 'php' => '7.3', 'release' => '10.32', 'code' => ErrorCode::RangeInvalidBounds, 'offset' => 5];
        yield 'N from a letter' => ['pattern' => '/[a-\N/', 'php' => '8.2', 'release' => '10.40', 'code' => ErrorCode::RangeInvalidBounds, 'offset' => 5];
        yield 'N, PCRE2 10.45' => ['pattern' => '/[!-\N/', 'php' => '8.4', 'release' => '10.45', 'code' => ErrorCode::CharclassInvalidEscape, 'offset' => 5];
        yield 'N read as its letter, PCRE2 10.32' => ['pattern' => '/[a-\N/', 'php' => '7.3', 'release' => '10.32', 'code' => ErrorCode::RangeReversed, 'offset' => 4];
        yield 'property, PCRE2 10.44' => ['pattern' => '/[!-\pL/', 'php' => '8.4', 'release' => '10.44', 'code' => ErrorCode::RangeInvalidBounds, 'offset' => 5];
        yield 'property, PCRE2 10.45' => ['pattern' => '/[!-\pL/', 'php' => '8.4', 'release' => '10.45', 'code' => ErrorCode::RangeInvalidBounds, 'offset' => 6];
        yield 'braced property, PCRE2 10.47' => ['pattern' => '/[!-\p{L}/', 'php' => '8.4', 'release' => '10.47', 'code' => ErrorCode::RangeInvalidBounds, 'offset' => 8];
        yield 'property read as its letter, PCRE2 10.32' => ['pattern' => '/[!-\pL/', 'php' => '7.3', 'release' => '10.32', 'code' => ErrorCode::CharclassUnclosed, 'offset' => 6];
        yield 'POSIX class, PCRE2 10.44' => ['pattern' => '/[!-[:alpha:]/', 'php' => '8.4', 'release' => '10.44', 'code' => ErrorCode::RangeInvalidBounds, 'offset' => 4];
        yield 'POSIX class, PCRE2 10.45' => ['pattern' => '/[!-[:alpha:]/', 'php' => '8.4', 'release' => '10.45', 'code' => ErrorCode::RangeInvalidBounds, 'offset' => 12];
        yield 'unknown POSIX class, PCRE2 10.49' => ['pattern' => '/[!-[:foo:]/', 'php' => '8.5', 'release' => '10.49', 'code' => ErrorCode::RangeInvalidBounds, 'offset' => 10];
    }

    /**
     * PHP 7.3 and 7.4 without "X" read a "\x{" PCRE2 refuses as the letter
     * x, then the brace as text: the range ends on x, not on NUL as a bare
     * "\x" does (php:7.3-cli, php:7.4.0-cli, php:7.4-cli: "missing
     * terminating ] for character class at offset 10" for "[a-\x{100}",
     * "range out of order in character class at offset 4" for "[z-\x{").
     */
    #[Test]
    #[DataProvider('provideFailedHexBracesEndingARange')]
    public function test_php_7_ends_a_range_on_the_x_of_a_hex_brace_it_refuses(string $pattern, string $php, string $release, ErrorCode $code, int $offset): void
    {
        $result = self::parser($php, $release)->validate($pattern);

        $this->assertFalse($result->isValid, \sprintf('%s on PHP %s with PCRE2 %s', $pattern, $php, $release));
        $this->assertSame($code, $result->errorCode, \sprintf('%s on PHP %s with PCRE2 %s: %s', $pattern, $php, $release, (string) $result->error));
        $this->assertSame($offset, $result->offset, \sprintf('%s on PHP %s with PCRE2 %s', $pattern, $php, $release));
    }

    /**
     * @return iterable<string, array{pattern: string, php: string, release: string, code: ErrorCode, offset: int}>
     */
    public static function provideFailedHexBracesEndingARange(): iterable
    {
        foreach ([['7.3', '10.32'], ['7.4', '10.33'], ['7.4', '10.35']] as [$php, $release]) {
            yield \sprintf('code point too large, PCRE2 %s', $release) => ['pattern' => '/[a-\x{100}/', 'php' => $php, 'release' => $release, 'code' => ErrorCode::CharclassUnclosed, 'offset' => 10];
        }

        yield 'brace with no digits' => ['pattern' => '/[!-\x{/', 'php' => '7.3', 'release' => '10.32', 'code' => ErrorCode::CharclassUnclosed, 'offset' => 6];
        yield 'empty braces' => ['pattern' => '/[!-\x{}/', 'php' => '7.4', 'release' => '10.33', 'code' => ErrorCode::CharclassUnclosed, 'offset' => 7];
        yield 'brace left open' => ['pattern' => '/[!-\x{41/', 'php' => '7.4', 'release' => '10.35', 'code' => ErrorCode::CharclassUnclosed, 'offset' => 8];
        yield 'code point too large under u' => ['pattern' => '/[a-\x{110000}/u', 'php' => '7.3', 'release' => '10.32', 'code' => ErrorCode::CharclassUnclosed, 'offset' => 13];
        yield 'x below the start' => ['pattern' => '/[z-\x{/', 'php' => '7.3', 'release' => '10.32', 'code' => ErrorCode::RangeReversed, 'offset' => 4];
        yield 'bare x, NUL' => ['pattern' => '/[!-\x/', 'php' => '7.4', 'release' => '10.35', 'code' => ErrorCode::RangeReversed, 'offset' => 4];
    }

    /**
     * @return iterable<string, array{pattern: string, offset: int}>
     */
    public static function provideUnclosedClasses(): iterable
    {
        yield 'first range reversed' => ['pattern' => '/[z-abcd/', 'offset' => 4];
        yield 'reversed range after other characters' => ['pattern' => '/[a(?-1)/', 'offset' => 6];
        yield 'second range reversed' => ['pattern' => '/[a-zz-a/', 'offset' => 7];
        yield 'negated class' => ['pattern' => '/[^z-a/', 'offset' => 5];
        yield 'bracket first' => ['pattern' => '/[]z-a/', 'offset' => 5];
        yield 'after an escape' => ['pattern' => '/[\\dz-a/', 'offset' => 6];
        yield 'after a POSIX class' => ['pattern' => '/[[:alpha:]z-a/', 'offset' => 13];
        yield 'after a POSIX class never closed' => ['pattern' => '/[[:z-a/', 'offset' => 6];
        // A bound written as an escape, quoted, or past an "\\E".
        yield 'hex start' => ['pattern' => '/[\\x7A-abc/', 'offset' => 7];
        yield 'hex end' => ['pattern' => '/[z-\\x61bc/', 'offset' => 7];
        yield 'braced hex start' => ['pattern' => '/[\\x{7A}-abc/', 'offset' => 9];
        yield 'octal start' => ['pattern' => '/[\\172-abc/', 'offset' => 7];
        yield 'named start under u' => ['pattern' => '/[\\N{U+7A}-abc/u', 'offset' => 11];
        yield 'second range from an escape' => ['pattern' => '/[a-z\\x7A-abc/', 'offset' => 10];
        yield 'start before \\E' => ['pattern' => '/[z\\E-abc/', 'offset' => 6];
        yield 'start before an empty quote' => ['pattern' => '/[z\\Q\\E-abc/', 'offset' => 8];
        yield 'quoted start' => ['pattern' => '/[\\Qz\\E-abc/', 'offset' => 8];
        yield 'last quoted character as the start' => ['pattern' => '/[\\Qaz\\E-bc/', 'offset' => 9];
        yield 'quoted end' => ['pattern' => '/[z-\\Qa\\Ebc/', 'offset' => 6];
        yield 'multibyte start under u' => ['pattern' => '/[é-abc/u', 'offset' => 5];
        yield 'multibyte start without u, its last byte' => ['pattern' => '/[é-abc/', 'offset' => 5];
        yield 'hyphen as the end' => ['pattern' => '/[a--bc/', 'offset' => 4];
        yield 'multibyte start above an escaped end under u' => ['pattern' => "/[\u{100}-\\x{81}bc/u", 'offset' => 10];
    }

    /**
     * An ordered range, or a hyphen that makes none, leaves the missing "]"
     * to be reported.
     *
     * @return iterable<string, array{pattern: string, offset: int}>
     */
    public static function provideUnclosedClassesWithoutAReversedRange(): iterable
    {
        yield 'ordered range' => ['pattern' => '/[a-z/', 'offset' => 4];
        yield 'ordered range from a braced hex escape' => ['pattern' => '/[\\x{41}-abc/', 'offset' => 11];
        yield 'quoted hyphen' => ['pattern' => '/[\\Qz-a\\Ebc/', 'offset' => 10];
        yield 'control escape below the end' => ['pattern' => '/[\\cz-abc/', 'offset' => 8];
        yield 'hyphen as an ordered end' => ['pattern' => '/[%--bc/', 'offset' => 6];
        yield 'multibyte end without u' => ['pattern' => '/[a-ébc/', 'offset' => 7];
        // Without u the range starts at the last byte of U+0100, 0x80.
        yield 'last byte of a multibyte start below an escaped end without u' => ['pattern' => "/[\u{100}-\\x{81}bc/", 'offset' => 12];
    }

    private static function parser(string $php, string $release): RegexParser
    {
        return RegexParser::create(['cache' => null, 'php_version' => $php, 'pcre_version' => $release, 'runtime_pcre_validation' => false]);
    }
}
