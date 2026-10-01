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
use PHPUnit\Framework\TestCase;

final class OptimizeFlagOnlyChangesTest extends TestCase
{
    #[DataProvider('provideFlagOnlyOptimizations')]
    public function test_flag_only_optimizations_preserve_pattern_body(string $pattern, string $expectedOptimized): void
    {
        $optimized = Regex::create()->optimize($pattern)->optimized;

        $this->assertSame($expectedOptimized, $optimized);
    }

    public static function provideFlagOnlyOptimizations(): \Generator
    {
        yield 'lang attribute keeps escaped quotes' => [
            "/<html [^>]*lang=['\\\"](.*?)['\\\"]/ism",
            "/<html [^>]*lang=['\\\"](.*?)['\\\"]/is",
        ];
        yield 'dir attribute keeps escaped quotes' => [
            "/<html [^>]*dir=['\\\"]\\s*rtl\\s*['\\\"]/ism",
            "/<html [^>]*dir=['\\\"]\\s*rtl\\s*['\\\"]/i",
        ];
        yield 'style attribute keeps escaped quotes' => [
            '/style=[\\"](.*?)[\\"]/ism',
            '/style=[\\"](.*?)[\\"]/is',
        ];
        yield 'style attribute single quotes' => [
            "/style=['](.*?)[']/ism",
            "/style=['](.*?)[']/is",
        ];
        yield 'lang attribute shorthand' => [
            "/lang=['\\\"](.*?)['\\\"]/ism",
            "/lang=['\\\"](.*?)['\\\"]/is",
        ];
    }
}
