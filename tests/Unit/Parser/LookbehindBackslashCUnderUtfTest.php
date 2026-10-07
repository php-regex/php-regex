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

use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * "(*UTF)" turns UTF-8 mode on without the "u" flag: "\C" compiles, except
 * in a lookbehind, which PHP refuses where the lookbehind is measured
 * ("\C is not allowed in a lookbehind assertion in UTF-8 mode at offset 6").
 */
final class LookbehindBackslashCUnderUtfTest extends TestCase
{
    #[Test]
    #[DataProvider('provideLookbehinds')]
    public function test_backslash_c_in_a_lookbehind_under_utf_is_refused(string $pattern, int $offset): void
    {
        $this->assertFalse(@preg_match($pattern, ''));

        $result = Regex::create(['cache' => null])->validate($pattern);

        $this->assertFalse($result->isValid);
        $this->assertSame($offset, $result->offset);
    }

    /**
     * @return iterable<string, array{pattern: string, offset: int}>
     */
    public static function provideLookbehinds(): iterable
    {
        yield 'utf verb' => ['pattern' => '/(*UTF)(?<=b\C)/', 'offset' => 6];
    }

    /**
     * With a branch reset anywhere in the pattern, PCRE2 measures no back
     * reference in a lookbehind: "length of lookbehind assertion is not
     * limited", where the lookbehind starts.
     */
    #[Test]
    #[DataProvider('provideReferencesBesideABranchReset')]
    public function test_a_back_reference_in_a_lookbehind_beside_a_branch_reset_is_not_limited(string $pattern, int $offset): void
    {
        $this->assertFalse(@preg_match($pattern, ''));

        $result = Regex::create(['cache' => null])->validate($pattern);

        $this->assertFalse($result->isValid, $pattern);
        $this->assertSame($offset, $result->offset, $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string, offset: int}>
     */
    public static function provideReferencesBesideABranchReset(): iterable
    {
        yield 'number' => ['pattern' => '/(a)(?|b|c)(?<=\1)/', 'offset' => 10];
        yield 'name' => ['pattern' => '/(?<n>a)(?|b|c)(?<=\k<n>)/', 'offset' => 14];
        yield 'g number' => ['pattern' => '/(a)(?|b|c)(?<=\g1)/', 'offset' => 10];
        yield 'branch reset after the lookbehind' => ['pattern' => '/(a)(?<=\1)(?|b|c)/', 'offset' => 3];
    }

    #[Test]
    public function test_backslash_c_outside_a_lookbehind_under_utf_compiles(): void
    {
        // The JIT does not take \C under UTF and warns; the match runs.
        $this->assertNotFalse(@preg_match('/(*UTF)b\C/', 'bx'));
        $this->assertTrue(Regex::create(['cache' => null])->validate('/(*UTF)b\C/')->isValid);
    }
}
