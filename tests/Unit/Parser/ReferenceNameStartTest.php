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
 * PCRE2 reads the name of "\k{...}", "\k<...>", "\k'...'" and of "\g{...}",
 * "\g<...>" and "\g'...'" when no number stands there with read_name(): past
 * the spaces and tabs after "{" from 10.43, a digit first is refused on it
 * ("subpattern name must start with a non-digit"), past it from 10.47; a
 * character no name holds first leaves no name ("subpattern name
 * expected"); else the name ends at the first character no name holds,
 * and what closes it must follow, past spaces and tabs before "}" from
 * 10.43 ("syntax error in subpattern name (missing terminator?)").
 *
 * A name holds ASCII letters, digits and underscores; under UTF from 10.33,
 * Unicode letters and decimal digits too, a digit being any decimal digit.
 * Without UTF, a byte past ASCII is no name character.
 *
 * Every row was run through pcre2test 10.32 to 10.49, under "X" for PHP 7.3.
 */
final class ReferenceNameStartTest extends TestCase
{
    #[Test]
    #[DataProvider('provideRefusals')]
    public function test_a_reference_name_is_refused_where_pcre2_stops_reading_it(string $pattern, string $php, string $release, ErrorCode $code, int $offset): void
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
        // Before 10.43 the space is no padding: the digit is read first.
        yield 'digit before a space' => ['pattern' => '/(a)\k{1 }/', 'php' => '8.3', 'release' => '10.42', 'code' => ErrorCode::GroupNameInvalid, 'offset' => 6];
        yield 'digit and letter before a space' => ['pattern' => '/(a)\k{1n }/', 'php' => '8.2', 'release' => '10.40', 'code' => ErrorCode::GroupNameInvalid, 'offset' => 6];
        yield 'digit before a space under X' => ['pattern' => '/(a)\k{1 }/X', 'php' => '7.3', 'release' => '10.32', 'code' => ErrorCode::GroupNameInvalid, 'offset' => 6];
        yield 'byte past ASCII before a space' => ['pattern' => '/(?<n>a)\k{é }/', 'php' => '8.2', 'release' => '10.40', 'code' => ErrorCode::GroupNameExpected, 'offset' => 10];
        yield 'letter and byte past ASCII before a space' => ['pattern' => '/(?<n>a)\k{aé }/', 'php' => '8.3', 'release' => '10.42', 'code' => ErrorCode::GroupNameUnterminated, 'offset' => 11];
        yield 'Arabic digit before a space under UTF' => ['pattern' => '/(?<n>a)\k{١ }/u', 'php' => '8.3', 'release' => '10.42', 'code' => ErrorCode::GroupNameInvalid, 'offset' => 10];

        // From 10.43 the digit is read past the spaces.
        yield 'digit after a space' => ['pattern' => '/(a)\k{ 1}/', 'php' => '8.4', 'release' => '10.44', 'code' => ErrorCode::GroupNameInvalid, 'offset' => 7];
        yield 'digit after a space, PCRE2 10.49' => ['pattern' => '/(a)\k{ 1}/', 'php' => '8.5', 'release' => '10.49', 'code' => ErrorCode::GroupNameInvalid, 'offset' => 8];
        yield 'digit after two spaces' => ['pattern' => '/(a)\k{  1}/', 'php' => '8.4', 'release' => '10.46', 'code' => ErrorCode::GroupNameInvalid, 'offset' => 8];
        yield 'digit and letter after a space, PCRE2 10.47' => ['pattern' => '/(a)\k{ 1n}/', 'php' => '8.4', 'release' => '10.47', 'code' => ErrorCode::GroupNameInvalid, 'offset' => 8];
        yield 'Arabic digit after a space under UTF' => ['pattern' => '/(?<n>a)\k{ ١}/u', 'php' => '8.4', 'release' => '10.44', 'code' => ErrorCode::GroupNameInvalid, 'offset' => 11];
        yield 'Arabic digit after a space under UTF, PCRE2 10.47' => ['pattern' => '/(?<n>a)\k{ ١}/u', 'php' => '8.4', 'release' => '10.47', 'code' => ErrorCode::GroupNameInvalid, 'offset' => 13];
        yield 'byte past ASCII after a space' => ['pattern' => '/(?<n>a)\k{ é}/', 'php' => '8.4', 'release' => '10.44', 'code' => ErrorCode::GroupNameExpected, 'offset' => 11];

        // Without UTF a byte past ASCII is no name character.
        yield 'braced byte past ASCII' => ['pattern' => '/(?<n>a)\k{é}/', 'php' => '8.4', 'release' => '10.44', 'code' => ErrorCode::GroupNameExpected, 'offset' => 10];
        yield 'angled byte past ASCII' => ['pattern' => '/(?<n>a)\k<é>/', 'php' => '8.2', 'release' => '10.40', 'code' => ErrorCode::GroupNameExpected, 'offset' => 10];
        yield 'g braced byte past ASCII' => ['pattern' => '/(?<n>a)\g{é}/', 'php' => '8.5', 'release' => '10.49', 'code' => ErrorCode::GroupNameExpected, 'offset' => 10];
        yield 'g angled byte past ASCII' => ['pattern' => '/(?<n>a)\g<é>/', 'php' => '8.4', 'release' => '10.44', 'code' => ErrorCode::GroupNameExpected, 'offset' => 10];
        yield 'braced Arabic digit without UTF' => ['pattern' => '/(?<n>a)\k{١}/', 'php' => '8.4', 'release' => '10.44', 'code' => ErrorCode::GroupNameExpected, 'offset' => 10];
        yield 'g quoted Arabic digit without UTF' => ['pattern' => "/(?<n>a)\\g'١'/", 'php' => '8.4', 'release' => '10.47', 'code' => ErrorCode::GroupNameExpected, 'offset' => 10];
        yield 'g angled letter and byte past ASCII' => ['pattern' => '/(?<n>a)\g<aé>/', 'php' => '8.4', 'release' => '10.44', 'code' => ErrorCode::GroupNameUnterminated, 'offset' => 11];

        // Under UTF from 10.33 any decimal digit is one.
        yield 'braced Arabic digit under UTF' => ['pattern' => '/(?<n>a)\k{١}/u', 'php' => '8.4', 'release' => '10.44', 'code' => ErrorCode::GroupNameInvalid, 'offset' => 10];
        yield 'braced Arabic digit under UTF, PCRE2 10.47' => ['pattern' => '/(?<n>a)\k{١}/u', 'php' => '8.4', 'release' => '10.47', 'code' => ErrorCode::GroupNameInvalid, 'offset' => 12];
        yield 'g angled Arabic digit under UTF' => ['pattern' => '/(?<n>a)\g<١>/u', 'php' => '8.2', 'release' => '10.40', 'code' => ErrorCode::GroupNameInvalid, 'offset' => 10];
        yield 'g angled Arabic digit under UTF, PCRE2 10.49' => ['pattern' => '/(?<n>a)\g<١>/u', 'php' => '8.5', 'release' => '10.49', 'code' => ErrorCode::GroupNameInvalid, 'offset' => 12];
        yield 'g braced Arabic digit under UTF, PCRE2 10.47' => ['pattern' => '/(?<n>a)\g{١}/u', 'php' => '8.4', 'release' => '10.47', 'code' => ErrorCode::GroupNameInvalid, 'offset' => 12];
        yield 'g angled Arabic digit before a space under UTF' => ['pattern' => '/(?<n>a)\g<١ >/u', 'php' => '8.4', 'release' => '10.44', 'code' => ErrorCode::GroupNameInvalid, 'offset' => 10];
        yield 'g angled Arabic digit before a space under UTF, PCRE2 10.47' => ['pattern' => '/(?<n>a)\g<١ >/u', 'php' => '8.4', 'release' => '10.47', 'code' => ErrorCode::GroupNameInvalid, 'offset' => 12];

        // PCRE2 10.32 reads ASCII names only, under UTF too.
        yield 'angled Arabic digit under UTF, PCRE2 10.32' => ['pattern' => '/(?<n>a)\k<١ >/uX', 'php' => '7.3', 'release' => '10.32', 'code' => ErrorCode::GroupNameExpected, 'offset' => 10];
        yield 'braced letter past ASCII under UTF, PCRE2 10.32' => ['pattern' => '/(?<n>a)\k{aé}/uX', 'php' => '7.3', 'release' => '10.32', 'code' => ErrorCode::GroupNameUnterminated, 'offset' => 11];
        yield 'g angled letter past ASCII under UTF, PCRE2 10.32' => ['pattern' => '/(?<n>a)\g<é>/uX', 'php' => '7.3', 'release' => '10.32', 'code' => ErrorCode::GroupNameExpected, 'offset' => 10];
    }
}
