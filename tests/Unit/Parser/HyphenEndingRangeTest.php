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
 * A "-" that ends a range, "[!--\pL]", starts none: the "\p" after it is a
 * member, and an error PCRE meets later in the pattern is the one reported,
 * a quoted "-" starting the range too, "[\Q-\E--\pL]".
 *
 * Every row was run through pcre2test 10.32 to 10.49: "missing closing
 * parenthesis at offset 13" for "([\Q-\E--\pL]" and "at offset 9" for
 * "([!--\pL]" on every release; "invalid range in character class at
 * offset 7" for "[!--\pL-z]" up to 10.44, at offset 8 from 10.45.
 */
final class HyphenEndingRangeTest extends TestCase
{
    #[Test]
    #[DataProvider('provideRefusals')]
    public function test_a_hyphen_ending_a_range_starts_none(string $pattern, string $php, string $release, ErrorCode $code, int $offset): void
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
        foreach ([['7.3', '10.32'], ['8.2', '10.40'], ['8.4', '10.44'], ['8.4', '10.45'], ['8.5', '10.49']] as [$php, $release]) {
            yield \sprintf('range from a quoted hyphen to a hyphen, then a property, PCRE2 %s', $release) => ['pattern' => '/([\Q-\E--\pL]/'.($php < '8' ? 'X' : ''), 'php' => $php, 'release' => $release, 'code' => ErrorCode::GroupUnclosed, 'offset' => 13];
        }

        yield 'range to a hyphen, then a property' => ['pattern' => '/([!--\pL]/', 'php' => '8.4', 'release' => '10.44', 'code' => ErrorCode::GroupUnclosed, 'offset' => 9];
        yield 'range from a hyphen to a hyphen, then a property' => ['pattern' => '/([---\pL]/', 'php' => '8.2', 'release' => '10.40', 'code' => ErrorCode::GroupUnclosed, 'offset' => 9];
        yield 'range from an escaped hyphen to a hyphen, then a property' => ['pattern' => '/([\---\pL]/', 'php' => '8.4', 'release' => '10.44', 'code' => ErrorCode::GroupUnclosed, 'offset' => 10];
        yield 'range from a hex hyphen to a hyphen, then a property' => ['pattern' => '/([\x2d--\pL]/', 'php' => '8.4', 'release' => '10.44', 'code' => ErrorCode::GroupUnclosed, 'offset' => 12];
        yield 'range to a hyphen, then a range from a property' => ['pattern' => '/[!--\pL-z]/', 'php' => '8.4', 'release' => '10.44', 'code' => ErrorCode::RangeInvalidBounds, 'offset' => 7];
        yield 'range to a hyphen, then a range from a property, PCRE2 10.45' => ['pattern' => '/[!--\pL-z]/', 'php' => '8.4', 'release' => '10.45', 'code' => ErrorCode::RangeInvalidBounds, 'offset' => 8];
        yield 'range from a quoted hyphen, then a range from a property' => ['pattern' => '/[\Q-\E--\pL-z]/', 'php' => '8.4', 'release' => '10.44', 'code' => ErrorCode::RangeInvalidBounds, 'offset' => 11];
        yield 'range to a hyphen, then a property, class left open' => ['pattern' => '/[!--\pL/', 'php' => '8.4', 'release' => '10.44', 'code' => ErrorCode::CharclassUnclosed, 'offset' => 7];
        yield 'range from a quoted hyphen, then a property, class left open' => ['pattern' => '/[\Q-\E--\pL/', 'php' => '8.4', 'release' => '10.44', 'code' => ErrorCode::CharclassUnclosed, 'offset' => 11];

        // A hyphen first in the class is a member: the "-" after it starts
        // a range to the property (pcre2test: "invalid range in character
        // class at offset 6", 7 from 10.45).
        yield 'hyphen first, then a range to a property' => ['pattern' => '/([--\pL]/', 'php' => '8.4', 'release' => '10.44', 'code' => ErrorCode::RangeInvalidBounds, 'offset' => 6];
    }
}
