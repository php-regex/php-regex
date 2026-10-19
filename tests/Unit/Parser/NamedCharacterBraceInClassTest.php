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
 * "\N{" in a class: check_escape() reads it before the class does, and
 * before a range ending on it is checked. Up to PCRE2 10.44 it reads a
 * count after "\N", as outside a class: a brace that is no count is a
 * character name, refused as unsupported where the "\N" ends; a count
 * whose numbers it refuses is refused there too; a count it reads leaves
 * "\N", which the class refuses. From 10.45 the brace is a character name
 * whatever follows it, refused where the "\N" ends, and from 10.47 past
 * the brace.
 *
 * Every row was run on php:7.3-cli (PCRE2 10.32) under "X", php:8.0-cli
 * (10.35), php:8.2-cli (10.40), php:8.3-cli (10.42), php:8.4-cli (10.44),
 * through pcre2test 10.32 to 10.49, and on PHP with PCRE2 10.49:
 * "PCRE2 does not support \F, \L, \l, \N{name}, \U, or \u at offset 3" for
 * "[\N{]" up to 10.46, at offset 4 from 10.47; at offset 5 for "[!-\N{]"
 * up to 10.46; "\N is not supported in a class at offset 3" for "[\N{2}]"
 * up to 10.44, "PCRE2 does not support..." at offset 3 on 10.45 and 10.46;
 * "number too big in {} quantifier at offset 3" for "[\N{65536}]" and
 * "numbers out of order in {} quantifier at offset 3" for "[\N{3,2}]" up
 * to 10.44; "[\N{,2}]" and "[\N{ 2}]" are a name up to 10.42, a count on
 * 10.43 and 10.44.
 */
final class NamedCharacterBraceInClassTest extends TestCase
{
    #[Test]
    #[DataProvider('provideRefusals')]
    public function test_the_brace_after_n_in_a_class_is_judged_as_the_release_reads_it(string $pattern, string $php, string $release, ErrorCode $code, int $offset): void
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
        foreach ([['8.0', '10.35'], ['8.2', '10.40'], ['8.4', '10.44'], ['8.4', '10.45'], ['8.4', '10.46']] as [$php, $release]) {
            yield \sprintf('empty name, PCRE2 %s', $release) => ['pattern' => '/[\N{]/', 'php' => $php, 'release' => $release, 'code' => ErrorCode::EscapeUnsupported, 'offset' => 3];
            yield \sprintf('empty name ending a range, PCRE2 %s', $release) => ['pattern' => '/[!-\N{]/', 'php' => $php, 'release' => $release, 'code' => ErrorCode::EscapeUnsupported, 'offset' => 5];
        }

        yield 'empty name, PCRE2 10.47' => ['pattern' => '/[\N{]/', 'php' => '8.4', 'release' => '10.47', 'code' => ErrorCode::EscapeUnsupported, 'offset' => 4];
        yield 'empty name ending a range, PCRE2 10.49' => ['pattern' => '/[!-\N{]/', 'php' => '8.5', 'release' => '10.49', 'code' => ErrorCode::EscapeUnsupported, 'offset' => 6];
        yield 'empty name under X, PCRE2 10.32' => ['pattern' => '/[\N{]/X', 'php' => '7.3', 'release' => '10.32', 'code' => ErrorCode::EscapeUnsupported, 'offset' => 3];
        yield 'empty name ending a range under X, PCRE2 10.32' => ['pattern' => '/[!-\N{]/X', 'php' => '7.3', 'release' => '10.32', 'code' => ErrorCode::EscapeUnsupported, 'offset' => 5];
        yield 'empty name ending a range under X, PCRE2 10.35' => ['pattern' => '/[!-\N{]/X', 'php' => '7.4', 'release' => '10.35', 'code' => ErrorCode::EscapeUnsupported, 'offset' => 5];
        yield 'empty name ending a range under u' => ['pattern' => '/[!-\N{]/u', 'php' => '8.4', 'release' => '10.44', 'code' => ErrorCode::EscapeUnsupported, 'offset' => 5];
        yield 'empty name ending a range under x' => ['pattern' => '/[!-\N{]/x', 'php' => '8.2', 'release' => '10.40', 'code' => ErrorCode::EscapeUnsupported, 'offset' => 5];
        yield 'empty name ending a range, negated' => ['pattern' => '/[^!-\N{]/', 'php' => '8.4', 'release' => '10.44', 'code' => ErrorCode::EscapeUnsupported, 'offset' => 6];
        yield 'empty name ending a range from a code point' => ['pattern' => '/[\N{U+41}-\N{]/u', 'php' => '8.4', 'release' => '10.44', 'code' => ErrorCode::EscapeUnsupported, 'offset' => 12];
        yield 'empty name after a member' => ['pattern' => '/[a\N{]/', 'php' => '8.4', 'release' => '10.44', 'code' => ErrorCode::EscapeUnsupported, 'offset' => 4];
        yield 'empty name starting a range' => ['pattern' => '/[\N{-a]/', 'php' => '8.4', 'release' => '10.44', 'code' => ErrorCode::EscapeUnsupported, 'offset' => 3];
        yield 'empty braces' => ['pattern' => '/[\N{}]/', 'php' => '8.3', 'release' => '10.42', 'code' => ErrorCode::EscapeUnsupported, 'offset' => 3];
        yield 'two commas' => ['pattern' => '/[\N{2,,}]/', 'php' => '8.4', 'release' => '10.44', 'code' => ErrorCode::EscapeUnsupported, 'offset' => 3];

