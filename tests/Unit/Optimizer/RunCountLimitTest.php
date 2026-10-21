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

namespace PHPRegex\Tests\Unit\Optimizer;

use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * PCRE takes at most 65535 in one count, so a run of one item compacted
 * past that count goes on in a count of its own: "a{40000}a{30000}"
 * compiles, where the "a{70000}" it would compact to is refused as too
 * big. The rewrite keeps two counts and matches the same.
 */
final class RunCountLimitTest extends TestCase
{
    #[Test]
    #[DataProvider('provideLongRuns')]
    public function test_a_run_compacted_past_the_largest_count_still_compiles(string $pattern, string $subject): void
    {
        $regex = Regex::create(['cache' => null]);

        $this->assertSame(1, preg_match($pattern, $subject), 'Oracle: PCRE compiles and matches the pattern.');

        $optimized = $regex->optimize($pattern)->optimized;

        $this->assertTrue($regex->validate($optimized)->isValid, $optimized);
        $this->assertStringNotContainsString('{70000}', $optimized);
        $this->assertSame(
            preg_match($pattern, $subject, $before),
            preg_match($optimized, $subject, $after),
            $optimized,
        );
        $this->assertSame($before, $after, $optimized);
    }

    /**
     * @return iterable<string, array{pattern: string, subject: string}>
     */
    public static function provideLongRuns(): iterable
    {
        yield 'a literal counted twice past the largest count' => ['pattern' => '/a{40000}a{30000}/', 'subject' => str_repeat('a', 70000)];
        yield 'two equal counts past the largest count' => ['pattern' => '/a{33000}a{33000}/', 'subject' => str_repeat('a', 66000)];
        yield 'a count one past the largest count' => ['pattern' => '/a{65535}a{2}/', 'subject' => str_repeat('a', 65537)];
        yield 'a class counted twice past the largest count' => ['pattern' => '/\d{40000}\d{30000}/', 'subject' => str_repeat('1', 70000)];
    }
}
