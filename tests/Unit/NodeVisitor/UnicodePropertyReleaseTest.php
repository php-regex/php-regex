<?php

declare(strict_types=1);

/*
 * This file is part of the RegexParser package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace RegexParser\Tests\Unit\NodeVisitor;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RegexParser\Regex;

/**
 * Each PCRE2 release knows the scripts and properties of its Unicode
 * version. PHP 8.2 bundles PCRE2 10.40, 8.3 bundles 10.42 (Unicode 14), 8.4
 * and 8.5 bundle 10.44 (Unicode 15: Kawi, Nag Mundari). The Unicode 16 and 17
 * scripts and the binary properties 10.45 added are unknown to all of them,
 * as is a "^" after spaces, which 10.45 started to allow. Every verdict and
 * offset below is pcre2test's on those releases ("pcre2test -LS" and "-LP"
 * list what each knows).
 */
final class UnicodePropertyReleaseTest extends TestCase
{
    /**
     * @param list<int> $refusedBy
     */
    #[Test]
    #[DataProvider('provideProperties')]
    public function test_a_property_is_judged_for_the_pcre2_the_target_bundles(string $pattern, array $refusedBy): void
    {
        foreach ([80200, 80300, 80400, 80500] as $phpVersion) {
            $result = Regex::create(['cache' => null, 'php_version' => $phpVersion])->validate($pattern);

            if (\in_array($phpVersion, $refusedBy, true)) {
                $this->assertFalse($result->isValid, \sprintf('%s is refused on PHP %d.', $pattern, $phpVersion));
                $this->assertSame('regex.unicode.property_invalid', $result->errorCode);
                // Past the "}", where PCRE has read the whole name.
                $this->assertSame(strrpos($pattern, '}'), $result->offset, $pattern);
            } else {
                $this->assertTrue($result->isValid, \sprintf('%s compiles on PHP %d: %s', $pattern, $phpVersion, (string) $result->error));
            }
        }
    }

    /**
     * @param list<int> $refusedBy
     */
    #[Test]
    #[DataProvider('provideProperties')]
    public function test_without_a_target_the_running_pcre2_decides(string $pattern, array $refusedBy): void
    {
        unset($refusedBy);

        $this->assertSame(false !== @preg_match($pattern, ''), Regex::create(['cache' => null])->validate($pattern)->isValid, $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string, refusedBy: list<int>}>
     */
    public static function provideProperties(): iterable
    {
        $all = [80200, 80300, 80400, 80500];

        // Unicode 15, PCRE2 10.43.
        yield 'Kawi' => ['pattern' => '/\\p{Kawi}/u', 'refusedBy' => [80200, 80300]];
        yield 'Nag Mundari by its code' => ['pattern' => '/\\p{sc=Nagm}/u', 'refusedBy' => [80200, 80300]];
        yield 'Nag Mundari, loosely spelled' => ['pattern' => '/\\p{Script = nag-mundari}/u', 'refusedBy' => [80200, 80300]];

        // Unicode 16 and 17, PCRE2 10.45 and later.
        yield 'Garay' => ['pattern' => '/\\p{Garay}/u', 'refusedBy' => $all];
        yield 'Garay by its code' => ['pattern' => '/\\P{sc=Gara}/u', 'refusedBy' => $all];
        yield 'Gurung Khema in script extensions' => ['pattern' => '/\\p{scx:Gukh}/u', 'refusedBy' => $all];
        yield 'Tulu Tigalari' => ['pattern' => '/\\p{Tulu_Tigalari}/u', 'refusedBy' => $all];
        yield 'Sidetic' => ['pattern' => '/\\p{Sidetic}/u', 'refusedBy' => $all];
        yield 'Beria Erfe in script extensions' => ['pattern' => '/\\p{Script_Extensions=Berf}/u', 'refusedBy' => $all];
        yield 'binary property of 10.45' => ['pattern' => '/\\p{IDS_Unary_Operator}/u', 'refusedBy' => $all];
        yield 'binary property of 10.45 by its code' => ['pattern' => '/\\p{MCM}/u', 'refusedBy' => $all];

        // A "^" after spaces, PCRE2 10.45.
        yield 'caret after a space' => ['pattern' => '/\\p{ ^Lu}/', 'refusedBy' => $all];
        yield 'caret after spaces, name spaced out' => ['pattern' => '/\\p{  ^ L u }/', 'refusedBy' => $all];

        // Known to every release.
        yield 'Greek' => ['pattern' => '/\\p{Greek}/u', 'refusedBy' => []];
        yield 'spaces around the name' => ['pattern' => '/\\p{ Lu }/', 'refusedBy' => []];
        yield 'caret first, then a space' => ['pattern' => '/\\p{^ Lu}/', 'refusedBy' => []];
        yield 'bidi class loosely spelled' => ['pattern' => '/\\p{ bidi class = al }/u', 'refusedBy' => []];
    }
}