        yield 'empty name ending a range in a class left open' => ['pattern' => '/[!-\N{/', 'php' => '8.4', 'release' => '10.44', 'code' => ErrorCode::EscapeUnsupported, 'offset' => 5];
        yield 'empty name ending a range in a class left open, PCRE2 10.45' => ['pattern' => '/[!-\N{/', 'php' => '8.4', 'release' => '10.45', 'code' => ErrorCode::EscapeUnsupported, 'offset' => 5];
        yield 'empty name ending a range in a class left open, PCRE2 10.48' => ['pattern' => '/[!-\N{/', 'php' => '8.4', 'release' => '10.48', 'code' => ErrorCode::EscapeUnsupported, 'offset' => 6];
        yield 'empty name ending a range in a class left open under X, PCRE2 10.32' => ['pattern' => '/[!-\N{/X', 'php' => '7.3', 'release' => '10.32', 'code' => ErrorCode::EscapeUnsupported, 'offset' => 5];

        // A code point with no digits is refused as it is read, before the
        // range it ends (pcre2test 10.32 to 10.49, php:8.4-cli: "digits
        // missing in \x{} or \o{} or \N{U+} at offset 8").
        yield 'code point with no digits ending a range' => ['pattern' => '/[!-\N{U+}]/u', 'php' => '8.4', 'release' => '10.44', 'code' => ErrorCode::EscapeDigitsMissing, 'offset' => 8];
        yield 'code point with no digits ending a range, PCRE2 10.35' => ['pattern' => '/[!-\N{U+}]/u', 'php' => '8.0', 'release' => '10.35', 'code' => ErrorCode::EscapeDigitsMissing, 'offset' => 8];
        yield 'code point with no digits starting a range' => ['pattern' => '/[\N{U+}-a]/u', 'php' => '8.4', 'release' => '10.44', 'code' => ErrorCode::EscapeDigitsMissing, 'offset' => 6];

        // A count with no minimum, or padded, is a name before 10.43.
        yield 'count with no minimum, PCRE2 10.40' => ['pattern' => '/[\N{,2}]/', 'php' => '8.2', 'release' => '10.40', 'code' => ErrorCode::EscapeUnsupported, 'offset' => 3];
        yield 'count with no minimum, PCRE2 10.44' => ['pattern' => '/[\N{,2}]/', 'php' => '8.4', 'release' => '10.44', 'code' => ErrorCode::CharclassInvalidEscape, 'offset' => 3];
        yield 'padded count, PCRE2 10.42' => ['pattern' => '/[\N{ 2}]/', 'php' => '8.3', 'release' => '10.42', 'code' => ErrorCode::EscapeUnsupported, 'offset' => 3];
        yield 'padded count, PCRE2 10.44' => ['pattern' => '/[\N{ 2}]/', 'php' => '8.4', 'release' => '10.44', 'code' => ErrorCode::CharclassInvalidEscape, 'offset' => 3];

