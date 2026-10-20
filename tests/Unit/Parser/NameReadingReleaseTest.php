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
 * PCRE2 reads every group name with read_name(): a digit first is refused
 * ("subpattern name must start with a non-digit"), then the characters a
 * name holds are read, the name is measured ("subpattern name is too
 * long"), an empty one refused ("subpattern name expected"), and what must
 * close it looked for ("syntax error in subpattern name (missing
 * terminator?)"). Up to 10.46 the name of "(?&name)" and "(?P>name)" must be
 * closed by ")"; from 10.47 it is read alone, and a missing ")" is "missing
 * closing parenthesis". 10.32 counts the characters as it reads them, and
 * refuses a name on the one past the limit.
 *
 * Every row was run through pcre2test 10.32 to 10.49, under "X" for PHP 7.3
 * and 7.4.
 */
final class NameReadingReleaseTest extends TestCase
{
    #[Test]
    #[DataProvider('provideRefusals')]
    public function test_a_name_is_refused_where_pcre2_stops_reading_it(string $pattern, string $php, string $release, ErrorCode $code, int $offset): void
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
        // Up to 10.46 the ")" must close the name of a call; from 10.47 PCRE2
        // reads the name alone, and a ")" is then missing.
        yield 'missing terminator after a call name, 10.33' => ['pattern' => '/(?<n>a)(?&n /X', 'php' => '7.4', 'release' => '10.33', 'code' => ErrorCode::GroupNameUnterminated, 'offset' => 11];
        yield 'missing terminator after a call name, 10.46' => ['pattern' => '/(?<n>a)(?&n /', 'php' => '8.4', 'release' => '10.46', 'code' => ErrorCode::GroupNameUnterminated, 'offset' => 11];
        yield 'call name the pattern ends in, 10.44' => ['pattern' => '/(?<n>a)(?&n/', 'php' => '8.4', 'release' => '10.44', 'code' => ErrorCode::GroupNameUnterminated, 'offset' => 11];
        yield 'Python call name before a space, 10.40' => ['pattern' => '/(?<n>a)(?P>n /', 'php' => '8.2', 'release' => '10.40', 'code' => ErrorCode::GroupNameUnterminated, 'offset' => 12];
        yield 'call name before a parenthesis, 10.46' => ['pattern' => '/(?<n>a)(?&n(/', 'php' => '8.4', 'release' => '10.46', 'code' => ErrorCode::GroupNameUnterminated, 'offset' => 11];
        yield 'call name before a hyphen, 10.42' => ['pattern' => '/(?<n>a)(?&n-)/', 'php' => '8.3', 'release' => '10.42', 'code' => ErrorCode::GroupNameUnterminated, 'offset' => 11];
        yield 'call name before a space, 10.47' => ['pattern' => '/(?<n>a)(?&n /', 'php' => '8.4', 'release' => '10.47', 'code' => ErrorCode::GroupUnclosed, 'offset' => 11];
        yield 'call name before a byte past ASCII, 10.46' => ['pattern' => '/(?<n>a)(?&aé)/', 'php' => '8.4', 'release' => '10.46', 'code' => ErrorCode::GroupNameUnterminated, 'offset' => 11];
        yield 'call name before a byte past ASCII, 10.47' => ['pattern' => '/(?<n>a)(?&aé)/', 'php' => '8.4', 'release' => '10.47', 'code' => ErrorCode::GroupUnclosed, 'offset' => 11];

        yield 'call name starting with a digit' => ['pattern' => '/(?<n>a)(?&1n)/', 'php' => '8.2', 'release' => '10.40', 'code' => ErrorCode::GroupNameInvalid, 'offset' => 10];
        yield 'call name starting with a digit, 10.47' => ['pattern' => '/(?<n>a)(?&1n)/', 'php' => '8.4', 'release' => '10.47', 'code' => ErrorCode::GroupNameInvalid, 'offset' => 11];
        yield 'Python call name starting with a digit' => ['pattern' => '/(?<n>a)(?P>1)/', 'php' => '8.4', 'release' => '10.47', 'code' => ErrorCode::GroupNameInvalid, 'offset' => 12];
        yield 'call name starting with an Arabic digit under UTF, 10.47' => ['pattern' => '/(?<n>a)(?&١)/u', 'php' => '8.4', 'release' => '10.47', 'code' => ErrorCode::GroupNameInvalid, 'offset' => 12];

        // A name holds ASCII word characters without UTF, and on 10.32 under
        // UTF too: a byte past them leaves no name.
        yield 'call on a byte past ASCII' => ['pattern' => '/(?<n>a)(?&é)/', 'php' => '8.4', 'release' => '10.44', 'code' => ErrorCode::GroupNameExpected, 'offset' => 10];
        yield 'call on a byte past ASCII, 10.49' => ['pattern' => '/(?<n>a)(?&é)/', 'php' => '8.5', 'release' => '10.49', 'code' => ErrorCode::GroupNameExpected, 'offset' => 10];
        yield 'call on a byte past ASCII the pattern ends in' => ['pattern' => '/(?<n>a)(?&é/', 'php' => '8.2', 'release' => '10.40', 'code' => ErrorCode::GroupNameExpected, 'offset' => 10];
        yield 'call on an Arabic digit without UTF' => ['pattern' => '/(?<n>a)(?&١)/', 'php' => '8.4', 'release' => '10.44', 'code' => ErrorCode::GroupNameExpected, 'offset' => 10];
        yield 'Python call on an Arabic digit without UTF' => ['pattern' => '/(?<n>a)(?P>١)/', 'php' => '8.4', 'release' => '10.47', 'code' => ErrorCode::GroupNameExpected, 'offset' => 11];
        yield 'call on a letter past ASCII under UTF, 10.32' => ['pattern' => '/(?<n>a)(?&é)/uX', 'php' => '7.3', 'release' => '10.32', 'code' => ErrorCode::GroupNameExpected, 'offset' => 10];
        yield 'call name before a letter past ASCII under UTF, 10.32' => ['pattern' => '/(?<n>a)(?&aé)/uX', 'php' => '7.3', 'release' => '10.32', 'code' => ErrorCode::GroupNameUnterminated, 'offset' => 11];
        yield 'call on an Arabic digit under UTF, 10.32' => ['pattern' => '/(?<n>a)(?&١)/uX', 'php' => '7.3', 'release' => '10.32', 'code' => ErrorCode::GroupNameExpected, 'offset' => 10];
        yield 'condition on an Arabic digit under UTF, 10.32' => ['pattern' => '/(?<n>a)(?(١)a)/uX', 'php' => '7.3', 'release' => '10.32', 'code' => ErrorCode::ConditionalInvalid, 'offset' => 10];

        // The name of "(?(R&" wants a ")".
        yield 'recursion condition name the pattern ends in' => ['pattern' => '/(?<n>a)(?(R&n/', 'php' => '8.4', 'release' => '10.44', 'code' => ErrorCode::GroupNameUnterminated, 'offset' => 13];
        yield 'recursion condition name before a letter' => ['pattern' => '/(?<n>a)(?(R&n>a)/', 'php' => '8.5', 'release' => '10.49', 'code' => ErrorCode::GroupNameUnterminated, 'offset' => 13];

        // A digit first after padding is refused past the digit from 10.47.
        yield 'padded braced Arabic digit under UTF, 10.47' => ['pattern' => '/(?<n>a)\\g{١ /u', 'php' => '8.4', 'release' => '10.47', 'code' => ErrorCode::GroupNameInvalid, 'offset' => 12];

        // PCRE2 measures a name before it looks for what closes it, from 10.33
        // once read whole; 10.32 refuses it on the character past the limit.
        yield 'reference name too long nothing closes' => ['pattern' => '/(?<n>a)\\k<'.str_repeat('a', 33).' /', 'php' => '8.3', 'release' => '10.42', 'code' => ErrorCode::GroupNameTooLong, 'offset' => 43];
        yield 'braced reference name too long nothing closes, 10.43' => ['pattern' => '/(?<n>a)\\k{'.str_repeat('a', 33).' /', 'php' => '8.4', 'release' => '10.43', 'code' => ErrorCode::GroupNameTooLong, 'offset' => 43];
        yield 'g reference name too long the pattern ends in' => ['pattern' => '/(?<n>a)\\g<'.str_repeat('a', 33).'/', 'php' => '8.2', 'release' => '10.40', 'code' => ErrorCode::GroupNameTooLong, 'offset' => 43];
        yield 'quoted reference name too long before a letter' => ['pattern' => '/(?<n>a)\\k\''.str_repeat('a', 33).'x/', 'php' => '8.4', 'release' => '10.43', 'code' => ErrorCode::GroupNameTooLong, 'offset' => 44];
        yield 'reference name past 128 nothing closes' => ['pattern' => '/(?<n>a)\\k<'.str_repeat('a', 129).'x/', 'php' => '8.4', 'release' => '10.44', 'code' => ErrorCode::GroupNameTooLong, 'offset' => 140];
        yield 'reference name too long before a byte past ASCII' => ['pattern' => '/(?<n>a)\\k<'.str_repeat('a', 33).'é>/', 'php' => '8.2', 'release' => '10.40', 'code' => ErrorCode::GroupNameTooLong, 'offset' => 43];
        yield 'call name too long before a space' => ['pattern' => '/(?<n>a)(?&'.str_repeat('a', 33).' /', 'php' => '8.2', 'release' => '10.40', 'code' => ErrorCode::GroupNameTooLong, 'offset' => 43];
        yield 'call name past 128 before a space' => ['pattern' => '/(?<n>a)(?&'.str_repeat('a', 129).' /', 'php' => '8.4', 'release' => '10.47', 'code' => ErrorCode::GroupNameTooLong, 'offset' => 139];
        yield 'condition name too long before a space' => ['pattern' => '/(?('.str_repeat('a', 33).' )a)/', 'php' => '8.2', 'release' => '10.40', 'code' => ErrorCode::GroupNameTooLong, 'offset' => 36];
        yield 'group name too long, 10.32' => ['pattern' => '/(?<'.str_repeat('a', 33).'x/X', 'php' => '7.3', 'release' => '10.32', 'code' => ErrorCode::GroupNameTooLong, 'offset' => 36];
        yield 'closed group name past 32, 10.32' => ['pattern' => '/(?<'.str_repeat('a', 128).'>x)/X', 'php' => '7.3', 'release' => '10.32', 'code' => ErrorCode::GroupNameTooLong, 'offset' => 36];
        yield 'Python group name too long, 10.32' => ['pattern' => '/(?P<'.str_repeat('a', 129).'/X', 'php' => '7.3', 'release' => '10.32', 'code' => ErrorCode::GroupNameTooLong, 'offset' => 37];
        yield 'call name too long, 10.32' => ['pattern' => '/(?<n>a)(?&'.str_repeat('a', 33).'x/X', 'php' => '7.3', 'release' => '10.32', 'code' => ErrorCode::GroupNameTooLong, 'offset' => 43];
        yield 'closed call name past 32, 10.32' => ['pattern' => '/(?<n>a)(?&'.str_repeat('a', 128).')/X', 'php' => '7.3', 'release' => '10.32', 'code' => ErrorCode::GroupNameTooLong, 'offset' => 43];
        yield 'name past 128 in the groups a scan reads' => ['pattern' => '/(*scan_substring:(<'.str_repeat('b', 129).'>)x)/', 'php' => '8.4', 'release' => '10.45', 'code' => ErrorCode::GroupNameTooLong, 'offset' => 148];
        yield 'name past 128 in the groups a call returns' => ['pattern' => '/(?<n>a)(?&n(<'.str_repeat('b', 129).'>))/', 'php' => '8.4', 'release' => '10.47', 'code' => ErrorCode::GroupNameTooLong, 'offset' => 142];
        yield 'quoted name past 128 in the groups a call returns' => ['pattern' => "/(?<n>a)(?&n('".str_repeat('b', 129)."'))/", 'php' => '8.5', 'release' => '10.49', 'code' => ErrorCode::GroupNameTooLong, 'offset' => 142];
        yield 'name past 128 nothing closes in the groups a call returns' => ['pattern' => '/(?<n>a)(?&n(<'.str_repeat('b', 129).'/', 'php' => '8.4', 'release' => '10.48', 'code' => ErrorCode::GroupNameTooLong, 'offset' => 142];
    }
}
