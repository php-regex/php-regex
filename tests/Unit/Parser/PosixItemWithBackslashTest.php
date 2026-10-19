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
 * PCRE reads a "[" followed by ":", "." or "=" up to the same character
 * before a "]" as one POSIX item, whatever the name holds, a backslash
 * included: a "]" inside only escaped, as "\]". It refuses the item whole,
 * on its name up to PCRE2 10.44 and past its end from 10.45 (10.47 for an
 * item that opens the class), before anything the name holds is read.
 *
 * Every row was run through pcre2test 10.32 to 10.49: "unknown POSIX class
 * name at offset 3" for "[[:\d-z:]]" up to 10.44, at offset 9 from 10.45;
 * "invalid range in character class at offset 11" for "[a-[:\d-z:]]" from
 * 10.45; "POSIX named classes are supported only within a class at offset
 * 8" for "[:\d-z:]" from 10.47.
 */
final class PosixItemWithBackslashTest extends TestCase
{
    #[Test]
    #[DataProvider('provideRefusals')]
    public function test_a_posix_item_is_refused_whole_before_what_its_name_holds(string $pattern, string $php, string $release, ErrorCode $code, int $offset): void
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
        foreach ([['7.3', '10.32', 3], ['8.4', '10.44', 3], ['8.4', '10.45', 9], ['8.4', '10.47', 9], ['8.5', '10.49', 9]] as [$php, $release, $offset]) {
            yield \sprintf('name holding a range from a type, PCRE2 %s', $release) => ['pattern' => '/[[:\d-z:]]/'.($php < '8' ? 'X' : ''), 'php' => $php, 'release' => $release, 'code' => ErrorCode::PosixInvalid, 'offset' => $offset];
        }

        yield 'name holding a range from a type, ending a range' => ['pattern' => '/[a-[:\d-z:]]/', 'php' => '8.4', 'release' => '10.44', 'code' => ErrorCode::RangeInvalidBounds, 'offset' => 4];
        yield 'name holding a range from a type, ending a range, PCRE2 10.45' => ['pattern' => '/[a-[:\d-z:]]/', 'php' => '8.4', 'release' => '10.45', 'code' => ErrorCode::RangeInvalidBounds, 'offset' => 11];
        yield 'name holding a control escape PCRE2 refuses' => ['pattern' => '/[[:\cé:]]/', 'php' => '8.4', 'release' => '10.45', 'code' => ErrorCode::PosixInvalid, 'offset' => 9];
        yield 'name holding a control escape PCRE2 refuses, PCRE2 10.47' => ['pattern' => '/[[:\cé:]]/', 'php' => '8.4', 'release' => '10.47', 'code' => ErrorCode::PosixInvalid, 'offset' => 9];
        yield 'name holding a letter, then a range from a type' => ['pattern' => '/[[:a\d-z:]]/', 'php' => '8.4', 'release' => '10.45', 'code' => ErrorCode::PosixInvalid, 'offset' => 10];
        yield 'name holding a range between types' => ['pattern' => '/[[:\w-\d:]]/', 'php' => '8.4', 'release' => '10.46', 'code' => ErrorCode::PosixInvalid, 'offset' => 10];
        yield 'name holding a range to a type' => ['pattern' => '/[[:a-\d:]]/', 'php' => '8.5', 'release' => '10.49', 'code' => ErrorCode::PosixInvalid, 'offset' => 9];
        yield 'name holding a range from a type, class left open' => ['pattern' => '/[[:\d-z:]/', 'php' => '8.4', 'release' => '10.45', 'code' => ErrorCode::PosixInvalid, 'offset' => 9];
        yield 'name holding a range from a type, group left open' => ['pattern' => '/([[:\d-z:]]/', 'php' => '8.4', 'release' => '10.45', 'code' => ErrorCode::PosixInvalid, 'offset' => 10];
        yield 'name holding a range from a type, before a range to a type' => ['pattern' => '/[[:\d-z:]a-\d]/', 'php' => '8.4', 'release' => '10.45', 'code' => ErrorCode::PosixInvalid, 'offset' => 9];
        yield 'name holding a range from a type, starting a range to a type' => ['pattern' => '/[[:\d-z:]-\d]/', 'php' => '8.4', 'release' => '10.45', 'code' => ErrorCode::PosixInvalid, 'offset' => 9];
        yield 'name holding a range from a type, negated class' => ['pattern' => '/[^[:\d-z:]]/', 'php' => '8.4', 'release' => '10.45', 'code' => ErrorCode::PosixInvalid, 'offset' => 10];
        yield 'name holding a range from a type, in a lookahead' => ['pattern' => '/(?=[[:\d-z:]])/', 'php' => '8.4', 'release' => '10.45', 'code' => ErrorCode::PosixInvalid, 'offset' => 12];
        yield 'name holding a range from a type, in an alphabetic lookahead' => ['pattern' => '/(*pla:[[:\d-z:]])/', 'php' => '8.4', 'release' => '10.45', 'code' => ErrorCode::PosixInvalid, 'offset' => 15];