        // A count whose numbers PCRE2 refuses, up to 10.44.
        yield 'count too big' => ['pattern' => '/[\N{65536}]/', 'php' => '8.4', 'release' => '10.44', 'code' => ErrorCode::QuantifierTooBig, 'offset' => 3];
        yield 'maximum too big' => ['pattern' => '/[\N{2,65536}]/', 'php' => '8.0', 'release' => '10.35', 'code' => ErrorCode::QuantifierTooBig, 'offset' => 3];
        yield 'count out of order' => ['pattern' => '/[\N{3,2}]/', 'php' => '8.2', 'release' => '10.40', 'code' => ErrorCode::QuantifierInvalidRange, 'offset' => 3];
        yield 'count out of order ending a range' => ['pattern' => '/[!-\N{3,2}]/', 'php' => '8.4', 'release' => '10.44', 'code' => ErrorCode::QuantifierInvalidRange, 'offset' => 5];
        yield 'count out of order under X, PCRE2 10.32' => ['pattern' => '/[\N{3,2}]/X', 'php' => '7.3', 'release' => '10.32', 'code' => ErrorCode::QuantifierInvalidRange, 'offset' => 3];

        // A count PCRE2 reads leaves "\N", refused in a class, up to 10.44.
        yield 'count' => ['pattern' => '/[\N{2}]/', 'php' => '8.4', 'release' => '10.44', 'code' => ErrorCode::CharclassInvalidEscape, 'offset' => 3];
        yield 'count ending a range' => ['pattern' => '/[!-\N{2}]/', 'php' => '8.4', 'release' => '10.44', 'code' => ErrorCode::RangeInvalidBounds, 'offset' => 5];

        // From 10.45 a count is a name too.
        yield 'count, PCRE2 10.45' => ['pattern' => '/[\N{2}]/', 'php' => '8.4', 'release' => '10.45', 'code' => ErrorCode::EscapeUnsupported, 'offset' => 3];
        yield 'count too big, PCRE2 10.46' => ['pattern' => '/[\N{65536}]/', 'php' => '8.4', 'release' => '10.46', 'code' => ErrorCode::EscapeUnsupported, 'offset' => 3];
        yield 'count ending a range, PCRE2 10.45' => ['pattern' => '/[!-\N{2}]/', 'php' => '8.4', 'release' => '10.45', 'code' => ErrorCode::EscapeUnsupported, 'offset' => 5];
        yield 'count out of order, PCRE2 10.47' => ['pattern' => '/[\N{3,2}]/', 'php' => '8.4', 'release' => '10.47', 'code' => ErrorCode::EscapeUnsupported, 'offset' => 4];
    }

    /**
     * PHP 7.3 and 7.4 read a "\N{" PCRE2 refuses as the letter N without
     * "X" (php:7.3-cli and php:7.4-cli compile each); a padded code point is
     * one from 10.43 (php:8.4-cli compiles it).
     */
    #[Test]
    #[DataProvider('provideAccepted')]
    public function test_a_brace_after_n_the_release_reads_compiles(string $pattern, string $php, string $release): void
    {
        $result = self::parser($php, $release)->validate($pattern);

        $this->assertTrue($result->isValid, \sprintf('%s on PHP %s with PCRE2 %s: %s', $pattern, $php, $release, (string) $result->error));
    }

    /**
     * @return iterable<string, array{pattern: string, php: string, release: string}>
     */
    public static function provideAccepted(): iterable
    {
        yield 'empty name ending a range, PCRE2 10.32' => ['pattern' => '/[!-\N{]/', 'php' => '7.3', 'release' => '10.32'];
        yield 'empty name, PCRE2 10.35' => ['pattern' => '/[\N{]/', 'php' => '7.4', 'release' => '10.35'];
        yield 'count out of order, PCRE2 10.33' => ['pattern' => '/[\N{3,2}]/', 'php' => '7.4', 'release' => '10.33'];
        yield 'padded code point, PCRE2 10.44' => ['pattern' => '/[\N{ U+41}]/u', 'php' => '8.4', 'release' => '10.44'];
    }

    private static function parser(string $php, string $release): RegexParser
    {
        return RegexParser::create(['cache' => null, 'php_version' => $php, 'pcre_version' => $release, 'runtime_pcre_validation' => false]);
    }
}
