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
 * Under /x, a possessive rewrite keeps the matches when an inline or
 * start-of-pattern option changes what the dot or the newline reads.
 */
final class PossessiveUnderExtendedModeTest extends TestCase
{
    #[Test]
    #[DataProvider('providePatterns')]
    public function test_the_possessive_rewrite_keeps_the_matches(string $pattern): void
    {
        $optimized = Regex::create()->optimize($pattern, ['possessive' => true])->optimized;

        foreach (["a\n", "ab\n", "a\r\n", "a\nb\n", "ab\r", "12a\n", "12a\rb\n"] as $subject) {
            $this->assertSame(preg_match($pattern, $subject), preg_match($optimized, $subject), $optimized.' on '.json_encode($subject));
        }
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function providePatterns(): iterable
    {
        yield 'carriage return newline' => ['pattern' => '/(*CR)[0-9][0-9]a.*\n/x'];
        yield 'dot-all set inline' => ['pattern' => '/(?s)a.*\n/x'];
        yield 'dot-all modifier' => ['pattern' => '/a.*\n/xs'];
        yield 'any newline convention' => ['pattern' => '/(*ANYCRLF)a.*\r/x'];
        yield 'plain' => ['pattern' => '/a.*\n/x'];
    }
}
