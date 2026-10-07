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
 * PCRE refuses the 251st level of parentheses as it opens it, before it
 * finds a group left open or a class never closed further on: PHP 8.4.26 /
 * PCRE2 10.49 says "parentheses are too deeply nested at offset 251" for
 * every pattern here.
 */
final class NestingLimitOnUnclosedGroupsTest extends TestCase
{
    #[Test]
    #[DataProvider('provideDeepPatterns')]
    public function test_the_nesting_limit_is_reported_before_a_later_error(string $pattern): void
    {
        $this->assertFalse(@preg_match($pattern, ''));

        $result = Regex::create(['cache' => null])->validate($pattern);

        $this->assertFalse($result->isValid);
        $this->assertSame(251, $result->offset);
        $this->assertStringContainsString('nested too deeply', (string) $result->error);
    }

    #[Test]
    #[DataProvider('provideShallowPatterns')]
    public function test_a_shallow_pattern_keeps_its_own_error(string $pattern, int $offset): void
    {
        $this->assertFalse(@preg_match($pattern, ''));

        $result = Regex::create(['cache' => null])->validate($pattern);

        $this->assertFalse($result->isValid);
        $this->assertSame($offset, $result->offset);
        $this->assertStringNotContainsString('nested too deeply', (string) $result->error);
    }

    /**
     * @return iterable<string, array{pattern: string, offset: int}>
     */
    public static function provideShallowPatterns(): iterable
    {
        // PHP: "missing closing parenthesis at offset 5".
        yield 'a group closed, then one left open' => ['pattern' => '/(a)(b/', 'offset' => 5];
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideDeepPatterns(): iterable
    {
        yield 'groups left open' => ['pattern' => '/'.str_repeat('(', 300).'/'];
        yield 'groups closed' => ['pattern' => '/'.str_repeat('(', 251).str_repeat(')', 251).'/'];
        yield 'groups left open, then a class never closed' => ['pattern' => '/'.str_repeat('(', 251).'[/'];
        yield 'groups closed, then a class never closed' => ['pattern' => '/'.str_repeat('(', 251).str_repeat(')', 251).'[/'];
    }
}
