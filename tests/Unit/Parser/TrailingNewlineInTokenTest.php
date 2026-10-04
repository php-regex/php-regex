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

use PHPRegex\Tests\TestUtils\PhpErrorOffset;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A group name or a limit that ends with a newline is no name and no limit:
 * PCRE refuses "(?<n\n>a)" and "(*LIMIT_MATCH=1\n)". A check written with
 * "$" takes them, as "$" matches before a final newline.
 */
final class TrailingNewlineInTokenTest extends TestCase
{
    #[Test]
    #[DataProvider('provideRefusedPatterns')]
    public function test_a_trailing_newline_is_refused_as_php_refuses_it(string $pattern): void
    {
        $this->assertFalse(@preg_match($pattern, ''), \sprintf('%s must be refused by PHP.', json_encode($pattern)));

        $result = Regex::create()->validate($pattern);

        $this->assertFalse($result->isValid, \sprintf('%s is refused by PHP but was reported valid.', json_encode($pattern)));
        $this->assertSame(PhpErrorOffset::of($pattern), $result->offset, json_encode($pattern) ?: $pattern);
    }

    #[Test]
    #[DataProvider('provideAcceptedPatterns')]
    public function test_the_same_token_without_the_newline_stays_valid(string $pattern): void
    {
        $this->assertNotFalse(@preg_match($pattern, ''));
        $this->assertTrue(Regex::create()->validate($pattern)->isValid);
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideRefusedPatterns(): iterable
    {
        yield 'angle-bracket name' => ['pattern' => "/(?<n\n>a)/"];
        yield 'quoted name' => ['pattern' => "/(?'n\n'a)/"];
        yield 'python name' => ['pattern' => "/(?P<n\n>a)/"];
        yield 'match limit' => ['pattern' => "/(*LIMIT_MATCH=1\n)a/"];
        yield 'heap limit' => ['pattern' => "/(*LIMIT_HEAP=1\n)a/"];
        yield 'depth limit' => ['pattern' => "/(*LIMIT_DEPTH=1\n)a/"];
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideAcceptedPatterns(): iterable
    {
        yield 'angle-bracket name' => ['pattern' => '/(?<n>a)/'];
        yield 'match limit' => ['pattern' => '/(*LIMIT_MATCH=1)a/'];
        yield 'heap limit' => ['pattern' => '/(*LIMIT_HEAP=1)a/'];
    }
}
