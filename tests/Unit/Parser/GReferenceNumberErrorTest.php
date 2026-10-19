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
 * PCRE2 reads the number of "\g{n}", "\g<n>" and "\g'n'" with read_number()
 * before it looks for what closes it: a number past 65535, counting the
 * groups opened before a "+", a relative zero, or a "-" counting back past
 * the groups opened, is refused right after the "g", on every release,
 * whether anything closes the number or not. A plain "\g+0" or "\g-00" is
 * refused past its digits, on every release; a number made of zeros is zero.
 *
 * Every row was run through pcre2test 10.32 to 10.49, on php:8.0-cli,
 * php:8.2-cli, php:8.4-cli and on PHP with PCRE2 10.49, and on php:7.3-cli and
 * php:7.4-cli under "X": "subpattern number is too big at offset 4" for
 * "()\g{+65535", "group number is too big at offset 4" on 10.32; "a relative
 * value of zero is not allowed at offset 4" for "()\g{+0", at offset 6 for
 * "()\g+0" and 7 for "()\g+00"; "reference to non-existent subpattern at
 * offset 4" for "()\g<-2". "\g{" padded with a space is read as a number
 * from 10.43; before, "subpattern name expected at offset 5".
 */
final class GReferenceNumberErrorTest extends TestCase
{
    #[Test]
    #[DataProvider('provideRefusals')]
    public function test_the_number_of_a_g_reference_is_refused_as_pcre2_reads_it(string $pattern, string $php, string $release, ErrorCode $code, int $offset): void
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
        $targets = [['8.0', '10.35'], ['8.2', '10.40'], ['8.4', '10.44'], ['8.4', '10.46'], ['8.4', '10.47'], ['8.5', '10.49']];
        foreach ($targets as [$php, $release]) {
            yield \sprintf('plain relative zero, PCRE2 %s', $release) => ['pattern' => '/()\g+0/', 'php' => $php, 'release' => $release, 'code' => ErrorCode::BackrefZero, 'offset' => 6];
            yield \sprintf('braced number too big left open, PCRE2 %s', $release) => ['pattern' => '/()\g{+65535/', 'php' => $php, 'release' => $release, 'code' => ErrorCode::GroupNumberTooBig, 'offset' => 4];
            yield \sprintf('angled number too big left open, PCRE2 %s', $release) => ['pattern' => '/()\g<+65535/', 'php' => $php, 'release' => $release, 'code' => ErrorCode::GroupNumberTooBig, 'offset' => 4];
            yield \sprintf('braced number too big with no group, PCRE2 %s', $release) => ['pattern' => '/\g{+65536/', 'php' => $php, 'release' => $release, 'code' => ErrorCode::GroupNumberTooBig, 'offset' => 2];
        }

        // Under "X", PHP 7.3 and 7.4 refuse what PHP 8 does.
        yield 'plain relative zero under X, PCRE2 10.32' => ['pattern' => '/()\g+0/X', 'php' => '7.3', 'release' => '10.32', 'code' => ErrorCode::BackrefZero, 'offset' => 6];
        yield 'plain relative zero under X, PCRE2 10.35' => ['pattern' => '/()\g+0/X', 'php' => '7.4', 'release' => '10.35', 'code' => ErrorCode::BackrefZero, 'offset' => 6];
        yield 'braced number too big left open under X, PCRE2 10.32' => ['pattern' => '/()\g{+65535/X', 'php' => '7.3', 'release' => '10.32', 'code' => ErrorCode::GroupNumberTooBig, 'offset' => 4];

        // A plain number is refused past its digits.
        yield 'plain relative zero before a letter' => ['pattern' => '/()\g+0a/', 'php' => '8.4', 'release' => '10.44', 'code' => ErrorCode::BackrefZero, 'offset' => 6];
        yield 'plain relative zeros' => ['pattern' => '/()\g+00/', 'php' => '8.4', 'release' => '10.44', 'code' => ErrorCode::BackrefZero, 'offset' => 7];
        yield 'plain relative zeros, PCRE2 10.49' => ['pattern' => '/()\g+00/', 'php' => '8.5', 'release' => '10.49', 'code' => ErrorCode::BackrefZero, 'offset' => 7];
        yield 'plain backward zeros' => ['pattern' => '/()\g-00/', 'php' => '8.2', 'release' => '10.40', 'code' => ErrorCode::BackrefZero, 'offset' => 7];
        yield 'plain zeros' => ['pattern' => '/()\g00/', 'php' => '8.4', 'release' => '10.44', 'code' => ErrorCode::BackrefZero, 'offset' => 6];
        yield 'braced zeros' => ['pattern' => '/()\g{00}/', 'php' => '8.4', 'release' => '10.47', 'code' => ErrorCode::BackrefZero, 'offset' => 8];
        yield 'braced relative zeros' => ['pattern' => '/()\g{+00}/', 'php' => '8.4', 'release' => '10.44', 'code' => ErrorCode::BackrefZero, 'offset' => 4];
        yield 'braced backward zeros' => ['pattern' => '/()\g{-00}/', 'php' => '8.4', 'release' => '10.47', 'code' => ErrorCode::BackrefZero, 'offset' => 4];

        // Read before what closes it.
        yield 'quoted number too big left open' => ['pattern' => "/()\\g'+65535/", 'php' => '8.4', 'release' => '10.47', 'code' => ErrorCode::GroupNumberTooBig, 'offset' => 4];
        yield 'braced absolute number too big left open' => ['pattern' => '/\g{65536/', 'php' => '8.4', 'release' => '10.47', 'code' => ErrorCode::GroupNumberTooBig, 'offset' => 2];
        yield 'angled absolute number too big left open' => ['pattern' => '/\g<65536/', 'php' => '8.4', 'release' => '10.45', 'code' => ErrorCode::GroupNumberTooBig, 'offset' => 2];
        yield 'braced backward number too big left open' => ['pattern' => '/\g{-65536/', 'php' => '8.4', 'release' => '10.44', 'code' => ErrorCode::GroupNumberTooBig, 'offset' => 2];
        yield 'braced relative number too big past two groups' => ['pattern' => '/()()\g{+65534/', 'php' => '8.4', 'release' => '10.47', 'code' => ErrorCode::GroupNumberTooBig, 'offset' => 6];
        yield 'braced relative number with many digits' => ['pattern' => '/()\g{+99999999999/', 'php' => '8.4', 'release' => '10.48', 'code' => ErrorCode::GroupNumberTooBig, 'offset' => 4];
        yield 'braced number too big before a letter and a space' => ['pattern' => '/(a)\g{65536n }/', 'php' => '8.4', 'release' => '10.44', 'code' => ErrorCode::GroupNumberTooBig, 'offset' => 5];
        yield 'braced number too big before a letter and a space, PCRE2 10.47' => ['pattern' => '/(a)\g{65536n }/', 'php' => '8.4', 'release' => '10.47', 'code' => ErrorCode::GroupNumberTooBig, 'offset' => 5];
        yield 'braced relative number too big before a letter' => ['pattern' => '/()\g{+65535a/', 'php' => '8.4', 'release' => '10.47', 'code' => ErrorCode::GroupNumberTooBig, 'offset' => 4];
        yield 'angled relative number too big before a letter' => ['pattern' => '/\g<+65536a/', 'php' => '8.5', 'release' => '10.49', 'code' => ErrorCode::GroupNumberTooBig, 'offset' => 2];
        yield 'braced relative zero left open' => ['pattern' => '/()\g{+0/', 'php' => '8.4', 'release' => '10.44', 'code' => ErrorCode::BackrefZero, 'offset' => 4];
        yield 'braced relative zero left open, PCRE2 10.47' => ['pattern' => '/()\g{+0/', 'php' => '8.4', 'release' => '10.47', 'code' => ErrorCode::BackrefZero, 'offset' => 4];
        yield 'braced backward zero left open' => ['pattern' => '/()\g{-0/', 'php' => '8.4', 'release' => '10.49', 'code' => ErrorCode::BackrefZero, 'offset' => 4];
        yield 'angled relative zero left open' => ['pattern' => '/()\g<+0/', 'php' => '8.4', 'release' => '10.44', 'code' => ErrorCode::SubroutineRelativeZero, 'offset' => 4];
        yield 'angled backward zero left open, PCRE2 10.47' => ['pattern' => '/()\g<-0/', 'php' => '8.4', 'release' => '10.47', 'code' => ErrorCode::SubroutineRelativeZero, 'offset' => 4];
        yield 'angled number back past the groups left open' => ['pattern' => '/()\g<-2/', 'php' => '8.4', 'release' => '10.44', 'code' => ErrorCode::SubroutineRelativeMissing, 'offset' => 4];
        yield 'quoted number back past the groups left open, PCRE2 10.47' => ['pattern' => "/()\\g'-2/", 'php' => '8.4', 'release' => '10.47', 'code' => ErrorCode::SubroutineRelativeMissing, 'offset' => 4];

        // A padded brace holds a number from 10.43; before, a name.
        yield 'padded relative zero, PCRE2 10.44' => ['pattern' => '/()\g{ +0/', 'php' => '8.4', 'release' => '10.44', 'code' => ErrorCode::BackrefZero, 'offset' => 4];
        yield 'padded relative zero, PCRE2 10.47' => ['pattern' => '/()\g{ +0/', 'php' => '8.4', 'release' => '10.47', 'code' => ErrorCode::BackrefZero, 'offset' => 4];
        yield 'padded number too big, PCRE2 10.43' => ['pattern' => '/()\g{ 65536/', 'php' => '8.4', 'release' => '10.43', 'code' => ErrorCode::GroupNumberTooBig, 'offset' => 4];
        yield 'padded number too big, PCRE2 10.49' => ['pattern' => '/()\g{ 65536/', 'php' => '8.5', 'release' => '10.49', 'code' => ErrorCode::GroupNumberTooBig, 'offset' => 4];
        yield 'padded relative zero, PCRE2 10.42' => ['pattern' => '/()\g{ +0/', 'php' => '8.3', 'release' => '10.42', 'code' => ErrorCode::GroupNameExpected, 'offset' => 5];
        yield 'padded number, PCRE2 10.40' => ['pattern' => '/()\g{ 3/', 'php' => '8.2', 'release' => '10.40', 'code' => ErrorCode::GroupNameExpected, 'offset' => 5];
        yield 'padded backward number, PCRE2 10.42' => ['pattern' => '/()\g{ -2/', 'php' => '8.3', 'release' => '10.42', 'code' => ErrorCode::GroupNameExpected, 'offset' => 5];
        yield 'padded backward number under X, PCRE2 10.32' => ['pattern' => '/()\g{ -2/X', 'php' => '7.3', 'release' => '10.32', 'code' => ErrorCode::GroupNameExpected, 'offset' => 5];

        // A sign is no name: "subpattern name expected at offset 5" for
        // "()\g<+n>" (pcre2test 10.32 to 10.49).
        yield 'angled sign before a name' => ['pattern' => '/()\g<+n>/', 'php' => '8.4', 'release' => '10.44', 'code' => ErrorCode::GroupNameExpected, 'offset' => 5];
        yield 'quoted sign before a name, PCRE2 10.47' => ['pattern' => "/()\\g'-n'/", 'php' => '8.4', 'release' => '10.47', 'code' => ErrorCode::GroupNameExpected, 'offset' => 5];
        yield 'angled sign before a name, PCRE2 10.32' => ['pattern' => '/()\g<+n>/X', 'php' => '7.3', 'release' => '10.32', 'code' => ErrorCode::GroupNameExpected, 'offset' => 5];

        // No number at all.
        yield 'a letter after g' => ['pattern' => '/()\gx/', 'php' => '8.4', 'release' => '10.47', 'code' => ErrorCode::BackrefInvalidSyntax, 'offset' => 4];

        // A number PCRE2 reads leaves what closes it missing.
        yield 'braced number left open' => ['pattern' => '/()\g{3/', 'php' => '8.4', 'release' => '10.44', 'code' => ErrorCode::BackrefInvalidSyntax, 'offset' => 4];
        yield 'braced number left open, PCRE2 10.47' => ['pattern' => '/()\g{3/', 'php' => '8.4', 'release' => '10.47', 'code' => ErrorCode::BackrefInvalidSyntax, 'offset' => 6];
        yield 'padded number left open, PCRE2 10.43' => ['pattern' => '/()\g{ 3/', 'php' => '8.4', 'release' => '10.43', 'code' => ErrorCode::BackrefInvalidSyntax, 'offset' => 4];
    }

    /**
     * PHP 7.3 and 7.4 without "X" read a "\g" PCRE2 refuses as the letter g
     * (php:7.3-cli, php:7.4-cli compile each).
     */
    #[Test]
    #[DataProvider('provideAcceptedByPhp7')]
    public function test_php_7_reads_a_g_reference_it_refuses_as_text(string $pattern, string $php, string $release): void
    {
        $result = self::parser($php, $release)->validate($pattern);

        $this->assertTrue($result->isValid, \sprintf('%s on PHP %s with PCRE2 %s: %s', $pattern, $php, $release, (string) $result->error));
    }

    /**
     * @return iterable<string, array{pattern: string, php: string, release: string}>
     */
    public static function provideAcceptedByPhp7(): iterable
    {
        yield 'plain relative zero, PCRE2 10.32' => ['pattern' => '/()\g+0/', 'php' => '7.3', 'release' => '10.32'];
        yield 'braced relative zero left open, PCRE2 10.35' => ['pattern' => '/()\g{+0/', 'php' => '7.4', 'release' => '10.35'];
        yield 'angled number back past the groups left open, PCRE2 10.33' => ['pattern' => '/()\g<-2/', 'php' => '7.4', 'release' => '10.33'];
    }

    private static function parser(string $php, string $release): RegexParser
    {
        return RegexParser::create(['cache' => null, 'php_version' => $php, 'pcre_version' => $release, 'runtime_pcre_validation' => false]);
    }
}
