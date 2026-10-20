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
 * An extended class nested too deeply is refused on the "(" that goes past
 * the limit from PCRE2 10.47, one byte past it before: the one offset that
 * release moved back rather than forward.
 *
 * Oracle (PHP 8.4.26, PCRE2 10.49): the pattern below is refused with
 * "extended character class nesting is too deep at offset 69"; the
 * PCRE2 test data of 10.45 and 10.46 put it one byte further.
 */
final class ExtendedClassNestingOffsetTest extends TestCase
{
    #[Test]
    #[DataProvider('provideReleases')]
    public function test_the_nesting_limit_is_refused_where_the_release_says(string $pattern, string $pcreVersion, int $offset): void
    {
        if (version_compare(explode(' ', \PCRE_VERSION)[0], '10.47', '>=')) {
            $this->assertFalse(@preg_match($pattern, ''), 'Oracle: PCRE refuses the nesting.');
            $this->assertStringEndsWith('at offset 69', (string) (error_get_last()['message'] ?? ''), 'Oracle: PCRE refuses it there.');
        }

        $result = RegexParser::create(['cache' => null, 'php_version' => '8.4', 'pcre_version' => $pcreVersion])->validate($pattern);

        $this->assertSame(ErrorCode::ExtendedClassNestedTooDeep, $result->errorCode);
        $this->assertSame($offset, $result->offset);
    }

    /**
     * @return iterable<string, array{pattern: string, pcreVersion: string, offset: int}>
     */
    public static function provideReleases(): iterable
    {
        $pattern = '/(?['.str_repeat('([a]&', 16).'[b]'.str_repeat(')', 16).'])/';

        yield 'PCRE2 10.46' => ['pattern' => $pattern, 'pcreVersion' => '10.46', 'offset' => 70];
        yield 'PCRE2 10.47' => ['pattern' => $pattern, 'pcreVersion' => '10.47', 'offset' => 69];
    }
}
