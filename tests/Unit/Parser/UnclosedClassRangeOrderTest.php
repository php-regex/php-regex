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
        yield 'bare hex escape as the end' => ['pattern' => '/[a-\\x/', 'code' => ErrorCode::RangeReversed, 'offset' => 5];
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
}
