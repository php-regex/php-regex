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
 * A "\p{" or "\P{" that no "}" closes stops what PCRE reads: it looks for
 * the "}" to the end of the pattern. The items of the class it stands in,
 * read before it, are judged first.
 *
 * Every row was run through pcre2test 10.32 to 10.49: "range out of order
 * in character class at offset 3" for "[z-aa-\p{L]" up to 10.46, at offset
 * 4 from 10.47; "at offset 4" for "([z-a\P{]" up to 10.46, 5 from 10.47.
 */
final class ClassItemBeforeUnclosedPropertyTest extends TestCase
{
    #[Test]
    #[DataProvider('provideRefusals')]
    public function test_an_item_before_an_unclosed_property_comes_first(string $pattern, string $php, string $release, ErrorCode $code, int $offset): void
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
        foreach ([['7.3', '10.32', 3], ['8.2', '10.40', 3], ['8.4', '10.44', 3], ['8.4', '10.45', 3], ['8.4', '10.47', 4]] as [$php, $release, $offset]) {
            yield \sprintf('reversed range before a range to an unclosed property, PCRE2 %s', $release) => ['pattern' => '/[z-aa-\p{L]/'.($php < '8' ? 'X' : ''), 'php' => $php, 'release' => $release, 'code' => ErrorCode::RangeReversed, 'offset' => $offset];
        }

        foreach ([['7.3', '10.32', 4], ['8.2', '10.40', 4], ['8.4', '10.44', 4], ['8.4', '10.45', 4], ['8.4', '10.47', 5], ['8.5', '10.49', 5]] as [$php, $release, $offset]) {
            yield \sprintf('reversed range before an unclosed property with no name, PCRE2 %s', $release) => ['pattern' => '/([z-a\P{]/'.($php < '8' ? 'X' : ''), 'php' => $php, 'release' => $release, 'code' => ErrorCode::RangeReversed, 'offset' => $offset];
        }
    }
}
