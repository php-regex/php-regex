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
 * One pass does all the optimizer does: its output optimized again comes
 * back as it is, with no change to report.
 */
final class OptimizerIdempotenceTest extends TestCase
{
    #[Test]
    #[DataProvider('providePatterns')]
    public function test_the_output_optimized_again_is_unchanged(string $pattern, string $optimized): void
    {
        $regex = Regex::create(['cache' => null]);

        $once = $regex->optimize($pattern);
        $this->assertSame($optimized, $once->optimized);

        $twice = $regex->optimize($once->optimized);
        $this->assertSame($once->optimized, $twice->optimized);
        $this->assertSame([], $twice->changes);
    }

    /**
     * @return iterable<string, array{pattern: string, optimized: string}>
     */
    public static function providePatterns(): iterable
    {
        // The branches become a class, the class a range, and the group
        // around one character goes, all in one pass.
        yield 'repeated branches of one letter' => ['pattern' => '/(?:a|b|c)*d/', 'optimized' => '/[a-c]*d/'];
        yield 'branches of one letter' => ['pattern' => '/(?:a|b|c)d/', 'optimized' => '/[a-c]d/'];
        yield 'a group of one class' => ['pattern' => '/(?:[abc])+d/', 'optimized' => '/[a-c]+d/'];
        // A capture keeps its group: factorizing "et" out would change $1.
        yield 'a capture of two words' => ['pattern' => '/^(get|set)/', 'optimized' => '/^(get|set)/'];
    }
}
