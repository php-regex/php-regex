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
 * Under UTF, from PCRE2 10.33, a group name holds the letters and decimal
 * digits of the release's own Unicode tables: 11 for 10.33, 12.1 for 10.34,
 * 13 for 10.35 to 10.38, 14 for 10.39 to 10.42, 15 for 10.43 and 10.44, 16
 * for 10.45 to 10.47, 17 from 10.48. A character a later Unicode made a
 * letter ends a name on an older release, and one it made a digit is no
 * digit there; whatever the PCRE2 the library runs on knows.
 *
 * U+0870 is a letter from Unicode 14, U+088F and U+11DE0 (a digit) from 17,
 * U+08BE from 13, U+16AC0 a digit from 14, U+10D40 a digit from 16. Every
 * row was run through pcre2test 10.33 to 10.49, under "X" for PHP 7.4.
 */
final class NameLettersReleaseTest extends TestCase
{
    #[Test]
    #[DataProvider('provideRefusals')]
    public function test_a_name_holds_the_letters_of_the_release_tables(string $pattern, string $php, string $release, ErrorCode $code, int $offset): void
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
        yield 'Unicode 14 letter after a letter, 10.38' => ['pattern' => "/(?<a\u{870}>x)/u", 'php' => '8.0', 'release' => '10.38', 'code' => ErrorCode::GroupNameUnterminated, 'offset' => 4];
        yield 'Unicode 14 letter first, 10.35' => ['pattern' => "/(?<\u{870}>x)/u", 'php' => '8.0', 'release' => '10.35', 'code' => ErrorCode::GroupNameExpected, 'offset' => 3];
        yield 'Unicode 13 letter after a letter, 10.34' => ['pattern' => "/(?<a\u{8BE}>x)/uX", 'php' => '7.4', 'release' => '10.34', 'code' => ErrorCode::GroupNameUnterminated, 'offset' => 4];
        yield 'Unicode 14 digit in a reference, 10.37' => ['pattern' => "/(?<a>x)\\k<\u{16AC0}>/u", 'php' => '8.0', 'release' => '10.37', 'code' => ErrorCode::GroupNameExpected, 'offset' => 10];
        yield 'Unicode 14 digit first, 10.38' => ['pattern' => "/(?<\u{16AC0}>x)/u", 'php' => '8.0', 'release' => '10.38', 'code' => ErrorCode::GroupNameExpected, 'offset' => 3];
        yield 'Unicode 14 digit first, 10.48' => ['pattern' => "/(?<\u{16AC0}>x)/u", 'php' => '8.4', 'release' => '10.48', 'code' => ErrorCode::GroupNameInvalid, 'offset' => 7];
        yield 'Unicode 14 letter in a call, 10.38' => ['pattern' => "/(?<n>a)(?&a\u{870})/u", 'php' => '8.0', 'release' => '10.38', 'code' => ErrorCode::GroupNameUnterminated, 'offset' => 11];
        yield 'Unicode 14 letter in a braced reference, 10.36' => ['pattern' => "/(?<n>a)\\g{a\u{870}}/u", 'php' => '8.0', 'release' => '10.36', 'code' => ErrorCode::GroupNameUnterminated, 'offset' => 11];
        yield 'Unicode 17 letter after a letter, 10.47' => ['pattern' => "/(?<a\u{88F}>x)/u", 'php' => '8.4', 'release' => '10.47', 'code' => ErrorCode::GroupNameUnterminated, 'offset' => 4];
        yield 'Unicode 16 digit first, 10.44' => ['pattern' => "/(?<\u{10D40}>x)/u", 'php' => '8.4', 'release' => '10.44', 'code' => ErrorCode::GroupNameExpected, 'offset' => 3];
        yield 'Unicode 16 digit after a letter, 10.43' => ['pattern' => "/(?<a\u{10D40}>x)/u", 'php' => '8.4', 'release' => '10.43', 'code' => ErrorCode::GroupNameUnterminated, 'offset' => 4];
        yield 'Unicode 17 letter in an angled condition, 10.45' => ['pattern' => "/(?<n>a)(?(<a\u{88F}>)a)/u", 'php' => '8.4', 'release' => '10.45', 'code' => ErrorCode::GroupNameUnterminated, 'offset' => 12];
        yield 'Unicode 17 letter in a bare condition, 10.46' => ['pattern' => "/(?<n>a)(?(a\u{88F})a)/u", 'php' => '8.4', 'release' => '10.46', 'code' => ErrorCode::GroupNameUnterminated, 'offset' => 11];
        yield 'Unicode 17 letter in a recursion condition, 10.47' => ['pattern' => "/(?<n>a)(?(R&a\u{88F})a)/u", 'php' => '8.4', 'release' => '10.47', 'code' => ErrorCode::GroupNameUnterminated, 'offset' => 13];
        yield 'Unicode 17 letter in a Python reference, 10.40' => ['pattern' => "/(?<n>a)(?P=a\u{88F})/u", 'php' => '8.2', 'release' => '10.40', 'code' => ErrorCode::GroupNameUnterminated, 'offset' => 12];
        yield 'Unicode 16 digit after padding, 10.44' => ['pattern' => "/(?<n>a)\\k{ \u{10D40}}/u", 'php' => '8.4', 'release' => '10.44', 'code' => ErrorCode::GroupNameExpected, 'offset' => 11];
        yield 'Unicode 17 letter in the groups a call returns, 10.47' => ['pattern' => "/(?<n>a)(?1(<a\u{88F}>))/u", 'php' => '8.4', 'release' => '10.47', 'code' => ErrorCode::GroupNameUnterminated, 'offset' => 13];
    }

    #[Test]
    #[DataProvider('provideAccepted')]
    public function test_a_name_takes_a_letter_from_the_release_that_knows_it(string $pattern, string $php, string $release): void
    {
        $result = RegexParser::create(['cache' => null, 'php_version' => $php, 'pcre_version' => $release, 'runtime_pcre_validation' => false])->validate($pattern);

        $this->assertTrue($result->isValid, \sprintf('%s on PHP %s with PCRE2 %s: %s', $pattern, $php, $release, (string) $result->error));
    }

    /**
     * @return iterable<string, array{pattern: string, php: string, release: string}>
     */
    public static function provideAccepted(): iterable
    {
        yield 'Unicode 14 letter, 10.39' => ['pattern' => "/(?<a\u{870}>x)/u", 'php' => '8.1', 'release' => '10.39'];
        yield 'Unicode 16 digit, 10.45' => ['pattern' => "/(?<a\u{10D40}>x)/u", 'php' => '8.4', 'release' => '10.45'];
        yield 'Unicode 17 letter, 10.48' => ['pattern' => "/(?<a\u{88F}>x)(?&a\u{88F})\\k<a\u{88F}>/u", 'php' => '8.4', 'release' => '10.48'];
        yield 'Unicode 17 digit, 10.49' => ['pattern' => "/(?<a\u{11DE0}>x)/u", 'php' => '8.5', 'release' => '10.49'];
    }
}