        // A collating element.
        yield 'collating element holding a range from a type' => ['pattern' => '/[[=\d-z=]]/', 'php' => '8.4', 'release' => '10.44', 'code' => ErrorCode::PosixCollatingElement, 'offset' => 1];
        yield 'collating element holding a range from a type, PCRE2 10.45' => ['pattern' => '/[[=\d-z=]]/', 'php' => '8.4', 'release' => '10.45', 'code' => ErrorCode::PosixCollatingElement, 'offset' => 9];
        yield 'dotted collating element holding a range from a type' => ['pattern' => '/[[.\d-z.]]/', 'php' => '8.4', 'release' => '10.47', 'code' => ErrorCode::PosixCollatingElement, 'offset' => 9];
        yield 'collating element holding a control escape PCRE2 refuses' => ['pattern' => '/[[.\cé.]]/', 'php' => '8.4', 'release' => '10.45', 'code' => ErrorCode::PosixCollatingElement, 'offset' => 9];
        yield 'collating element holding a range from a type, ending a range' => ['pattern' => '/[a-[.\d-z.]]/', 'php' => '8.4', 'release' => '10.45', 'code' => ErrorCode::RangeInvalidBounds, 'offset' => 11];

        // A class left open: the item ends the range, which is no reversed
        // range from "a" to its "[" (pcre2test: "invalid range in character
        // class at offset 4" up to 10.44, at offset 12 from 10.45).
        foreach ([['8.4', '10.44', 4], ['8.4', '10.45', 12], ['8.4', '10.47', 12]] as [$php, $release, $offset]) {
            yield \sprintf('collating element holding an escape, ending a range, class left open, PCRE2 %s', $release) => ['pattern' => '/[a-[=[\o{^=]-\d/x', 'php' => $php, 'release' => $release, 'code' => ErrorCode::RangeInvalidBounds, 'offset' => $offset];
        }

        // No item: no ":]" closes it, or the "[" is quoted (pcre2test:
        // "invalid range in character class at offset 5", 6 from 10.45;
        // "range out of order in character class at offset 5", 6 from 10.47).
        yield 'POSIX opener no terminator closes' => ['pattern' => '/[[:\d-z:/', 'php' => '8.4', 'release' => '10.44', 'code' => ErrorCode::RangeInvalidBounds, 'offset' => 5];
        yield 'POSIX opener no terminator closes, PCRE2 10.45' => ['pattern' => '/[[:\d-z:/', 'php' => '8.4', 'release' => '10.45', 'code' => ErrorCode::RangeInvalidBounds, 'offset' => 6];
        yield 'quoted bracket ending a reversed range, class left open' => ['pattern' => '/[z-\Q[.a.]\E/', 'php' => '8.4', 'release' => '10.44', 'code' => ErrorCode::RangeReversed, 'offset' => 5];
        yield 'quoted bracket ending a reversed range, class left open, PCRE2 10.47' => ['pattern' => '/[z-\Q[.a.]\E/', 'php' => '8.4', 'release' => '10.47', 'code' => ErrorCode::RangeReversed, 'offset' => 6];

        // The item opens the class.
        foreach ([['7.3', '10.32', 0], ['8.4', '10.44', 0], ['8.4', '10.46', 0], ['8.4', '10.47', 8], ['8.5', '10.49', 8]] as [$php, $release, $offset]) {
            yield \sprintf('class that is a POSIX item holding a range from a type, PCRE2 %s', $release) => ['pattern' => '/[:\d-z:]/'.($php < '8' ? 'X' : ''), 'php' => $php, 'release' => $release, 'code' => ErrorCode::PosixOutsideClass, 'offset' => $offset];
        }

        yield 'class that is a POSIX item holding a control escape PCRE2 refuses' => ['pattern' => '/[:\cé:]/', 'php' => '8.4', 'release' => '10.47', 'code' => ErrorCode::PosixOutsideClass, 'offset' => 8];
        yield 'class that is a collating element holding a range from a type' => ['pattern' => '/[.\d-z.]/', 'php' => '8.4', 'release' => '10.47', 'code' => ErrorCode::PosixCollatingElement, 'offset' => 8];
        yield 'class that is an equivalence class holding a range from a type' => ['pattern' => '/[=\d-z=]/', 'php' => '8.4', 'release' => '10.49', 'code' => ErrorCode::PosixCollatingElement, 'offset' => 8];
    }

    #[Test]
    #[DataProvider('provideAccepted')]
    public function test_what_is_no_posix_item_compiles(string $pattern): void
    {
        foreach ([['8.4', '10.44'], ['8.4', '10.45'], ['8.5', '10.49']] as [$php, $release]) {
            $result = self::parser($php, $release)->validate($pattern);

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
        // An escaped backslash before the "]" ends no item: the "]" closes
        // the class.
        yield 'escaped backslash before a bracket' => ['pattern' => '/[[:\\\\]:]]/'];
        yield 'quoted item' => ['pattern' => '/[\Q[:\d-z:]\E]/'];
        yield 'escaped bracket' => ['pattern' => '/[\[:\d:]/'];
    }

    private static function parser(string $php, string $release): RegexParser
    {
        return RegexParser::create(['cache' => null, 'php_version' => $php, 'pcre_version' => $release, 'runtime_pcre_validation' => false]);
    }
}
